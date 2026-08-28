<?php
/**
 * Single post template.
 * Variables: $post (Post), $html (rendered Markdown), $settings, $navPages, $siteUrl, $render,
 *            $legacyPaths (datePath segments this post has moved away from)
 */

use CMS\Helpers;

$siteTitle   = $settings['site_title'] ?? 'My CMS';
// Notes (asides and photo posts) carry no title: no h1, no byline, no reading
// time, and the site title stands in for the page title.
$isNote      = $post->isNote();
$pageTitle   = $isNote ? $siteTitle : ($post->title . ' — ' . $siteTitle);
$effectiveExcerpt = $post->effectiveExcerpt();
$description = $effectiveExcerpt !== null
    ? strip_tags($effectiveExcerpt)
    : Helpers::truncate($html, 160);
$canonical   = rtrim($siteUrl, '/') . '/' . CMS\Post::datePath($post->published_at, $post->slug, $settings['timezone'] ?? '') . '/';
$ogType      = 'article';

// JSON-LD structured data (BlogPosting). Notes have no title; use the
// derived description as the headline so the schema stays valid.
$authorName = $settings['author_name'] ?? '';
$jsonLdData = [
    '@context'      => 'https://schema.org',
    '@type'         => 'BlogPosting',
    'headline'      => $isNote ? $description : $post->title,
    'description'   => $description,
    'url'           => $canonical,
    'datePublished' => date('Y-m-d\TH:i:s\Z', strtotime($post->published_at)),
    'dateModified'  => date('Y-m-d\TH:i:s\Z', strtotime($post->updated_at)),
    'publisher'     => ['@type' => 'Organization', 'name' => $siteTitle, 'url' => $siteUrl . '/'],
];
if ($authorName !== '') {
    $jsonLdData['author'] = ['@type' => 'Person', 'name' => $authorName];
}
if ($ogImageUrl !== '') {
    $jsonLdData['image'] = $ogImageUrl;
}
$jsonLd = json_encode($jsonLdData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP);

// Reading time estimate.
$readingTime = Helpers::readingTime($html);

ob_start();
?>
<article class="post<?= $isNote ? ' post--note' : '' ?><?= $post->isPhoto() ? ' post--photo' : '' ?> h-entry">
    <header class="post__header">
        <?php if (!$isNote): ?>
        <h1 class="post__title p-name"><?= htmlspecialchars($post->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
        <a class="post__author p-author h-card" href="<?= htmlspecialchars($siteUrl . '/', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" rel="author"><?= htmlspecialchars($authorName !== '' ? $authorName : $siteTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
        <?php endif; ?>
        <?php if ($post->published_at): ?>
        <time class="post__date dt-published" datetime="<?= date('Y-m-d\TH:i:s\Z', strtotime($post->published_at)) ?>">
            <a class="u-url" href="<?= htmlspecialchars($canonical, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= Helpers::formatDate($post->published_at, 'F j, Y', $settings['locale'] ?? '', $settings['timezone'] ?? '') ?></a>
        </time>
        <?php if (!$isNote): ?>
        <span class="post__reading-time"><?= $readingTime ?> min read</span>
        <?php endif; ?>
        <?php if ($post->updated_at && $post->updated_at !== $post->published_at): ?>
        <time class="u-update" datetime="<?= date('Y-m-d\TH:i:s\Z', strtotime($post->updated_at)) ?>" hidden></time>
        <?php endif; ?>
        <?php endif; ?>
        <?php
            $terms = [];
            foreach ($post->categories as $cat) {
                $terms[] = ['url' => '/category/' . rawurlencode($cat['slug']) . '/', 'label' => $cat['name'], 'type' => 'category'];
            }
            foreach ($post->tags as $tag) {
                $terms[] = ['url' => '/tag/' . rawurlencode($tag['slug']) . '/', 'label' => $tag['name'], 'type' => 'tag'];
            }
            usort($terms, fn($a, $b) =>
                ($a['type'] === $b['type'])
                    ? strcmp($a['label'], $b['label'])
                    : ($a['type'] === 'category' ? -1 : 1)
            );
        ?>
        <?php if (!empty($terms)): ?>
        <ul class="post__terms">
            <?php foreach ($terms as $term): ?>
            <li><a href="<?= htmlspecialchars($term['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                   class="term term--<?= $term['type'] ?> p-category"><?= htmlspecialchars($term['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </header>

    <?php /* The featured image leads a titled post: after the metadata, before
             the words, at the column width — the place the picture already sits
             on every post written before the field existed.

             $post->featured_image_url, deliberately, and not
             effectiveFeaturedImage(): that helper falls back to the body's
             leading image, which is already about to be rendered as part of
             $html a few lines below. Reading it here would draw the picture
             twice. Only the *stored* image has a slot of its own. */ ?>
    <?php if (!$isNote && ($post->featured_image_url ?? '') !== ''): ?>
    <?php
        /* Above the fold on a post that has one, so this is the Largest
           Contentful Paint element and must not be lazy-loaded. */
        $featuredAttrs = [
            // u-featured, not u-photo: an article's lead picture is not its
            // photo property, and saying so changes how a client reads its type.
            'class'         => 'u-featured',
            'sizes'         => '(max-width: 44rem) 100vw, 44rem',
            'loading'       => 'eager',
            'fetchpriority' => 'high',
            'decoding'      => 'sync',
        ];
        /* Same backstop as the photos below — without a usable href there is
           nothing to link to, so the figure renders the picture alone. */
        $featuredHref = Helpers::safeUrl($post->featured_image_url);
    ?>
    <figure class="post__featured">
        <?php if ($featuredHref !== ''): ?>
        <a href="<?= Helpers::e($featuredHref) ?>" class="post__featured-link" data-gallery-item>
            <?= CMS\ImageTag::render($mediaDir, $post->featured_image_url, $post->featured_image_alt, $featuredAttrs) ?>
        </a>
        <?php else: ?>
        <?= CMS\ImageTag::render($mediaDir, $post->featured_image_url, $post->featured_image_alt, $featuredAttrs) ?>
        <?php endif; ?>
    </figure>
    <?php endif; ?>

    <?php if (!empty($post->contexts)): ?>
    <div class="post__contexts"><?= CMS\Post::contextsHtml($post->contexts) ?></div>
    <?php endif; ?>
    <?php if (!empty($post->photos)): ?>
    <?php /* Contract: data-gallery-item is what theme.js binds the lightbox to.
             The href is the full-size original, so the link still works without
             JS. Attached photos are shown at the column width, and a reader who
             came for the pictures should be able to open them — the same
             affordance an image written into the body has. */ ?>
    <div class="post__photos" data-count="<?= count($post->photos) ?>">
        <?php foreach ($post->photos as $photoIndex => $photo): ?>
        <?php
            /* On a photo post's own page the first picture is the Largest
               Contentful Paint element, so it must not be lazy-loaded. The
               rest of the set stays deferred. */
            $imgAttrs = [
                'class' => 'u-photo',
                'sizes' => '(max-width: 44rem) 100vw, 44rem',
            ];
            if ($photoIndex === 0) {
                $imgAttrs['loading']       = 'eager';
                $imgAttrs['fetchpriority'] = 'high';
                $imgAttrs['decoding']      = 'sync';
            }
        ?>
        <?php /* Micropub refuses a photo URL that is not http(s) or site-rooted,
                 so this is a backstop for rows stored before that check existed.
                 Without a usable href there is nothing to link to, so the figure
                 renders the picture alone rather than an anchor to nowhere. */ ?>
        <?php $photoHref = Helpers::safeUrl($photo['url']); ?>
        <figure class="post__photo">
            <?php if ($photoHref !== ''): ?>
            <a href="<?= Helpers::e($photoHref) ?>" class="post__photo-link" data-gallery-item>
                <?= CMS\ImageTag::render($mediaDir, $photo['url'], $photo['alt'], $imgAttrs) ?>
            </a>
            <?php else: ?>
            <?= CMS\ImageTag::render($mediaDir, $photo['url'], $photo['alt'], $imgAttrs) ?>
            <?php endif; ?>
        </figure>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="post__content prose e-content">
        <?= $html ?>
        <?php /* On a photo post the content is only the picture and the words
                 live in the excerpt, so the caption goes here — inside e-content,
                 so a feed reader parsing this post gets the picture and the words
                 together, exactly as the generated feeds carry them. */ ?>
        <?= CMS\Post::photoCaptionHtml($post->post_kind, $post->excerpt, 'post__caption p-summary') ?>
    </div>
    <?php
    $showKudos = ($settings['tinylytics_code'] ?? '') !== ''
              && ($settings['tinylytics_kudos_emoji'] ?? '') !== '';
    $kudosPath = '/' . CMS\Post::datePath($post->published_at, $post->slug, $settings['timezone'] ?? '') . '/';
    ?>
    <?php
    $replyEmail = $settings['reply_email'] ?? '';
    $showEmail  = $replyEmail !== '';
    ?>
    <?php /* webmention.io receives; it never goes looking. A response written on a
             site that does not send webmentions itself reaches us only if someone
             submits it by hand, and webmention.io's own form prefills nothing — so
             the reader would have to copy this post's URL across. We already know
             it, which is the whole reason this form is worth its markup. */ ?>
    <?php
    $webmentionDomain = $settings['webmention_domain'] ?? '';
    $showWebmention   = $webmentionDomain !== '';
    ?>
    <?php if ($showKudos || $post->mastodon_url || $post->bluesky_url || $post->pixelfed_url || $showEmail || $showWebmention): ?>
    <?php if ($showWebmention): ?>
    <?php /* The disclosure is a checkbox and a <label> pill rather than
             <details>/<summary>, and it is the layout that decides that. A
             <details> is one box: its summary can sit in the pill row or its
             content can span the measure, never both, so the pill dropped to a
             line of its own the moment it opened. Split into a checkbox before
             the row and a form after it, the pill stays put and the form still
             lines up with the article.

             `display: contents` on the <details> is the other way to do this and
             is a trap: where it is honoured the summary and content become
             siblings as wanted, and where it is not the content stops hiding
             when closed — leaving the URL field permanently on show, the one
             thing this was built to avoid. A checkbox renders the same
             everywhere. It costs the free `aria-expanded` <summary> carried;
             what it keeps is Tab, Space, and a form that works with no JS. */ ?>
    <input type="checkbox" id="wm-submit-toggle" class="wm-submit__checkbox">
    <?php endif; ?>
    <footer class="post__syndication">
        <?php if ($showEmail):
            $emailSubject = $isNote
                ? 'Re: note from ' . Helpers::formatDate($post->published_at, 'Y-m-d', $settings['locale'] ?? '', $settings['timezone'] ?? '')
                : 'Re: ' . $post->title;
        ?>
        <a href="mailto:<?= htmlspecialchars($replyEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>?subject=<?= rawurlencode($emailSubject) ?>"
           class="u-syndication">Email</a>
        <?php endif; ?>
        <?php if ($post->mastodon_url): ?>
        <a href="<?= htmlspecialchars($post->mastodon_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
           class="u-syndication" target="_blank" rel="noopener noreferrer">Mastodon</a>
        <?php endif; ?>
        <?php if ($post->bluesky_url): ?>
        <a href="<?= htmlspecialchars($post->bluesky_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
           class="u-syndication" target="_blank" rel="noopener noreferrer">Bluesky</a>
        <?php endif; ?>
        <?php if ($post->pixelfed_url): ?>
        <a href="<?= htmlspecialchars($post->pixelfed_url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
           class="u-syndication" target="_blank" rel="noopener noreferrer">Pixelfed</a>
        <?php endif; ?>
        <?php if ($showWebmention): ?>
        <label class="wm-submit__toggle" for="wm-submit-toggle">Webmention</label>
        <?php endif; ?>
        <?php if ($showKudos): ?>
        <button class="tinylytics_kudos" data-path="<?= htmlspecialchars($kudosPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></button>
        <?php endif; ?>
    </footer>
    <?php if ($showWebmention): ?>
    <?php /* A real form posting cross-origin, not a JS-only widget. With JS off —
             or running a copy of webmentions.js cached before it learned about
             this form — the native POST still lands and the browser ends up on
             webmention.io's own confirmation page.

             The hidden target is $canonical, the same URL #webmentions queries
             below. If the two ever drift, a mention is accepted against a URL
             this page never asks about and is invisible forever. */ ?>
    <form class="wm-submit__form" method="post"
          action="https://webmention.io/<?= Helpers::e($webmentionDomain) ?>/webmention">
        <input type="hidden" name="target" value="<?= Helpers::e($canonical) ?>">
        <label class="wm-submit__label" for="wm-source">Written a response on your own site? Paste its URL and it&rsquo;ll show up here.</label>
        <div class="wm-submit__row">
            <input class="wm-submit__input" type="url" name="source" id="wm-source"
                   required placeholder="https://" autocomplete="url" inputmode="url" spellcheck="false">
            <button class="wm-submit__send" type="submit">Send</button>
        </div>
        <p class="wm-submit__status" role="status" aria-live="polite" hidden></p>
    </form>
    <?php endif; ?>
    <?php endif; ?>
</article>

<?php $hasWebmentions = ($settings['webmention_domain'] ?? '') !== ''; ?>
<?php if ($hasWebmentions): ?>
<?php
/* Every address this post has been reachable at, oldest first. webmention.io
   matches and stores a target verbatim, so a mention sent before a rename stays
   filed under the URL it was sent to — asking only about the canonical would
   make it disappear from the page it belongs to. */
$legacyUrls = array_map(
    static fn(string $path): string => rtrim($siteUrl, '/') . '/' . $path . '/',
    $legacyPaths ?? []
);
?>
<?php /* data-link-tags carries the query suffixes other sites append to links
         pointing here, so webmentions.js can ask about those variants alongside
         the canonical. webmention.io matches and stores a target verbatim, so a
         mention from a link-tagging blog is filed under an address this page
         would otherwise never query.

         data-legacy-urls is the same problem in time rather than in shape: an
         address this post used to have. Both are extra target[] values on the
         one query; neither is ever the *submit* target above, which has to be
         the live address or a new mention arrives somewhere already dead. */ ?>
<section class="webmentions" id="webmentions" data-url="<?= htmlspecialchars($canonical, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
         data-link-tags="<?= Helpers::e(implode(' ', Helpers::linkTagVariants($settings['webmention_link_tags'] ?? ''))) ?>"
         data-legacy-urls="<?= Helpers::e(implode(' ', $legacyUrls)) ?>">
    <h2 class="webmentions__title">Webmentions</h2>
    <div class="webmentions__body"></div>
</section>
<?php endif; ?>
<?php /* Related before prev/next: the reader has just finished the article, so
         the lateral move by subject is the more useful offer, and the
         chronological pair reads as the last thing on the page. */ ?>
<?php if (!empty($relatedPosts)): ?>
<?php include __DIR__ . '/partials/related-posts.php'; ?>
<?php endif; ?>
<?php
$navLabel = function (CMS\Post $p) use ($settings): string {
    if (!$p->isNote() && $p->title !== '') {
        return $p->title;
    }
    $date = CMS\Helpers::formatDate($p->published_at, 'F j, Y', $settings['locale'] ?? '', $settings['timezone'] ?? '');
    $time = CMS\Helpers::formatDate($p->published_at, 'g:i A', '', $settings['timezone'] ?? '');
    return $date . ', ' . $time;
};
?>
<?php if ($prevPost || $nextPost): ?>
<nav class="post-nav" aria-label="Post navigation">
    <div class="post-nav__prev">
        <?php if ($nextPost): ?>
        <span class="post-nav__label">Newer</span>
        <a class="post-nav__link" rel="prev" href="/<?= CMS\Post::datePath($nextPost->published_at, $nextPost->slug, $settings['timezone'] ?? '') ?>/"><?= htmlspecialchars($navLabel($nextPost), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
        <?php endif; ?>
    </div>
    <div class="post-nav__next">
        <?php if ($prevPost): ?>
        <span class="post-nav__label">Older</span>
        <a class="post-nav__link" rel="next" href="/<?= CMS\Post::datePath($prevPost->published_at, $prevPost->slug, $settings['timezone'] ?? '') ?>/"><?= htmlspecialchars($navLabel($prevPost), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
        <?php endif; ?>
    </div>
</nav>
<?php endif; ?>
<?php
$bodyContent = ob_get_clean();

echo $render('base.php', compact(
    'pageTitle', 'description', 'canonical', 'ogType', 'ogImageUrl', 'bodyContent',
    'jsonLd', 'settings', 'navPages', 'siteUrl', 'render', 'criticalCss', 'headerAvatar',
    // The only template that renders #webmentions, and so the only one whose
    // page has any use for webmentions.js. See base.php.
    'hasWebmentions'
));
