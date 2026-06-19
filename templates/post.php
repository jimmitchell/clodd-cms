<?php
/**
 * Single post template.
 * Variables: $post (Post), $html (rendered Markdown), $settings, $navPages, $siteUrl, $render
 */

use CMS\Helpers;

$siteTitle   = $settings['site_title'] ?? 'My CMS';
$isAside     = $post->isAside();
$pageTitle   = $isAside ? $siteTitle : ($post->title . ' — ' . $siteTitle);
$effectiveExcerpt = $post->effectiveExcerpt();
$description = $effectiveExcerpt !== null
    ? strip_tags($effectiveExcerpt)
    : Helpers::truncate($html, 160);
$canonical   = rtrim($siteUrl, '/') . '/' . CMS\Post::datePath($post->published_at, $post->slug, $settings['timezone'] ?? '') . '/';
$ogType      = 'article';

// JSON-LD structured data (BlogPosting). Asides have no title; use the
// derived description as the headline so the schema stays valid.
$authorName = $settings['author_name'] ?? '';
$jsonLdData = [
    '@context'      => 'https://schema.org',
    '@type'         => 'BlogPosting',
    'headline'      => $isAside ? $description : $post->title,
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
<article class="post<?= $isAside ? ' post--note' : '' ?> h-entry">
    <header class="post__header">
        <?php if (!$isAside): ?>
        <h1 class="post__title p-name"><?= htmlspecialchars($post->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
        <a class="post__author p-author h-card" href="<?= htmlspecialchars($siteUrl . '/', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" rel="author"><?= htmlspecialchars($authorName !== '' ? $authorName : $siteTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
        <?php endif; ?>
        <?php if ($post->published_at): ?>
        <time class="post__date dt-published" datetime="<?= date('Y-m-d\TH:i:s\Z', strtotime($post->published_at)) ?>">
            <a class="u-url" href="<?= htmlspecialchars($canonical, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= Helpers::formatDate($post->published_at, 'F j, Y', $settings['locale'] ?? '', $settings['timezone'] ?? '') ?></a>
        </time>
        <?php if (!$isAside): ?>
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

    <div class="post__content prose e-content">
        <?= $html ?>
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
    <?php if ($showKudos || $post->mastodon_url || $post->bluesky_url || $showEmail): ?>
    <footer class="post__syndication">
        <?php if ($showEmail):
            $emailSubject = $isAside
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
        <?php if ($showKudos): ?>
        <button class="tinylytics_kudos" data-path="<?= htmlspecialchars($kudosPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></button>
        <?php endif; ?>
    </footer>
    <?php endif; ?>
</article>

<?php if (($settings['webmention_domain'] ?? '') !== ''): ?>
<section class="webmentions" id="webmentions" data-url="<?= htmlspecialchars($canonical, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <h2 class="webmentions__title">Webmentions</h2>
    <div class="webmentions__body"></div>
</section>
<?php endif; ?>
<?php
$navLabel = function (CMS\Post $p) use ($settings): string {
    if (!$p->isAside() && $p->title !== '') {
        return $p->title;
    }
    $date = CMS\Helpers::formatDate($p->published_at, 'F j, Y', $settings['locale'] ?? '', $settings['timezone'] ?? '');
    $time = CMS\Helpers::formatDate($p->published_at, 'g:i A', '', $settings['timezone'] ?? '');
    return $date . ' @ ' . $time;
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
    'jsonLd', 'settings', 'navPages', 'siteUrl', 'render', 'hasGallery'
));
