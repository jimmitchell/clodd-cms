<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth->check();

use CMS\Media;
use CMS\Helpers;

$media = new Media(
    $db,
    CMS_ROOT . '/content/media',
    (int) ($config['media']['max_bytes'] ?? 52_428_800)
);

$jsonResponse = fn(array $payload, int $status = 200) => (function () use ($payload, $status) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
})();

$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
       || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

// ── Handle upload (AJAX or regular POST) ─────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');

    $results  = [];
    $failures = [];

    $files = $_FILES['files'] ?? [];

    // Normalise the multi-file $_FILES structure into a list of single-file arrays.
    if (isset($files['name']) && is_array($files['name'])) {
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $single = [
                'name'     => $files['name'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'size'     => $files['size'][$i],
                'error'    => $files['error'][$i],
            ];
            try {
                $result    = $media->upload($single);
                $results[] = $result;
                $activityLog->log('upload', 'media', $result['id'], $single['name']);
            } catch (\RuntimeException $e) {
                $failures[] = $files['name'][$i] . ': ' . $e->getMessage();
            }
        }
    } elseif (isset($files['name'])) {
        // Single file, non-array structure.
        try {
            $result    = $media->upload($files);
            $results[] = $result;
            $activityLog->log('upload', 'media', $result['id'], $files['name'] ?? '');
        } catch (\RuntimeException $e) {
            $failures[] = ($files['name'] ?? 'file') . ': ' . $e->getMessage();
        }
    }

    if ($isAjax) {
        $jsonResponse([
            'uploaded' => $results,
            'errors'   => $failures,
        ], empty($results) ? 422 : 200);
    }

    // Non-AJAX redirect.
    $qs = empty($failures) ? '?uploaded=' . count($results) : '?error=' . urlencode(implode(' ', $failures));
    header('Location: /admin/media.php' . $qs);
    exit;
}

// ── Handle delete ─────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');
    $id         = (int) ($_POST['id'] ?? 0);
    $mediaItem  = $db->selectOne("SELECT original_name FROM media WHERE id = :id", ['id' => $id]);
    $ok         = $media->delete($id);
    if ($ok) {
        $activityLog->log('delete', 'media', $id, $mediaItem['original_name'] ?? '');
    }

    if ($isAjax) {
        $jsonResponse(['ok' => $ok], $ok ? 200 : 404);
    }

    header('Location: /admin/media.php');
    exit;
}

// ── Render page ───────────────────────────────────────────────────────────────

$items     = $media->all();
$siteTitle = $db->getSetting('site_title', 'My CMS');
$csrf      = $auth->csrfToken();

$flash      = '';
$flashType  = 'success';
if (isset($_GET['uploaded'])) {
    $n     = (int) $_GET['uploaded'];
    $flash = $n === 1 ? '1 file uploaded.' : "{$n} files uploaded.";
} elseif (isset($_GET['error'])) {
    $flash     = urldecode($_GET['error']);
    $flashType = 'error';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Media — <?= Helpers::e($siteTitle) ?></title>
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="admin-page">

<?php require __DIR__ . '/partials/nav.php'; ?>

<main class="admin-main">
    <header class="page-header">
        <h1>Media Library</h1>
        <span class="meta"><?= count($items) ?> file<?= count($items) !== 1 ? 's' : '' ?></span>
    </header>

    <?php if ($flash !== ''): ?>
        <p class="alert alert--<?= $flashType ?>" id="flash-msg"><?= Helpers::e($flash) ?></p>
    <?php endif; ?>

    <!-- Upload zone -->
    <div class="upload-zone" id="upload-zone">
        <div class="upload-zone__inner" id="drop-target">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="17 8 12 3 7 8"/>
                <line x1="12" y1="3" x2="12" y2="15"/>
            </svg>
            <p>Drag files here, or <label for="file-input" class="upload-zone__browse">browse</label></p>
            <p class="form-hint">Images, video, audio — max <?= round(($config['media']['max_bytes'] ?? 52_428_800) / 1_048_576) ?> MB each</p>
        </div>

        <form id="upload-form" method="post" action="/admin/media.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
            <input type="hidden" name="action"     value="upload">
            <input type="file"   name="files[]"    id="file-input"
                   multiple
                   accept="<?= Helpers::e(Media::acceptAttribute()) ?>"
                   style="display:none">
        </form>

        <!-- Upload progress list -->
        <ul class="upload-progress-list" id="upload-progress-list"></ul>
    </div>

    <!-- Media grid -->
    <?php if (empty($items)): ?>
        <p style="color:var(--color-muted); margin-top:1.5rem">No files uploaded yet.</p>
    <?php else: ?>
    <div class="media-library-grid" id="media-library-grid">
        <?php foreach ($items as $item): ?>
        <?php
            $url     = '/media/' . rawurlencode($item['filename']);
            $isImage = Media::isImage($item['mime_type']);
            $isVideo = Media::isVideo($item['mime_type']);
        ?>
        <div class="media-card" data-id="<?= (int) $item['id'] ?>">
            <div class="media-card__thumb">
                <?php if ($isImage): ?>
                    <?php /* Through ImageTag so the grid pulls the 800px WebP
                             companion rather than a full-size original per card
                             — this page shows every item in the library at
                             once, so the originals added up to more than the
                             rest of the admin put together. */ ?>
                    <?= CMS\ImageTag::render(
                        $config['paths']['content'] . '/media',
                        $url,
                        $item['original_name'],
                        ['sizes' => '200px']
                    ) ?>
                <?php elseif ($isVideo): ?>
                    <span class="media-card__icon">▶</span>
                <?php else: ?>
                    <span class="media-card__icon">♪</span>
                <?php endif; ?>
            </div>
            <div class="media-card__info">
                <span class="media-card__name" title="<?= Helpers::e($item['original_name']) ?>">
                    <?= Helpers::e($item['original_name']) ?>
                </span>
                <span class="media-card__meta">
                    <?= Media::formatBytes((int) $item['size']) ?>
                    · <?= strtoupper(pathinfo($item['filename'], PATHINFO_EXTENSION)) ?>
                </span>
            </div>
            <div class="media-card__actions">
                <button type="button"
                        class="btn btn--sm btn--secondary js-copy-url"
                        data-url="<?= Helpers::e($url) ?>"
                        title="Copy URL">
                    Copy URL
                </button>
                <button type="button"
                        class="btn btn--sm btn--danger js-delete"
                        data-id="<?= (int) $item['id'] ?>"
                        data-name="<?= Helpers::e($item['original_name']) ?>"
                        data-csrf="<?= Helpers::e($csrf) ?>"
                        title="Delete">
                    Delete
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<script src="/admin/assets/admin.js"></script>
<script src="/admin/assets/media.js"></script>

</body>
</html>
