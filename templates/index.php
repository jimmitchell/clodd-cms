<?php
/**
 * Post listing / pagination template.
 * Variables: $posts (Post[]), $currentPage, $totalPages, $totalPosts,
 *            $settings, $navPages, $siteUrl, $render
 */

use CMS\Helpers;

$siteTitle   = $settings['site_title']       ?? 'My CMS';
$description = $settings['site_description'] ?? '';
$suffix      = $currentPage > 1 ? ' — Page ' . $currentPage : '';
$pageTitle   = $siteTitle . $suffix;
$canonical   = rtrim($siteUrl, '/') . ($currentPage === 1 ? '/' : '/page/' . $currentPage . '/');
$ogType      = 'website';

ob_start();
?>
<div class="post-list">
    <?php if (empty($posts)): ?>
    <p class="post-list__empty">Nothing published yet. Check back soon.</p>
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
        <?php if (!empty($post->photos)): ?>
        <div class="post-card__photos">
            <?php foreach ($post->photos as $photo): ?>
            <figure class="post-card__photo">
                <img class="u-photo" src="<?= htmlspecialchars($photo['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                     alt="<?= htmlspecialchars($photo['alt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" loading="lazy">
            </figure>
            <?php endforeach; ?>
        </div>
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
        <?php if (!empty($post->photos)): $cardPhoto = $post->photos[0]; ?>
        <figure class="post-card__photo post-card__photo--thumb">
            <img class="u-photo" src="<?= htmlspecialchars($cardPhoto['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                 alt="<?= htmlspecialchars($cardPhoto['alt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" loading="lazy">
        </figure>
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
$isHomepage  = ($currentPage === 1);

echo $render('base.php', compact(
    'pageTitle', 'description', 'canonical', 'ogType', 'bodyContent',
    'settings', 'navPages', 'siteUrl', 'render', 'isHomepage'
));
