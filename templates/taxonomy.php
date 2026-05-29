<?php
/**
 * Taxonomy archive template (categories and tags).
 * Variables: $type ('category'|'tag'), $term (assoc: id, name, slug, description?),
 *            $posts (Post[]), $currentPage, $totalPages, $totalPosts,
 *            $settings, $navPages, $siteUrl, $render
 */

use CMS\Helpers;

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

    <div class="post-list">
        <?php if (empty($posts)): ?>
        <p class="post-list__empty">No published posts yet.</p>
        <?php else: ?>

        <?php foreach ($posts as $post): ?>
        <?php $postUrl = rtrim($siteUrl, '/') . '/' . CMS\Post::datePath($post->published_at, $post->slug, $settings['timezone'] ?? '') . '/'; ?>
        <?php if ($post->isAside()): ?>
        <article class="post-card post-card--note h-entry">
            <?php if ($post->published_at): ?>
            <time class="post-card__date dt-published" datetime="<?= date('Y-m-d\TH:i:s\Z', strtotime($post->published_at)) ?>">
                <a class="u-url" href="<?= htmlspecialchars($postUrl) ?>"><?= Helpers::formatDate($post->published_at, 'l, F j, Y', $settings['locale'] ?? '', $settings['timezone'] ?? '') ?></a>
            </time>
            <?php endif; ?>
            <div class="post-card__body prose e-content"><?= $postHtml[$post->id] ?? '' ?></div>
        </article>
        <?php else: ?>
        <article class="post-card h-entry">
            <h2 class="post-card__title">
                <a href="<?= htmlspecialchars($postUrl) ?>" class="u-url p-name"><?= htmlspecialchars($post->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
            </h2>
            <?php if ($post->published_at): ?>
            <time class="post-card__date dt-published" datetime="<?= date('Y-m-d\TH:i:s\Z', strtotime($post->published_at)) ?>">
                <?= Helpers::formatDate($post->published_at, 'l, F j, Y', $settings['locale'] ?? '', $settings['timezone'] ?? '') ?>
            </time>
            <?php endif; ?>
            <?php $cardExcerpt = $post->effectiveExcerpt(); ?>
            <?php if ($cardExcerpt !== null): ?>
            <p class="post-card__excerpt p-summary"><?= htmlspecialchars(strip_tags($cardExcerpt), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php endif; ?>
        </article>
        <?php endif; ?>
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
    'settings', 'navPages', 'siteUrl', 'render', 'extraFeedLinks'
));
