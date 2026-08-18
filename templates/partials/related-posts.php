<?php
/**
 * The "Related posts" block at the foot of a titled post.
 *
 * Required in scope:
 *   $relatedPosts  CMS\Post[]  already scored and limited by Post::findRelated()
 *   $settings      array
 *   $siteUrl       string
 *   $mediaDir      string      for CMS\ImageTag::render()
 *
 * Deliberately carries no microformats classes, unlike partials/post-card.php.
 * That partial marks each card as an h-entry with u-url and p-name, which is
 * right on a listing page and wrong here: a nested h-entry inside this page's
 * own h-entry parses as a *child* entry — a reply or comment — so a webmention
 * consumer or Bridgy reading this post would see three phantom responses to it.
 * The block is navigation, and navigation is not content.
 *
 * The block is only included when $relatedPosts is non-empty, so there is no
 * empty-state branch here: a post with nothing related shows nothing at all,
 * not a heading over a gap.
 */

use CMS\Helpers;
use CMS\Post;
?>
<section class="related-posts" aria-labelledby="related-posts-title">
    <h2 class="related-posts__title" id="related-posts-title">Related posts</h2>
    <ul class="related-posts__list">
        <?php foreach ($relatedPosts as $relatedPost): ?>
        <?php
            $relatedUrl = rtrim($siteUrl, '/') . '/'
                . Post::datePath($relatedPost->published_at, $relatedPost->slug, $settings['timezone'] ?? '') . '/';
            /* $relatedPost->photos, not effectivePhotos(): that helper only
               derives images from the body for post_kind 'photo', which is
               excluded from this list anyway, so it could only ever return the
               same attached rows by a longer route. */
            $relatedThumb = $relatedPost->photos[0] ?? null;
        ?>
        <li class="related-posts__item">
            <?php if ($relatedThumb !== null): ?>
            <div class="related-posts__thumb">
                <?= CMS\ImageTag::render($mediaDir, $relatedThumb['url'], $relatedThumb['alt'], [
                    // Three columns inside a 740px measure, one column on a phone.
                    'sizes' => '(max-width: 600px) 100vw, 240px',
                ]) ?>
            </div>
            <?php endif; ?>
            <a class="related-posts__link" href="<?= Helpers::e($relatedUrl) ?>"><?= Helpers::e($relatedPost->title) ?></a>
            <?php if ($relatedPost->published_at): ?>
            <time class="related-posts__date" datetime="<?= date('Y-m-d\TH:i:s\Z', strtotime($relatedPost->published_at)) ?>"><?=
                Helpers::formatDate($relatedPost->published_at, 'F j, Y', $settings['locale'] ?? '', $settings['timezone'] ?? '')
            ?></time>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</section>
