<?php
// POST handler + GET-side data prep for the Import tab.
// Included from admin/tools.php after auth check. Exits on POST.

use CMS\Helpers;
use CMS\Media;
use CMS\Post;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');

    $kindMode = $_POST['kind_mode'] ?? 'auto';
    if (!in_array($kindMode, ['auto', 'aside', 'standard'], true)) {
        $kindMode = 'auto';
    }

    $downloadImages = !empty($_POST['download_images']);
    if ($downloadImages) {
        // Image fetching extends per-import time considerably.
        @set_time_limit(0);
    }
    $mediaInstance = $downloadImages
        ? new Media($db, CMS_ROOT . '/content/media', (int) ($config['media']['max_bytes'] ?? 52_428_800))
        : null;
    $siteUrlForImages = (string) $db->getSetting('site_url', '');
    /** @var array<string, array{url:string,reused:bool}|false> $imageCache */
    $imageCache = [];
    $imgDownloaded = 0;
    $imgReused     = 0;
    $imgErrors     = [];

    $upload = $_FILES['wxr_file'] ?? null;
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) {
        $auth->flash('No file uploaded or upload failed.', 'error');
        header('Location: /admin/tools.php?tab=import');
        exit;
    }

    $xmlBody = file_get_contents($upload['tmp_name']);
    if ($xmlBody === false || $xmlBody === '') {
        $auth->flash('Could not read the uploaded file.', 'error');
        header('Location: /admin/tools.php?tab=import');
        exit;
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlBody, \SimpleXMLElement::class, LIBXML_NOCDATA);
    if ($xml === false) {
        $errs = array_map(fn($e) => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        $auth->flash('Could not parse XML: ' . (empty($errs) ? 'unknown error' : implode('; ', array_slice($errs, 0, 3))), 'error');
        header('Location: /admin/tools.php?tab=import');
        exit;
    }

    if (!isset($xml->channel)) {
        $auth->flash('Not a valid WordPress WXR file (missing <channel>).', 'error');
        header('Location: /admin/tools.php?tab=import');
        exit;
    }

    // Status mapping: WXR → Clodd. 'trash' is filtered out before we get here.
    $statusMap = [
        'publish' => 'published',
        'draft'   => 'draft',
        'future'  => 'draft',
        'pending' => 'draft',
        'private' => 'draft',
    ];

    $imported  = 0;
    $skippedDup = 0;
    $skippedTyp = 0;  // non-post items (page, attachment, etc.)
    $skippedSt  = 0;  // trash, unknown status
    $errors     = [];

    // Single transaction: avoids 1 fsync per insert (orders of magnitude faster).
    $db->exec('BEGIN');

    try {
        foreach ($xml->channel->item as $item) {
            $wp = $item->children('wp', true);

            $type = (string) $wp->post_type;
            if ($type !== 'post') {
                $skippedTyp++;
                continue;
            }

            $rawStatus = (string) $wp->status;
            if ($rawStatus === 'trash') {
                $skippedSt++;
                continue;
            }
            $cloddStatus = $statusMap[$rawStatus] ?? 'draft';

            $guid = trim((string) $item->guid);
            $title = trim((string) $item->title);
            $wpPostName = trim((string) $wp->post_name);
            $wpPostDate = trim((string) $wp->post_date);
            $contentEnc = (string) $item->children('content', true)->encoded;
            $excerptEnc = (string) $item->children('excerpt', true)->encoded;
            $excerpt = trim($excerptEnc) !== '' ? trim($excerptEnc) : null;

            // Dedup against prior imports.
            if ($guid !== '') {
                $existing = $db->selectOne(
                    "SELECT id FROM posts WHERE import_guid = :g",
                    ['g' => $guid]
                );
                if ($existing !== null) {
                    $skippedDup++;
                    continue;
                }
            }

            // Determine post kind.
            if ($kindMode === 'aside') {
                $kind = 'aside';
            } elseif ($kindMode === 'standard') {
                $kind = 'standard';
            } else { // auto
                $kind = $title === '' ? 'aside' : 'standard';
            }

            // Determine published_at — fall back to current time if unparseable.
            $publishedAt = null;
            if ($wpPostDate !== '') {
                $ts = strtotime($wpPostDate);
                if ($ts !== false) {
                    $publishedAt = date('Y-m-d H:i:s', $ts);
                }
            }
            if ($publishedAt === null) {
                $publishedAt = date('Y-m-d H:i:s');
            }

            try {
                $post = new Post($db);
                $post->title         = $title;
                $post->content       = $contentEnc;
                $post->excerpt       = $excerpt;
                $post->status        = $cloddStatus;
                $post->published_at  = $publishedAt;
                $post->post_kind     = $kind;
                $post->mastodon_skip = 1;
                $post->bluesky_skip  = 1;

                // <wp:post_name> is untrusted: it ends up in posts.slug, which
                // Post::datePath() splices into the on-disk output path. Slugify
                // it before anything else so a value like "../../../etc" can't
                // walk out of the output root.
                $base = $wpPostName !== '' ? Helpers::slugify($wpPostName) : '';
                if ($base === 'untitled') {
                    $base = '';
                }

                if ($base === '') {
                    $base = $kind === 'aside'
                        ? Post::slugFromContent($contentEnc)
                        : Helpers::slugify($title);
                }
                if ($base === '' || $base === 'untitled') {
                    // A titleless, bodyless item has nothing to slug from. Fall back
                    // to the WXR post id: posts.slug is UNIQUE NOT NULL, so a batch
                    // loop can't leave it empty the way a single insert can.
                    $base = 'imported-' . (string) ((int) ($wp->post_id ?? 0) ?: time());
                }

                // resolveUniqueSlug() slugifies again, applies the digit-only
                // guard (legacy asides own the bare-id URLs), and dedupes.
                $post->slug = Post::resolveUniqueSlug($db, $base);

                $post->save();

                // Persist import_guid (Post::save() doesn't write this column).
                if ($guid !== '') {
                    $db->update('posts', ['import_guid' => $guid], 'id = :id', ['id' => $post->id]);
                }

                // Categories and tags: <category domain="category|post_tag" nicename="...">
                $categoryIds = [];
                $tagIds      = [];
                foreach ($item->category as $term) {
                    $attrs    = $term->attributes();
                    $domain   = (string) ($attrs['domain'] ?? '');
                    $nicename = trim((string) ($attrs['nicename'] ?? ''));
                    $name     = trim((string) $term);

                    if ($name === '' && $nicename === '') {
                        continue;
                    }

                    $slug = $nicename !== '' ? Helpers::slugify($nicename) : Helpers::slugify($name);
                    if ($slug === '' || $slug === 'untitled') {
                        continue;
                    }
                    if ($name === '') {
                        $name = $nicename;
                    }

                    if ($domain === 'category') {
                        $row = $db->selectOne("SELECT id FROM categories WHERE slug = :s", ['s' => $slug]);
                        $categoryIds[] = $row
                            ? (int) $row['id']
                            : $db->insert('categories', ['name' => $name, 'slug' => $slug, 'description' => '']);
                    } elseif ($domain === 'post_tag') {
                        $row = $db->selectOne("SELECT id FROM tags WHERE slug = :s", ['s' => $slug]);
                        $tagIds[] = $row
                            ? (int) $row['id']
                            : $db->insert('tags', ['name' => $name, 'slug' => $slug]);
                    }
                }

                if (!empty($categoryIds) || !empty($tagIds)) {
                    $post->saveTerms($categoryIds, $tagIds);
                }

                // Optional: download external <img> sources locally and rewrite
                // the post HTML. Failures don't abort — they leave the URL in
                // place and are reported in the summary.
                if ($mediaInstance !== null && $contentEnc !== '') {
                    $imgUrls = Media::extractExternalImageUrls($contentEnc, $siteUrlForImages);
                    if (!empty($imgUrls)) {
                        $localMap = [];
                        foreach ($imgUrls as $imgUrl) {
                            if (array_key_exists($imgUrl, $imageCache)) {
                                $cached = $imageCache[$imgUrl];
                                if ($cached !== false) {
                                    $localMap[$imgUrl] = $cached['url'];
                                }
                                continue;
                            }
                            try {
                                $r = $mediaInstance->ingestFromUrl($imgUrl);
                                $imageCache[$imgUrl] = $r;
                                $localMap[$imgUrl] = $r['url'];
                                if ($r['reused']) $imgReused++; else $imgDownloaded++;
                            } catch (\Throwable $imgE) {
                                $imageCache[$imgUrl] = false;
                                $imgErrors[] = "post {$post->id}: " . $imgE->getMessage();
                            }
                        }
                        if (!empty($localMap)) {
                            $newContent = Media::rewriteImageUrls($contentEnc, $localMap);
                            if ($newContent !== $contentEnc) {
                                $db->update(
                                    'posts',
                                    ['content' => $newContent, 'updated_at' => date('Y-m-d H:i:s')],
                                    'id = :id',
                                    ['id' => $post->id]
                                );
                            }
                        }
                    }
                }

                $imported++;
            } catch (\Throwable $e) {
                $wpId = (int) ($wp->post_id ?? 0);
                $errors[] = "wp:post_id={$wpId}: " . $e->getMessage();
            }
        }

        $db->exec('COMMIT');
    } catch (\Throwable $e) {
        $db->exec('ROLLBACK');
        $auth->flash('Import aborted: ' . $e->getMessage(), 'error');
        header('Location: /admin/tools.php?tab=import');
        exit;
    }

    // Build site outputs once at the end. rebuildPosts() rerenders every
    // published post (including the new ones); rebuildSharedResources()
    // refreshes index, feed, sitemap; buildAllTaxonomyArchives() picks up
    // any newly-created categories/tags.
    if ($imported > 0) {
        $builder->rebuildPosts();
        $builder->buildAllTaxonomyArchives();
        $builder->rebuildSharedResources();
    }

    $detail = "Imported {$imported}";
    if ($skippedDup > 0) $detail .= ", {$skippedDup} duplicate(s)";
    if ($skippedTyp > 0) $detail .= ", {$skippedTyp} non-post item(s) skipped";
    if ($skippedSt > 0)  $detail .= ", {$skippedSt} trashed item(s) skipped";
    if (!empty($errors)) $detail .= ", " . count($errors) . " error(s)";
    if ($downloadImages) {
        $detail .= "; images: {$imgDownloaded} downloaded, {$imgReused} reused";
        if (!empty($imgErrors)) $detail .= ', ' . count($imgErrors) . ' image error(s)';
    }

    $activityLog->log('import', 'batch', null, $detail);

    $flashType = (!empty($errors) || !empty($imgErrors)) ? 'error' : 'success';
    $flashMsg  = "WXR import: {$detail}.";
    if (!empty($errors)) {
        $flashMsg .= ' First errors: ' . implode(' | ', array_slice($errors, 0, 3));
    }
    if (!empty($imgErrors)) {
        $flashMsg .= ' First image errors: ' . implode(' | ', array_slice($imgErrors, 0, 3));
    }
    $auth->flash($flashMsg, $flashType);
    header('Location: /admin/tools.php?tab=import');
    exit;
}

// ── GET-side data prep ─────────────────────────────────────────────────────

$csrf = $auth->csrfToken();

$importedCount = (int) ($db->selectOne("SELECT COUNT(*) AS n FROM posts WHERE import_guid IS NOT NULL")['n'] ?? 0);
$totalPosts    = (int) ($db->selectOne("SELECT COUNT(*) AS n FROM posts")['n'] ?? 0);

$uploadMaxBytes  = trim((string) ini_get('upload_max_filesize'));
$postMaxBytes    = trim((string) ini_get('post_max_size'));
