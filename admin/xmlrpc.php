<?php

declare(strict_types=1);

/**
 * WordPress + MetaWeblog XML-RPC API endpoint.
 *
 * MarsEdit setup (WordPress mode — recommended, enables page management):
 *   API type    : WordPress
 *   Endpoint URL: https://example.com/admin/xmlrpc.php
 *   Username    : admin username from config.php
 *   Password    : admin plaintext password
 *
 * MarsEdit setup (MetaWeblog mode — posts only, no pages):
 *   API type    : MetaWeblog
 *   Endpoint URL: https://example.com/admin/xmlrpc.php
 *
 * Supported methods:
 *   WordPress API  : wp.getUsersBlogs, wp.getOptions, wp.getAuthors,
 *                    wp.getPostFormats, wp.getTaxonomies, wp.getTerms,
 *                    wp.getPosts, wp.getPost, wp.newPost, wp.editPost, wp.deletePost,
 *                    wp.getPages, wp.getPageList, wp.getPageStatusList, wp.getPage, wp.newPage, wp.editPage, wp.deletePage,
 *                    wp.getMediaLibrary, wp.uploadFile
 *   MetaWeblog API : blogger.getUsersBlogs, metaWeblog.getRecentPosts,
 *                    metaWeblog.getPost, metaWeblog.newPost, metaWeblog.editPost,
 *                    metaWeblog.deletePost, metaWeblog.getCategories,
 *                    metaWeblog.newMediaObject
 */

// PHP errors must never appear in an XML-RPC response — they corrupt the XML.
// Errors are still logged server-side; they just won't break the response body.
ini_set('display_errors', '0');

// Read the raw request body BEFORE bootstrap.php starts a session or does
// anything that could consume php://input on some PHP-FPM configurations.
$_xmlrpcBody = file_get_contents('php://input');

require __DIR__ . '/bootstrap.php';
// Note: $auth->check() is intentionally NOT called.
// XML-RPC authenticates per-request via the username/password params.

use CMS\Bluesky;
use CMS\Helpers;
use CMS\Mastodon;
use CMS\Page;
use CMS\Post;
use CMS\SyndicationMedia;
use CMS\XmlRpc;

// ── ID space ──────────────────────────────────────────────────────────────────
// Posts and pages have separate auto-increment sequences, so IDs can collide.
// The unified wp.*Post methods use large offsets for page and media IDs so MarsEdit
// can tell them apart from post IDs without ambiguity.
// Offsets must exceed any realistic post/media count.
define('PAGE_ID_OFFSET',  1_000_000);
define('MEDIA_ID_OFFSET', 2_000_000);

// ── Debug logging ─────────────────────────────────────────────────────────────
// To enable: touch data/xmlrpc.log  (file must exist; logging stops when deleted)
// To view:   tail -f data/xmlrpc.log
// To disable: rm data/xmlrpc.log

function xmlrpc_debug(string $msg): void
{
    $logPath = dirname(__DIR__) . '/data/xmlrpc.log';
    if (file_exists($logPath)) {
        file_put_contents($logPath, date('H:i:s.') . substr((string) microtime(), 2, 3) . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
    }
}

// ── Output setup ──────────────────────────────────────────────────────────────

header('Content-Type: text/xml; charset=utf-8');

function xmlrpc_fault(int $code, string $msg): never
{
    echo XmlRpc::encodeFault($code, $msg);
    exit;
}

// Guard: simplexml is in the php8.3-xml package (separate from php8.3-fpm).
if (!function_exists('simplexml_load_string')) {
    xmlrpc_fault(500, 'Server error: simplexml extension not installed (apt install php8.3-xml).');
}

// ── Only accept POST ──────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    xmlrpc_fault(405, 'Method Not Allowed');
}

// ── Parse request ─────────────────────────────────────────────────────────────

$body = $_xmlrpcBody;
unset($_xmlrpcBody);
try {
    $req = XmlRpc::parseRequest($body ?: '');
} catch (\Throwable $e) {
    xmlrpc_fault(400, 'Bad Request: ' . $e->getMessage());
}

$method = $req['method'];
$params = $req['params'];

xmlrpc_debug("→ $method (" . count($params) . " params)");

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Authenticate username + password from XML-RPC params.
 * Records the attempt in login_attempts under the 'xmlrpc' scope for rate-limit
 * purposes, kept separate from the admin login's counter.
 */
function xmlrpc_auth(array $params, int $userIdx, int $passIdx): void
{
    global $db, $auth, $config;

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Scoped to 'xmlrpc' so a misconfigured MarsEdit account cannot lock the
    // owner out of the admin login from the same IP.
    if ($auth->isLockedOut($ip, \CMS\Auth::SCOPE_XMLRPC)) {
        xmlrpc_fault(429, 'Too many failed login attempts. Try again later.');
    }

    $username = (string) ($params[$userIdx] ?? '');
    $password = (string) ($params[$passIdx] ?? '');

    $cfgUser = $config['admin']['username'] ?? '';
    $cfgHash = $config['admin']['password_hash'] ?? '';

    $ok = hash_equals($cfgUser, $username) && password_verify($password, $cfgHash);

    if (!$ok) {
        $db->insert('login_attempts', [
            'ip'           => $ip,
            'scope'        => \CMS\Auth::SCOPE_XMLRPC,
            'success'      => 0,
            'attempted_at' => date('Y-m-d H:i:s'),
        ]);
        xmlrpc_fault(403, 'Forbidden: incorrect username or password.');
    }
}

/**
 * Build the struct array that MarsEdit expects for a single post.
 */
function postToStruct(Post $post, string $siteUrl, string $timezone = ''): array
{
    $pubAt  = $post->published_at ?? $post->created_at;
    $url    = rtrim($siteUrl, '/') . '/' . Post::datePath($pubAt, $post->slug, $timezone) . '/';

    return [
        'postid'         => (string) $post->id,
        'userid'         => '1',
        'title'          => $post->title,
        'description'    => $post->content,
        'mt_excerpt'     => $post->excerpt ?? '',
        'wp_slug'        => $post->slug,
        'dateCreated'    => new \CMS\DateTimeValue(XmlRpc::isoDate($pubAt)),
        'link'           => $url,
        'permaLink'      => $url,
        'categories'     => array_column($post->categories, 'name'),
        'post_status'    => $post->status,
        'wp_post_format' => xmlrpc_format_from_post($post),
    ];
}

/**
 * WordPress post formats this endpoint speaks, as advertised by
 * wp.getPostFormats. WordPress's vocabulary is closed, so these are its names,
 * not ours: `photo` travels as `image` or `gallery` depending on how many
 * pictures it carries, and a post bookmarking something travels as `link`.
 */
const XMLRPC_POST_FORMATS = [
    'standard' => 'Standard',
    'aside'    => 'Aside',
    'image'    => 'Image',
    'gallery'  => 'Gallery',
    'link'     => 'Link',
];

/**
 * Count the pictures in a post. Photos attached as u-photo rows (how Micropub
 * and the admin editor store them) and images written inline in the body (how
 * MarsEdit stores them, via metaWeblog.newMediaObject) both count, so a gallery
 * reads as a gallery whichever client built it.
 */
function xmlrpc_image_count(Post $post): int
{
    return count($post->photos) + preg_match_all('/<img\b/i', $post->content);
}

/**
 * Translate a post to its WordPress post format name for output.
 *
 * `link` wins over everything: a post carrying a bookmark-of context is a link
 * post in WordPress's vocabulary regardless of what else it holds, and it is
 * the only format that round-trips a context. Reply, like and repost contexts
 * have no WordPress equivalent, so those posts travel as their bare kind.
 */
function xmlrpc_format_from_post(Post $post): string
{
    foreach ($post->contexts as $context) {
        if (($context['kind'] ?? '') === 'bookmark-of') {
            return 'link';
        }
    }

    if ($post->post_kind === 'photo') {
        return xmlrpc_image_count($post) > 1 ? 'gallery' : 'image';
    }

    return $post->post_kind;
}

/**
 * The first http(s) URL in a body, as a link post's bookmark target. WordPress
 * derives a link post's URL the same way — from the content rather than a field
 * of its own — because no MetaWeblog or WordPress struct carries one.
 *
 * Handles HTML anchors, Markdown links and autolinks, and finally a bare URL;
 * MarsEdit may send any of them depending on how its editor is configured. In
 * every pattern the URL is the last capture group.
 */
function xmlrpc_first_link_url(string $content): string
{
    $patterns = [
        '/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/i',          // <a href="…">
        '/\[[^\]]*\]\(\s*<?([^)\s>]+)>?/',                  // [text](…)
        '/<(https?:\/\/[^>\s]+)>/i',                        // <https://…>
        '/(?<![\w"\'(\[])(https?:\/\/[^\s<>"\')\]]+)/i',    // bare https://…
    ];

    foreach ($patterns as $pattern) {
        if (!preg_match($pattern, $content, $m)) {
            continue;
        }
        $url    = html_entity_decode(trim((string) end($m)), ENT_QUOTES, 'UTF-8');
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (in_array($scheme, ['http', 'https'], true)) {
            return $url;
        }
    }

    return '';
}

/**
 * Pick the post_kind from an incoming struct (MetaWeblog or WordPress).
 * Accepts the WordPress format names plus our internal `photo`, and treats
 * `status` as an alias for `aside` (both are WordPress's titleless note).
 *
 * Returns null when the struct carries no format key at all — the caller keeps
 * the post's existing kind rather than demoting it, so a client that edits a
 * post without touching its format can't silently reshape it. This mirrors
 * Micropub update, which likewise never re-derives the kind.
 */
function xmlrpc_kind_from_struct(array $struct): ?string
{
    $raw = $struct['wp_post_format'] ?? $struct['post_format'] ?? null;
    if ($raw === null) {
        return null;
    }

    $format = strtolower(trim((string) $raw));

    // A link post has no kind of its own — the bookmark lives in a context, so
    // the post is shaped by its title like any other: titled → standard,
    // titleless → aside. (Every client that sends a format sends a title key
    // alongside it, even when empty.)
    if ($format === 'link') {
        $title = trim((string) ($struct['title'] ?? $struct['post_title'] ?? ''));
        return $title !== '' ? 'standard' : 'aside';
    }

    return match ($format) {
        'aside', 'status'           => 'aside',
        'image', 'photo', 'gallery' => 'photo',
        default                     => 'standard',
    };
}

/**
 * Apply the link format's bookmark-of context, after save() so the post has an
 * id. Only runs when the struct actually states a format: a client editing a
 * post without touching its format leaves every context alone.
 *
 * Switching a post away from `link` drops the bookmark-of context, which is
 * what picking a different format in the client means. Reply, like and repost
 * contexts are never touched — no WordPress format maps to them, so an edit
 * from a WordPress client must not be able to destroy them.
 */
function xmlrpc_apply_link_context(Post $post, array $struct): void
{
    $raw = $struct['wp_post_format'] ?? $struct['post_format'] ?? null;
    if ($raw === null || $post->id === null) {
        return;
    }

    $others = array_values(array_filter(
        $post->contexts,
        fn(array $c) => ($c['kind'] ?? '') !== 'bookmark-of'
    ));

    if (strtolower(trim((string) $raw)) !== 'link') {
        if (count($others) !== count($post->contexts)) {
            $post->saveContexts($others);
        }
        return;
    }

    // A body with no link in it — a bookmark posted as a bare comment, say —
    // leaves any existing target in place rather than clearing it.
    $url = xmlrpc_first_link_url($post->content);
    if ($url === '') {
        return;
    }

    $post->saveContexts(array_merge($others, [['kind' => 'bookmark-of', 'url' => $url]]));
}

/**
 * Apply the incoming struct's post format to $post, if it carries one.
 * A new post with no format stated is a standard post.
 */
function xmlrpc_apply_kind(Post $post, array $struct): void
{
    $kind = xmlrpc_kind_from_struct($struct);

    if ($kind !== null) {
        $post->post_kind = $kind;
    } elseif ($post->id === null) {
        $post->post_kind = 'standard';
    }
}

/**
 * Resolve a unique slug for $post from the raw string $raw.
 */
function xmlrpc_resolve_slug(Post $post, string $raw): string
{
    global $db;

    return Post::resolveUniqueSlug($db, $raw, $post->id);
}

/**
 * After a post has been saved (id is known), give an aside with no words to slug
 * from — a photo-only note — the autoincrement id as its slug.
 * Idempotent: only writes when the slug is still empty.
 */
function xmlrpc_finalize_aside_slug(Post $post): void
{
    if ($post->slug !== '' || $post->id === null) {
        return;
    }
    $post->slug = (string) $post->id;
    $post->save();
}

/**
 * Apply a MetaWeblog content struct onto a Post object.
 * Mutates $post in place; does not save.
 */
function applyStruct(Post $post, array $struct, bool $publish, string $timezone): void
{
    // Post kind (WordPress post_format). 'aside' = titleless note.
    xmlrpc_apply_kind($post, $struct);

    // Title
    if (isset($struct['title'])) {
        $post->title = trim((string) $struct['title']);
    }

    // Body
    if (isset($struct['description'])) {
        $post->content = (string) $struct['description'];
    }

    // Excerpt
    if (array_key_exists('mt_excerpt', $struct)) {
        $ex = trim((string) $struct['mt_excerpt']);
        $post->excerpt = $ex !== '' ? $ex : null;
    }

    // Slug — an aside with none yet derives one from its body; a photo-only note
    // yields nothing and defers to xmlrpc_finalize_aside_slug() after save().
    // A standard post holding a digit-only slug is a converted legacy aside and
    // re-derives, since digit-only slugs belong to that older numbering.
    $rawSlug = trim((string) ($struct['wp_slug'] ?? ''));
    if ($rawSlug === '') {
        if ($post->isNote()) {
            if ($post->slug === '') {
                $rawSlug = Post::slugFromContent($post->content);
            }
        } elseif ($post->slug === '' || ctype_digit($post->slug)) {
            $rawSlug = $post->title;
        }
    }
    if ($rawSlug !== '') {
        $post->slug = xmlrpc_resolve_slug($post, $rawSlug);
    }

    // post_status field overrides $publish for draft detection.
    $postStatus = strtolower(trim((string) ($struct['post_status'] ?? '')));
    if ($postStatus === 'draft') {
        $publish = false;
    }

    // Date
    $rawDate = trim((string) ($struct['dateCreated'] ?? ''));
    $pubAt   = $rawDate !== '' ? XmlRpc::parseDate($rawDate, $timezone) : null;

    // Status
    if (!$publish) {
        $post->status = 'draft';
        // Preserve published_at if already set; update if caller supplied one.
        if ($pubAt !== null) {
            $post->published_at = $pubAt;
        }
    } else {
        $effectivePubAt = $pubAt ?? $post->published_at ?? date('Y-m-d H:i:s');
        $post->published_at = $effectivePubAt;
        $post->status = strtotime($effectivePubAt) > time() ? 'scheduled' : 'published';
    }
}

/**
 * Syndicate a newly-published post to Mastodon and/or Bluesky.
 * Mirrors the first-publish logic in admin/post-edit.php.
 * Only fires when status = 'published' and the post has not already been shared.
 */
function syndicatePost(Post $post): void
{
    global $db, $config, $siteUrl, $timezone, $hasMastodon, $mastodonInstance, $mastodonToken,
           $hasBluesky, $blueskyHandle, $blueskyAppPassword;

    if ($post->status !== 'published') {
        return;
    }

    // POSSE: notes (asides and photo posts) syndicate as native-looking notes —
    // no title, no link back.
    if ($post->isNote()) {
        $postUrl = '';
        $excerpt = $post->noteText();
    } else {
        $postUrl = rtrim($siteUrl, '/') . '/' . Post::datePath($post->published_at, $post->slug, $timezone) . '/';
        $excerpt = ($post->effectiveExcerpt() !== null)
            ? strip_tags($post->effectiveExcerpt())
            : Helpers::truncate($post->content, 280);
    }

    // Photos ride along with the text so a photo post looks native on both
    // networks instead of arriving as a caption with no picture.
    $images = SyndicationMedia::forPost(
        $post,
        $config['paths']['content'] . '/media',
        rtrim($siteUrl, '/')
    );

    // A note syndicates with no title and no link back, so the text and the
    // photos are all there is. Nothing to say — no excerpt and no photo — means
    // there is no post to make; don't publish a blank status.
    if ($post->isNote() && $excerpt === '' && $images === []) {
        return;
    }

    if ($hasMastodon && $post->tooted_at === null && $post->mastodon_skip === 0) {
        $mastodon = new Mastodon($mastodonInstance, $mastodonToken);
        if ($tootUrl = $mastodon->tootPost($post->title, $excerpt, $postUrl, $images)) {
            $post->markTooted($tootUrl);
        }
    }

    if ($hasBluesky && $post->bluesky_at === null && $post->bluesky_skip === 0) {
        $bluesky = new Bluesky($blueskyHandle, $blueskyAppPassword);
        if ($bskyUrl = $bluesky->postToBluesky($post->title, $excerpt, $postUrl, $images)) {
            $post->markBluesky($bskyUrl);
        }
    }
}

/**
 * Save binary media data to content/media/ and register it in the DB.
 * Mirrors the filename pattern from Media.php: {stem}_{8hex}.{ext}
 * Returns ['url' => string] struct on success; calls xmlrpc_fault() on error.
 */
function xmlrpc_save_media(string $originalName, string $mimeType, string $bits): array
{
    global $db, $config, $siteUrl;

    // MIME whitelist (mirrors Media.php)
    $allowed = [
        'image/jpeg'    => 'jpg',
        'image/png'     => 'png',
        'image/gif'     => 'gif',
        'image/webp'    => 'webp',
        'video/mp4'     => 'mp4',
        'video/webm'    => 'webm',
        'audio/mpeg'    => 'mp3',
        'audio/ogg'     => 'ogg',
    ];

    if (!isset($allowed[$mimeType])) {
        $ext      = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mimeType = array_search($ext, $allowed, true) ?: '';
    }

    if (!isset($allowed[$mimeType])) {
        xmlrpc_fault(400, 'Unsupported file type.');
    }

    // Verify actual file contents match the declared MIME type.
    $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bits);
    if ($detectedMime !== $mimeType) {
        xmlrpc_fault(400, 'File content does not match declared type.');
    }

    $ext      = $allowed[$mimeType];
    $stem     = strtolower(pathinfo($originalName, PATHINFO_FILENAME));
    $stem     = preg_replace('/[^a-z0-9_-]+/', '-', $stem);
    $stem     = trim($stem, '-');
    $stem     = mb_substr($stem !== '' ? $stem : 'file', 0, 60);

    $mediaDir = $config['paths']['content'] . '/media';
    if (!is_dir($mediaDir)) {
        mkdir($mediaDir, 0775, true);
    }

    $filename = '';
    for ($i = 0; $i < 10; $i++) {
        $candidate = $stem . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!file_exists($mediaDir . '/' . $candidate)) {
            $filename = $candidate;
            break;
        }
    }

    if ($filename === '') {
        xmlrpc_fault(500, 'Could not generate a unique filename.');
    }

    if (file_put_contents($mediaDir . '/' . $filename, $bits) === false) {
        xmlrpc_fault(500, 'Failed to write media file.');
    }

    $db->insert('media', [
        'filename'      => $filename,
        'original_name' => $originalName,
        'mime_type'     => $mimeType,
        'size'          => strlen($bits),
        'uploaded_at'   => date('Y-m-d H:i:s'),
    ]);

    return ['url' => rtrim($siteUrl, '/') . '/media/' . $filename];
}

/**
 * Run the appropriate Builder calls after saving a post.
 * Mirrors the logic in admin/post-edit.php.
 */
function rebuildPost(Post $post, bool $wasPublished = false): void
{
    global $builder, $db;

    if ($post->status === 'published' || $wasPublished) {
        // buildPost() rebuilds archives for $post->categories (old in-memory terms).
        // Re-fetch to find any newly added terms that also need their archives rebuilt.
        $oldCatIds = array_column($post->categories, 'id');
        $oldTagIds = array_column($post->tags, 'id');
        $fresh     = Post::findById($db, $post->id);
        $newCatIds = $fresh ? array_column($fresh->categories, 'id') : [];
        $newTagIds = $fresh ? array_column($fresh->tags, 'id') : [];

        $builder->buildPost($post);
        $prev = Post::findPrev($db, $post);
        if ($prev) $builder->buildPost($prev);
        $next = Post::findNext($db, $post);
        if ($next) $builder->buildPost($next);
        $builder->rebuildSharedResources();

        // Rebuild archives for terms that were added (old terms handled by buildPost above).
        foreach (array_diff($newCatIds, $oldCatIds) as $catId) {
            $builder->buildCategoryArchive($catId);
        }
        foreach (array_diff($newTagIds, $oldTagIds) as $tagId) {
            $builder->buildTagArchive($tagId);
        }
    } else {
        // Draft/scheduled save — no public file needed.
        $builder->buildPost($post);
    }
}

// ── Taxonomy helpers ──────────────────────────────────────────────────────────

/**
 * Look up a category by name (case-insensitive slug match), creating it if absent.
 * Returns the category ID.
 */
function xmlrpc_upsert_category(string $name): int
{
    global $db;
    $slug     = \CMS\Helpers::slugify($name);
    $existing = $db->selectOne("SELECT id FROM categories WHERE slug = :slug", [':slug' => $slug]);
    if ($existing) {
        return (int) $existing['id'];
    }
    return $db->insert('categories', ['name' => $name, 'slug' => $slug, 'description' => '']);
}

/**
 * Look up a tag by name (case-insensitive slug match), creating it if absent.
 * Returns the tag ID.
 */
function xmlrpc_upsert_tag(string $name): int
{
    global $db;
    $slug     = \CMS\Helpers::slugify($name);
    $existing = $db->selectOne("SELECT id FROM tags WHERE slug = :slug", [':slug' => $slug]);
    if ($existing) {
        return (int) $existing['id'];
    }
    return $db->insert('tags', ['name' => $name, 'slug' => $slug]);
}

/**
 * Extract and save category + tag associations from a MetaWeblog struct's
 * 'categories' array (names) or a WordPress struct's 'terms' array.
 * Also handles a flat 'mt_tags' / 'mt_keywords' field (comma-separated names).
 */
function xmlrpc_save_terms(Post $post, array $struct): void
{
    $categoryIds = [];
    $tagIds      = [];

    // MetaWeblog-style: 'categories' is an array of category name strings.
    if (!empty($struct['categories']) && is_array($struct['categories'])) {
        foreach ($struct['categories'] as $catName) {
            $catName = trim((string) $catName);
            if ($catName !== '') {
                $categoryIds[] = xmlrpc_upsert_category($catName);
            }
        }
    }

    // WordPress-style 'terms': two possible formats depending on the client:
    //   a) wp.editPost set format — struct of taxonomy → [term_ids]:
    //        {'category': [1, 2], 'post_tag': [3]}
    //   b) Round-tripped get format — sequential array of term-object structs:
    //        [{'term_id': '1', 'taxonomy': 'category', ...}, ...]
    if (!empty($struct['terms']) && is_array($struct['terms'])) {
        $terms = $struct['terms'];
        $isSequential = array_keys($terms) === range(0, count($terms) - 1);

        if (!$isSequential) {
            // Format (a): struct of taxonomy → [term_ids or term_names]
            foreach ($terms as $taxonomy => $termRefs) {
                $taxonomy = strtolower(trim((string) $taxonomy));
                if (!is_array($termRefs)) {
                    continue;
                }
                foreach ($termRefs as $ref) {
                    $id = (int) $ref;
                    if ($id > 0) {
                        if ($taxonomy === 'category') {
                            $categoryIds[] = $id;
                        } elseif ($taxonomy === 'post_tag') {
                            $tagIds[] = $id;
                        }
                    } else {
                        $name = trim((string) $ref);
                        if ($name !== '') {
                            if ($taxonomy === 'category') {
                                $categoryIds[] = xmlrpc_upsert_category($name);
                            } elseif ($taxonomy === 'post_tag') {
                                $tagIds[] = xmlrpc_upsert_tag($name);
                            }
                        }
                    }
                }
            }
        } else {
            // Format (b): sequential array of term-object structs
            foreach ($terms as $term) {
                if (!is_array($term)) {
                    continue;
                }
                $taxonomy = strtolower(trim((string) ($term['taxonomy'] ?? '')));
                $termId   = isset($term['term_id']) ? (int) $term['term_id'] : 0;
                $termName = trim((string) ($term['name'] ?? ''));

                if ($taxonomy === 'category') {
                    if ($termId > 0) {
                        $categoryIds[] = $termId;
                    } elseif ($termName !== '') {
                        $categoryIds[] = xmlrpc_upsert_category($termName);
                    }
                } elseif ($taxonomy === 'post_tag') {
                    if ($termId > 0) {
                        $tagIds[] = $termId;
                    } elseif ($termName !== '') {
                        $tagIds[] = xmlrpc_upsert_tag($termName);
                    }
                }
            }
        }
    }

    // WordPress-style 'terms_names': struct of taxonomy → [names] (used by some WP clients).
    if (!empty($struct['terms_names']) && is_array($struct['terms_names'])) {
        foreach ($struct['terms_names'] as $taxonomy => $names) {
            $taxonomy = strtolower(trim((string) $taxonomy));
            if (!is_array($names)) {
                continue;
            }
            foreach ($names as $name) {
                $name = trim((string) $name);
                if ($name === '') {
                    continue;
                }
                if ($taxonomy === 'category') {
                    $categoryIds[] = xmlrpc_upsert_category($name);
                } elseif ($taxonomy === 'post_tag') {
                    $tagIds[] = xmlrpc_upsert_tag($name);
                }
            }
        }
    }

    // mt_keywords / mt_tags: comma-separated tag names.
    $keywords = trim((string) ($struct['mt_keywords'] ?? $struct['mt_tags'] ?? ''));
    if ($keywords !== '') {
        foreach (array_filter(array_map('trim', explode(',', $keywords))) as $kw) {
            $tagIds[] = xmlrpc_upsert_tag($kw);
        }
    }

    $post->saveTerms(array_unique($categoryIds), array_unique($tagIds));
}

// ── WordPress API helpers ─────────────────────────────────────────────────────

/** Map CMS status → WordPress status string */
function wpStatus(string $s): string
{
    return match ($s) {
        'published' => 'publish',
        'scheduled' => 'future',
        default     => 'draft',
    };
}

/** Map WordPress status string → CMS status */
function cmsStatusFromWp(string $s): string
{
    return match ($s) {
        'publish' => 'published',
        'future'  => 'scheduled',
        default   => 'draft',
    };
}

/**
 * Build the WordPress-style struct for a single post.
 */
function wpPostToStruct(Post $post, string $siteUrl, string $timezone = ''): array
{
    $pubAt = $post->published_at ?? $post->created_at;
    $url   = rtrim($siteUrl, '/') . '/' . Post::datePath($pubAt, $post->slug, $timezone) . '/';

    // WordPress requires post_status='publish' only for past-dated posts.
    // If a post is 'published' in our DB but its date is still in the future,
    // return 'future' so MarsEdit shows it in Scheduled (avoids display confusion).
    $wpStat = wpStatus($post->status);
    if ($wpStat === 'publish' && $pubAt && strtotime($pubAt) > time()) {
        $wpStat = 'future';
    }

    return [
        'post_id'           => (string) $post->id,
        'post_title'        => $post->title,
        'post_content'      => $post->content,
        'post_excerpt'      => $post->excerpt ?? '',
        'post_name'         => $post->slug,
        'post_status'       => $wpStat,
        'post_type'         => 'post',
        'post_date'         => new \CMS\DateTimeValue(XmlRpc::isoDate($pubAt)),
        'post_date_gmt'     => new \CMS\DateTimeValue(XmlRpc::isoDate($pubAt)),
        'post_modified'     => new \CMS\DateTimeValue(XmlRpc::isoDate($post->updated_at)),
        'post_modified_gmt' => new \CMS\DateTimeValue(XmlRpc::isoDate($post->updated_at)),
        'link'              => $url,
        'guid'              => $url,
        'post_author'       => '1',
        'comment_status'    => 'closed',
        'ping_status'       => 'closed',
        'post_format'       => xmlrpc_format_from_post($post),
        'post_thumbnail'    => '',
        'terms'             => array_merge(
            array_map(fn($c) => [
                'term_id'  => (string) $c['id'],
                'name'     => $c['name'],
                'slug'     => $c['slug'],
                'taxonomy' => 'category',
            ], $post->categories),
            array_map(fn($t) => [
                'term_id'  => (string) $t['id'],
                'name'     => $t['name'],
                'slug'     => $t['slug'],
                'taxonomy' => 'post_tag',
            ], $post->tags)
        ),
        'custom_fields'     => [],
    ];
}

/**
 * Apply a WordPress-style content struct onto a Post object.
 * Mutates $post in place; does not save.
 */
function applyWpPostStruct(Post $post, array $struct, string $timezone): void
{
    xmlrpc_apply_kind($post, $struct);

    if (isset($struct['post_title'])) {
        $post->title = trim((string) $struct['post_title']);
    }

    if (isset($struct['post_content'])) {
        $post->content = (string) $struct['post_content'];
    }

    if (array_key_exists('post_excerpt', $struct)) {
        $ex = trim((string) $struct['post_excerpt']);
        $post->excerpt = $ex !== '' ? $ex : null;
    }

    // Slug — see applyStruct() for the aside/standard derivation rules.
    $rawSlug = trim((string) ($struct['post_name'] ?? ''));
    if ($rawSlug === '') {
        if ($post->isNote()) {
            if ($post->slug === '') {
                $rawSlug = Post::slugFromContent($post->content);
            }
        } elseif ($post->slug === '' || ctype_digit($post->slug)) {
            $rawSlug = $post->title;
        }
    }
    if ($rawSlug !== '') {
        $post->slug = xmlrpc_resolve_slug($post, $rawSlug);
    }

    // Date
    $rawDate = trim((string) ($struct['post_date'] ?? ''));
    $pubAt   = $rawDate !== '' ? XmlRpc::parseDate($rawDate, $timezone) : null;

    // Status
    $wpStat = strtolower(trim((string) ($struct['post_status'] ?? 'publish')));

    if ($wpStat === 'draft') {
        $post->status = 'draft';
        if ($pubAt !== null) {
            $post->published_at = $pubAt;
        }
    } else {
        // 'publish', 'future', or anything else → attempt to publish/schedule.
        // Preserve the existing published_at when no date is supplied (edit case).
        $effectivePubAt     = $pubAt ?? $post->published_at ?? date('Y-m-d H:i:s');
        $post->published_at = $effectivePubAt;
        $post->status       = strtotime($effectivePubAt) > time() ? 'scheduled' : 'published';
    }
}

/**
 * Build the WordPress-style struct for a single page.
 */
function wpPageToStruct(Page $page, string $siteUrl): array
{
    $url = rtrim($siteUrl, '/') . '/' . $page->slug . '/';

    return [
        'post_id'           => (string) ($page->id + PAGE_ID_OFFSET),
        'post_title'        => $page->title,
        'post_content'      => $page->content,
        'post_name'         => $page->slug,
        'post_status'       => $page->status === 'published' ? 'publish' : 'draft',
        'post_type'         => 'page',
        'post_date'         => new \CMS\DateTimeValue(XmlRpc::isoDate($page->created_at)),
        'post_date_gmt'     => new \CMS\DateTimeValue(XmlRpc::isoDate($page->created_at)),
        'post_modified'     => new \CMS\DateTimeValue(XmlRpc::isoDate($page->updated_at)),
        'post_modified_gmt' => new \CMS\DateTimeValue(XmlRpc::isoDate($page->updated_at)),
        'link'              => $url,
        'guid'              => $url,
        'post_author'       => '1',
        'menu_order'        => $page->nav_order,
        'comment_status'    => 'closed',
        'ping_status'       => 'closed',
        'custom_fields'     => [],
    ];
}

/**
 * Apply a WordPress-style struct onto a Page object.
 * Mutates $page in place; does not save.
 */
function applyWpPageStruct(Page $page, array $struct): void
{
    global $db;

    if (isset($struct['post_title'])) {
        $page->title = trim((string) $struct['post_title']);
    }

    if (isset($struct['post_content'])) {
        $page->content = (string) $struct['post_content'];
    }

    // Slug
    $rawSlug = trim((string) ($struct['post_name'] ?? ''));
    if ($rawSlug === '' && $page->slug === '') {
        $rawSlug = $page->title;
    }
    if ($rawSlug !== '') {
        $base      = Helpers::slugify($rawSlug);
        $candidate = $base;
        $suffix    = 2;
        while (true) {
            $existing = Page::findBySlug($db, $candidate);
            if ($existing === null || $existing->id === $page->id) {
                break;
            }
            $candidate = $base . '-' . $suffix++;
        }
        $page->slug = $candidate;
    }

    // Status ('publish' → published, anything else → draft; no scheduling for pages)
    $wpStat = strtolower(trim((string) ($struct['post_status'] ?? '')));
    if ($wpStat !== '') {
        $page->status = $wpStat === 'publish' ? 'published' : 'draft';
    }

    // Nav order
    if (array_key_exists('menu_order', $struct)) {
        $page->nav_order = (int) $struct['menu_order'];
    }
}

/**
 * Run the appropriate Builder calls after saving a page.
 * Always rebuilds index when status changes (pages appear in nav).
 */
function rebuildPage(Page $page, bool $wasPublished): void
{
    global $builder;

    $builder->buildPage($page);

    if ($page->status === 'published' || $wasPublished) {
        $builder->buildIndex();
    }
}

// ── Site-wide settings (used by multiple handlers) ────────────────────────────

$siteUrl         = $db->getSetting('site_url', '');
$siteTitle       = $db->getSetting('site_title', 'My CMS');
$siteDescription = $db->getSetting('site_description', '');
$timezone        = $db->getSetting('timezone', '');

$mastodonInstance   = $db->getSetting('mastodon_instance', '');
$mastodonToken      = $db->getSetting('mastodon_token', '');
$hasMastodon        = $mastodonInstance !== '' && $mastodonToken !== '';

$blueskyHandle      = $db->getSetting('bluesky_handle', '');
$blueskyAppPassword = $db->getSetting('bluesky_app_password', '');
$hasBluesky         = $blueskyHandle !== '' && $blueskyAppPassword !== '';

// ── Method dispatch ───────────────────────────────────────────────────────────

switch ($method) {

    // ── blogger.getUsersBlogs(appkey, username, password) ─────────────────────
    case 'blogger.getUsersBlogs':
        xmlrpc_auth($params, 1, 2);
        echo XmlRpc::encodeResponse([[
            'blogid'   => '1',
            'blogName' => $siteTitle,
            'url'      => $siteUrl,
        ]]);
        break;

    // ── metaWeblog.getRecentPosts(blogid, username, password, numberOfPosts) ──
    // numberOfPosts=0 means "return all" (MarsEdit "Download all posts" mode).
    case 'metaWeblog.getRecentPosts':
        xmlrpc_auth($params, 1, 2);
        $numberRaw = isset($params[3]) ? (int) $params[3] : 0;
        $limit     = $numberRaw > 0 ? $numberRaw : PHP_INT_MAX;
        $all       = Post::findAll($db, 'published');
        $posts     = array_slice($all, 0, $limit);
        $structs   = array_map(fn($p) => postToStruct($p, $siteUrl, $timezone), $posts);
        xmlrpc_debug("  number=$numberRaw → " . count($posts) . " posts returned");
        echo XmlRpc::encodeResponse($structs);
        break;

    // ── metaWeblog.getPost(postid, username, password) ────────────────────────
    case 'metaWeblog.getPost':
        xmlrpc_auth($params, 1, 2);
        $post = Post::findById($db, (int) ($params[0] ?? 0));
        if ($post === null) {
            xmlrpc_fault(404, 'Post not found.');
        }
        echo XmlRpc::encodeResponse(postToStruct($post, $siteUrl, $timezone));
        break;

    // ── metaWeblog.newPost(blogid, username, password, struct, publish) ───────
    case 'metaWeblog.newPost':
        xmlrpc_auth($params, 1, 2);
        $struct  = (array) ($params[3] ?? []);
        $publish = (bool) ($params[4] ?? false);

        $post = new Post($db);
        applyStruct($post, $struct, $publish, $timezone);

        if ($post->title === '' && !$post->isNote()) {
            xmlrpc_fault(400, 'Title is required.');
        }

        $post->save();
        xmlrpc_finalize_aside_slug($post);
        xmlrpc_apply_link_context($post, $struct);
        xmlrpc_save_terms($post, $struct);
        syndicatePost($post);
        rebuildPost($post);

        echo XmlRpc::encodeResponse((string) $post->id);
        break;

    // ── metaWeblog.editPost(postid, username, password, struct, publish) ──────
    case 'metaWeblog.editPost':
        xmlrpc_auth($params, 1, 2);
        $post = Post::findById($db, (int) ($params[0] ?? 0));
        if ($post === null) {
            xmlrpc_fault(404, 'Post not found.');
        }
        $struct       = (array) ($params[3] ?? []);
        $publish      = (bool) ($params[4] ?? false);
        $wasPublished = $post->status === 'published';

        applyStruct($post, $struct, $publish, $timezone);

        if ($post->title === '' && !$post->isNote()) {
            xmlrpc_fault(400, 'Title is required.');
        }

        $post->save();
        xmlrpc_finalize_aside_slug($post);
        xmlrpc_apply_link_context($post, $struct);
        xmlrpc_save_terms($post, $struct);
        syndicatePost($post);
        rebuildPost($post, $wasPublished);

        echo XmlRpc::encodeResponse(true);
        break;

    // ── metaWeblog.deletePost(appkey, postid, username, password, publish) ────
    case 'metaWeblog.deletePost':
    case 'blogger.deletePost':
        xmlrpc_auth($params, 2, 3);
        $post = Post::findById($db, (int) ($params[1] ?? 0));
        if ($post === null) {
            xmlrpc_fault(404, 'Post not found.');
        }
        $wasPublished = $post->status === 'published';
        $prevNeighbor = $wasPublished ? Post::findPrev($db, $post) : null;
        $nextNeighbor = $wasPublished ? Post::findNext($db, $post) : null;
        $post->delete();
        // buildPost() removal path also rebuilds taxonomy archives for $post->categories.
        $post->status = 'draft';
        $builder->buildPost($post);
        if ($wasPublished) {
            if ($prevNeighbor) $builder->buildPost($prevNeighbor);
            if ($nextNeighbor) $builder->buildPost($nextNeighbor);
            $builder->rebuildSharedResources();
        }
        echo XmlRpc::encodeResponse(true);
        break;

    // ── metaWeblog.getCategories(blogid, username, password) ─────────────────
    case 'metaWeblog.getCategories':
        xmlrpc_auth($params, 1, 2);
        $cats = $db->select("SELECT * FROM categories ORDER BY name");
        echo XmlRpc::encodeResponse(array_map(fn($c) => [
            'categoryId'   => (string) $c['id'],
            'categoryName' => $c['name'],
            'description'  => $c['description'],
            'htmlUrl'      => rtrim($siteUrl, '/') . '/category/' . rawurlencode($c['slug']) . '/',
            'rssUrl'       => '',
        ], $cats));
        break;

    // ── metaWeblog.newMediaObject(blogid, username, password, struct) ─────────
    case 'metaWeblog.newMediaObject':
        xmlrpc_auth($params, 1, 2);
        $struct       = (array) ($params[3] ?? []);
        $originalName = trim((string) ($struct['name'] ?? ''));
        $mimeType     = trim((string) ($struct['type'] ?? ''));
        $bits         = $struct['bits'] ?? '';

        if ($originalName === '' || !is_string($bits) || $bits === '') {
            xmlrpc_fault(400, 'Invalid media object: name and bits are required.');
        }

        echo XmlRpc::encodeResponse(xmlrpc_save_media($originalName, $mimeType, $bits));
        break;

    // ════════════════════════════════════════════════════════════════════════
    // Movable Type API stubs
    // MarsEdit calls these during blog discovery regardless of API mode.
    // ════════════════════════════════════════════════════════════════════════

    // ── mt.supportedMethods() ────────────────────────────────────────────────
    case 'mt.supportedMethods':
        xmlrpc_auth($params, 1, 2);
        echo XmlRpc::encodeResponse([
            'blogger.getUsersBlogs',
            'metaWeblog.getRecentPosts', 'metaWeblog.getPost',
            'metaWeblog.newPost', 'metaWeblog.editPost', 'metaWeblog.deletePost',
            'metaWeblog.getCategories', 'metaWeblog.newMediaObject',
            'mt.supportedMethods', 'mt.supportedTextFilters',
            'mt.getCategoryList', 'mt.getPostCategories', 'mt.setPostCategories',
            'wp.getUsersBlogs', 'wp.getUsers', 'wp.getOptions', 'wp.getAuthors',
            'wp.getPostFormats', 'wp.getTaxonomies', 'wp.getTerms',
            'wp.getTags', 'wp.newCategory', 'wp.deleteCategory',
            'wp.getPosts', 'wp.getPost', 'wp.newPost', 'wp.editPost', 'wp.deletePost',
            'wp.getPages', 'wp.getPageList', 'wp.getPageStatusList', 'wp.getPage', 'wp.newPage', 'wp.editPage', 'wp.deletePage',
            'wp.getMediaLibrary', 'wp.uploadFile',
        ]);
        break;

    // ── mt.supportedTextFilters(blogid, username, password) ──────────────────
    case 'mt.supportedTextFilters':
        xmlrpc_auth($params, 1, 2);
        echo XmlRpc::encodeResponse([]);
        break;

    // ── mt.getCategoryList(blogid, username, password) ────────────────────────
    case 'mt.getCategoryList':
        xmlrpc_auth($params, 1, 2);
        $cats = $db->select("SELECT id, name FROM categories ORDER BY name");
        echo XmlRpc::encodeResponse(array_map(fn($c) => [
            'categoryId'   => (string) $c['id'],
            'categoryName' => $c['name'],
        ], $cats));
        break;

    // ── mt.getPostCategories(postid, username, password) ─────────────────────
    case 'mt.getPostCategories':
        xmlrpc_auth($params, 1, 2);
        $post = Post::findById($db, (int) ($params[0] ?? 0));
        if ($post === null) {
            xmlrpc_fault(404, 'Post not found.');
        }
        echo XmlRpc::encodeResponse(array_map(fn($c) => [
            'categoryId'   => (string) $c['id'],
            'categoryName' => $c['name'],
            'isPrimary'    => true,
        ], $post->categories));
        break;

    // ── mt.setPostCategories(postid, username, password, categories) ──────────
    case 'mt.setPostCategories':
        xmlrpc_auth($params, 1, 2);
        $post = Post::findById($db, (int) ($params[0] ?? 0));
        if ($post === null) {
            xmlrpc_fault(404, 'Post not found.');
        }
        $catStructs  = (array) ($params[3] ?? []);
        $categoryIds = [];
        foreach ($catStructs as $cs) {
            $cid = (int) ($cs['categoryId'] ?? 0);
            if ($cid > 0) {
                $categoryIds[] = $cid;
            }
        }
        $post->saveTerms($categoryIds, array_column($post->tags, 'id'));
        if ($post->status === 'published') {
            $builder->buildPost($post);
        }
        echo XmlRpc::encodeResponse(true);
        break;

    // ════════════════════════════════════════════════════════════════════════
    // WordPress XML-RPC API
    // ════════════════════════════════════════════════════════════════════════

    // ── wp.getUsersBlogs(appkey, username, password) ──────────────────────────
    case 'wp.getUsersBlogs':
        xmlrpc_auth($params, 1, 2);
        echo XmlRpc::encodeResponse([[
            'blogid'   => '1',
            'blogName' => $siteTitle,
            'url'      => $siteUrl,
            'xmlrpc'   => rtrim($siteUrl, '/') . '/admin/xmlrpc.php',
            'isAdmin'  => true,
        ]]);
        break;

    // ── wp.getOptions(blogid, username, password[, options]) ──────────────────
    case 'wp.getOptions':
        xmlrpc_auth($params, 1, 2);
        $opt = fn(string $desc, bool $ro, string $val): array =>
            ['desc' => $desc, 'readonly' => $ro, 'value' => $val];
        echo XmlRpc::encodeResponse([
            'software_name'    => $opt('Software Name',    true,  'WordPress'),
            'software_version' => $opt('Software Version', true,  '6.4'),
            'blog_url'         => $opt('Blog URL',         true,  $siteUrl),
            'home_url'         => $opt('Home URL',         true,  $siteUrl),
            'blog_title'       => $opt('Blog Title',       false, $siteTitle),
            'blog_tagline'     => $opt('Tagline',          false, $siteDescription),
            'time_zone'        => $opt('Time Zone',        true,  $timezone),
            'date_format'      => $opt('Date Format',      true,  'Y-m-d'),
            'time_format'      => $opt('Time Format',      true,  'H:i:s'),
            'upload_path'      => $opt('Upload Path',      true,  '/media/'),
            'thumbnail_size_w' => $opt('Thumbnail Width',  true,  '150'),
            'thumbnail_size_h' => $opt('Thumbnail Height', true,  '150'),
        ]);
        break;

    // ── wp.getUsers(blogid, username, password[, filter]) ────────────────────
    case 'wp.getUsers':
        xmlrpc_auth($params, 1, 2);
        $cfgUser = $config['admin']['username'] ?? '';
        xmlrpc_debug("  → returning user '$cfgUser'");
        echo XmlRpc::encodeResponse([[
            'user_id'          => '1',
            'user_login'       => $cfgUser,
            'display_name'     => $cfgUser,
            'user_registered'  => new \CMS\DateTimeValue(XmlRpc::isoDate(null)),
            'bio'              => '',
            'email'            => '',
            'nickname'         => $cfgUser,
            'firstname'        => '',
            'lastname'         => '',
            'url'              => $siteUrl,
            'roles'            => ['administrator'],
        ]]);
        break;

    // ── wp.getAuthors(blogid, username, password) ─────────────────────────────
    case 'wp.getAuthors':
        xmlrpc_auth($params, 1, 2);
        echo XmlRpc::encodeResponse([[
            'user_id'      => '1',
            'user_login'   => $config['admin']['username'] ?? '',
            'display_name' => $config['admin']['username'] ?? '',
            'user_email'   => '',
            'meta_value'   => '',
        ]]);
        break;

    // ── wp.getPostFormats(blogid, username, password[, filter]) ───────────────
    case 'wp.getPostFormats':
        xmlrpc_auth($params, 1, 2);
        echo XmlRpc::encodeResponse(XMLRPC_POST_FORMATS);
        break;

    // ── wp.getTaxonomies(blogid, username, password) ──────────────────────────
    case 'wp.getTaxonomies':
        xmlrpc_auth($params, 1, 2);
        echo XmlRpc::encodeResponse([
            [
                'name'         => 'category',
                'label'        => 'Categories',
                'hierarchical' => true,
                'public'       => true,
                'show_ui'      => true,
                'cap'          => ['manage_terms' => 'manage_categories', 'edit_terms' => 'manage_categories', 'delete_terms' => 'manage_categories', 'assign_terms' => 'edit_posts'],
                '_builtin'     => true,
                'labels'       => ['name' => 'Categories', 'singular_name' => 'Category'],
            ],
            [
                'name'         => 'post_tag',
                'label'        => 'Tags',
                'hierarchical' => false,
                'public'       => true,
                'show_ui'      => true,
                'cap'          => ['manage_terms' => 'manage_post_tags', 'edit_terms' => 'manage_post_tags', 'delete_terms' => 'manage_post_tags', 'assign_terms' => 'edit_posts'],
                '_builtin'     => true,
                'labels'       => ['name' => 'Tags', 'singular_name' => 'Tag'],
            ],
        ]);
        break;

    // ── wp.getTerms(blogid, username, password, taxonomy[, filter]) ───────────
    case 'wp.getTerms':
        xmlrpc_auth($params, 1, 2);
        $taxonomy = strtolower(trim((string) ($params[3] ?? '')));
        if ($taxonomy === 'category') {
            $rows = $db->select(
                "SELECT c.id, c.name, c.slug, c.description,
                        COUNT(pc.post_id) AS count
                   FROM categories c
              LEFT JOIN post_categories pc ON pc.category_id = c.id
                  GROUP BY c.id ORDER BY c.name"
            );
            echo XmlRpc::encodeResponse(array_map(fn($c) => [
                'term_id'     => (string) $c['id'],
                'name'        => $c['name'],
                'slug'        => $c['slug'],
                'term_group'  => '0',
                'term_taxonomy_id' => (string) $c['id'],
                'taxonomy'    => 'category',
                'description' => $c['description'],
                'parent'      => '0',
                'count'       => (int) $c['count'],
            ], $rows));
        } elseif ($taxonomy === 'post_tag') {
            $rows = $db->select(
                "SELECT t.id, t.name, t.slug,
                        COUNT(pt.post_id) AS count
                   FROM tags t
              LEFT JOIN post_tags pt ON pt.tag_id = t.id
                  GROUP BY t.id ORDER BY t.name"
            );
            echo XmlRpc::encodeResponse(array_map(fn($t) => [
                'term_id'     => (string) $t['id'],
                'name'        => $t['name'],
                'slug'        => $t['slug'],
                'term_group'  => '0',
                'term_taxonomy_id' => (string) $t['id'],
                'taxonomy'    => 'post_tag',
                'description' => '',
                'parent'      => '0',
                'count'       => (int) $t['count'],
            ], $rows));
        } else {
            echo XmlRpc::encodeResponse([]);
        }
        break;

    // ── wp.getTags(blogid, username, password) ────────────────────────────────
    case 'wp.getTags':
        xmlrpc_auth($params, 1, 2);
        $rows = $db->select(
            "SELECT t.id, t.name, t.slug,
                    COUNT(pt.post_id) AS count
               FROM tags t
          LEFT JOIN post_tags pt ON pt.tag_id = t.id
              GROUP BY t.id ORDER BY t.name"
        );
        echo XmlRpc::encodeResponse(array_map(fn($t) => [
            'tag_id'   => (string) $t['id'],
            'name'     => $t['name'],
            'slug'     => $t['slug'],
            'tag_description' => '',
            'count'    => (int) $t['count'],
            'html_url' => rtrim($siteUrl, '/') . '/tag/' . rawurlencode($t['slug']) . '/',
            'rss_url'  => '',
        ], $rows));
        break;

    // ── wp.getPosts(blogid, username, password[, filter]) ─────────────────────
    // ── wp.newCategory(blogid, username, password, category) ──────────────────
    case 'wp.newCategory':
        xmlrpc_auth($params, 1, 2);
        $cat     = (array) ($params[3] ?? []);
        $name    = trim((string) ($cat['name'] ?? ''));
        $slug    = trim((string) ($cat['slug'] ?? ''));
        $desc    = trim((string) ($cat['description'] ?? ''));
        if ($name === '') {
            xmlrpc_fault(400, 'Category name is required.');
        }
        if ($slug === '') {
            $slug = Helpers::slugify($name);
        }
        $existing = $db->selectOne("SELECT id FROM categories WHERE slug = :slug", [':slug' => $slug]);
        if ($existing) {
            echo XmlRpc::encodeResponse((int) $existing['id']);
        } else {
            $id = $db->insert('categories', ['name' => $name, 'slug' => $slug, 'description' => $desc]);
            echo XmlRpc::encodeResponse($id);
        }
        break;

    // ── wp.deleteCategory(blogid, username, password, category_id) ────────────
    case 'wp.deleteCategory':
        xmlrpc_auth($params, 1, 2);
        $catId = (int) ($params[3] ?? 0);
        if ($catId <= 0) {
            xmlrpc_fault(400, 'Invalid category ID.');
        }
        $db->delete('categories', 'id = :id', ['id' => $catId]);
        $builder->buildAllTaxonomyArchives();
        echo XmlRpc::encodeResponse(true);
        break;

    // ── wp.getPosts(blogid, username, password[, filter]) ─────────────────────
    // MarsEdit uses post_type='page' filter to list pages via this method.
    // number=0 (or absent) means "return all" — used by "Download all posts".
    case 'wp.getPosts':
        xmlrpc_auth($params, 1, 2);
        $filter      = (array) ($params[3] ?? []);
        $postType    = strtolower(trim((string) ($filter['post_type'] ?? 'post')));
        $numberRaw   = isset($filter['number']) ? (int) $filter['number'] : 0;
        $limit       = $numberRaw > 0 ? $numberRaw : PHP_INT_MAX;
        $offset      = (int) ($filter['offset'] ?? 0);

        xmlrpc_debug("  post_type=$postType post_status=" . ($filter['post_status'] ?? '(none)') . " number=$numberRaw offset=$offset limit=" . ($limit === PHP_INT_MAX ? 'ALL' : $limit));

        if ($postType === 'page') {
            $all    = Page::findAll($db);
            $sliced = array_slice($all, $offset, $limit);
            xmlrpc_debug("  → " . count($sliced) . " pages (of " . count($all) . " total)");
            echo XmlRpc::encodeResponse(array_map(fn($pg) => wpPageToStruct($pg, $siteUrl), $sliced));
        } else {
            $wpStat = strtolower(trim((string) ($filter['post_status'] ?? 'any')));
            $status = ($wpStat === 'any' || $wpStat === '') ? null : cmsStatusFromWp($wpStat);
            $all    = Post::findAll($db, $status);
            $sliced = array_slice($all, $offset, $limit);
            xmlrpc_debug("  → " . count($sliced) . " posts (of " . count($all) . " total, db_status_filter=" . ($status ?? 'all') . ")");
            $structs = array_map(fn($p) => wpPostToStruct($p, $siteUrl, $timezone), $sliced);
            echo XmlRpc::encodeResponse($structs);
        }
        break;

    // ── wp.getPost(blogid, username, password, post_id) ───────────────────────
    // IDs >= PAGE_ID_OFFSET are pages (offset encoded in wpPageToStruct).
    case 'wp.getPost':
        xmlrpc_auth($params, 1, 2);
        $postId = (int) ($params[3] ?? 0);
        xmlrpc_debug("  post_id=$postId" . ($postId >= PAGE_ID_OFFSET ? " (page, real_id=" . ($postId - PAGE_ID_OFFSET) . ")" : ""));
        if ($postId >= PAGE_ID_OFFSET) {
            $page = Page::findById($db, $postId - PAGE_ID_OFFSET);
            if ($page === null) {
                xmlrpc_debug("  → 404 page not found");
                xmlrpc_fault(404, 'Page not found.');
            }
            xmlrpc_debug("  → page '{$page->title}' status={$page->status}");
            echo XmlRpc::encodeResponse(wpPageToStruct($page, $siteUrl));
            break;
        }
        $post = Post::findById($db, $postId);
        if ($post === null) {
            xmlrpc_debug("  → 404 post not found");
            xmlrpc_fault(404, 'Post not found.');
        }
        xmlrpc_debug("  → post '{$post->title}' status={$post->status} pub=" . ($post->published_at ?? 'null'));
        echo XmlRpc::encodeResponse(wpPostToStruct($post, $siteUrl, $timezone));
        break;

    // ── wp.newPost(blogid, username, password, content) ───────────────────────
    // MarsEdit passes post_type='page' when creating a page from the Pages section.
    case 'wp.newPost':
        xmlrpc_auth($params, 1, 2);
        $struct   = (array) ($params[3] ?? []);
        $postType = strtolower(trim((string) ($struct['post_type'] ?? 'post')));

        if ($postType === 'page') {
            $page = new Page($db);
            applyWpPageStruct($page, $struct);
            if ($page->title === '') {
                xmlrpc_fault(400, 'Title is required.');
            }
            $page->save();
            rebuildPage($page, false);
            echo XmlRpc::encodeResponse((string) ($page->id + PAGE_ID_OFFSET));
        } else {
            $post = new Post($db);
            applyWpPostStruct($post, $struct, $timezone);
            if ($post->title === '' && !$post->isNote()) {
                xmlrpc_fault(400, 'Title is required.');
            }
            $post->save();
            xmlrpc_finalize_aside_slug($post);
            xmlrpc_apply_link_context($post, $struct);
            xmlrpc_save_terms($post, $struct);
            syndicatePost($post);
            rebuildPost($post);
            echo XmlRpc::encodeResponse((string) $post->id);
        }
        break;

    // ── wp.editPost(blogid, username, password, post_id, content) ────────────
    // IDs >= PAGE_ID_OFFSET are pages (offset encoded in wpPageToStruct).
    case 'wp.editPost':
        xmlrpc_auth($params, 1, 2);
        $postId = (int) ($params[3] ?? 0);
        $struct = (array) ($params[4] ?? []);

        if ($postId >= PAGE_ID_OFFSET) {
            $page = Page::findById($db, $postId - PAGE_ID_OFFSET);
            if ($page === null) {
                xmlrpc_fault(404, 'Page not found.');
            }
            $wasPublished = $page->status === 'published';
            applyWpPageStruct($page, $struct);
            if ($page->title === '') {
                xmlrpc_fault(400, 'Title is required.');
            }
            $page->save();
            rebuildPage($page, $wasPublished);
            echo XmlRpc::encodeResponse(true);
            break;
        }

        $post = Post::findById($db, $postId);
        if ($post === null) {
            xmlrpc_fault(404, 'Post not found.');
        }
        $wasPublished = $post->status === 'published';
        applyWpPostStruct($post, $struct, $timezone);
        if ($post->title === '' && !$post->isNote()) {
            xmlrpc_fault(400, 'Title is required.');
        }
        $post->save();
        xmlrpc_finalize_aside_slug($post);
        xmlrpc_apply_link_context($post, $struct);
        xmlrpc_save_terms($post, $struct);
        syndicatePost($post);
        rebuildPost($post, $wasPublished);
        echo XmlRpc::encodeResponse(true);
        break;

    // ── wp.deletePost(blogid, username, password, post_id) ────────────────────
    // IDs >= PAGE_ID_OFFSET are pages (offset encoded in wpPageToStruct).
    case 'wp.deletePost':
        xmlrpc_auth($params, 1, 2);
        $postId = (int) ($params[3] ?? 0);

        if ($postId >= PAGE_ID_OFFSET) {
            $page = Page::findById($db, $postId - PAGE_ID_OFFSET);
            if ($page === null) {
                xmlrpc_fault(404, 'Page not found.');
            }
            $wasPublished = $page->status === 'published';
            $page->delete();
            $page->status = 'draft';
            $builder->buildPage($page);
            if ($wasPublished) {
                $builder->buildIndex();
            }
            echo XmlRpc::encodeResponse(true);
            break;
        }

        $post = Post::findById($db, $postId);
        if ($post === null) {
            xmlrpc_fault(404, 'Post not found.');
        }
        $wasPublished = $post->status === 'published';
        $prevNeighbor = $wasPublished ? Post::findPrev($db, $post) : null;
        $nextNeighbor = $wasPublished ? Post::findNext($db, $post) : null;
        $post->delete();
        // buildPost() removal path also rebuilds taxonomy archives for $post->categories.
        $post->status = 'draft';
        $builder->buildPost($post);
        if ($wasPublished) {
            if ($prevNeighbor) $builder->buildPost($prevNeighbor);
            if ($nextNeighbor) $builder->buildPost($nextNeighbor);
            $builder->rebuildSharedResources();
        }
        echo XmlRpc::encodeResponse(true);
        break;

    // ── wp.getPages(blogid, username, password[, num_pages]) ──────────────────
    case 'wp.getPages':
        xmlrpc_auth($params, 1, 2);
        $limit = isset($params[3]) ? max(1, (int) $params[3]) : PHP_INT_MAX;
        $all   = Page::findAll($db);        // all statuses — MarsEdit needs drafts
        $pages = array_slice($all, 0, $limit);
        echo XmlRpc::encodeResponse(array_map(fn($pg) => wpPageToStruct($pg, $siteUrl), $pages));
        break;

    // ── wp.getPageList(blogid, username, password) ────────────────────────────
    // MarsEdit calls this (not wp.getPages) to populate its Pages sidebar.
    // Returns a lightweight index per the WP spec: page_id and page_parent_id
    // must be integers (not strings) or MarsEdit may silently drop the list.
    case 'wp.getPageList':
        xmlrpc_auth($params, 1, 2);
        $all = Page::findAll($db);
        $list = array_map(fn(Page $pg): array => [
            'page_id'          => (int) $pg->id,
            'page_title'       => $pg->title,
            'page_parent_id'   => 0,
            'dateCreated'      => new \CMS\DateTimeValue(XmlRpc::isoDate($pg->created_at)),
            'date_created_gmt' => new \CMS\DateTimeValue(XmlRpc::isoDate($pg->created_at)),
        ], $all);
        echo XmlRpc::encodeResponse($list);
        break;

    // ── wp.getPageStatusList(blogid, username, password) ─────────────────────
    case 'wp.getPageStatusList':
        xmlrpc_auth($params, 1, 2);
        echo XmlRpc::encodeResponse(['draft' => 'Draft', 'publish' => 'Published']);
        break;

    // ── wp.getPage(blogid, username, password, page_id) ───────────────────────
    case 'wp.getPage':
        xmlrpc_auth($params, 1, 2);
        $page = Page::findById($db, (int) ($params[3] ?? 0));
        if ($page === null) {
            xmlrpc_fault(404, 'Page not found.');
        }
        echo XmlRpc::encodeResponse(wpPageToStruct($page, $siteUrl));
        break;

    // ── wp.newPage(blogid, username, password, content[, publish]) ────────────
    case 'wp.newPage':
        xmlrpc_auth($params, 1, 2);
        $struct  = (array) ($params[3] ?? []);
        $publish = (bool) ($params[4] ?? true);

        $page = new Page($db);
        applyWpPageStruct($page, $struct);

        // Honour explicit $publish=false even if struct says 'publish'.
        if (!$publish) {
            $page->status = 'draft';
        }

        if ($page->title === '') {
            xmlrpc_fault(400, 'Title is required.');
        }

        $page->save();
        rebuildPage($page, false);

        echo XmlRpc::encodeResponse((string) $page->id);
        break;

    // ── wp.editPage(blogid, username, password, page_id, content[, publish]) ──
    case 'wp.editPage':
        xmlrpc_auth($params, 1, 2);
        $page = Page::findById($db, (int) ($params[3] ?? 0));
        if ($page === null) {
            xmlrpc_fault(404, 'Page not found.');
        }
        $wasPublished = $page->status === 'published';
        $struct       = (array) ($params[4] ?? []);
        $publish      = isset($params[5]) ? (bool) $params[5] : null;

        applyWpPageStruct($page, $struct);

        // Explicit $publish param overrides struct's post_status.
        if ($publish === false) {
            $page->status = 'draft';
        } elseif ($publish === true) {
            $page->status = 'published';
        }

        if ($page->title === '') {
            xmlrpc_fault(400, 'Title is required.');
        }

        $page->save();
        rebuildPage($page, $wasPublished);

        echo XmlRpc::encodeResponse(true);
        break;

    // ── wp.deletePage(blogid, username, password, page_id) ────────────────────
    case 'wp.deletePage':
        xmlrpc_auth($params, 1, 2);
        $page = Page::findById($db, (int) ($params[3] ?? 0));
        if ($page === null) {
            xmlrpc_fault(404, 'Page not found.');
        }
        $wasPublished = $page->status === 'published';
        $page->delete();
        $page->status = 'draft';
        $builder->buildPage($page);     // removes the static file
        if ($wasPublished) {
            $builder->buildIndex();     // refresh nav on all pages
        }
        echo XmlRpc::encodeResponse(true);
        break;

    // ── wp.getMediaLibrary(blogid, username, password[, filter]) ─────────────
    case 'wp.getMediaLibrary':
        xmlrpc_auth($params, 1, 2);
        $filter    = (array) ($params[3] ?? []);
        $numberRaw = isset($filter['number']) ? (int) $filter['number'] : 0;
        $limit     = $numberRaw > 0 ? $numberRaw : PHP_INT_MAX;
        $offset    = max(0, (int) ($filter['offset'] ?? 0));
        $rows    = $db->select(
            "SELECT * FROM media ORDER BY uploaded_at DESC LIMIT :lim OFFSET :off",
            ['lim' => $limit, 'off' => $offset]
        );
        $items = [];
        foreach ($rows as $row) {
            $url      = rtrim($siteUrl, '/') . '/media/' . $row['filename'];
            $isImage  = str_starts_with((string) $row['mime_type'], 'image/');
            $items[] = [
                'attachment_id'   => (string) ($row['id'] + MEDIA_ID_OFFSET),
                'date_created_gmt' => new \CMS\DateTimeValue(XmlRpc::isoDate($row['uploaded_at'])),
                'parent'          => 0,
                'link'            => $url,
                'title'           => $row['original_name'],
                'caption'         => '',
                'description'     => '',
                'metadata'        => [
                    'width'  => 0,
                    'height' => 0,
                    'file'   => $row['filename'],
                    'sizes'  => [],
                ],
                'thumbnail'       => $isImage ? $url : '',
                'mime_type'       => $row['mime_type'],
            ];
        }
        echo XmlRpc::encodeResponse($items);
        break;

    // ── wp.uploadFile(blogid, username, password, data) ───────────────────────
    case 'wp.uploadFile':
        xmlrpc_auth($params, 1, 2);
        $data         = (array) ($params[3] ?? []);
        $originalName = trim((string) ($data['name'] ?? ''));
        $mimeType     = trim((string) ($data['type'] ?? ''));
        $bits         = $data['bits'] ?? '';

        if ($originalName === '' || !is_string($bits) || $bits === '') {
            xmlrpc_fault(400, 'Invalid upload: name and bits are required.');
        }

        echo XmlRpc::encodeResponse(xmlrpc_save_media($originalName, $mimeType, $bits));
        break;

    // ── Unknown method ────────────────────────────────────────────────────────
    default:
        xmlrpc_fault(404, 'Unknown method: ' . $method);
}
