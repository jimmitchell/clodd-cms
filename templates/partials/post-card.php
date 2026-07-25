<?php
/**
 * One h-entry card in a post list. Shared by index.php and taxonomy.php so the
 * three post kinds can only ever be styled in one place.
 *
 * Required in scope:
 *   $post      CMS\Post   the post to render
 *   $postHtml  array      note bodies pre-rendered by Builder::renderNoteHtmlMap()
 *   $settings  array
 *   $siteUrl   string
 *
 * The three kinds differ structurally, not decoratively:
 *   aside  — tinted, compact, body in full, no footer
 *   photo  — image bleeds to the card edge, caption block beneath
 *   article— title, excerpt, and a footer rule carrying date + reading time
 */

use CMS\Helpers;
use CMS\Post;

$cardUrl  = rtrim($siteUrl, '/') . '/' . Post::datePath($post->published_at, $post->slug, $settings['timezone'] ?? '') . '/';
$cardIso  = $post->published_at ? date('Y-m-d\TH:i:s\Z', strtotime($post->published_at)) : '';
$cardDate = $post->published_at
    ? Helpers::formatDate($post->published_at, 'l, F j, Y', $settings['locale'] ?? '', $settings['timezone'] ?? '')
    : '';
$isPhoto  = $post->isPhoto();
$isNote   = $post->isNote();

$cardClass = 'post-card h-entry post-card--'
    . ($isPhoto ? 'photo' : ($post->isAside() ? 'note' : 'article'));
?>
<article class="<?= $cardClass ?>">

    <?php if ($isPhoto && !empty($post->photos)): ?>
    <?php /* The photo is the post: it leads, full-bleed to the card's edge. */ ?>
    <div class="post-card__gallery" data-count="<?= count($post->photos) ?>">
        <?php foreach ($post->photos as $photo): ?>
        <img class="u-photo" src="<?= Helpers::e($photo['url']) ?>"
             alt="<?= Helpers::e($photo['alt']) ?>" loading="lazy" decoding="async">
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="post-card__inner">

        <?php if (!$isNote && $post->title !== ''): ?>
        <h2 class="post-card__title">
            <a href="<?= Helpers::e($cardUrl) ?>" class="u-url p-name"><?= Helpers::e($post->title) ?></a>
        </h2>
        <?php endif; ?>

        <?php if ($isNote && !$isPhoto && $cardDate !== ''): ?>
        <?php /* Notes have no title, so the date carries the permalink. On a
                 photo post the date moves below the picture instead — see the
                 matching block at the end of this card. */ ?>
        <time class="post-card__date dt-published" datetime="<?= $cardIso ?>">
            <a class="u-url" href="<?= Helpers::e($cardUrl) ?>"><?= $cardDate ?></a>
        </time>
        <?php endif; ?>

        <?php if (!empty($post->contexts)): ?>
        <div class="post-card__contexts"><?= Post::contextsHtml($post->contexts) ?></div>
        <?php endif; ?>

        <?php if ($post->isAside() && !empty($post->photos)): ?>
        <?php /* An aside that happens to carry images keeps them inside the padding. */ ?>
        <div class="post-card__photos">
            <?php foreach ($post->photos as $photo): ?>
            <figure class="post-card__photo">
                <img class="u-photo" src="<?= Helpers::e($photo['url']) ?>"
                     alt="<?= Helpers::e($photo['alt']) ?>" loading="lazy" decoding="async">
            </figure>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!$isNote && !empty($post->photos)): $cardPhoto = $post->photos[0]; ?>
        <?php /* A titled post's image illustrates rather than leads: thumbnail. */ ?>
        <figure class="post-card__photo post-card__photo--thumb">
            <img class="u-photo" src="<?= Helpers::e($cardPhoto['url']) ?>"
                 alt="<?= Helpers::e($cardPhoto['alt']) ?>" loading="lazy" decoding="async">
        </figure>
        <?php endif; ?>

        <?php if ($isNote): ?>
        <?php $noteBody = $postHtml[$post->id] ?? ''; ?>
        <?php if (trim($noteBody) !== ''): ?>
        <div class="post-card__body prose e-content"><?= $noteBody ?></div>
        <?php endif; ?>

        <?php /* A photo post's picture must be the first thing on the card. When
                 it comes from attached u-photo rows the gallery above handles
                 that, but an image written inline in the body sits after
                 whatever precedes it — so on photo posts the date goes last in
                 both cases, reading as a credit line under the picture. */ ?>
        <?php if ($isPhoto && $cardDate !== ''): ?>
        <time class="post-card__date post-card__date--after dt-published" datetime="<?= $cardIso ?>">
            <a class="u-url" href="<?= Helpers::e($cardUrl) ?>"><?= $cardDate ?></a>
        </time>
        <?php endif; ?>

        <?php else: ?>
        <?php $cardExcerpt = $post->effectiveExcerpt(); ?>
        <?php if ($cardExcerpt !== null): ?>
        <p class="post-card__excerpt p-summary"><?= Helpers::e(strip_tags($cardExcerpt)) ?></p>
        <?php endif; ?>

        <?php /* The footer rule is the article's tell: only a long-form post has
                 further to go, so only a long-form post advertises how far. */ ?>
        <footer class="post-card__meta">
            <?php if ($cardDate !== ''): ?>
            <time class="dt-published" datetime="<?= $cardIso ?>"><?= $cardDate ?></time>
            <?php endif; ?>
            <span class="post-card__readtime"><?= Helpers::readingTime($post->content) ?> min read →</span>
        </footer>
        <?php endif; ?>

    </div>
</article>
