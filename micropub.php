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
 *                         ?q=source with no url lists posts (Post List
 *                         extension) — limit/offset/post-type/post-status
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

// Never render a PHP notice or fatal into the response: it would leak absolute
// filesystem paths, and on the JSON endpoints it also corrupts the body. Errors
// still reach the server log.
ini_set('display_errors', '0');

define('CMS_ROOT', __DIR__);
require CMS_ROOT . '/vendor/autoload.php';

$config      = require CMS_ROOT . '/config.php';
$db          = new \CMS\Database($config['paths']['data'] . '/cms.db');
$builder     = new \CMS\Builder($config, $db);
$activityLog = new \CMS\ActivityLog($db);
$syndication = new \CMS\Syndication($db, $config);

// The scheduler deliberately does NOT run here. It used to, on this line,
// before a token had been looked at — so an anonymous request could drive a
// full site build plus outbound syndication (GD re-encoding, multi-megabyte
// uploads, and up to 5s of sleep() waiting on Mastodon), all ahead of the rate
// limiter in MicropubAuth::authenticate(). It is the same mistake CLAUDE.md
// records as fixed in admin/api.php, which was corrected while this was not.
//
// Cron publishes now; the fallback tick() moved below the POST branch's
// authenticate() call, where a token has already been proved.

// ── Response helpers (delegate to the shared endpoint auth class) ───────────

function mp_json(mixed $data, int $status = 200): never
{
    \CMS\MicropubAuth::json($data, $status);
}

function mp_error(string $code, string $description = '', int $status = 400): never
{
    \CMS\MicropubAuth::error($code, $description, $status);
}

// Page size for the q=source post list. Each item carries the post's full body,
// so an unbounded list would serve the entire archive in one response.
const MP_LIST_DEFAULT_LIMIT = 20;
const MP_LIST_MAX_LIMIT     = 100;

// ── Post resolution by URL ──────────────────────────────────────────────────

/**
 * Resolve a public post URL to a Post.
 *
 * Accepts URLs like https://example.com/2026/04/28/my-slug/ — the slug is the
 * final non-empty path segment. Slugs are unique across posts, so the date
 * portion is informational only.
 *
 * The URL must belong to this site: an absolute URL on another origin is
 * rejected rather than being matched on its last path segment, which would let
 * a caller address our posts through someone else's domain.
 */
function mp_resolve_post_by_url(\CMS\Database $db, string $url): ?\CMS\Post
{
    $url = trim($url);
    if ($url === '') return null;

    $parts = parse_url($url);
    if ($parts === false) return null;

    // A host means an absolute URL — it has to be ours. Relative URLs (no host)
    // are accepted as-is; they can only ever refer to this site.
    if (isset($parts['host'])) {
        $siteUrl  = rtrim($db->getSetting('site_url', ''), '/');
        $siteHost = $siteUrl !== '' ? (string) (parse_url($siteUrl, PHP_URL_HOST) ?: '') : '';
        if ($siteHost === '' || strcasecmp((string) $parts['host'], $siteHost) !== 0) {
            return null;
        }
    }

    $path = $parts['path'] ?? null;
    if (!is_string($path)) return null;
    $segments = array_values(array_filter(explode('/', $path), fn($s) => $s !== ''));
    if (empty($segments)) return null;
    $slug = end($segments);
    return \CMS\Post::findBySlug($db, (string) $slug);
}

// ── Syndication targets ─────────────────────────────────────────────────────

/**
 * The syndicate-to targets advertised in q=config / q=syndicate-to: Mastodon,
 * Bluesky and Pixelfed, each present only when its credentials are configured.
 *
 * Pixelfed is advertised to every client, not only the ones about to send a
 * photo — the targets are read once when a client opens its composer, long
 * before it knows what is being posted. Selecting it on something that is not a
 * photo post is simply ignored (see Syndication::wantsPixelfed()).
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

    $pixelfed = $db->getSetting('pixelfed_instance');
    if ($pixelfed !== '' && $db->getSetting('pixelfed_token') !== '') {
        $host = parse_url($pixelfed, PHP_URL_HOST) ?: trim($pixelfed);
        $targets[] = ['uid' => 'pixelfed', 'name' => 'Pixelfed (' . $host . ', photos only)'];
    }

    return $targets;
}

// ── Post types ──────────────────────────────────────────────────────────────

/**
 * The post-types advertised in q=config — a Micropub extension clients use to
 * populate a type picker instead of guessing what the server accepts.
 *
 * Types are Post Type Discovery names, listed most-used first (clients render
 * them in order). Only the ones this server stores end-to-end appear: `article`
 * and `note` are the name/no-name split, `photo` is the photo property, and the
 * four interaction types are the CONTEXT_KINDS. Deliberately absent are video,
 * audio, rsvp, and checkin — their properties are currently dropped on create,
 * so advertising them would invite posts that lose their point.
 *
 * @return array<array<string,string>>
 */
function mp_post_types(): array
{
    return [
        ['type' => 'note',     'name' => 'Note'],
        ['type' => 'article',  'name' => 'Article'],
        ['type' => 'photo',    'name' => 'Photo'],
        ['type' => 'reply',    'name' => 'Reply'],
        ['type' => 'repost',   'name' => 'Repost'],
        ['type' => 'like',     'name' => 'Like'],
        ['type' => 'bookmark', 'name' => 'Bookmark'],
    ];
}

// ── Photo property parsing ──────────────────────────────────────────────────

/**
 * Parse Micropub `photo` property values — plain URL strings or mf2
 * {value, alt} objects — into photo rows for Post::savePhotos(). URLs under
 * the site's own origin are stored site-relative, matching uploads.
 *
 * A photo URL reaches both an <img src> and, since 1.15.2, the href of the
 * link that opens it in the lightbox. An href is a live sink: `javascript:`
 * there is a working XSS on a public page, where in a src it is inert. The
 * token that posted it holds `create`, which CLAUDE.md already treats as
 * trusted — but this is one allowlist call, so refuse it at the boundary
 * rather than rely on every future consumer to remember.
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
        // Origin rewrite plus the scheme allowlist, shared with the featured
        // image so there is one definition rather than two drifting ones.
        $url = \CMS\Post::normaliseImageUrl($url, $siteUrl);
        if ($url === null) {
            continue;
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

    // effectivePhotos(), not ->photos: a photo post written in the admin keeps
    // its picture in the body rather than in post_photos, and a client asking
    // for the source still needs to be told the post has one.
    $sourcePhotos = $post->effectivePhotos();
    if ($sourcePhotos !== []) {
        $props['photo'] = array_map(function ($p) use ($siteUrl) {
            $url = (string) $p['url'];
            if ($siteUrl !== '' && str_starts_with($url, '/')) {
                $url = $siteUrl . $url;
            }
            return ((string) $p['alt'] === '') ? $url : ['value' => $url, 'alt' => (string) $p['alt']];
        }, $sourcePhotos);
    }

    // effectiveFeaturedImage(), for the same reason: a post written before the
    // field existed keeps its lead picture at the top of the body, and a client
    // asking for the source still needs to be told the post has one.
    $sourceFeatured = $post->effectiveFeaturedImage();
    if ($sourceFeatured !== null) {
        $featuredUrl = (string) $sourceFeatured['url'];
        if ($siteUrl !== '' && str_starts_with($featuredUrl, '/')) {
            $featuredUrl = $siteUrl . $featuredUrl;
        }
        $featuredAlt = (string) $sourceFeatured['alt'];
        $props['featured'] = [
            $featuredAlt === '' ? $featuredUrl : ['value' => $featuredUrl, 'alt' => $featuredAlt],
        ];
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

    // Every post gets a url, published or not. addressablePath() returns the
    // date permalink once there is a publish date and the bare slug before —
    // the same value the create/update response puts in Location:, so a client
    // that stored one can send it straight back as ?url=. An unpublished post
    // has no page on disk yet, so this URL 404s for visitors until it goes
    // live; that is the point at which it starts resolving, not a broken link.
    if ($siteUrl !== '') {
        $props['url'] = [$siteUrl . '/' . $post->addressablePath($cfgTz) . '/'];
    }

    return $props;
}

/**
 * Apply the optional `properties[]` query filter to a source representation.
 *
 * Returns null when the caller sent no filter, so a caller can tell "no filter"
 * from "filter matched nothing".
 */
function mp_filter_properties(array $all, mixed $requested): ?array
{
    if (!is_array($requested) || $requested === []) {
        return null;
    }

    $filtered = [];
    foreach ($requested as $prop) {
        if (is_string($prop) && isset($all[$prop])) {
            $filtered[$prop] = $all[$prop];
        }
    }
    return $filtered;
}

// ── GET: configuration queries ──────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD') {
    $authz = \CMS\MicropubAuth::authenticate($db, $config);

    // A token issued for sign-in alone carries only 'profile', and every q=
    // response below discloses authoring data: q=source returns the full body
    // of any post including drafts and scheduled ones, q=config names the
    // syndication accounts, q=category lists the whole taxonomy. Require a
    // publishing scope so a third-party site the owner merely signed in to
    // cannot read unpublished work.
    \CMS\MicropubAuth::requireScope($authz, 'create', 'update', 'delete');

    $q       = $_GET['q'] ?? '';
    $siteUrl = rtrim($db->getSetting('site_url', ''), '/');

    if ($q === 'config') {
        mp_json([
            'media-endpoint' => $siteUrl . '/media.php',
            'syndicate-to'   => mp_syndication_targets($db),
            'post-types'     => mp_post_types(),
            'q'              => ['config', 'source', 'syndicate-to', 'category'],
        ]);
    }

    if ($q === 'syndicate-to') {
        mp_json(['syndicate-to' => mp_syndication_targets($db)]);
    }

    if ($q === 'source') {
        $targetUrl = $_GET['url'] ?? '';
        $cfgTz     = $db->getSetting('timezone', '');
        $requested = $_GET['properties'] ?? null;

        // ── Post List extension: no url means "list my posts" ───────────────
        // https://indieweb.org/Micropub-extensions#Query_for_Post_List
        if (!is_string($targetUrl) || $targetUrl === '') {
            $postType   = $_GET['post-type']   ?? null;
            $postStatus = $_GET['post-status'] ?? null;

            // A typo answered with the whole archive looks like a working
            // filter that matched everything, so reject unknown values.
            if ($postType !== null && !in_array($postType, \CMS\Post::MICROPUB_TYPES, true)) {
                mp_error('invalid_request', 'unsupported post-type: ' . (is_string($postType) ? $postType : ''));
            }
            if ($postStatus !== null && !in_array($postStatus, \CMS\Post::MICROPUB_STATUSES, true)) {
                mp_error('invalid_request', 'unsupported post-status: ' . (is_string($postStatus) ? $postStatus : ''));
            }

            // limit/offset fall back rather than error — they are hints about
            // page size, and a client sending nonsense still wants its first
            // page. Validate before casting: (int) 'abc' is 0, which would
            // clamp to a one-post page rather than the default one.
            $limit  = filter_var($_GET['limit']  ?? null, FILTER_VALIDATE_INT);
            $offset = filter_var($_GET['offset'] ?? null, FILTER_VALIDATE_INT);
            $limit  = $limit  === false ? MP_LIST_DEFAULT_LIMIT : max(1, min(MP_LIST_MAX_LIMIT, $limit));
            $offset = $offset === false ? 0 : max(0, $offset);

            $posts = \CMS\Post::findForMicropub($db, $postType, $postStatus, $limit, $offset);

            $items = [];
            foreach ($posts as $listPost) {
                $props = mp_post_source_properties($listPost, $cfgTz, $siteUrl);
                // Unlike the single-post response below, list items keep their
                // type wrapper even when filtered: a bare property bag is still
                // readable on its own, but a list of them is not parseable mf2.
                $items[] = [
                    'type'       => ['h-entry'],
                    'properties' => mp_filter_properties($props, $requested) ?? $props,
                ];
            }

            mp_json(['items' => $items]);
        }

        $post = mp_resolve_post_by_url($db, $targetUrl);
        if (!$post || $post->deleted_at !== null) {
            mp_error('invalid_request', 'post not found for url', 404);
        }

        $all = mp_post_source_properties($post, $cfgTz, $siteUrl);

        // Optional properties[] filter — when present, omit the type wrapper.
        $filtered = mp_filter_properties($all, $requested);
        if ($filtered !== null) {
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

// Now — and only now, with a live token behind us — take the same turn at the
// scheduler the admin UI does. Deliberately on the write branch only: a q=
// query is a read, and should not be the request that pays for a build. A
// no-op while cron keeps the heartbeat fresh.
(new \CMS\Scheduler($db, $builder, $syndication, $activityLog))->tick();

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
    $mpBareFile = (empty($_POST['action']) && empty($_POST['h']))
        ? \CMS\MicropubAuth::firstUploadedFile($_FILES['file'] ?? null)
        : null;

    if ($mpBareFile !== null) {
        $f = $mpBareFile;
        if ($f['error'] !== UPLOAD_ERR_OK) {
            mp_error('invalid_request', 'file upload error');
        }
        \CMS\MicropubAuth::requireScope($mpAuthz, 'media', 'create');
        try {
            $mediaService = new \CMS\Media($db, $config['paths']['content'] . '/media', (int) ($config['media']['max_bytes'] ?? 52_428_800));
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

    // The copies go with it. A later undelete restores the post here but
    // cannot bring them back — the statuses are gone from the networks, and
    // the post returns unsyndicated.
    $syndication->remove($post);

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

    $oldDir = $wasPublished ? $builder->postOutputDir($post->published_at, $post->slug) : null;

    // Old-position neighbors, snapshotted before mutation: if the post moves in
    // the timeline (published date or slug change) their prev/next links must be
    // rebuilt too, alongside the new-position neighbors.
    $oldPrev = $wasPublished ? \CMS\Post::findPrev($db, $post) : null;
    $oldNext = $wasPublished ? \CMS\Post::findNext($db, $post) : null;

    // Terms as they stand now, for the same reason. saveTerms() refreshes
    // $post->categories/->tags in memory, so once the update has run there is no
    // way back to the set the post is being moved *out* of — and buildPost()
    // only ever rebuilds the archives of the terms it holds at the time. Without
    // this, recategorising over Micropub left the vacated archive showing the
    // post's card until the next full rebuild.
    $oldCategoryIds = array_map('intval', array_column($post->categories, 'id'));
    $oldTagIds      = array_map('intval', array_column($post->tags, 'id'));

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

            // No touched flag: this lives on the post row, which
            // $post->save() writes. An empty value list clears it.
            case 'featured':
                $featured = mp_parse_photo_values($vals, $updSiteUrl)[0] ?? null;
                $post->featured_image_url = $featured['url'] ?? null;
                $post->featured_image_alt = (string) ($featured['alt'] ?? '');
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
        } elseif ($prop === 'featured') {
            // A post has one featured image, so there is nothing to append to.
            // Adding one where none is set is the useful reading; adding one
            // over an existing one would silently discard whichever lost.
            $featured = mp_parse_photo_values($vals, $updSiteUrl)[0] ?? null;
            if ($featured !== null && ($post->featured_image_url ?? '') === '') {
                $post->featured_image_url = $featured['url'];
                $post->featured_image_alt = (string) $featured['alt'];
            }
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
        } elseif ($prop === 'featured') {
            // delete: [featured]        (empty $vals) → clear it
            // delete: {featured: [url]}               → clear it if it matches
            if (empty($vals) || in_array((string) $post->featured_image_url, $normalizePhotoUrls($vals), true)) {
                $post->featured_image_url = null;
                $post->featured_image_alt = '';
            }
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
    $builder->removeVacatedPostOutput($oldDir, $post);

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

        // buildPost() above covered the terms the post holds now; these are the
        // ones it was just taken out of, which still carry its card.
        $nowCategoryIds = array_map('intval', array_column($post->categories, 'id'));
        $nowTagIds      = array_map('intval', array_column($post->tags, 'id'));
        foreach (array_diff($oldCategoryIds, $nowCategoryIds) as $catId) {
            $builder->buildCategoryArchive((int) $catId);
        }
        foreach (array_diff($oldTagIds, $nowTagIds) as $tagId) {
            $builder->buildTagArchive((int) $tagId);
        }

        $builder->rebuildSharedResources();
    }

    // The syndicated copies follow the post: taken down when the update pulls
    // it off the public site, brought into line with it when it stays.
    if ($wasPublished && $post->status !== 'published') {
        $syndication->remove($post);
    } elseif ($post->status === 'published') {
        $syndication->update($post);
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
    $mediaService = new \CMS\Media($db, $config['paths']['content'] . '/media', (int) ($config['media']['max_bytes'] ?? 52_428_800));
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

// ── Featured image (the mf2 `featured` property) ─────────────────────────────
// One picture, parsed the same way a photo is — it accepts both a bare URL and
// an {value, alt} object, and applies the scheme allowlist an href needs.
// Post::save() drops it for a note, so no kind check is needed here.

$featuredRow = isset($properties['featured']) && is_array($properties['featured'])
    ? (mp_parse_photo_values($properties['featured'], $mpSiteUrl)[0] ?? null)
    : null;

// ── Contexts: reply/like/repost/bookmark targets ────────────────────────────

$contextRows = mp_parse_context_values($properties);

if ($content === '' && $photoRows === [] && $contextRows === []) {
    mp_error('invalid_request', 'content, photo, or a context property (in-reply-to, like-of, repost-of, bookmark-of) is required');
}

// An absent name property is the note/article distinction in Micropub, so every
// titleless post is a note: no derived title, no h1. A titleless post carrying
// photos is a photo post — the image is the point, so it gets its own kind. A
// titled post keeps its photos as illustration and stays standard.
$isAside = $title === '';
$postKind = $isAside
    ? ($photoRows !== [] ? 'photo' : 'aside')
    : 'standard';

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
$post->post_kind    = $postKind;
$post->title        = $title;
$post->slug         = $slug;
$post->content      = $content;
$post->excerpt      = $summary !== '' ? $summary : null;
$post->status       = $status;
$post->published_at = $publishedAt;
if ($featuredRow !== null) {
    $post->featured_image_url = $featuredRow['url'];
    $post->featured_image_alt = (string) $featuredRow['alt'];
}

// mp-syndicate-to: when the property is present, syndicate only to the listed
// target uids (empty list = none). Absent = default auto-syndication.
if (array_key_exists('mp-syndicate-to', $properties)) {
    $requested = array_map('strval', $properties['mp-syndicate-to']);
    $post->mastodon_skip = in_array('mastodon', $requested, true) ? 0 : 1;
    $post->bluesky_skip  = in_array('bluesky', $requested, true) ? 0 : 1;
    $post->pixelfed_skip = in_array('pixelfed', $requested, true) ? 0 : 1;
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

// ── Syndicate to Mastodon / Bluesky / Pixelfed on first publish ─────────────

// Deliberately after the build: the networks fetch the permalink to make a
// preview card, so a copy created before the page is on disk links to a 404.
// The post page shows the copies it made, though, so a syndication that
// recorded a URL leaves the page just written a version behind — rebuild it.
// Only post.php renders these URLs; the feeds and index don't, so nothing else
// needs the second pass.
if ($status === 'published') {
    $syndicationBefore = [$post->mastodon_url, $post->bluesky_url, $post->pixelfed_url];
    $syndication->publish($post);

    if ([$post->mastodon_url, $post->bluesky_url, $post->pixelfed_url] !== $syndicationBefore) {
        $builder->buildPost($post);
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

$siteUrl = rtrim($db->getSetting('site_url', ''), '/');
$cfgTz   = $db->getSetting('timezone', '');

// The Location must be a URL this endpoint can resolve again, because clients
// store it and send it straight back as the `url` of a later update or delete.
// A draft created without a `published` property has no date yet, so it has no
// date-based permalink — fall back to the bare slug. Slugs are unique and
// mp_resolve_post_by_url() matches on the final path segment, so both forms
// address the same post; once the draft is published, the update response
// returns 201 with the real permalink and the client re-points itself.
//
// Deliberately NOT /admin/post-edit.php?id=N, which this endpoint cannot
// resolve — clients stored it and then failed every update with a 404.
$location = ($siteUrl !== '' ? $siteUrl : '') . '/' . $post->addressablePath($cfgTz) . '/';

// Spec: 202 Accepted when the post will be processed later (scheduled).
http_response_code($status === 'scheduled' ? 202 : 201);
header('Location: ' . $location);
exit;
