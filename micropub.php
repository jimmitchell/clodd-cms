<?php

declare(strict_types=1);

/**
 * Micropub endpoint — accepts posts from Micropub clients (iA Writer, Quill,
 * obsidian-micropub, etc.).
 *
 * Auth: Bearer token — either the legacy shared token (Settings → Micropub,
 * full scopes) or an IndieAuth token issued by /token.php (scoped:
 * create / update / delete / media).
 *
 * Supported requests:
 *   GET  ?q=config|source|syndicate-to|category   discovery queries
 *   POST (form-encoded)   h=entry create; action=delete|undelete
 *   POST (JSON)           {type:["h-entry"], properties:{…}} create;
 *                         {action: update|delete|undelete, url, …}
 *   POST (multipart)      create with photo[] uploads; bare `file` uploads
 *                         (legacy media-endpoint behavior — new clients use
 *                         /media.php from q=config)
 *
 * Responses:
 *   201 Created + Location  (create; update when the URL changed)
 *   202 Accepted + Location (scheduled create)
 *   204 No Content          (update, delete, undelete)
 *   400 / 401 / 403 / 404 / 422 / 429 / 500 + JSON {error, error_description}
 */

// Read raw body before any session-starting code consumes it.
$_mpRawBody = (string) file_get_contents('php://input');

define('CMS_ROOT', __DIR__);
require CMS_ROOT . '/vendor/autoload.php';

$config      = require CMS_ROOT . '/config.php';
$db          = new \CMS\Database($config['paths']['data'] . '/cms.db');
$builder     = new \CMS\Builder($config, $db);
$activityLog = new \CMS\ActivityLog($db);

\CMS\Post::promoteScheduled($db);

// ── Response helpers (delegate to the shared endpoint auth class) ───────────

function mp_json(mixed $data, int $status = 200): never
{
    \CMS\MicropubAuth::json($data, $status);
}

function mp_error(string $code, string $description = '', int $status = 400): never
{
    \CMS\MicropubAuth::error($code, $description, $status);
}

// ── Post resolution by URL ──────────────────────────────────────────────────

/**
 * Resolve a public post URL to a Post.
 *
 * Accepts URLs like https://example.com/2026/04/28/my-slug/ — the slug is the
 * final non-empty path segment. Slugs are unique across posts, so the date
 * portion is informational only.
 */
function mp_resolve_post_by_url(\CMS\Database $db, string $url): ?\CMS\Post
{
    $url = trim($url);
    if ($url === '') return null;
    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path)) return null;
    $segments = array_values(array_filter(explode('/', $path), fn($s) => $s !== ''));
    if (empty($segments)) return null;
    $slug = end($segments);
    return \CMS\Post::findBySlug($db, (string) $slug);
}

// ── Syndication targets ─────────────────────────────────────────────────────

/**
 * The syndicate-to targets advertised in q=config / q=syndicate-to: Mastodon
 * and Bluesky, each present only when its credentials are configured.
 */
function mp_syndication_targets(\CMS\Database $db): array
{
    $targets = [];

    $instance = $db->getSetting('mastodon_instance');
    if ($instance !== '' && $db->getSetting('mastodon_token') !== '') {
        $host = parse_url($instance, PHP_URL_HOST) ?: trim($instance);
        $targets[] = ['uid' => 'mastodon', 'name' => 'Mastodon (' . $host . ')'];
    }

    $handle = $db->getSetting('bluesky_handle');
    if ($handle !== '' && $db->getSetting('bluesky_app_password') !== '') {
        $targets[] = ['uid' => 'bluesky', 'name' => 'Bluesky (@' . ltrim($handle, '@') . ')'];
    }

    return $targets;
}

// ── Photo property parsing ──────────────────────────────────────────────────

/**
 * Parse Micropub `photo` property values — plain URL strings or mf2
 * {value, alt} objects — into photo rows for Post::savePhotos(). URLs under
 * the site's own origin are stored site-relative, matching uploads.
 *
 * @return array<array{url: string, alt: string, media_id: null}>
 */
function mp_parse_photo_values(array $vals, string $siteUrl): array
{
    $rows = [];
    foreach ($vals as $val) {
        if (is_array($val)) {
            $url = is_string($val['value'] ?? null) ? trim($val['value']) : '';
            $alt = is_string($val['alt'] ?? null) ? $val['alt'] : '';
        } else {
            $url = trim((string) $val);
            $alt = '';
        }
        if ($url === '') {
            continue;
        }
        if ($siteUrl !== '' && str_starts_with($url, $siteUrl . '/')) {
            $url = substr($url, strlen($siteUrl));
        }
        $rows[] = ['url' => $url, 'alt' => $alt, 'media_id' => null];
    }
    return $rows;
}

/**
 * Extract validated target URLs for one context property (in-reply-to /
 * like-of / repost-of / bookmark-of). Values may be URL strings, mf2
 * {url}/{value} objects, or embedded h-cite objects with properties.url.
 * Non-absolute-http(s) URLs → 400.
 *
 * @return string[]
 */
function mp_context_urls_from_values(string $kind, array $vals): array
{
    $urls = [];
    foreach ($vals as $val) {
        if (is_array($val)) {
            $url = $val['url'] ?? $val['value'] ?? ($val['properties']['url'][0] ?? '');
            $url = is_string($url) ? trim($url) : '';
        } else {
            $url = trim((string) $val);
        }
        if ($url === '') {
            continue;
        }
        if (!preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            mp_error('invalid_request', "{$kind} values must be absolute http(s) URLs");
        }
        $urls[] = $url;
    }
    return $urls;
}

/**
 * Parse all context properties into rows for Post::saveContexts().
 *
 * @return array<array{kind: string, url: string}>
 */
function mp_parse_context_values(array $properties): array
{
    $rows = [];
    foreach (\CMS\Post::CONTEXT_KINDS as $kind) {
        if (!isset($properties[$kind]) || !is_array($properties[$kind])) {
            continue;
        }
        foreach (mp_context_urls_from_values($kind, $properties[$kind]) as $url) {
            $rows[] = ['kind' => $kind, 'url' => $url];
        }
    }
    return $rows;
}

// ── Source representation (used by GET ?q=source) ───────────────────────────

/**
 * Build the Micropub h-entry source representation of a post.
 *
 * Returns an associative array of property → array-of-values, matching the
 * shape clients send on create. `category` is a flat list of category + tag
 * names. `published` is ISO 8601 in the site's configured timezone (or UTC).
 */
function mp_post_source_properties(\CMS\Post $post, string $cfgTz, string $siteUrl): array
{
    $props = [
        'name'        => [$post->title],
        'content'     => [$post->content],
        'mp-slug'     => [$post->slug],
        'post-status' => [$post->status === 'published' ? 'published' : 'draft'],
    ];

    if ($post->excerpt !== null && $post->excerpt !== '') {
        $props['summary'] = [$post->excerpt];
    }

    if ($post->published_at !== null && $post->published_at !== '') {
        try {
            $dt = new \DateTime($post->published_at, new \DateTimeZone('UTC'));
            if ($cfgTz !== '') {
                $dt->setTimezone(new \DateTimeZone($cfgTz));
            }
            $props['published'] = [$dt->format('c')];
        } catch (\Exception) {
            // Skip malformed dates.
        }
    }

    if ($post->photos !== []) {
        $props['photo'] = array_map(function ($p) use ($siteUrl) {
            $url = (string) $p['url'];
            if ($siteUrl !== '' && str_starts_with($url, '/')) {
                $url = $siteUrl . $url;
            }
            return ((string) $p['alt'] === '') ? $url : ['value' => $url, 'alt' => (string) $p['alt']];
        }, $post->photos);
    }

    foreach (\CMS\Post::CONTEXT_KINDS as $kind) {
        $urls = array_values(array_map(
            fn($c) => (string) $c['url'],
            array_filter($post->contexts, fn($c) => ($c['kind'] ?? '') === $kind)
        ));
        if ($urls !== []) {
            $props[$kind] = $urls;
        }
    }

    $catNames = array_map(fn($c) => (string) $c['name'], $post->categories);
    $tagNames = array_map(fn($t) => (string) $t['name'], $post->tags);
    $allTerms = array_values(array_filter(array_merge($catNames, $tagNames), fn($n) => $n !== ''));
    if ($allTerms !== []) {
        $props['category'] = $allTerms;
    }

    if ($post->status === 'published' && $post->published_at !== null && $siteUrl !== '') {
        $props['url'] = [$siteUrl . '/' . \CMS\Post::datePath($post->published_at, $post->slug, $cfgTz) . '/'];
    }

    return $props;
}

// ── GET: configuration queries ──────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD') {
    \CMS\MicropubAuth::authenticate($db, $config);

    $q       = $_GET['q'] ?? '';
    $siteUrl = rtrim($db->getSetting('site_url', ''), '/');

    if ($q === 'config') {
        mp_json([
            'media-endpoint' => $siteUrl . '/media.php',
            'syndicate-to'   => mp_syndication_targets($db),
            'q'              => ['config', 'source', 'syndicate-to', 'category'],
        ]);
    }

    if ($q === 'syndicate-to') {
        mp_json(['syndicate-to' => mp_syndication_targets($db)]);
    }

    if ($q === 'source') {
        $targetUrl = $_GET['url'] ?? '';
        if (!is_string($targetUrl) || $targetUrl === '') {
            mp_error('invalid_request', 'url is required');
        }
        $post = mp_resolve_post_by_url($db, $targetUrl);
        if (!$post || $post->deleted_at !== null) {
            mp_error('invalid_request', 'post not found for url', 404);
        }

        $cfgTz = $db->getSetting('timezone', '');
        $all   = mp_post_source_properties($post, $cfgTz, $siteUrl);

        // Optional properties[] filter — when present, omit the type wrapper.
        $requested = $_GET['properties'] ?? null;
        if (is_array($requested) && $requested !== []) {
            $filtered = [];
            foreach ($requested as $prop) {
                if (is_string($prop) && isset($all[$prop])) {
                    $filtered[$prop] = $all[$prop];
                }
            }
            mp_json(['properties' => $filtered]);
        }

        mp_json([
            'type'       => ['h-entry'],
            'properties' => $all,
        ]);
    }

    if ($q === 'category') {
        $rows  = $db->select(
            'SELECT name FROM categories UNION SELECT name FROM tags ORDER BY name COLLATE NOCASE'
        );
        $names = array_values(array_filter(
            array_map(fn($r) => (string) $r['name'], $rows),
            fn($n) => $n !== ''
        ));
        mp_json(['categories' => $names]);
    }

    mp_error('invalid_request', $q === '' ? 'q parameter is required' : "unsupported query: {$q}");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST, HEAD');
    mp_error('invalid_request', 'Method not allowed', 405);
}

// ── POST: dispatch on action (create | update | delete | undelete) ─────────

$mpAuthz = \CMS\MicropubAuth::authenticate($db, $config);

$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
$properties  = [];
$photoFiles  = [];
$jsonBody    = null;
$action      = '';
$updateOps   = ['replace' => [], 'add' => [], 'delete' => []];
$targetUrl   = '';

if ($contentType === 'application/json') {
    $jsonBody = json_decode($_mpRawBody, true);
    if (!is_array($jsonBody)) {
        mp_error('invalid_request', 'Malformed JSON body');
    }

    if (isset($jsonBody['action']) && is_string($jsonBody['action'])) {
        $action    = strtolower($jsonBody['action']);
        $targetUrl = isset($jsonBody['url']) && is_string($jsonBody['url']) ? $jsonBody['url'] : '';

        if ($action === 'update') {
            foreach (['replace', 'add', 'delete'] as $op) {
                if (!isset($jsonBody[$op])) continue;
                $val = $jsonBody[$op];
                // `delete` may be either {prop: [vals]} or [prop, prop] (whole-property removal).
                if ($op === 'delete' && is_array($val) && array_is_list($val)) {
                    foreach ($val as $prop) {
                        if (is_string($prop)) $updateOps['delete'][$prop] = [];
                    }
                    continue;
                }
                if (!is_array($val)) {
                    mp_error('invalid_request', "{$op} must be an object");
                }
                foreach ($val as $prop => $vals) {
                    $updateOps[$op][$prop] = is_array($vals) ? array_values($vals) : [$vals];
                }
            }
        }
    } else {
        $action = 'create';
        $type   = $jsonBody['type'][0] ?? null;
        if ($type !== 'h-entry') {
            mp_error('invalid_request', 'Only h-entry is supported');
        }
        $rawProps = $jsonBody['properties'] ?? [];
        if (!is_array($rawProps)) {
            mp_error('invalid_request', 'properties must be an object');
        }
        foreach ($rawProps as $key => $value) {
            $properties[$key] = is_array($value) ? array_values($value) : [$value];
        }
    }
} else {
    // Media-endpoint upload: multipart request with a `file` field, no h-entry,
    // no action. Stores the file and returns 201 Created + Location.
    if (
        empty($_POST['action'])
        && empty($_POST['h'])
        && !empty($_FILES['file'])
        && (!is_array($_FILES['file']['name']) || $_FILES['file']['name'] !== [])
    ) {
        $f = $_FILES['file'];
        if (is_array($f['name'])) {
            // Take the first file if a client sends file[].
            $f = [
                'name'     => $f['name'][0]     ?? '',
                'tmp_name' => $f['tmp_name'][0] ?? '',
                'size'     => (int) ($f['size'][0]  ?? 0),
                'error'    => (int) ($f['error'][0] ?? UPLOAD_ERR_NO_FILE),
            ];
        }
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            mp_error('invalid_request', 'file upload error');
        }
        \CMS\MicropubAuth::requireScope($mpAuthz, 'media', 'create');
        try {
            $mediaService = new \CMS\Media($db, $config['paths']['content'] . '/media');
            $result       = $mediaService->upload($f);
        } catch (\RuntimeException $e) {
            mp_error('invalid_request', $e->getMessage(), 422);
        }
        $mediaUrl = rtrim($db->getSetting('site_url', ''), '/') . $result['url'];
        http_response_code(201);
        header('Location: ' . $mediaUrl);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['url' => $mediaUrl], JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Form-encoded / multipart. Spec allows action=delete (and action=undelete,
    // unsupported here). action=update requires JSON because it has nested ops.
    if (isset($_POST['action']) && is_string($_POST['action']) && $_POST['action'] !== '') {
        $action    = strtolower($_POST['action']);
        $targetUrl = isset($_POST['url']) && is_string($_POST['url']) ? $_POST['url'] : '';
        if ($action === 'update') {
            mp_error('invalid_request', 'update requires application/json');
        }
    } else {
        $action = 'create';
        if (($_POST['h'] ?? '') !== 'entry') {
            mp_error('invalid_request', 'Only h=entry is supported');
        }

        foreach ($_POST as $key => $value) {
            if ($key === 'h' || $key === 'access_token') continue;
            $properties[$key] = is_array($value) ? array_values($value) : [(string) $value];
        }

        if (!empty($_FILES['photo'])) {
            $f = $_FILES['photo'];
            if (is_array($f['name'])) {
                $count = count($f['name']);
                for ($i = 0; $i < $count; $i++) {
                    if ($f['error'][$i] === UPLOAD_ERR_OK) {
                        $photoFiles[] = [
                            'name'     => $f['name'][$i],
                            'tmp_name' => $f['tmp_name'][$i],
                            'size'     => (int) $f['size'][$i],
                            'error'    => (int) $f['error'][$i],
                        ];
                    }
                }
            } elseif ($f['error'] === UPLOAD_ERR_OK) {
                $photoFiles[] = $f;
            }
        }
    }
}

// ── action: delete ──────────────────────────────────────────────────────────

if ($action === 'delete') {
    \CMS\MicropubAuth::requireScope($mpAuthz, 'delete');
    if ($targetUrl === '') {
        mp_error('invalid_request', 'url is required');
    }
    $post = mp_resolve_post_by_url($db, $targetUrl);
    if (!$post || $post->deleted_at !== null) {
        mp_error('invalid_request', 'post not found for url', 404);
    }

    $wasPublished = $post->status === 'published';
    $prev = $wasPublished ? \CMS\Post::findPrev($db, $post) : null;
    $next = $wasPublished ? \CMS\Post::findNext($db, $post) : null;

    // Soft delete so action=undelete can restore. buildPost() removes the
    // output file and rebuilds taxonomy archives for deleted posts.
    $post->softDelete();
    $builder->buildPost($post);
    if ($wasPublished) {
        if ($prev) $builder->buildPost($prev);
        if ($next) $builder->buildPost($next);
        $builder->rebuildSharedResources();
    }

    $activityLog->log('delete', 'post', $post->id, $post->title . ' (via micropub)');

    http_response_code(204);
    exit;
}

// ── action: undelete ────────────────────────────────────────────────────────

if ($action === 'undelete') {
    \CMS\MicropubAuth::requireScope($mpAuthz, 'delete');
    if ($targetUrl === '') {
        mp_error('invalid_request', 'url is required');
    }
    $post = mp_resolve_post_by_url($db, $targetUrl);
    if (!$post) {
        mp_error('invalid_request', 'post not found for url', 404);
    }
    if ($post->deleted_at === null) {
        mp_error('invalid_request', 'post is not deleted');
    }

    $post->restore();
    if ($post->status === 'published') {
        $builder->buildPost($post);
        if ($p = \CMS\Post::findPrev($db, $post)) $builder->buildPost($p);
        if ($n = \CMS\Post::findNext($db, $post)) $builder->buildPost($n);
        $builder->rebuildSharedResources();
    }

    $activityLog->log('undelete', 'post', $post->id, $post->title . ' (via micropub)');

    http_response_code(204);
    exit;
}

// ── action: update ──────────────────────────────────────────────────────────

if ($action === 'update') {
    \CMS\MicropubAuth::requireScope($mpAuthz, 'update');
    if ($targetUrl === '') {
        mp_error('invalid_request', 'url is required');
    }
    $post = mp_resolve_post_by_url($db, $targetUrl);
    if (!$post || $post->deleted_at !== null) {
        mp_error('invalid_request', 'post not found for url', 404);
    }

    // Snapshot fields used to decide neighbor/shared-resource rebuilds.
    $snapTitle       = $post->title;
    $snapSlug        = $post->slug;
    $snapPublishedAt = $post->published_at;
    $snapExcerpt     = $post->excerpt;
    $wasPublished    = $post->status === 'published';

    $oldDir = ($wasPublished && $post->published_at !== null)
        ? rtrim($config['paths']['output'], '/\\') . '/posts/' . \CMS\Post::datePath($post->published_at, $post->slug, $db->getSetting('timezone', ''))
        : null;

    // Old-position neighbors, snapshotted before mutation: if the post moves in
    // the timeline (published date or slug change) their prev/next links must be
    // rebuilt too, alongside the new-position neighbors.
    $oldPrev = $wasPublished ? \CMS\Post::findPrev($db, $post) : null;
    $oldNext = $wasPublished ? \CMS\Post::findNext($db, $post) : null;

    // ── Apply replace ops ────────────────────────────────────────────────────
    //
    // Supported properties: name, content, mp-slug, category, post-status,
    // published, photo, and the context properties. `published` may only be
    // replaced — add/delete would orphan the date-based URL.

    $rejectFrozen = function (array $ops, string $opName): void {
        if (array_key_exists('published', $ops)) {
            mp_error('invalid_request', "cannot {$opName} published on existing post");
        }
    };
    $rejectFrozen($updateOps['add'],    'add');
    $rejectFrozen($updateOps['delete'], 'delete');

    $touchedTerms = false;
    $newCategoryIds = array_map('intval', array_column($post->categories, 'id'));
    $newTagIds      = array_map('intval', array_column($post->tags, 'id'));

    $updSiteUrl    = rtrim($db->getSetting('site_url', ''), '/');
    $touchedPhotos = false;
    $newPhotos     = $post->photos;

    $touchedContexts = false;
    $newContexts     = $post->contexts;

    // Normalize photo urls from update ops the same way stored rows are kept
    // (site-relative for own-origin urls) so delete-by-value matches.
    $normalizePhotoUrls = fn(array $vals): array => array_map(
        fn($r) => $r['url'],
        mp_parse_photo_values($vals, $updSiteUrl)
    );

    $applyCategories = function (array $cats) use ($db, &$newCategoryIds, &$newTagIds): void {
        $newCategoryIds = [];
        $newTagIds      = [];
        foreach ($cats as $cat) {
            $cat = (string) $cat;
            $catSlug = \CMS\Helpers::slugify($cat);
            if ($catSlug === '' || $catSlug === 'untitled') continue;
            $existingCat = $db->selectOne('SELECT id FROM categories WHERE slug = :slug', [':slug' => $catSlug]);
            if ($existingCat) {
                $newCategoryIds[] = (int) $existingCat['id'];
                continue;
            }
            $existingTag = $db->selectOne('SELECT id FROM tags WHERE slug = :slug', [':slug' => $catSlug]);
            if ($existingTag) {
                $newTagIds[] = (int) $existingTag['id'];
            } else {
                $newTagIds[] = (int) $db->insert('tags', ['name' => $cat, 'slug' => $catSlug]);
            }
        }
    };

    foreach ($updateOps['replace'] as $prop => $vals) {
        switch ($prop) {
            case 'name':
                $title = is_string($vals[0] ?? null) ? trim($vals[0]) : '';
                if ($title === '') mp_error('invalid_request', 'name cannot be empty');
                $post->title = $title;
                break;

            case 'content':
                $first = $vals[0] ?? null;
                if (is_array($first)) {
                    $picked = $first['markdown'] ?? $first['html'] ?? $first['value'] ?? '';
                    $post->content = is_string($picked) ? $picked : '';
                } else {
                    $post->content = (string) $first;
                }
                break;

            case 'summary':
                $first = is_string($vals[0] ?? null) ? trim($vals[0]) : '';
                $post->excerpt = $first !== '' ? $first : null;
                break;

            case 'mp-slug':
                $newSlug = \CMS\Helpers::slugify((string) ($vals[0] ?? ''));
                if ($newSlug === '' || $newSlug === 'untitled') {
                    mp_error('invalid_request', 'mp-slug invalid');
                }
                if ($newSlug !== $post->slug) {
                    $clash = \CMS\Post::findBySlug($db, $newSlug);
                    if ($clash && $clash->id !== $post->id) {
                        mp_error('invalid_request', 'slug already in use');
                    }
                    $post->slug = $newSlug;
                }
                break;

            case 'category':
                $applyCategories($vals);
                $touchedTerms = true;
                break;

            case 'photo':
                $newPhotos     = mp_parse_photo_values($vals, $updSiteUrl);
                $touchedPhotos = true;
                break;

            case 'in-reply-to':
            case 'like-of':
            case 'repost-of':
            case 'bookmark-of':
                $newContexts = array_values(array_filter($newContexts, fn($c) => $c['kind'] !== $prop));
                foreach (mp_context_urls_from_values($prop, $vals) as $ctxUrl) {
                    $newContexts[] = ['kind' => $prop, 'url' => $ctxUrl];
                }
                $touchedContexts = true;
                break;

            case 'published':
                $newTs = is_string($vals[0] ?? null) ? strtotime($vals[0]) : false;
                if ($newTs === false) {
                    mp_error('invalid_request', 'published must be a valid datetime');
                }
                $post->published_at = date('Y-m-d H:i:s', $newTs);
                if ($post->status === 'published' && $newTs > time()) {
                    // Moved into the future: unpublish until the scheduler promotes it.
                    $post->status = 'scheduled';
                }
                break;

            case 'post-status':
                $newStatus = (string) ($vals[0] ?? '');
                if ($newStatus === 'draft') {
                    $post->status = 'draft';
                } elseif ($newStatus === 'published') {
                    $post->status = 'published';
                    if ($post->published_at === null) {
                        // Going draft → published for the first time: stamp now.
                        $post->published_at = date('Y-m-d H:i:s');
                    }
                } else {
                    mp_error('invalid_request', 'post-status must be draft or published');
                }
                break;

            default:
                // Silently ignore unsupported properties (per spec, servers MAY).
                break;
        }
    }

    // `add` for category appends; `summary` is single-valued so add ≈ replace.
    foreach ($updateOps['add'] as $prop => $vals) {
        if ($prop === 'category') {
            $current = array_map(fn($c) => (string) $c['name'], $post->categories);
            $merged  = array_values(array_unique(array_merge($current, array_map('strval', $vals))));
            $applyCategories($merged);
            $touchedTerms = true;
        } elseif ($prop === 'summary') {
            $first = is_string($vals[0] ?? null) ? trim($vals[0]) : '';
            if ($first !== '') $post->excerpt = $first;
        } elseif ($prop === 'photo') {
            $newPhotos     = array_merge($newPhotos, mp_parse_photo_values($vals, $updSiteUrl));
            $touchedPhotos = true;
        } elseif (in_array($prop, \CMS\Post::CONTEXT_KINDS, true)) {
            foreach (mp_context_urls_from_values($prop, $vals) as $ctxUrl) {
                $newContexts[] = ['kind' => $prop, 'url' => $ctxUrl];
            }
            $touchedContexts = true;
        }
    }

    // `delete` per-property: category/photo clearing/removal and summary clearing.
    foreach ($updateOps['delete'] as $prop => $vals) {
        if ($prop === 'photo') {
            // delete: [photo] (empty $vals) → clear all
            // delete: {photo: [urls]}       → remove matching urls
            if (empty($vals)) {
                $newPhotos = [];
            } else {
                $remove    = $normalizePhotoUrls($vals);
                $newPhotos = array_values(array_filter(
                    $newPhotos,
                    fn($p) => !in_array($p['url'], $remove, true)
                ));
            }
            $touchedPhotos = true;
        } elseif ($prop === 'category') {
            // delete: [category] (empty $vals) → clear all
            // delete: {category: [a, b]}      → remove specific values
            if (empty($vals)) {
                $applyCategories([]);
            } else {
                $remove  = array_map('strval', $vals);
                $current = array_map(fn($c) => (string) $c['name'], $post->categories);
                $kept    = array_values(array_diff($current, $remove));
                $applyCategories($kept);
            }
            $touchedTerms = true;
        } elseif ($prop === 'summary') {
            $post->excerpt = null;
        } elseif (in_array($prop, \CMS\Post::CONTEXT_KINDS, true)) {
            // delete: [in-reply-to] (empty $vals) → clear that kind
            // delete: {like-of: [urls]}           → remove matching urls
            if (empty($vals)) {
                $newContexts = array_values(array_filter($newContexts, fn($c) => $c['kind'] !== $prop));
            } else {
                $remove      = mp_context_urls_from_values($prop, $vals);
                $newContexts = array_values(array_filter(
                    $newContexts,
                    fn($c) => $c['kind'] !== $prop || !in_array($c['url'], $remove, true)
                ));
            }
            $touchedContexts = true;
        }
    }

    if (!$post->save()) {
        mp_error('server_error', 'failed to save update', 500);
    }
    if ($touchedTerms) {
        $post->saveTerms($newCategoryIds, $newTagIds);
    }
    if ($touchedPhotos) {
        $post->savePhotos($newPhotos);
    }
    if ($touchedContexts) {
        $post->saveContexts($newContexts);
    }

    // Remove stale output when the date-path changed (slug or published date).
    if ($oldDir !== null) {
        $newDir = $post->published_at !== null
            ? rtrim($config['paths']['output'], '/\\') . '/posts/' . \CMS\Post::datePath($post->published_at, $post->slug, $db->getSetting('timezone', ''))
            : null;
        if ($newDir !== $oldDir) {
            $oldFile = $oldDir . '/index.html';
            if (is_file($oldFile)) @unlink($oldFile);
            if (is_dir($oldDir))   @rmdir($oldDir);
        }
    }

    if ($post->status === 'published' || $wasPublished) {
        $builder->buildPost($post);
        $neighborsAffected = !$wasPublished
            || $post->status !== 'published'
            || $post->title  !== $snapTitle
            || $post->slug   !== $snapSlug
            || $post->published_at !== $snapPublishedAt;
        if ($neighborsAffected) {
            // Rebuild both the old-position and new-position neighbors, once each.
            $built = [];
            foreach ([$oldPrev, $oldNext, \CMS\Post::findPrev($db, $post), \CMS\Post::findNext($db, $post)] as $neighbor) {
                if ($neighbor && $neighbor->id !== $post->id && !isset($built[$neighbor->id])) {
                    $builder->buildPost($neighbor);
                    $built[$neighbor->id] = true;
                }
            }
        }
        $builder->rebuildSharedResources();
    }

    $activityLog->log('update', 'post', $post->id, $post->title . ' (via micropub)');

    // Spec: 201 + Location when the update changed the post's URL, else 204.
    $siteUrl = rtrim($db->getSetting('site_url', ''), '/');
    $cfgTz   = $db->getSetting('timezone', '');
    $newUrl = ($post->status === 'published' && $post->published_at !== null && $siteUrl !== '')
        ? $siteUrl . '/' . \CMS\Post::datePath($post->published_at, $post->slug, $cfgTz) . '/'
        : '';
    $oldUrl = ($wasPublished && $snapPublishedAt !== null && $siteUrl !== '')
        ? $siteUrl . '/' . \CMS\Post::datePath($snapPublishedAt, $snapSlug, $cfgTz) . '/'
        : '';

    if ($newUrl !== '' && $newUrl !== $oldUrl) {
        http_response_code(201);
        header('Location: ' . $newUrl);
    } else {
        http_response_code(204);
    }
    exit;
}

if ($action !== 'create') {
    mp_error('invalid_request', "unsupported action: {$action}");
}

\CMS\MicropubAuth::requireScope($mpAuthz, 'create');

// ── Property accessors ──────────────────────────────────────────────────────

function mp_first(array $properties, string $key, string $default = ''): string
{
    $val = $properties[$key][0] ?? null;
    if ($val === null) {
        return $default;
    }
    if (is_array($val)) {
        // {markdown: …} | {html: …} | {value: …, html: …}
        $picked = $val['markdown'] ?? $val['html'] ?? $val['value'] ?? '';
        return is_string($picked) ? $picked : $default;
    }
    return (string) $val;
}

$title      = trim(mp_first($properties, 'name'));
$content    = mp_first($properties, 'content');
$summary    = trim(mp_first($properties, 'summary'));
$slugInput  = trim(mp_first($properties, 'mp-slug'));
$published  = trim(mp_first($properties, 'published'));
$postStatus = trim(mp_first($properties, 'post-status'));
$categories = isset($properties['category']) && is_array($properties['category'])
    ? array_values(array_filter(array_map('strval', $properties['category']), fn($c) => $c !== ''))
    : [];

// ── Photos: first-class u-photo property ────────────────────────────────────
//
// Sources: `photo` property values (URL strings or mf2 {value, alt} objects,
// typically pointing at the media endpoint) and direct multipart uploads.
// Alt text for multipart files may arrive via mp-photo-alt[] (by index).

$mpSiteUrl = rtrim($db->getSetting('site_url', ''), '/');
$photoRows = isset($properties['photo']) && is_array($properties['photo'])
    ? mp_parse_photo_values($properties['photo'], $mpSiteUrl)
    : [];

if (!empty($photoFiles)) {
    $mediaService = new \CMS\Media($db, $config['paths']['content'] . '/media');
    $photoAlts    = isset($properties['mp-photo-alt']) && is_array($properties['mp-photo-alt'])
        ? array_map('strval', $properties['mp-photo-alt'])
        : [];
    foreach ($photoFiles as $i => $photo) {
        try {
            $result = $mediaService->upload($photo);
        } catch (\RuntimeException $e) {
            mp_error('invalid_request', 'photo upload failed: ' . $e->getMessage(), 422);
        }
        $photoRows[] = ['url' => $result['url'], 'alt' => $photoAlts[$i] ?? '', 'media_id' => $result['id']];
    }
}

// ── Contexts: reply/like/repost/bookmark targets ────────────────────────────

$contextRows = mp_parse_context_values($properties);

if ($content === '' && $photoRows === [] && $contextRows === []) {
    mp_error('invalid_request', 'content, photo, or a context property (in-reply-to, like-of, repost-of, bookmark-of) is required');
}

// An absent name property is the note/article distinction in Micropub, so every
// titleless post is an aside: no derived title, no h1.
$isAside = $title === '';

// ── Slug + uniqueness ───────────────────────────────────────────────────────

// mp-slug wins when supplied; otherwise a standard post slugs from its title
// and an aside from the opening words of its body.
$slugBase = $slugInput !== ''
    ? $slugInput
    : ($isAside ? \CMS\Post::slugFromContent($content) : $title);

// A photo-only or bare like-of aside has no words to slug from — leave the slug
// empty and finalize it to the autoincrement id after save.
$slug = $slugBase !== '' ? \CMS\Post::resolveUniqueSlug($db, $slugBase) : '';

// ── Status + published_at ───────────────────────────────────────────────────

$publishTs = $published !== '' ? strtotime($published) : false;
$now       = time();

if ($postStatus === 'draft') {
    $status      = 'draft';
    $publishedAt = $publishTs !== false ? date('Y-m-d H:i:s', $publishTs) : null;
} elseif ($publishTs !== false && $publishTs > $now) {
    $status      = 'scheduled';
    $publishedAt = date('Y-m-d H:i:s', $publishTs);
} else {
    $status      = 'published';
    $publishedAt = date('Y-m-d H:i:s', $publishTs !== false ? $publishTs : $now);
}

// ── Resolve categories: existing-category-slug → category, else → tag ───────

$categoryIds = [];
$tagIds      = [];
foreach ($categories as $cat) {
    $catSlug = \CMS\Helpers::slugify($cat);
    if ($catSlug === '' || $catSlug === 'untitled') continue;

    $existingCat = $db->selectOne('SELECT id FROM categories WHERE slug = :slug', [':slug' => $catSlug]);
    if ($existingCat) {
        $categoryIds[] = (int) $existingCat['id'];
        continue;
    }

    $existingTag = $db->selectOne('SELECT id FROM tags WHERE slug = :slug', [':slug' => $catSlug]);
    if ($existingTag) {
        $tagIds[] = (int) $existingTag['id'];
    } else {
        $tagIds[] = (int) $db->insert('tags', ['name' => $cat, 'slug' => $catSlug]);
    }
}

// ── Save ────────────────────────────────────────────────────────────────────

$post               = new \CMS\Post($db);
$post->post_kind    = $isAside ? 'aside' : 'standard';
$post->title        = $title;
$post->slug         = $slug;
$post->content      = $content;
$post->excerpt      = $summary !== '' ? $summary : null;
$post->status       = $status;
$post->published_at = $publishedAt;

// mp-syndicate-to: when the property is present, syndicate only to the listed
// target uids (empty list = none). Absent = default auto-syndication.
if (array_key_exists('mp-syndicate-to', $properties)) {
    $requested = array_map('strval', $properties['mp-syndicate-to']);
    $post->mastodon_skip = in_array('mastodon', $requested, true) ? 0 : 1;
    $post->bluesky_skip  = in_array('bluesky', $requested, true) ? 0 : 1;
}

if (!$post->save()) {
    mp_error('server_error', 'Failed to save post', 500);
}

// An aside with no words to slug from falls back to the autoincrement id.
// Re-save once the id is known.
if ($post->slug === '' && $post->id !== null) {
    $post->slug = (string) $post->id;
    $post->save();
}

$post->saveTerms($categoryIds, $tagIds);
if ($photoRows !== []) {
    $post->savePhotos($photoRows);
}
if ($contextRows !== []) {
    $post->saveContexts($contextRows);
}

// ── Build static output + neighbors + shared resources ──────────────────────

if ($status === 'published') {
    $builder->buildPost($post);
    if ($prev = \CMS\Post::findPrev($db, $post)) $builder->buildPost($prev);
    if ($next = \CMS\Post::findNext($db, $post)) $builder->buildPost($next);
    $builder->rebuildSharedResources();
}

// ── Syndicate to Mastodon / Bluesky on first publish ────────────────────────
//
// Content-less interaction posts (a bare like/repost/bookmark) have nothing to
// say on other networks — skip syndication for those.

if ($status === 'published' && trim($post->content) !== '') {
    $cfgTz              = $db->getSetting('timezone', '');
    $mastodonInstance   = $db->getSetting('mastodon_instance');
    $mastodonToken      = $db->getSetting('mastodon_token');
    $hasMastodon        = $mastodonInstance !== '' && $mastodonToken !== '';
    $blueskyHandle      = $db->getSetting('bluesky_handle');
    $blueskyAppPassword = $db->getSetting('bluesky_app_password');
    $hasBluesky         = $blueskyHandle !== '' && $blueskyAppPassword !== '';

    if (($hasMastodon && $post->mastodon_skip === 0) || ($hasBluesky && $post->bluesky_skip === 0)) {
        // POSSE: asides syndicate as native-looking notes — no title, no link back.
        if ($post->isAside()) {
            $postUrl = '';
            $excerpt = trim(\CMS\Post::plaintextFromMarkdown($post->content));
        } else {
            $postUrl = rtrim($db->getSetting('site_url', ''), '/')
                     . '/' . \CMS\Post::datePath($post->published_at, $post->slug, $cfgTz) . '/';

            $effective = $post->effectiveExcerpt();
            $excerpt   = $effective !== null
                ? strip_tags($effective)
                : \CMS\Helpers::truncate($post->content, 280);
        }

        if ($hasMastodon && $post->mastodon_skip === 0 && $post->tooted_at === null) {
            $mastodon = new \CMS\Mastodon($mastodonInstance, $mastodonToken);
            if ($tootUrl = $mastodon->tootPost($post->title, $excerpt, $postUrl)) {
                $post->markTooted($tootUrl);
            }
        }
        if ($hasBluesky && $post->bluesky_skip === 0 && $post->bluesky_at === null) {
            $bluesky = new \CMS\Bluesky($blueskyHandle, $blueskyAppPassword);
            if ($bskyUrl = $bluesky->postToBluesky($post->title, $excerpt, $postUrl)) {
                $post->markBluesky($bskyUrl);
            }
        }
    }
}

// ── Activity log ────────────────────────────────────────────────────────────

$logAction = match ($status) {
    'published' => 'publish',
    'scheduled' => 'schedule',
    default     => 'create',
};
$activityLog->log($logAction, 'post', $post->id, $post->title . ' (via micropub)');

// ── Response ────────────────────────────────────────────────────────────────

$siteUrl  = rtrim($db->getSetting('site_url', ''), '/');
$cfgTz    = $db->getSetting('timezone', '');
$location = $publishedAt !== null && $siteUrl !== ''
    ? $siteUrl . '/' . \CMS\Post::datePath($publishedAt, $post->slug, $cfgTz) . '/'
    : ($siteUrl !== '' ? $siteUrl . '/admin/post-edit.php?id=' . $post->id : '/admin/post-edit.php?id=' . $post->id);

// Spec: 202 Accepted when the post will be processed later (scheduled).
http_response_code($status === 'scheduled' ? 202 : 201);
header('Location: ' . $location);
exit;
