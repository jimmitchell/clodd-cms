/*
 * Public theme behaviour: code-copy buttons, lightbox, mobile nav, search
 * overlay and theme toggle.
 *
 * Extracted from templates/base.php, where it was inlined into every
 * generated page — ~11.5 KB repeated across 860+ files that the browser
 * could never cache. Served as a file it is fetched once per visitor.
 */
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
    // One lightbox for every picture on the site. Three things open into it:
    // a tiled [gallery]'s items and a photo post's attached u-photo rows, both
    // of which the templates mark with data-gallery-item, and an image written
    // into a post's body, which has no wrapper of its own. Those were two
    // separate implementations, and the body image got the poorer one: no way
    // to step to the next picture. So a photo post written in the admin, where
    // the picture goes in the body, behaved differently from one sent by
    // Micropub, where it arrives as an attachment — a distinction no reader can
    // see, since both are the same picture on the same kind of post.
    //
    // Items group by the post they belong to, so the arrows walk that post's
    // own pictures and stop there. On an archive page each card is its own
    // group; a gallery the server split into sibling chunks stays one, because
    // the chunks share an article.
    var lbGroups = new Map();

    // One pass in document order over both kinds, so a post's attached photos
    // and its body images fall into one sequence in the order they are read.
    document.querySelectorAll('[data-gallery-item], .prose img').forEach(function (el) {
        var item;

        if (el.matches('[data-gallery-item]')) {
            var inner = el.querySelector('img');
            item = { href: el.href, alt: (inner && inner.alt) || '', link: true };
        } else {
            // The anchor above already collected this one.
            if (el.closest('[data-gallery-item]')) { return; }
            // An image the author linked somewhere: that link is the intent.
            if (el.closest('a')) { return; }
            item = { href: el.src, alt: el.alt, link: false };
        }

        var owner = el.closest('article') || document.body;
        var group = lbGroups.get(owner);
        if (!group) { group = []; lbGroups.set(owner, group); }

        var index = group.length;
        group.push(item);

        el.addEventListener('click', function (e) {
            if (item.link) { e.preventDefault(); }
            lbOpen(group, index);
        });
    });

    var lbItems   = [];
    var lbCursor  = 0;
    var lbOverlay = null;
    var lbImg, lbClose, lbPrev, lbNext;

    function lbBuild() {
        lbOverlay = document.createElement('div');
        lbOverlay.className = 'gallery-lightbox';
        lbOverlay.setAttribute('role', 'dialog');
        lbOverlay.setAttribute('aria-modal', 'true');
        lbOverlay.setAttribute('aria-label', 'Image lightbox');

        lbImg = document.createElement('img');
        lbImg.className = 'gallery-lightbox__img';
        lbImg.setAttribute('alt', '');

        lbClose = document.createElement('button');
        lbClose.className = 'gallery-lightbox__btn gallery-lightbox__close';
        lbClose.setAttribute('aria-label', 'Close');
        lbClose.innerHTML = '&times;';

        lbPrev = document.createElement('button');
        lbPrev.className = 'gallery-lightbox__btn gallery-lightbox__prev';
        lbPrev.setAttribute('aria-label', 'Previous image');
        lbPrev.innerHTML = '&#8249;';

        lbNext = document.createElement('button');
        lbNext.className = 'gallery-lightbox__btn gallery-lightbox__next';
        lbNext.setAttribute('aria-label', 'Next image');
        lbNext.innerHTML = '&#8250;';

        lbOverlay.appendChild(lbImg);
        lbOverlay.appendChild(lbClose);
        lbOverlay.appendChild(lbPrev);
        lbOverlay.appendChild(lbNext);
        document.body.appendChild(lbOverlay);

        lbClose.addEventListener('click', lbHide);
        lbPrev.addEventListener('click', function () { lbShow(lbCursor - 1); });
        lbNext.addEventListener('click', function () { lbShow(lbCursor + 1); });

        lbOverlay.addEventListener('click', function (e) {
            if (e.target === lbOverlay) { lbHide(); }
        });

        document.addEventListener('keydown', function (e) {
            if (!lbOverlay.classList.contains('is-open')) { return; }
            if (e.key === 'Escape')     { lbHide(); }
            if (e.key === 'ArrowLeft')  { lbShow(lbCursor - 1); }
            if (e.key === 'ArrowRight') { lbShow(lbCursor + 1); }
        });
    }

    function lbOpen(group, index) {
        if (!lbOverlay) { lbBuild(); }
        lbItems = group;
        // A post with a single picture has nowhere to step to, so the arrows
        // would only lead back to it. Each group answers this for itself.
        lbPrev.hidden = lbNext.hidden = group.length < 2;
        lbShow(index);
        lbOverlay.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        lbClose.focus();
    }

    function lbShow(index) {
        lbCursor  = (index + lbItems.length) % lbItems.length;
        lbImg.src = lbItems[lbCursor].href;
        lbImg.alt = lbItems[lbCursor].alt;
    }

    function lbHide() {
        lbOverlay.classList.remove('is-open');
        document.body.style.overflow = '';
        lbImg.src = '';
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

    // ── Search overlay ───────────────────────────────────────────────────────
    // The header magnifier stays an <a href="/search/">; this only intercepts
    // the click. With JS off the link still works, and the overlay's form
    // submits natively, so Enter needs no handler here.
    var searchToggle  = document.getElementById('search-toggle');
    var searchOverlay = document.getElementById('search-overlay');
    var searchInput   = document.getElementById('search-overlay-q');

    if (searchToggle && searchOverlay && searchInput) {
        // Everything outside the dialog goes inert while it is open, which
        // keeps Tab inside the field without a hand-rolled focus trap.
        var pageRegions = document.querySelectorAll('.site-header, .site-main, .site-footer');

        function openSearch() {
            closeNav();
            // On a results page, start from the query already being viewed.
            var current = new URLSearchParams(window.location.search).get('q');
            if (current && !searchInput.value) { searchInput.value = current; }

            searchOverlay.classList.add('is-open');
            document.body.style.overflow = 'hidden';
            pageRegions.forEach(function (el) { el.inert = true; });
            searchInput.focus();
            searchInput.select();
        }

        function closeSearch() {
            if (!searchOverlay.classList.contains('is-open')) { return; }
            searchOverlay.classList.remove('is-open');
            document.body.style.overflow = '';
            pageRegions.forEach(function (el) { el.inert = false; });
            searchToggle.focus();
        }

        searchToggle.addEventListener('click', function (e) {
            e.preventDefault();
            openSearch();
        });

        // Clicking the blurred backdrop dismisses; clicking the panel does not.
        searchOverlay.addEventListener('click', function (e) {
            if (e.target === searchOverlay) { closeSearch(); }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { closeSearch(); }
        });
    }

    // ── Theme toggle (three-way: light → dark → system → light) ─────────────
    // The three glyphs ship in the page markup and the head script stamps
    // data-theme-pref on <html> before first paint, so CSS shows the right one
    // with no work here. This file only keeps that attribute in sync on click
    // and sets the aria-label, which CSS cannot do.

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
        document.documentElement.setAttribute('data-theme-pref', theme);
        localStorage.setItem('theme', theme);
        _updateToggleLabel(theme);
    }

    function _updateToggleLabel(theme) {
        var tb = document.getElementById('theme-toggle');
        if (!tb) { return; }
        if (theme === 'light') {
            tb.setAttribute('aria-label', 'Switch to dark mode');
        } else if (theme === 'dark') {
            tb.setAttribute('aria-label', 'Switch to system theme');
        } else {
            tb.setAttribute('aria-label', 'Switch to light mode');
        }
    }

    // While in system mode, track OS-level preference changes in real time.
    window.matchMedia('(prefers-color-scheme:dark)').addEventListener('change', function (e) {
        if (_storedTheme() === 'system') {
            document.documentElement.setAttribute('data-theme', e.matches ? 'dark' : 'light');
        }
    });

    // The glyph is already correct (head script + CSS); only the label is left.
    _updateToggleLabel(_storedTheme());

    var themeBtn = document.getElementById('theme-toggle');
    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            var next = { light: 'dark', dark: 'system', system: 'light' };
            _applyTheme(next[_storedTheme()] || 'light');
        });
    }

}());
