<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
$auth->check();

use CMS\Page;
use CMS\Helpers;

// Handle quick-delete from the list.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $auth->verifyCsrf($_POST['csrf_token'] ?? '');
    $page = Page::findById($db, (int) ($_POST['id'] ?? 0));
    if ($page) {
        if (Page::hasChildren($db, $page->id)) {
            $auth->flash('Cannot delete "' . $page->title . '": it has sub-pages.', 'error');
            header('Location: /admin/pages.php');
            exit;
        }
        $wasPublished = $page->status === 'published';
        $page->delete();
        $page->status = 'draft'; // so buildPage() removes the static file
        $builder->buildPage($page);
        if ($wasPublished) {
            $builder->buildIndex();
            $builder->buildSitemap();
            $builder->rebuildPages(); // refresh nav on remaining pages
        }
    }
    header('Location: /admin/pages.php');
    exit;
}

$statusFilter = $_GET['status'] ?? 'all';
$search       = trim($_GET['q'] ?? '');
$pages        = Page::findAll($db, $statusFilter === 'all' ? null : $statusFilter);

if ($search !== '') {
    $pages = array_values(array_filter($pages, fn($p) => stripos($p->title, $search) !== false));
}

$counts = $db->selectOne(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status='published' THEN 1 ELSE 0 END) AS published,
        SUM(CASE WHEN status='draft'     THEN 1 ELSE 0 END) AS draft
     FROM pages"
);

$siteTitle = $db->getSetting('site_title', 'My CMS');
$csrf      = $auth->csrfToken();

// Build an id => title map of all pages (any status) for the Parent column.
// One query, no N+1; falls back to '—' if a parent isn't found.
$parentTitles = [];
foreach (Page::findAll($db) as $p) {
    $parentTitles[$p->id] = $p->title;
}

?>
<?php
$adminTitle = 'Pages — ' . $siteTitle;
require __DIR__ . '/partials/head.php';
?>
<body class="admin-page">

<?php require __DIR__ . '/partials/nav.php'; ?>

<main class="admin-main">
    <header class="page-header">
        <h1>Pages</h1>
        <a href="/admin/page-edit.php" class="btn">+ New page</a>
    </header>

    <div class="toolbar">
        <div class="filter-tabs">
            <?php
            $tabs = [
                'all'       => 'All (' . ($counts['total'] ?? 0) . ')',
                'published' => 'Published (' . ($counts['published'] ?? 0) . ')',
                'draft'     => 'Draft (' . ($counts['draft'] ?? 0) . ')',
            ];
            foreach ($tabs as $key => $label): ?>
            <a href="/admin/pages.php?status=<?= Helpers::e($key) ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>"
               class="<?= $statusFilter === $key ? 'active' : '' ?>">
                <?= Helpers::e($label) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <form method="get" action="/admin/pages.php" class="search-form" role="search">
            <?php if ($statusFilter !== 'all'): ?>
            <input type="hidden" name="status" value="<?= Helpers::e($statusFilter) ?>">
            <?php endif; ?>
            <input type="search" name="q" value="<?= Helpers::e($search) ?>"
                   placeholder="Search pages…" class="search-input" aria-label="Search pages">
            <?php if ($search !== ''): ?>
            <a href="/admin/pages.php?status=<?= Helpers::e($statusFilter) ?>" class="search-clear" aria-label="Clear search">✕</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="panel" style="padding:0">
        <?php if (empty($pages)): ?>
            <p style="padding:1.5rem; color:var(--color-muted)">
                <?= $search !== '' ? 'No pages match &ldquo;' . Helpers::e($search) . '&rdquo;.' : 'No pages found.' ?>
            </p>
        <?php else: ?>
        <table class="data-table data-table--pages">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Slug</th>
                    <th>Parent</th>
                    <th>Nav order</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pages as $page): ?>
            <tr>
                <td>
                    <a href="/admin/page-edit.php?id=<?= $page->id ?>">
                        <?= Helpers::e($page->title) ?>
                    </a>
                </td>
                <td class="meta">/<?= Helpers::e($page->slug) ?>/</td>
                <td class="meta">
                    <?= $page->parent_id !== null && isset($parentTitles[$page->parent_id])
                        ? Helpers::e($parentTitles[$page->parent_id])
                        : '&mdash;' ?>
                </td>
                <td class="meta"><?= $page->nav_order ?></td>
                <td>
                    <span class="badge badge--<?= $page->status ?>">
                        <?= $page->status ?>
                    </span>
                </td>
                <td>
                    <div class="actions">
                        <a href="/admin/page-edit.php?id=<?= $page->id ?>" class="btn btn--sm btn--secondary">Edit</a>
                        <?php if ($page->status === 'published'): ?>
                        <a href="/<?= Helpers::e($page->slug) ?>/" target="_blank" class="btn btn--sm btn--secondary">View</a>
                        <?php endif; ?>
                        <form method="post" action="/admin/pages.php"
                              onsubmit="return confirm(<?= json_encode('Delete "' . $page->title . '"?', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)">
                            <input type="hidden" name="csrf_token" value="<?= Helpers::e($csrf) ?>">
                            <input type="hidden" name="action"     value="delete">
                            <input type="hidden" name="id"         value="<?= $page->id ?>">
                            <button type="submit" class="btn btn--sm btn--danger">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</main>
<script src="/admin/assets/admin.js?v=<?= rawurlencode(CMS_VERSION) ?>"></script>
</body>
</html>
