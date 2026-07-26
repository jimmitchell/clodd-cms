<?php
/**
 * Taxonomy archive template (categories and tags).
 * Variables: $type ('category'|'tag'), $term (assoc: id, name, slug, description?),
 *            $posts (Post[]), $currentPage, $totalPages, $totalPosts,
 *            $settings, $navPages, $siteUrl, $render
 */

$siteTitle   = $settings['site_title'] ?? 'My CMS';
$termName    = htmlspecialchars($term['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$heading     = $type === 'category'
    ? 'Category: ' . $term['name']
    : 'Tag: ' . $term['name'];
$suffix      = $currentPage > 1 ? ' — Page ' . $currentPage : '';
$pageTitle   = $heading . $suffix . ' — ' . $siteTitle;
$description = ($type === 'category' && ($term['description'] ?? '') !== '')
    ? $term['description']
    : ($siteTitle . ' posts ' . ($type === 'category' ? 'in category ' : 'tagged ') . $term['name']);
$termBaseUrl = rtrim($siteUrl, '/') . '/' . $type . '/' . rawurlencode($term['slug']);
$canonical   = $currentPage === 1
    ? $termBaseUrl . '/'
    : $termBaseUrl . '/page/' . $currentPage . '/';
$ogType      = 'website';
$ogImageUrl  = '';
$label       = $type === 'category' ? 'Category' : 'Tag';
$termFeedBase = rtrim($siteUrl, '/') . '/' . $type . '/' . rawurlencode($term['slug']);
$extraFeedLinks = [
    [
        'type'  => 'application/atom+xml',
        'title' => ($settings['site_title'] ?? 'My CMS') . ' — ' . $label . ': ' . $term['name'],
        'href'  => $termFeedBase . '/feed.xml',
    ],
    [
        'type'  => 'application/rss+xml',
        'title' => ($settings['site_title'] ?? 'My CMS') . ' — ' . $label . ': ' . $term['name'],
        'href'  => $termFeedBase . '/feed.rss',
    ],
    [
        'type'  => 'application/feed+json',
        'title' => ($settings['site_title'] ?? 'My CMS') . ' — ' . $label . ': ' . $term['name'],
        'href'  => $termFeedBase . '/feed.json',
    ],
];

ob_start();
?>
<div class="taxonomy-archive">
    <header class="taxonomy-archive__header">
        <p class="taxonomy-archive__type"><?= $type === 'category' ? 'Category' : 'Tag' ?></p>
        <h1 class="taxonomy-archive__title"><?= $termName ?></h1>
        <?php if ($type === 'category' && ($term['description'] ?? '') !== ''): ?>
        <p class="taxonomy-archive__description"><?= htmlspecialchars($term['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php endif; ?>
    </header>

    <div class="post-list h-feed">
        <data class="p-name" value="<?= htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></data>
        <?php if (empty($posts)): ?>
        <p class="post-list__empty">No published posts yet.</p>
        <?php else: ?>

        <?php foreach ($posts as $post): ?>
        <?php include __DIR__ . '/partials/post-card.php'; ?>
        <?php endforeach; ?>

        <?php endif; ?>
    </div>

    <?php if ($totalPages > 1): ?>
    <nav class="pagination" aria-label="Pagination">
        <?php if ($currentPage > 1): ?>
        <a href="<?= $currentPage === 2 ? htmlspecialchars($termBaseUrl . '/') : htmlspecialchars($termBaseUrl . '/page/' . ($currentPage - 1) . '/') ?>"
           class="pagination__prev" rel="prev">← Newer</a>
        <?php else: ?>
        <span class="pagination__prev pagination__prev--disabled">← Newer</span>
        <?php endif; ?>

        <span class="pagination__info">Page <?= $currentPage ?> of <?= $totalPages ?></span>

        <?php if ($currentPage < $totalPages): ?>
        <a href="<?= htmlspecialchars($termBaseUrl . '/page/' . ($currentPage + 1) . '/') ?>"
           class="pagination__next" rel="next">Older →</a>
        <?php else: ?>
        <span class="pagination__next pagination__next--disabled">Older →</span>
        <?php endif; ?>
    </nav>
    <?php endif; ?>
</div>
<?php
$bodyContent = ob_get_clean();

echo $render('base.php', compact(
    'pageTitle', 'description', 'canonical', 'ogType', 'ogImageUrl', 'bodyContent',
    'settings', 'navPages', 'siteUrl', 'render', 'extraFeedLinks', 'criticalCss'
));
