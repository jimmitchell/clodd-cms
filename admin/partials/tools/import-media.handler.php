<?php
// POST handler + GET-side data prep for the Import-media tab.
// Included from admin/tools.php after auth check. Exits on POST.

use CMS\Media;

$mediaStorage = CMS_ROOT . '/content/media';
$mediaMaxBytes = (int) ($config['media']['max_bytes'] ?? 52_428_800);
$media = new Media($db, $mediaStorage, $mediaMaxBytes);

$siteUrl = (string) $db->getSetting('site_url', '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');

    @set_time_limit(0);

    $rows = $db->select(
        "SELECT id, content FROM posts WHERE content LIKE '%<img%' ORDER BY id ASC"
    );

    $downloaded   = 0;
    $reused       = 0;
    $postsUpdated = 0;
    $errors       = [];

    /** @var array<string, array{id:int,filename:string,url:string,reused:bool}|false> $cache */
    $cache = [];

    foreach ($rows as $row) {
        $postId = (int) $row['id'];
        $content = (string) $row['content'];

        $urls = Media::extractExternalImageUrls($content, $siteUrl);
        if (empty($urls)) {
            continue;
        }

        $urlMap = [];
        foreach ($urls as $url) {
            if (array_key_exists($url, $cache)) {
                $cached = $cache[$url];
                if ($cached !== false) {
                    $urlMap[$url] = $cached['url'];
                }
                continue;
            }

            try {
                $result = $media->ingestFromUrl($url);
                $cache[$url] = $result;
                $urlMap[$url] = $result['url'];
                if ($result['reused']) {
                    $reused++;
                } else {
                    $downloaded++;
                }
            } catch (\Throwable $e) {
                $cache[$url] = false;
                $errors[] = "post {$postId}: " . $e->getMessage();
            }
        }

        if (empty($urlMap)) {
            continue;
        }

        $newContent = Media::rewriteImageUrls($content, $urlMap);
        if ($newContent !== $content) {
            $db->update(
                'posts',
                ['content' => $newContent, 'updated_at' => date('Y-m-d H:i:s')],
                'id = :id',
                ['id' => $postId]
            );
            $postsUpdated++;
        }
    }

    if ($postsUpdated > 0) {
        // buildAllTaxonomyArchives() is not optional here: rebuildPosts() defers
        // the per-post archive rebuild, and this handler rewrites post *bodies*
        // (the image URLs in them), so the cards on every category and tag
        // archive are stale until something covers the terms.
        $builder->rebuildPosts();
        $builder->buildAllTaxonomyArchives();
        $builder->rebuildSharedResources();
    }

    $detail = "Media import: {$downloaded} downloaded, {$reused} reused, {$postsUpdated} post(s) updated";
    if (!empty($errors)) $detail .= ', ' . count($errors) . ' error(s)';
    $activityLog->log('import', 'media-batch', null, $detail);

    $flashType = !empty($errors) ? 'error' : 'success';
    $flashMsg  = $detail . '.';
    if (!empty($errors)) {
        $flashMsg .= ' First errors: ' . implode(' | ', array_slice($errors, 0, 3));
    }
    $auth->flash($flashMsg, $flashType);
    header('Location: /admin/tools.php?tab=import-media');
    exit;
}

// ── Stats for the GET render ──────────────────────────────────────────────────

$postsWithImg = (int) ($db->selectOne(
    "SELECT COUNT(*) AS n FROM posts WHERE content LIKE '%<img%'"
)['n'] ?? 0);

$rehostedCount = (int) ($db->selectOne(
    "SELECT COUNT(*) AS n FROM media WHERE source_url IS NOT NULL"
)['n'] ?? 0);

// Distinct external image URLs across all posts. Cap at counting (we don't
// list them here) so the page stays fast even with thousands of posts.
$distinctExternal = 0;
$postsAffected    = 0;
$seen = [];
$rows = $db->select("SELECT content FROM posts WHERE content LIKE '%<img%'");
foreach ($rows as $r) {
    $urls = Media::extractExternalImageUrls((string) $r['content'], $siteUrl);
    if (!empty($urls)) {
        $postsAffected++;
        foreach ($urls as $u) $seen[$u] = true;
    }
}
$distinctExternal = count($seen);
unset($rows, $seen);

$csrf = $auth->csrfToken();
