<?php
/**
 * Post listing / pagination template.
 * Variables: $posts (Post[]), $currentPage, $totalPages, $totalPosts,
 *            $settings, $navPages, $siteUrl, $render
 */

$siteTitle   = $settings['site_title']       ?? 'My CMS';
$description = $settings['site_description'] ?? '';
$suffix      = $currentPage > 1 ? ' — Page ' . $currentPage : '';
$pageTitle   = $siteTitle . $suffix;
$canonical   = rtrim($siteUrl, '/') . ($currentPage === 1 ? '/' : '/page/' . $currentPage . '/');
$ogType      = 'website';
$isHomepage  = ($currentPage === 1);

ob_start();
?>
<?php if ($isHomepage): ?>
<?php include __DIR__ . '/partials/home-intro.php'; ?>
<?php endif; ?>

<div class="post-list h-feed">
    <data class="p-name" value="<?= htmlspecialchars($siteTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></data>
    <?php if (empty($posts)): ?>
    <p class="post-list__empty">Nothing published yet. Check back soon.</p>
    <?php else: ?>

    <?php foreach ($posts as $post): ?>
    <?php include __DIR__ . '/partials/post-card.php'; ?>
    <?php endforeach; ?>

    <?php endif; ?>
</div>

<?php if ($totalPages > 1): ?>
<nav class="pagination" aria-label="Pagination">
    <?php if ($currentPage > 1): ?>
    <a href="<?= $currentPage === 2 ? htmlspecialchars(rtrim($siteUrl, '/') . '/') : htmlspecialchars(rtrim($siteUrl, '/') . '/page/' . ($currentPage - 1) . '/') ?>"
       class="pagination__prev" rel="prev">← Newer</a>
    <?php else: ?>
    <span class="pagination__prev pagination__prev--disabled">← Newer</span>
    <?php endif; ?>

    <span class="pagination__info">Page <?= $currentPage ?> of <?= $totalPages ?></span>

    <?php if ($currentPage < $totalPages): ?>
    <a href="<?= htmlspecialchars(rtrim($siteUrl, '/') . '/page/' . ($currentPage + 1) . '/') ?>"
       class="pagination__next" rel="next">Older →</a>
    <?php else: ?>
    <span class="pagination__next pagination__next--disabled">Older →</span>
    <?php endif; ?>
</nav>
<?php endif; ?>
<?php
$bodyContent = ob_get_clean();

echo $render('base.php', compact(
    'pageTitle', 'description', 'canonical', 'ogType', 'bodyContent',
    'settings', 'navPages', 'siteUrl', 'render', 'isHomepage', 'criticalCss'
));
