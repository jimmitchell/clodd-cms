<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth->check();

use CMS\Post;
use CMS\Helpers;

// Handle quick-delete from the list.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');
    $post = Post::findById($db, (int) ($_POST['id'] ?? 0));
    if ($post) {
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
    }
    header('Location: /admin/posts.php');
    exit;
}

// Restore a soft-deleted post (deleted via Micropub).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');
    $post = Post::findById($db, (int) ($_POST['id'] ?? 0));
    if ($post && $post->deleted_at !== null) {
        $post->restore();
        if ($post->status === 'published') {
            $builder->buildPost($post);
            if ($p = Post::findPrev($db, $post)) $builder->buildPost($p);
            if ($n = Post::findNext($db, $post)) $builder->buildPost($n);
            $builder->rebuildSharedResources();
        }
    }
    header('Location: /admin/posts.php?status=deleted');
    exit;
}

$statusFilter = $_GET['status'] ?? 'all';
$search       = trim($_GET['q'] ?? '');
$posts        = $statusFilter === 'deleted'
    ? Post::findDeleted($db)
    : Post::findAll($db, $statusFilter === 'all' ? null : $statusFilter);

// Apply title search filter.
if ($search !== '') {
    $posts = array_values(array_filter($posts, fn($p) => stripos($p->title, $search) !== false));
}

// When showing all statuses, float drafts to the top.
if ($statusFilter === 'all') {
    usort($posts, function ($a, $b) {
        $aWeight = $a->status === 'draft' ? 0 : 1;
        $bWeight = $b->status === 'draft' ? 0 : 1;
        if ($aWeight !== $bWeight) {
            return $aWeight - $bWeight;
        }
        // Within the same group keep the existing date-descending order.
        $aDate = $a->published_at ?? $a->created_at;
        $bDate = $b->published_at ?? $b->created_at;
        return strcmp($bDate, $aDate);
    });
}

$counts = $db->selectOne(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status='published' THEN 1 ELSE 0 END) AS published,
        SUM(CASE WHEN status='draft'     THEN 1 ELSE 0 END) AS draft,
        SUM(CASE WHEN status='scheduled' THEN 1 ELSE 0 END) AS scheduled
     FROM posts
    WHERE deleted_at IS NULL"
);
$deletedCount = (int) ($db->selectOne(
    "SELECT COUNT(*) AS cnt FROM posts WHERE deleted_at IS NOT NULL"
)['cnt'] ?? 0);

// Pagination.
$perPage    = 20;
$totalPosts = count($posts);
$totalPages = max(1, (int) ceil($totalPosts / $perPage));
$page       = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$posts      = array_slice($posts, ($page - 1) * $perPage, $perPage);

// Base href for pagination links (preserves status + search, resets page).
$_paginationBase = '/admin/posts.php?' . http_build_query(array_filter([
    'status' => $statusFilter !== 'all' ? $statusFilter : '',
    'q'      => $search,
], fn($v) => $v !== ''));
$_paginationBase .= $_paginationBase !== '/admin/posts.php?' ? '&' : '';

$siteTitle = $db->getSetting('site_title', 'My CMS');
$timezone  = $db->getSetting('timezone', '');
$csrf      = $auth->csrfToken();
$flash     = $auth->getFlash();
$flashMsg  = $flash['message'] ?? '';
$flashType = $flash['type']    ?? 'success';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Posts — <?= Helpers::e($siteTitle) ?></title>
    <link rel="stylesheet" href="/admin/assets/admin.css">
</head>
<body class="admin-page">

<?php require __DIR__ . '/partials/nav.php'; ?>

<main class="admin-main">
    <header class="page-header">
        <h1>Posts</h1>
        <a href="/admin/post-edit.php" class="btn">+ New post</a>
    </header>

    <?php if ($flashMsg !== ''): ?>
        <p class="alert alert--<?= Helpers::e($flashType) ?>"><?= Helpers::e($flashMsg) ?></p>
    <?php endif; ?>

    <div class="toolbar">
        <div class="filter-tabs">
            <?php
            $tabs = [
                'all'       => 'All (' . ($counts['total'] ?? 0) . ')',
                'published' => 'Published (' . ($counts['published'] ?? 0) . ')',
                'draft'     => 'Draft (' . ($counts['draft'] ?? 0) . ')',
                'scheduled' => 'Scheduled (' . ($counts['scheduled'] ?? 0) . ')',
            ];
            if ($deletedCount > 0 || $statusFilter === 'deleted') {
                $tabs['deleted'] = 'Deleted (' . $deletedCount . ')';
            }
            foreach ($tabs as $key => $label):
                $tabHref = '/admin/posts.php?status=' . $key . ($search !== '' ? '&q=' . urlencode($search) : '');
            ?>
            <a href="<?= Helpers::e($tabHref) ?>"
               class="<?= $statusFilter === $key ? 'active' : '' ?>">
                <?= Helpers::e($label) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <form method="get" action="/admin/posts.php" class="search-form" role="search">
            <?php if ($statusFilter !== 'all'): ?>
            <input type="hidden" name="status" value="<?= Helpers::e($statusFilter) ?>">
            <?php endif; ?>
            <input type="search" name="q" value="<?= Helpers::e($search) ?>"
                   placeholder="Search posts…" class="search-input" aria-label="Search posts">
            <?php if ($search !== ''): ?>
            <a href="/admin/posts.php?status=<?= Helpers::e($statusFilter) ?>" class="search-clear" aria-label="Clear search">✕</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="panel" style="padding:0">
        <?php if (empty($posts)): ?>
            <p style="padding:1.5rem; color:var(--color-muted)">
                <?= $search !== '' ? 'No posts match &ldquo;' . Helpers::e($search) . '&rdquo;.' : 'No posts found.' ?>
            </p>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Status</th>
                    <th>Published</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($posts as $post): ?>
            <tr>
                <td>
                    <a href="/admin/post-edit.php?id=<?= $post->id ?>">
                        <?php if ($post->isAside()): ?>
                            <span class="badge badge--kind-aside">Aside</span>
                        <?php endif; ?>
                        <?php if (trim($post->title) !== ''): ?>
                            <?= Helpers::e($post->title) ?>
                        <?php else: ?>
                            <em class="post-untitled"><?= Helpers::e(Helpers::truncate($post->content, 80)) ?></em>
                        <?php endif; ?>
                    </a>
                </td>
                <td>
                    <?php if ($post->deleted_at !== null): ?>
                    <span class="badge badge--draft">deleted</span>
                    <?php else: ?>
                    <span class="badge badge--<?= $post->status ?>">
                        <?= $post->status ?>
                    </span>
                    <?php endif; ?>
                </td>
                <td class="meta">
                    <?= $post->published_at ? Helpers::formatDate($post->published_at, 'M j, Y g:i a', '', $timezone) : '—' ?>
                </td>
                <td>
                    <div class="actions">
                        <?php if ($post->deleted_at !== null): ?>
                        <form method="post" action="/admin/posts.php">
                            <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
                            <input type="hidden" name="action"     value="restore">
                            <input type="hidden" name="id"         value="<?= $post->id ?>">
                            <button type="submit" class="btn btn--sm btn--secondary">Restore</button>
                        </form>
                        <form method="post" action="/admin/posts.php"
                              onsubmit="return confirm(<?= json_encode('Permanently delete "' . $post->title . '"? This cannot be undone.', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)">
                            <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
                            <input type="hidden" name="action"     value="delete">
                            <input type="hidden" name="id"         value="<?= $post->id ?>">
                            <button type="submit" class="btn btn--sm btn--danger">Delete permanently</button>
                        </form>
                        <?php else: ?>
                        <a href="/admin/post-edit.php?id=<?= $post->id ?>" class="btn btn--sm btn--secondary">Edit</a>
                        <?php if ($post->status === 'published'): ?>
                        <a href="/<?= Helpers::e(Post::datePath($post->published_at, $post->slug, $timezone)) ?>/" target="_blank" class="btn btn--sm btn--secondary">View</a>
                        <?php endif; ?>
                        <form method="post" action="/admin/posts.php"
                              onsubmit="return confirm(<?= json_encode('Delete "' . $post->title . '"?', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)">
                            <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
                            <input type="hidden" name="action"     value="delete">
                            <input type="hidden" name="id"         value="<?= $post->id ?>">
                            <button type="submit" class="btn btn--sm btn--danger">Delete</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <?php $_paginTotal = $totalPosts; $_paginLabel = 'post'; ?>
    <?php require __DIR__ . '/partials/pagination.php'; ?>
</main>
<script src="/admin/assets/admin.js"></script>
</body>
</html>
