<?php
/**
 * Search results page template.
 * Fetches /search.json client-side and renders results with JavaScript.
 * Variables: $settings, $navPages, $siteUrl, $render
 */

$siteTitle   = $settings['site_title'] ?? 'My CMS';
$pageTitle   = 'Search — ' . $siteTitle;
$description = 'Search ' . $siteTitle;
$canonical   = rtrim($siteUrl, '/') . '/search/';
$ogType      = 'website';

ob_start();
?>
<div class="search-page">
    <h1 class="search-page__title">Search</h1>
    <form class="search-page__form"
          action="<?= htmlspecialchars(rtrim($siteUrl, '/') . '/search/') ?>"
          method="get" role="search">
        <input type="search" name="q" id="search-page-q"
               placeholder="Search posts…"
               autocomplete="off" aria-label="Search posts"
               autofocus value="">
        <button type="submit" aria-label="Search">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2" stroke-linecap="round"
                 stroke-linejoin="round" aria-hidden="true" focusable="false">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
        </button>
    </form>

    <div id="search-results" class="post-list" aria-live="polite"></div>
</div>

<script>
(function () {
    function escHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    var params   = new URLSearchParams(window.location.search);
    var q        = (params.get('q') || '').trim();
    var input    = document.getElementById('search-page-q');
    var resultEl = document.getElementById('search-results');

    if (input && q) { input.value = q; }
    if (!q || !resultEl) { return; }

    resultEl.innerHTML = '<p class="search-empty">Searching\u2026</p>';

    fetch('/search.json')
        .then(function (r) { return r.json(); })
        .then(function (data) {
            var ql      = q.toLowerCase();
            var results = data.filter(function (item) {
                return (item.title || '').toLowerCase().indexOf(ql) !== -1
                    || (item.excerpt || '').toLowerCase().indexOf(ql) !== -1
                    || (item.body_text || '').toLowerCase().indexOf(ql) !== -1;
            });

            if (results.length === 0) {
                resultEl.innerHTML = '<p class="search-empty">No results for <strong>'
                    + escHtml(q) + '</strong>.</p>';
                return;
            }

            // Mirrors templates/partials/post-card.php. Results have no photo
            // rows in the index, so a photo post renders as its caption — it
            // still reads as the same card family.
            resultEl.innerHTML = results.map(function (item) {
                // 'aside' maps to the --note modifier, matching the server's
                // class names exactly; a mismatch silently drops the styling.
                var modifier = item.kind === 'aside' ? 'note'
                             : item.kind === 'photo' ? 'photo'
                             : 'article';
                if (modifier !== 'article') {
                    return '<article class="post-card post-card--' + modifier + '">'
                        + '<div class="post-card__inner">'
                        + (item.date
                            ? '<time class="post-card__date"><a href="' + escHtml(item.url) + '">' + escHtml(item.date) + '</a></time>'
                            : '')
                        + '<p class="post-card__body">' + escHtml(item.body_text || '') + '</p>'
                        + '</div></article>';
                }
                return '<article class="post-card post-card--article">'
                    + '<div class="post-card__inner">'
                    + '<h2 class="post-card__title"><a href="' + escHtml(item.url) + '">'
                    + escHtml(item.title) + '</a></h2>'
                    + (item.excerpt ? '<p class="post-card__excerpt">' + escHtml(item.excerpt) + '</p>' : '')
                    + (item.date ? '<footer class="post-card__meta"><time>' + escHtml(item.date) + '</time></footer>' : '')
                    + '</div></article>';
            }).join('');
        })
        .catch(function () {
            resultEl.innerHTML = '<p class="search-empty">Search unavailable. Please try again.</p>';
        });
}());
</script>
<?php
$bodyContent = ob_get_clean();

echo $render('base.php', compact(
    'pageTitle', 'description', 'canonical', 'ogType', 'bodyContent',
    'settings', 'navPages', 'siteUrl', 'render', 'criticalCss', 'headerAvatar'
));
