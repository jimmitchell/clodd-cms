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
