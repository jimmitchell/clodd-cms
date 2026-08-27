<?php
/**
 * Base layout template.
 *
 * Expected variables (set by each content template):
 *   $pageTitle   — full <title> string (already escaped if needed; we re-escape here)
 *   $description — meta description plain text
 *   $canonical   — absolute canonical URL
 *   $bodyContent — pre-rendered inner HTML (safe)
 *   $ogType      — Open Graph type (default: 'website')
 *
 * Available from Builder context:
 *   $settings    — site settings array
 *   $navPages    — published Page objects sorted by nav_order
 *   $siteUrl     — site URL without trailing slash
 */

$ogType      = $ogType      ?? 'website';
$description = $description ?? ($settings['site_description'] ?? '');
$siteTitle   = $settings['site_title']       ?? 'My CMS';
$footerText  = $settings['footer_text']      ?? '';
$ogImageUrl  = $ogImageUrl  ?? '';

// The author avatar sits beside the site title. Builder inlines a 64px copy as
// a data URI so the header costs no second request — see Builder::headerAvatar().
// Anything it could not encode (a remote avatar, or no GD) falls back to the URL
// as written, held by Helpers::safeUrl() to the two shapes an avatar can
// legitimately take. Settings are owner-written, but this markup is on every
// public page.
$headerAvatar = trim((string) ($headerAvatar ?? ''));
if ($headerAvatar === '') {
    $headerAvatar = CMS\Helpers::safeUrl($settings['author_avatar_url'] ?? '');
}

// Mastodon: the handle only reaches the head as a meta value here. The profile
// links themselves live in partials/social-links.php, which resolves its own URLs.
$rawHandle    = $settings['mastodon_handle'] ?? '';
$mastodonMeta = \CMS\Helpers::mastodonProfileUrl($rawHandle) !== null ? '@' . ltrim($rawHandle, '@') : '';

if (!function_exists('_e')) {
    function _e(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<?php $tinylyticsCode = $settings['tinylytics_code'] ?? ''; ?>
<?php if ($tinylyticsCode !== ''): ?>
<?php $tinylyticsKudosEmoji = $settings['tinylytics_kudos_emoji'] ?? ''; ?>
<?php $tinylyticsParams = $tinylyticsKudosEmoji !== '' ? '?kudos=' . rawurlencode($tinylyticsKudosEmoji) : ''; ?>
<script src="https://tinylytics.app/embed/<?= _e($tinylyticsCode) ?>.js<?= $tinylyticsParams ?>" defer></script>
<?php endif; ?>
<?php $gaMeasurementId = $settings['ga_measurement_id'] ?? ''; ?>
<?php if ($gaMeasurementId !== ''): ?>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= _e($gaMeasurementId) ?>"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', <?= json_encode($gaMeasurementId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
</script>
<?php endif; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php
    $_faviconUrl  = $settings['favicon_url'] ?? '';
    $_faviconHref = '/favicon.svg';
    $_faviconMime = 'image/svg+xml';
    if ($_faviconUrl !== '') {
        $_faviconHref = $_faviconUrl;
        $_faviconMime = match(strtolower(pathinfo(parse_url($_faviconUrl, PHP_URL_PATH), PATHINFO_EXTENSION))) {
            'png'        => 'image/png',
            'jpg','jpeg' => 'image/jpeg',
            'gif'        => 'image/gif',
            'webp'       => 'image/webp',
            'ico'        => 'image/x-icon',
            default      => 'image/png',
        };
    }
    ?>
    <link rel="icon" href="<?= _e($_faviconHref) ?>" type="<?= _e($_faviconMime) ?>">
    <link rel="sitemap" type="application/xml" href="/sitemap.xml">
    <title><?= _e($pageTitle) ?></title>
    <?php if ($description !== ''): ?>
    <meta name="description" content="<?= _e($description) ?>">
    <?php endif; ?>
    <!-- Open Graph -->
    <meta property="og:title"       content="<?= _e($pageTitle) ?>">
    <meta property="og:type"        content="<?= _e($ogType) ?>">
    <meta property="og:url"         content="<?= _e($canonical ?? $siteUrl . '/') ?>">
    <?php if ($ogImageUrl !== ''): ?>
    <meta property="og:image"       content="<?= _e($ogImageUrl) ?>">
    <?php endif; ?>
    <?php if ($description !== ''): ?>
    <meta property="og:description" content="<?= _e($description) ?>">
    <?php endif; ?>
    <?php if ($mastodonMeta !== ''): ?>
    <meta name="fediverse:creator" content="<?= _e($mastodonMeta) ?>">
    <?php endif; ?>
    <?php $googleSiteVerification = $settings['google_site_verification'] ?? ''; ?>
    <?php if ($googleSiteVerification !== '' && ($isHomepage ?? false)): ?>
    <meta name="google-site-verification" content="<?= _e($googleSiteVerification) ?>">
    <?php endif; ?>
    <!-- Font preload — must come before the stylesheet.
         Roman face only: it sets essentially all the text on a page, so it is
         on the critical path. The italic (46 KB) is for the occasional <em>,
         and preloading it competed for bandwidth with the face actually needed
         to paint. It still loads from @font-face the moment something italic is
         encountered.

         Unstamped, unlike the four theme assets below: this URL has to match
         the `src` in theme.css exactly or the browser fetches the face twice,
         and a stylesheet cannot write $assetVersion into a url(). Nginx caches
         /fonts/ immutable for a year, so a *replacement* face ships under a new
         filename rather than by busting this one. -->
    <link rel="preload" href="/fonts/DMSans-Variable.woff2"
          as="font" type="font/woff2" crossorigin>
    <!-- Feeds -->
    <link rel="alternate" type="application/atom+xml"
          title="<?= _e($siteTitle) ?>"
          href="<?= _e($siteUrl . '/feed.xml') ?>">
    <link rel="alternate" type="application/rss+xml"
          title="<?= _e($siteTitle) ?>"
          href="<?= _e($siteUrl . '/feed.rss') ?>">
    <link rel="alternate" type="application/feed+json"
          title="<?= _e($siteTitle) ?>"
          href="<?= _e($siteUrl . '/feed.json') ?>">
    <?php foreach ($extraFeedLinks ?? [] as $feedLink): ?>
    <link rel="alternate" type="<?= _e($feedLink['type']) ?>"
          title="<?= _e($feedLink['title']) ?>"
          href="<?= _e($feedLink['href']) ?>">
    <?php endforeach; ?>
    <!-- Webmention -->
    <?php $webmentionDomain = $settings['webmention_domain'] ?? ''; ?>
    <?php if ($webmentionDomain !== ''): ?>
    <link rel="webmention" href="https://webmention.io/<?= _e($webmentionDomain) ?>/webmention">
    <link rel="pingback" href="https://webmention.io/xmlrpc">
    <?php endif; ?>
    <!-- Bridgy Fed verification. The href is the redirector Bridgy Fed documents
         for a bridged web site, not the bare *.web.brid.gy handle — that string
         is the fediverse/atproto handle and does not resolve over HTTP, so a
         rel="me" pointing at it verifies nothing. -->
    <link rel="me" href="<?= _e('https://web.brid.gy/r/' . rtrim($siteUrl, '/') . '/') ?>">
    <!-- Micropub + IndieAuth discovery -->
    <link rel="micropub" href="<?= _e($siteUrl . '/micropub.php') ?>">
    <link rel="indieauth-metadata" href="<?= _e($siteUrl . '/indieauth-metadata.php') ?>">
    <link rel="authorization_endpoint" href="<?= _e($siteUrl . '/indieauth.php') ?>">
    <link rel="token_endpoint" href="<?= _e($siteUrl . '/token.php') ?>">
    <!-- Anti-FOUC: apply saved/system theme before CSS renders to avoid flash.
         data-theme-pref carries the three-way preference so CSS can pick the
         toggle's icon on first paint, without waiting for deferred theme.js. -->
    <script>(function(){var d=document.documentElement;var t=localStorage.getItem('theme');if(t!=='dark'&&t!=='light'){t='system';}d.setAttribute('data-theme-pref',t);var dark=t==='dark'||(t==='system'&&window.matchMedia('(prefers-color-scheme:dark)').matches);d.setAttribute('data-theme',dark?'dark':'light');})();</script>
    <?php /* Nginx serves these four with `expires 7d, must-revalidate`, and inside
             that window a browser does not even ask whether they changed — so an
             edit took up to a week to reach a returning reader, on a deploy that
             looked complete. That is not hypothetical: the 1.31.0 webmention change
             was live and correct while browsers went on running the previous script.

             The version is the stamp because every user-visible change here ships
             with a VERSION bump. $uri excludes the query string, so nginx's asset
             regex still matches and no config change is needed. */ ?>
    <?php $_asset = '?v=' . rawurlencode($assetVersion ?? 'dev'); ?>
    <?php /* Critical CSS inline, the rest deferred. theme.deferred.css holds
             only what comes after the marker: linking theme.min.css here would
             re-send the block already inlined above, which was 4.6 KB of a
             10.8 KB gzipped page. Falls back to the whole stylesheet when
             there is no critical split to work with. */ ?>
    <?php if (!empty($criticalCss)): ?>
    <style><?= $criticalCss ?></style>
    <link rel="preload" href="/theme.deferred.css<?= $_asset ?>" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="/theme.deferred.css<?= $_asset ?>"></noscript>
    <?php else: ?>
    <link rel="stylesheet" href="/theme.min.css<?= $_asset ?>">
    <?php endif; ?>
    <?php if (!empty($jsonLd)): ?>
    <script type="application/ld+json"><?= $jsonLd ?></script>
    <?php endif; ?>
    <?php $customCss = $settings['custom_css'] ?? ''; ?>
    <?php if ($customCss !== ''): ?>
    <style><?= str_ireplace('</style', '<\/style', $customCss) ?></style>
    <?php endif; ?>
</head>
<body>

<header class="site-header">
    <div class="site-header__inner">
        <a href="/" class="site-header__title">
            <?php if ($headerAvatar !== ''): ?>
            <img class="site-header__avatar" src="<?= _e($headerAvatar) ?>" alt=""
                 width="32" height="32" decoding="sync">
            <?php endif; ?>
            <span><?= _e($siteTitle) ?></span>
        </a>

        <div class="site-header__right">
            <?php if (!empty($navPages)): ?>
            <button class="nav-toggle" id="nav-toggle"
                    aria-label="Open navigation" aria-expanded="false" aria-controls="site-nav">
                <span class="nav-toggle__bars" aria-hidden="true">
                    <span class="nav-toggle__bar"></span>
                    <span class="nav-toggle__bar"></span>
                    <span class="nav-toggle__bar"></span>
                </span>
            </button>
            <nav class="site-nav" id="site-nav" aria-label="Site navigation">
                <?php foreach ($navPages as $navPage): ?>
                <div class="site-nav__item<?= !empty($navPage->children) ? ' has-children' : '' ?>">
                    <a href="<?= _e($siteUrl . '/' . $navPage->slug . '/') ?>">
                        <?= _e($navPage->title) ?>
                    </a>
                    <?php if (!empty($navPage->children)): ?>
                    <ul class="site-nav__sub" aria-label="<?= _e($navPage->title) ?> sub-pages">
                        <?php foreach ($navPage->children as $child): ?>
                        <li>
                            <a href="<?= _e($siteUrl . '/' . $child->slug . '/') ?>">
                                <?= _e($child->title) ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>
            <a href="<?= _e($siteUrl . '/search/') ?>" class="search-toggle" id="search-toggle" aria-label="Search">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </a>
            <!-- All three glyphs ship in the markup; CSS shows the one matching
                 data-theme-pref. theme.js only refines the aria-label. -->
            <button class="theme-toggle" id="theme-toggle" aria-label="Toggle theme">
                <svg class="theme-toggle__icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <g class="theme-toggle__glyph theme-toggle__glyph--light">
                        <circle cx="12" cy="12" r="5"/>
                        <line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
                        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                        <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
                        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                    </g>
                    <g class="theme-toggle__glyph theme-toggle__glyph--dark">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                    </g>
                    <g class="theme-toggle__glyph theme-toggle__glyph--system">
                        <rect x="2" y="3" width="20" height="14" rx="2"/>
                        <line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
                    </g>
                </svg>
            </button>
        </div>
    </div>
</header>

<main class="site-main<?= !empty($wideLayout) ? ' site-main--wide' : '' ?>">
    <?= $bodyContent ?>
</main>

<footer class="site-footer">
    <div class="site-footer__inner">
        <?php if ($footerText !== ''): ?>
        <span><?= _e($footerText) ?></span>
        <?php else: ?>
        <span>&copy; <?= date('Y') ?> <?= _e($siteTitle) ?></span>
        <?php endif; ?>
        <div class="site-footer__links">
            <?php $socialFeed = true; include __DIR__ . '/partials/social-links.php'; ?>
        </div>
    </div>
</footer>

<?php /* Search overlay. The form's action/method do the work on their own —
         pressing Enter submits a plain GET to /search/?q=…, so search still
         reaches the results page with JavaScript disabled. The script below
         only opens it, closes it, and moves focus. */ ?>
<div class="search-overlay" id="search-overlay" role="dialog" aria-modal="true" aria-label="Search">
    <form class="search-overlay__panel"
          action="<?= _e($siteUrl . '/search/') ?>" method="get" role="search">
        <div class="search-overlay__field">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true" focusable="false">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="search" name="q" id="search-overlay-q"
                   placeholder="Search posts&hellip;" autocomplete="off"
                   aria-label="Search posts">
        </div>
        <p class="search-overlay__hint">Enter to search <span aria-hidden="true">·</span> Esc to close</p>
    </form>
</div>

<script src="/theme.js<?= $_asset ?>" defer></script>
<?php /* Post pages only. The script's first act is to look for #webmentions and
         return if it is absent (webmentions.js:3), and templates/post.php is
         the only place that renders it — so the home page, all 116 paginated
         index pages, every taxonomy archive, the search page and the 404 were
         each fetching 18 KB to do nothing. The home page is the common entry
         point, which is where it cost the most. */ ?>
<?php if (!empty($hasWebmentions)): ?>
<script src="/webmentions.js<?= $_asset ?>" defer></script>
<?php endif; ?>
<?php if (!empty($is404Page)): ?><script>window.analyticsIs404=true;</script><?php endif; ?>
<script>
(function(){
    try{
        var p=new URLSearchParams(location.search);
        if(p.get('ti')==='exclude'){localStorage.setItem('ti_exclude','1');}
        if(p.get('ti')==='include'){localStorage.removeItem('ti_exclude');}
        if(localStorage.getItem('ti_exclude')==='1')return;
    }catch(e){}
    var d={url:location.pathname,referrer:document.referrer||'',is404:window.analyticsIs404||false};
    navigator.sendBeacon('/track.php',new Blob([JSON.stringify(d)],{type:'application/json'}));
}());
</script>
</body>
</html>
