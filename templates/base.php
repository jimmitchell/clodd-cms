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

// Bluesky profile URL (stored as-is).
$blueskyUrl = $settings['bluesky_url'] ?? '';

// GitHub profile URL (stored as-is).
$githubUrl = $settings['github_url'] ?? '';

// Mastodon: parse @user@instance handle into a profile URL + meta value.
$rawHandle    = $settings['mastodon_handle'] ?? '';
$mastodonUrl  = \CMS\Helpers::mastodonProfileUrl($rawHandle) ?? '';
$mastodonMeta = $mastodonUrl !== '' ? '@' . ltrim($rawHandle, '@') : '';

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
  gtag('config', <?= json_encode($gaMeasurementId) ?>);
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
    <!-- Font preloads — must come before the stylesheet -->
    <link rel="preload" href="/fonts/Figtree-Variable.woff2"
          as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/Figtree-Variable-Italic.woff2"
          as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/AtkinsonHyperlegibleNext-Variable.woff2"
          as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/AtkinsonHyperlegibleNext-Variable-Italic.woff2"
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
    <!-- Bridgy Fed verification -->
    <link rel="me" href="https://jimmitchell.org.web.brid.gy">
    <!-- Micropub -->
    <?php if (($settings['micropub_token'] ?? '') !== ''): ?>
    <link rel="micropub" href="<?= _e($siteUrl . '/micropub.php') ?>">
    <?php endif; ?>
    <!-- Anti-FOUC: apply saved/system theme before CSS renders to avoid flash -->
    <script>(function(){var t=localStorage.getItem('theme');var sys=window.matchMedia('(prefers-color-scheme:dark)').matches;if(t==='dark'||(t!=='light'&&sys)){document.documentElement.setAttribute('data-theme','dark');}else{document.documentElement.setAttribute('data-theme','light');}})();</script>
    <?php if (!empty($criticalCss)): ?>
    <style><?= $criticalCss ?></style>
    <link rel="preload" href="/theme.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="/theme.min.css"></noscript>
    <?php else: ?>
    <link rel="stylesheet" href="/theme.min.css">
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
        <a href="/" class="site-header__title"><?= _e($siteTitle) ?></a>

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
            <a href="<?= _e($siteUrl . '/search/') ?>" class="search-toggle" aria-label="Search">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round"
                     stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </a>
            <button class="theme-toggle" id="theme-toggle" aria-label="Toggle dark mode"></button>
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
            <?php if ($mastodonUrl !== ''): ?>
            <a href="<?= _e($mastodonUrl) ?>" class="site-footer__mastodon"
               rel="me noopener" target="_blank" aria-label="Mastodon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M23.193 7.879c0-5.206-3.411-6.732-3.411-6.732C18.062.357 15.108.025 12.041 0h-.076c-3.069.025-6.02.357-7.74 1.147 0 0-3.411 1.526-3.411 6.732 0 1.192-.023 2.618.015 4.129.124 5.092.934 10.109 5.641 11.355 2.17.574 4.034.695 5.535.612 2.722-.15 4.25-.972 4.25-.972l-.09-1.975s-1.945.613-4.13.539c-2.165-.074-4.449-.233-4.801-2.891a5.499 5.499 0 0 1-.048-.745s2.125.52 4.818.643c1.646.075 3.19-.096 4.758-.283 3.007-.359 5.625-2.212 5.954-3.905.517-2.665.475-6.507.475-6.507zm-4.024 6.709h-2.497v-6.12c0-2.666-3.43-2.769-3.43.37v3.35H10.76v-3.35c0-3.139-3.43-3.036-3.43-.37v6.12H4.833c0-6.546-.28-7.919.985-9.374 1.388-1.55 4.28-1.652 5.561.327l.635 1.046.635-1.046c1.282-1.98 4.172-1.878 5.562-.327 1.265 1.455.985 2.828.985 9.374z" fill="currentColor"/></svg>
            </a>
            <?php endif; ?>
            <?php if ($blueskyUrl !== ''): ?>
            <a href="<?= _e($blueskyUrl) ?>" class="site-footer__bluesky"
               rel="me noopener" target="_blank" aria-label="Bluesky">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 10.8c-1.087-2.114-4.046-6.053-6.798-7.995C2.566.944 1.561 1.266.902 1.565.139 1.908 0 3.08 0 3.768c0 .69.378 5.65.624 6.479.815 2.736 3.713 3.66 6.383 3.364.136-.02.275-.039.415-.056-.138.022-.276.04-.415.056-3.912.58-7.387 2.005-2.83 7.078 5.013 5.19 6.87-1.113 7.823-4.308.953 3.195 2.05 9.271 7.733 4.308 4.267-4.308 1.172-6.498-2.74-7.078a8.741 8.741 0 0 1-.415-.056c.14.017.279.036.415.056 2.67.297 5.568-.628 6.383-3.364.246-.828.624-5.79.624-6.478 0-.69-.139-1.861-.902-2.206-.659-.298-1.664-.62-4.3 1.24C16.046 4.748 13.087 8.687 12 10.8Z" fill="currentColor"/></svg>
            </a>
            <?php endif; ?>
            <?php if ($githubUrl !== ''): ?>
            <a href="<?= _e($githubUrl) ?>" class="site-footer__github"
               rel="me noopener" target="_blank" aria-label="GitHub">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12" fill="currentColor"/></svg>
            </a>
            <?php endif; ?>
            <a href="<?= _e($siteUrl . '/feed.rss') ?>" class="site-footer__feed" aria-label="RSS feed">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 11a9 9 0 0 1 9 9"/><path d="M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1.5" fill="currentColor" stroke="none"/></svg>
            </a>
        </div>
    </div>
</footer>

<script>
(function () {
    var COPY_ICON  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
    var CHECK_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';

    document.querySelectorAll('.prose pre').forEach(function (pre) {
        // Wrap the <pre> in a div so the copy button is outside the
        // scroll container and stays fixed at the top-right corner.
        var wrap = document.createElement('div');
        wrap.className = 'code-block' + (pre.classList.contains('syntax-hl') ? ' code-block--dark' : '');
        pre.parentNode.insertBefore(wrap, pre);
        wrap.appendChild(pre);

        var btn = document.createElement('button');
        btn.className = 'code-copy';
        btn.setAttribute('aria-label', 'Copy code');
        btn.innerHTML = COPY_ICON;
        wrap.appendChild(btn);

        btn.addEventListener('click', function () {
            var code = pre.querySelector('code');
            var text = (code || pre).textContent;
            navigator.clipboard.writeText(text).then(function () {
                btn.innerHTML = CHECK_ICON;
                btn.classList.add('code-copy--copied');
                setTimeout(function () {
                    btn.innerHTML = COPY_ICON;
                    btn.classList.remove('code-copy--copied');
                }, 2000);
            });
        });
    });

    // ── Lightbox ────────────────────────────────────────────────────────────
    // Only wire up the lightbox when the page actually contains prose images.
    var _proseImgs = Array.from(document.querySelectorAll('.prose img')).filter(function (el) {
        return !el.closest('[data-gallery-item]');
    });

    if (_proseImgs.length) {
        var overlay = document.createElement('div');
        overlay.className = 'lightbox';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Image lightbox');

        var img = document.createElement('img');
        img.className = 'lightbox__img';
        img.setAttribute('alt', '');

        var closeBtn = document.createElement('button');
        closeBtn.className = 'lightbox__close';
        closeBtn.setAttribute('aria-label', 'Close lightbox');
        closeBtn.textContent = '×';

        overlay.appendChild(img);
        overlay.appendChild(closeBtn);
        document.body.appendChild(overlay);

        function openLightbox(src, alt, naturalW, naturalH) {
            img.src = src;
            img.alt = alt || '';
            img.style.maxWidth  = naturalW > 0 ? 'min(' + naturalW + 'px, 100%)' : '';
            img.style.maxHeight = naturalH > 0 ? 'min(' + naturalH + 'px, 100%)' : '';
            overlay.classList.add('is-open');
            document.body.style.overflow = 'hidden';
            closeBtn.focus();
        }

        function closeLightbox() {
            overlay.classList.remove('is-open');
            document.body.style.overflow = '';
            img.src = '';
            img.style.maxWidth  = '';
            img.style.maxHeight = '';
        }

        _proseImgs.forEach(function (el) {
            el.addEventListener('click', function () {
                openLightbox(el.src, el.alt, el.naturalWidth, el.naturalHeight);
            });
        });

        closeBtn.addEventListener('click', closeLightbox);

        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) { closeLightbox(); }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.classList.contains('is-open')) {
                closeLightbox();
            }
        });
    }

    // ── Mobile nav toggle ────────────────────────────────────────────────────
    var navToggle = document.getElementById('nav-toggle');
    var siteNav   = document.getElementById('site-nav');

    function closeNav() {
        if (!siteNav) return;
        siteNav.classList.remove('is-open');
        if (navToggle) {
            navToggle.classList.remove('is-open');
            navToggle.setAttribute('aria-expanded', 'false');
            navToggle.setAttribute('aria-label', 'Open navigation');
        }
    }

    if (navToggle && siteNav) {
        navToggle.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = siteNav.classList.toggle('is-open');
            navToggle.classList.toggle('is-open', open);
            navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            navToggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
        });
        siteNav.addEventListener('click', function (e) {
            if (e.target.tagName === 'A') { closeNav(); }
        });
        document.addEventListener('click', function (e) {
            if (siteNav.classList.contains('is-open') && !e.target.closest('.site-header')) {
                closeNav();
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closeNav(); }
        });
    }

    // ── Theme toggle (three-way: light → dark → system → light) ─────────────
    var MOON_ICON   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
    var SUN_ICON    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>';
    var SYSTEM_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>';

    // Returns the stored preference: 'light', 'dark', or 'system' (default).
    function _storedTheme() {
        return localStorage.getItem('theme') || 'system';
    }

    // Apply a theme preference and persist it.
    // Icon convention: shows the CURRENT active mode.
    //   light  → sun     icon → "Switch to dark mode"
    //   dark   → moon    icon → "Switch to system theme"
    //   system → monitor icon → "Switch to light mode"
    function _applyTheme(theme) {
        var dark;
        if (theme === 'dark') {
            dark = true;
        } else if (theme === 'light') {
            dark = false;
        } else {
            dark = window.matchMedia('(prefers-color-scheme:dark)').matches;
        }
        document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
        localStorage.setItem('theme', theme);
        _updateToggleIcon(theme);
    }

    function _updateToggleIcon(theme) {
        var tb = document.getElementById('theme-toggle');
        if (!tb) { return; }
        if (theme === 'light') {
            tb.innerHTML = SUN_ICON;
            tb.setAttribute('aria-label', 'Switch to dark mode');
        } else if (theme === 'dark') {
            tb.innerHTML = MOON_ICON;
            tb.setAttribute('aria-label', 'Switch to system theme');
        } else {
            tb.innerHTML = SYSTEM_ICON;
            tb.setAttribute('aria-label', 'Switch to light mode');
        }
    }

    // While in system mode, track OS-level preference changes in real time.
    window.matchMedia('(prefers-color-scheme:dark)').addEventListener('change', function (e) {
        if (_storedTheme() === 'system') {
            document.documentElement.setAttribute('data-theme', e.matches ? 'dark' : 'light');
        }
    });

    // Set correct icon immediately (theme attribute already set by head script).
    _updateToggleIcon(_storedTheme());

    var themeBtn = document.getElementById('theme-toggle');
    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            var next = { light: 'dark', dark: 'system', system: 'light' };
            _applyTheme(next[_storedTheme()] || 'light');
        });
    }

}());
</script>
<?php if (!empty($hasGallery)): ?>
<script>
(function () {
'use strict';

// ── Gallery lightbox ──────────────────────────────────────────────────────────
var glItems  = [];
var glCursor = 0;

var glOverlay = document.createElement('div');
glOverlay.className = 'gallery-lightbox';
glOverlay.setAttribute('role', 'dialog');
glOverlay.setAttribute('aria-modal', 'true');
glOverlay.setAttribute('aria-label', 'Gallery');

var glImg   = document.createElement('img');
glImg.className = 'gallery-lightbox__img';
glImg.setAttribute('alt', '');

var glClose = document.createElement('button');
glClose.className = 'gallery-lightbox__btn gallery-lightbox__close';
glClose.setAttribute('aria-label', 'Close');
glClose.innerHTML = '&times;';

var glPrev = document.createElement('button');
glPrev.className = 'gallery-lightbox__btn gallery-lightbox__prev';
glPrev.setAttribute('aria-label', 'Previous image');
glPrev.innerHTML = '&#8249;';

var glNext = document.createElement('button');
glNext.className = 'gallery-lightbox__btn gallery-lightbox__next';
glNext.setAttribute('aria-label', 'Next image');
glNext.innerHTML = '&#8250;';

glOverlay.appendChild(glImg);
glOverlay.appendChild(glClose);
glOverlay.appendChild(glPrev);
glOverlay.appendChild(glNext);
document.body.appendChild(glOverlay);

function glShow(index) {
    glCursor = (index + glItems.length) % glItems.length;
    glImg.src = glItems[glCursor].href;
    glImg.alt = glItems[glCursor].alt;
    glOverlay.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    glClose.focus();
}

function glClose_() {
    glOverlay.classList.remove('is-open');
    document.body.style.overflow = '';
    glImg.src = '';
}

// Collect all gallery items across all galleries on the page.
document.querySelectorAll('.gallery[data-gallery] [data-gallery-item]').forEach(function (a) {
    glItems.push({ href: a.href, alt: (a.querySelector('img') || {}).alt || '' });
    var idx = glItems.length - 1;
    a.addEventListener('click', function (e) {
        e.preventDefault();
        glShow(idx);
    });
});

glClose.addEventListener('click', glClose_);
glPrev.addEventListener('click', function () { glShow(glCursor - 1); });
glNext.addEventListener('click', function () { glShow(glCursor + 1); });

glOverlay.addEventListener('click', function (e) {
    if (e.target === glOverlay) glClose_();
});

document.addEventListener('keydown', function (e) {
    if (!glOverlay.classList.contains('is-open')) return;
    if (e.key === 'Escape')      glClose_();
    if (e.key === 'ArrowLeft')  glShow(glCursor - 1);
    if (e.key === 'ArrowRight') glShow(glCursor + 1);
});

}());
</script>
<?php endif; ?>
<?php if (($settings['webmention_domain'] ?? '') !== ''): ?>
<script>
(function () {
    var section = document.getElementById('webmentions');
    if (!section) return;

    var target = section.dataset.url;
    if (!target) return;

    var _wmCacheKey  = 'wm:' + target;
    var _wmCached    = null;
    var _wmRaw       = sessionStorage.getItem(_wmCacheKey);
    if (_wmRaw) {
        try {
            var _wmEntry = JSON.parse(_wmRaw);
            if (_wmEntry.ts && (Date.now() - _wmEntry.ts) < 300000) { _wmCached = _wmEntry.data; }
        } catch (e) {}
    }

    function esc(s) {
        return String(s || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function buildAvatar(a, size) {
        var cls = 'wm-avatar' + (size === 'sm' ? ' wm-avatar--sm' : '');
        var name = a.name || '?';
        var initial = name.charAt(0).toUpperCase();
        if (a.photo) {
            return '<img class="' + cls + '" src="' + esc(a.photo) + '" alt="' + esc(name) + '" width="' + (size === 'sm' ? 40 : 32) + '" height="' + (size === 'sm' ? 40 : 32) + '" loading="lazy">';
        }
        return '<span class="' + cls + ' wm-avatar--fallback">' + esc(initial) + '</span>';
    }

    function renderMentions(data) {
        var all      = data.children || [];
        var likes    = all.filter(function (m) { return m['wm-property'] === 'like-of'; });
        var reposts  = all.filter(function (m) { return m['wm-property'] === 'repost-of'; });
        var replies  = all.filter(function (m) { return m['wm-property'] === 'in-reply-to'; });
        var mentions = all.filter(function (m) { return m['wm-property'] === 'mention-of'; });

        if (!all.length) return;

        var html = '';

        // ── Reactions (likes + reposts) ─────────────────────────────────
        var reactions = likes.concat(reposts);
        if (reactions.length) {
            html += '<div class="webmentions__reactions">';
            html += '<p class="wm-counts">';
            if (likes.length)   html += '<span class="wm-count"><span aria-hidden="true">♥</span> ' + likes.length   + (likes.length   === 1 ? ' like'   : ' likes')   + '</span>';
            if (reposts.length) html += '<span class="wm-count"><span aria-hidden="true">↺</span> ' + reposts.length + (reposts.length === 1 ? ' repost' : ' reposts') + '</span>';
            html += '</p><div class="wm-avatars">';
            reactions.forEach(function (m) {
                var a    = m.author || {};
                var link = a.url || m.url || '#';
                html += '<a href="' + esc(link) + '" target="_blank" rel="noopener noreferrer" title="' + esc(a.name || 'Anonymous') + '" class="wm-avatar-link">' + buildAvatar(a, 'lg') + '</a>';
            });
            html += '</div></div>';
        }

        // ── Replies + mentions ──────────────────────────────────────────
        var allReplies = replies.concat(mentions);
        if (allReplies.length) {
            html += '<div class="webmentions__replies">';
            html += '<p class="wm-group-label">' + allReplies.length + (allReplies.length === 1 ? ' Reply' : ' Replies') + '</p>';
            allReplies.forEach(function (m) {
                var a       = m.author || {};
                var name    = a.name || 'Anonymous';
                var srcLink = a.url || m.url || '#';
                var pub     = m.published || m['wm-received'] || '';
                var dateStr = '';
                if (pub) {
                    try { dateStr = new Date(pub).toLocaleDateString('en', { year: 'numeric', month: 'short', day: 'numeric' }); } catch (e) {}
                }
                var text = (m.content && m.content.text) ? m.content.text : '';
                if (text.length > 300) text = text.slice(0, 300) + '\u2026';

                html += '<article class="wm-reply">';
                html += '<header class="wm-reply__header">';
                html += '<a href="' + esc(srcLink) + '" target="_blank" rel="noopener noreferrer" class="wm-avatar-link">' + buildAvatar(a, 'sm') + '</a>';
                html += '<div class="wm-reply__meta">';
                html += '<a href="' + esc(srcLink) + '" target="_blank" rel="noopener noreferrer" class="wm-reply__author">' + esc(name) + '</a>';
                if (dateStr) {
                    html += '<a href="' + esc(m.url || '#') + '" target="_blank" rel="noopener noreferrer" class="wm-reply__date-link"><time class="wm-reply__date" datetime="' + esc(pub) + '">' + esc(dateStr) + '</time></a>';
                }
                html += '</div></header>';
                if (text) {
                    html += '<p class="wm-reply__content">' + esc(text) + '</p>';
                }
                html += '</article>';
            });
            html += '</div>';
        }

        if (html) {
            var body = section.querySelector('.webmentions__body');
            if (body) body.innerHTML = html;
            section.classList.add('webmentions--loaded');
        }
    }

    if (_wmCached) {
        try { renderMentions(_wmCached); } catch (e) { /* ignore stale cache */ }
    } else {
        fetch('https://webmention.io/api/mentions.jf2?target=' + encodeURIComponent(target) + '&per-page=100')
            .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
            .then(function (data) {
                try { sessionStorage.setItem(_wmCacheKey, JSON.stringify({ts: Date.now(), data: data})); } catch (e) {}
                renderMentions(data);
            })
            .catch(function () { /* fail silently */ });
    }
}());
</script>
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
