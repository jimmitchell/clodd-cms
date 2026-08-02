<?php

declare(strict_types=1);

/**
 * REST API endpoint — single-file router.
 *
 * Base URL:  /admin/api/{resource}/{id}
 *
 * Resources:
 *   GET    /admin/api/posts              list posts (?status=draft|published|…)
 *   GET    /admin/api/posts/{id}         get post
 *   POST   /admin/api/posts              create post
 *   PUT    /admin/api/posts/{id}         update post
 *   DELETE /admin/api/posts/{id}         delete post
 *
 *   GET    /admin/api/pages              list pages (?status=…)
 *   GET    /admin/api/pages/{id}         get page
 *   POST   /admin/api/pages              create page
 *   PUT    /admin/api/pages/{id}         update page
 *   DELETE /admin/api/pages/{id}         delete page
 *
 *   GET    /admin/api/media              list media
 *   POST   /admin/api/media              upload file (multipart/form-data, field: "file")
 *   DELETE /admin/api/media/{id}         delete media
 *
 *   GET    /admin/api/categories         list categories
 *   GET    /admin/api/tags               list tags
 *   GET    /admin/api/settings           site settings (read-only)
 *
 * Auth: HTTP Basic — same username/password as the admin panel.
 * Rate-limiting uses the login_attempts table under its own 'api' scope, so a
 * broken API client cannot lock the owner out of the admin login.
 */

// Read the request body BEFORE any require that might start a session,
// which can consume the input stream on some PHP-FPM configurations.
$_apiRawBody = (string) file_get_contents('php://input');

define('CMS_ROOT', dirname(__DIR__));
require CMS_ROOT . '/vendor/autoload.php';

$config  = require CMS_ROOT . '/config.php';
$db      = new \CMS\Database($config['paths']['data'] . '/cms.db');
$builder = new \CMS\Builder($config, $db);

$syndication = new \CMS\Syndication($db, $config);

// Promote any due scheduled posts (same as bootstrap.php does for the UI).
\CMS\Post::promoteScheduled($db);

// ── CORS ────────────────────────────────────────────────────────────────────
// Restrict to the site's own origin. Native clients (iOS, Xcode simulator)
// do not send an Origin header and are unaffected by CORS policy.
// If site_url is not yet configured, fall back to wildcard so the API
// remains usable during initial setup.
$_corsSiteUrl = $db->getSetting('site_url', '');
$_allowedOrigin = '';
if ($_corsSiteUrl !== '') {
    $_parsed = parse_url($_corsSiteUrl);
    if ($_parsed !== false && isset($_parsed['scheme'], $_parsed['host'])) {
        $_allowedOrigin = $_parsed['scheme'] . '://' . $_parsed['host'];
        if (isset($_parsed['port'])) {
            $_allowedOrigin .= ':' . $_parsed['port'];
        }
    }
}
$_requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($_allowedOrigin !== '' && $_requestOrigin === $_allowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $_allowedOrigin);
    header('Vary: Origin');
} elseif ($_allowedOrigin === '') {
    // site_url not configured yet — allow all origins during setup.
    header('Access-Control-Allow-Origin: *');
}
// If origin is configured but doesn't match, send no ACAO header (browser will block).
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Response helpers ────────────────────────────────────────────────────────

function api_json(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function api_error(string $message, int $status = 400): never
{
    api_json(['error' => $message], $status);
}

// ── Serializers ─────────────────────────────────────────────────────────────

function post_to_array(\CMS\Post $post, string $siteUrl, string $timezone = ''): array
{
    $url = null;
    if ($post->status === 'published' && $post->published_at !== null) {
        $url = rtrim($siteUrl, '/') . '/' . \CMS\Post::datePath($post->published_at, $post->slug, $timezone) . '/';
    }

    return [
        'id'           => $post->id,
        'title'        => $post->title,
        'slug'         => $post->slug,
        'content'      => $post->content,
        'excerpt'      => $post->excerpt,
        'status'       => $post->status,
        'published_at' => $post->published_at,
        'created_at'   => $post->created_at,
        'updated_at'   => $post->updated_at,
        'categories'   => $post->categories,
        'tags'         => $post->tags,
        'url'          => $url,
    ];
}

function page_to_array(\CMS\Page $page): array
{
    return [
        'id'         => $page->id,
        'title'      => $page->title,
        'slug'       => $page->slug,
        'content'    => $page->content,
        'nav_order'  => $page->nav_order,
        'status'     => $page->status,
        'created_at' => $page->created_at,
        'updated_at' => $page->updated_at,
    ];
}

// ── Basic Auth ───────────────────────────────────────────────────────────────
// Verifies credentials against config.php and applies the same lockout rules as
// the admin login form, counted separately under the 'api' scope.

function api_authenticate(array $config, \CMS\Database $db): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Scoped to 'api' so a broken API client cannot lock the owner out of the
    // admin login from the same IP.
    if (\CMS\Auth::isLockedOutIn($db, $config, $ip, \CMS\Auth::SCOPE_API)) {
        api_error('Too many failed attempts. Try again later.', 429);
    }

    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW']   ?? '';

    $expectedUser = $config['admin']['username']      ?? '';
    $hash         = $config['admin']['password_hash'] ?? '';

    $ok = hash_equals($expectedUser, $user)
        && $hash !== ''
        && password_verify($pass, $hash);

    if (!$ok) {
        \CMS\Auth::recordFailureIn($db, $ip, \CMS\Auth::SCOPE_API);
        header('WWW-Authenticate: Basic realm="CMS API"');
        api_error('Unauthorized', 401);
    }
}

api_authenticate($config, $db);

// ── Parse request ────────────────────────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'];

// Strip the /admin/api prefix then split into segments.
$uri      = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri      = (string) preg_replace('#^/admin/api/?#', '', $uri);
$uri      = trim($uri, '/');
$parts    = ($uri !== '') ? explode('/', $uri) : [];

$resource = $parts[0] ?? '';
$id       = (isset($parts[1]) && ctype_digit($parts[1])) ? (int) $parts[1] : null;

// Parse a JSON body for write operations.
$body = [];
if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && $_apiRawBody !== '') {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($ct, 'application/json')) {
        $body = json_decode($_apiRawBody, true) ?? [];
    }
}

// Site URL and timezone (used in post URL generation).
$siteUrl  = $db->getSetting('site_url', '');
$timezone = $db->getSetting('timezone', '');

// ── Routes ───────────────────────────────────────────────────────────────────

// Posts — list
if ($resource === 'posts' && $method === 'GET' && $id === null) {
    $status = $_GET['status'] ?? null;
    $posts  = \CMS\Post::findAll($db, $status ?: null);
    api_json(array_map(fn($p) => post_to_array($p, $siteUrl, $timezone), $posts));
}

// Posts — get one
if ($resource === 'posts' && $method === 'GET' && $id !== null) {
    $post = \CMS\Post::findById($db, $id);
    if (!$post) {
        api_error('Post not found', 404);
    }
    api_json(post_to_array($post, $siteUrl, $timezone));
}

// Posts — create
if ($resource === 'posts' && $method === 'POST' && $id === null) {
    $title = trim($body['title'] ?? '');
    if ($title === '') {
        api_error('title is required');
    }

    $post          = new \CMS\Post($db);
    $post->title   = $title;
    $post->slug    = \CMS\Helpers::slugify($body['slug'] ?? $title);
    $post->content = $body['content'] ?? '';
    $post->excerpt = (($body['excerpt'] ?? '') !== '') ? $body['excerpt'] : null;
    $post->status  = in_array($body['status'] ?? '', ['draft', 'published', 'scheduled'], true)
                   ? $body['status'] : 'draft';

    if (isset($body['published_at'])) {
        $post->published_at = $body['published_at'];
    } elseif ($post->status === 'published') {
        $post->published_at = date('Y-m-d H:i:s');
    }

    if (!$post->save()) {
        api_error('Failed to save post', 500);
    }

    $catIds = array_map('intval', $body['category_ids'] ?? []);
    $tagIds = array_map('intval', $body['tag_ids']      ?? []);
    $post->saveTerms($catIds, $tagIds);

    if ($post->status === 'published') {
        // New post: $post->categories is empty so buildPost() won't rebuild any term archives.
        $builder->buildPost($post);
        $prev = \CMS\Post::findPrev($db, $post);
        if ($prev) $builder->buildPost($prev);
        $next = \CMS\Post::findNext($db, $post);
        if ($next) $builder->buildPost($next);
        $builder->rebuildSharedResources();
        foreach ($catIds as $catId) {
            $builder->buildCategoryArchive($catId);
        }
        foreach ($tagIds as $tagId) {
            $builder->buildTagArchive($tagId);
        }
    }

    api_json(post_to_array($post, $siteUrl, $timezone), 201);
}

// Posts — update
if ($resource === 'posts' && $method === 'PUT' && $id !== null) {
    $post = \CMS\Post::findById($db, $id);
    if (!$post) {
        api_error('Post not found', 404);
    }

    $wasPublished = $post->status === 'published';
    $oldCatIds    = array_column($post->categories, 'id');
    $oldTagIds    = array_column($post->tags, 'id');
    // Captured before the body is applied, so a slug or date change can clean up
    // the output directory the post moves away from.
    $oldDir       = $wasPublished ? $builder->postOutputDir($post->published_at, $post->slug) : null;

    if (isset($body['title']))   $post->title   = trim($body['title']);
    if (isset($body['slug']))    $post->slug    = \CMS\Helpers::slugify($body['slug']);
    if (isset($body['content'])) $post->content = $body['content'];
    if (array_key_exists('excerpt', $body)) {
        $post->excerpt = ($body['excerpt'] !== '') ? $body['excerpt'] : null;
    }
    if (isset($body['status']) && in_array($body['status'], ['draft', 'published', 'scheduled', 'unpublished'], true)) {
        $post->status = $body['status'];
    }
    if (isset($body['published_at'])) {
        $post->published_at = $body['published_at'];
    } elseif ($post->status === 'published' && $post->published_at === null) {
        $post->published_at = date('Y-m-d H:i:s');
    }

    if (!$post->save()) {
        api_error('Failed to save post', 500);
    }

    $newCatIds = $oldCatIds;
    $newTagIds = $oldTagIds;
    if (isset($body['category_ids']) || isset($body['tag_ids'])) {
        $newCatIds = array_map('intval', $body['category_ids'] ?? $oldCatIds);
        $newTagIds = array_map('intval', $body['tag_ids']      ?? $oldTagIds);
        $post->saveTerms($newCatIds, $newTagIds);
    }

    // buildPost() rebuilds archives for old terms ($post->categories); only explicitly
    // rebuild archives for terms that were newly added.
    $builder->removeVacatedPostOutput($oldDir, $post);
    $builder->buildPost($post);
    if ($post->status === 'published' || $wasPublished) {
        $prev = \CMS\Post::findPrev($db, $post);
        if ($prev) $builder->buildPost($prev);
        $next = \CMS\Post::findNext($db, $post);
        if ($next) $builder->buildPost($next);
        $builder->rebuildSharedResources();
        foreach (array_diff($newCatIds, $oldCatIds) as $catId) {
            $builder->buildCategoryArchive($catId);
        }
        foreach (array_diff($newTagIds, $oldTagIds) as $tagId) {
            $builder->buildTagArchive($tagId);
        }
    }

    // The syndicated copies follow the post: taken down when it leaves the
    // public site, brought into line with it when it stays. This endpoint never
    // creates copies, so an API-authored post that was never syndicated has
    // nothing here to keep in step.
    if ($wasPublished && $post->status !== 'published') {
        $syndication->remove($post);
    } elseif ($post->status === 'published') {
        $syndication->update($post);
    }

    api_json(post_to_array($post, $siteUrl, $timezone));
}

// Posts — delete
if ($resource === 'posts' && $method === 'DELETE' && $id !== null) {
    $post = \CMS\Post::findById($db, $id);
    if (!$post) {
        api_error('Post not found', 404);
    }

    $wasPublished = $post->status === 'published';
    $prevNeighbor = $wasPublished ? \CMS\Post::findPrev($db, $post) : null;
    $nextNeighbor = $wasPublished ? \CMS\Post::findNext($db, $post) : null;
    // Before the row goes: the ids of the syndicated copies live on it.
    $syndication->remove($post);
    // buildPost() removal path also rebuilds taxonomy archives for $post->categories.
    $post->status = 'draft';
    $builder->buildPost($post);
    $post->delete();
    if ($wasPublished) {
        if ($prevNeighbor) $builder->buildPost($prevNeighbor);
        if ($nextNeighbor) $builder->buildPost($nextNeighbor);
        $builder->rebuildSharedResources();
    }

    api_json(['deleted' => true]);
}

// Pages — list
if ($resource === 'pages' && $method === 'GET' && $id === null) {
    $status = $_GET['status'] ?? null;
    $pages  = \CMS\Page::findAll($db, $status ?: null);
    api_json(array_map('page_to_array', $pages));
}

// Pages — get one
if ($resource === 'pages' && $method === 'GET' && $id !== null) {
    $page = \CMS\Page::findById($db, $id);
    if (!$page) {
        api_error('Page not found', 404);
    }
    api_json(page_to_array($page));
}

// Pages — create
if ($resource === 'pages' && $method === 'POST' && $id === null) {
    $title = trim($body['title'] ?? '');
    if ($title === '') {
        api_error('title is required');
    }

    $page            = new \CMS\Page($db);
    $page->title     = $title;
    $page->slug      = \CMS\Helpers::slugify($body['slug'] ?? $title);
    $page->content   = $body['content'] ?? '';
    $page->nav_order = (int) ($body['nav_order'] ?? 0);
    $page->status    = in_array($body['status'] ?? '', ['draft', 'published'], true)
                     ? $body['status'] : 'draft';

    if (!$page->save()) {
        api_error('Failed to save page', 500);
    }

    if ($page->status === 'published') {
        $builder->buildPage($page);
    }

    api_json(page_to_array($page), 201);
}

// Pages — update
if ($resource === 'pages' && $method === 'PUT' && $id !== null) {
    $page = \CMS\Page::findById($db, $id);
    if (!$page) {
        api_error('Page not found', 404);
    }

    if (isset($body['title']))     $page->title     = trim($body['title']);
    if (isset($body['slug']))      $page->slug      = \CMS\Helpers::slugify($body['slug']);
    if (isset($body['content']))   $page->content   = $body['content'];
    if (isset($body['nav_order'])) $page->nav_order = (int) $body['nav_order'];
    if (isset($body['status']) && in_array($body['status'], ['draft', 'published'], true)) {
        $page->status = $body['status'];
    }

    if (!$page->save()) {
        api_error('Failed to save page', 500);
    }

    $builder->buildPage($page);

    api_json(page_to_array($page));
}

// Pages — delete
if ($resource === 'pages' && $method === 'DELETE' && $id !== null) {
    $page = \CMS\Page::findById($db, $id);
    if (!$page) {
        api_error('Page not found', 404);
    }

    $page->status = 'draft'; // Signals Builder to remove the static file.
    $builder->buildPage($page);
    $page->delete();

    api_json(['deleted' => true]);
}

// Media — list
if ($resource === 'media' && $method === 'GET' && $id === null) {
    $media  = new \CMS\Media($db, $config['paths']['content'] . '/media');
    $result = array_map(fn($row) => [
        'id'            => (int) $row['id'],
        'filename'      => $row['filename'],
        'original_name' => $row['original_name'],
        'mime_type'     => $row['mime_type'],
        'size'          => (int) $row['size'],
        'url'           => '/media/' . rawurlencode($row['filename']),
        'uploaded_at'   => $row['uploaded_at'],
    ], $media->all());

    api_json($result);
}

// Media — upload (multipart/form-data, field name: "file")
if ($resource === 'media' && $method === 'POST' && $id === null) {
    if (empty($_FILES['file'])) {
        api_error('No file received. Send multipart/form-data with field name "file".');
    }

    $media = new \CMS\Media($db, $config['paths']['content'] . '/media');
    try {
        $result = $media->upload($_FILES['file']);
        api_json($result, 201);
    } catch (\RuntimeException $e) {
        api_error($e->getMessage(), 422);
    }
}

// Media — delete
if ($resource === 'media' && $method === 'DELETE' && $id !== null) {
    $media = new \CMS\Media($db, $config['paths']['content'] . '/media');
    if (!$media->delete($id)) {
        api_error('Media not found', 404);
    }
    api_json(['deleted' => true]);
}

// Categories — list
if ($resource === 'categories' && $method === 'GET') {
    $rows = $db->select("SELECT id, name, slug, description FROM categories ORDER BY name");
    api_json($rows);
}

// Tags — list
if ($resource === 'tags' && $method === 'GET') {
    $rows = $db->select("SELECT id, name, slug FROM tags ORDER BY name");
    api_json($rows);
}

// Settings — read-only snapshot (excludes sensitive keys)
if ($resource === 'settings' && $method === 'GET') {
    $rows     = $db->select("SELECT key, value FROM settings");
    $settings = array_column($rows, 'value', 'key');
    foreach (['password_hash', 'mastodon_token', 'bluesky_app_password', 'totp_secret'] as $k) {
        unset($settings[$k]);
    }
    api_json($settings);
}

// No route matched.
api_error('Not found', 404);
