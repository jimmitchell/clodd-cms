/* Admin JS — EasyMDE init, slug generation, media insert, sidebar toggle */

'use strict';

// ── Slug helpers ──────────────────────────────────────────────────────────────

function slugify(text) {
    return text
        .toLowerCase()
        .replace(/['"]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '') || 'untitled';
}

(function initSlugField() {
    const titleInput = document.querySelector('[data-slug-source]');
    const slugInput  = document.getElementById('slug');
    const kindSelect = document.getElementById('post_kind');
    if (!titleInput || !slugInput) return;

    // Only auto-update if the slug hasn't been manually edited yet.
    let userEditedSlug = slugInput.value !== '';

    slugInput.addEventListener('input', () => { userEditedSlug = true; });

    titleInput.addEventListener('input', () => {
        // Asides slug from their body, not the title. The body is an EasyMDE
        // instance whose input events don't reach the raw textarea, so leave the
        // field blank and let the server derive it on save.
        if (kindSelect && kindSelect.value === 'aside') return;
        if (!userEditedSlug) {
            slugInput.value = slugify(titleInput.value);
        }
    });
})();

// ── Post kind toggle (Standard / Aside) ───────────────────────────────────────

(function initPostKindToggle() {
    const kindSelect = document.getElementById('post_kind');
    const titleInput = document.getElementById('title');
    if (!kindSelect) return;

    function apply() {
        const isAside = kindSelect.value === 'aside';

        document.querySelectorAll('[data-kind-only]').forEach(el => {
            el.hidden = el.dataset.kindOnly !== (isAside ? 'aside' : 'standard');
        });

        if (titleInput) {
            titleInput.required = !isAside;
        }
    }

    kindSelect.addEventListener('change', apply);
    apply();
})();

// ── Slug uniqueness check ─────────────────────────────────────────────────────

(function initSlugCheck() {
    const slugInput = document.getElementById('slug');
    if (!slugInput) return;

    const type = document.body.dataset.slugType; // 'post' or 'page'
    const id   = document.body.dataset.slugId;   // current record id, or empty for new
    if (!type) return;

    // Insert a status span inline inside the flex container, after the input.
    const status = document.createElement('span');
    status.id = 'slug-check-status';
    status.style.cssText = 'font-size:.8rem;white-space:nowrap';
    slugInput.insertAdjacentElement('afterend', status);

    let timer = null;

    function check(slug) {
        slug = slug.trim();
        status.textContent = '';
        if (!slug || slug === 'untitled') return;

        clearTimeout(timer);
        timer = setTimeout(async () => {
            const params = new URLSearchParams({ type, slug });
            if (id) params.set('id', id);
            try {
                const res  = await fetch('/admin/slug-check.php?' + params);
                const data = await res.json();
                if (data.available) {
                    status.style.color = 'var(--color-success, #2a9d5c)';
                    status.textContent = '✓ available';
                } else {
                    status.style.color = 'var(--color-danger, #c0392b)';
                    status.textContent = '✗ already in use';
                }
            } catch {
                status.textContent = '';
            }
        }, 350);
    }

    slugInput.addEventListener('input', () => check(slugInput.value));

    // Check initial value (edit mode) after a short delay.
    if (slugInput.value) setTimeout(() => check(slugInput.value), 600);
})();

// ── Form action helper ────────────────────────────────────────────────────────

function setAction(action) {
    const el = document.getElementById('form-action');
    if (el) el.value = action;
    return true;
}

// ── EasyMDE ───────────────────────────────────────────────────────────────────

(function initEditor() {
    const textarea = document.getElementById('content');
    if (!textarea || typeof EasyMDE === 'undefined') return;

    function insertShortcode(editor, shortcode) {
        const cm  = editor.codemirror;
        const pos = cm.getCursor();
        cm.replaceRange('\n' + shortcode + '\n', pos);
        cm.focus();
    }

    const embedButtons = [
        {
            name: 'embed-youtube',
            action: function(editor) {
                const id = prompt('YouTube video ID (e.g. dQw4w9WgXcQ):');
                if (id && id.trim()) insertShortcode(editor, `[youtube id="${id.trim()}"]`);
            },
            className: 'fa fa-youtube-play',
            title: 'Embed YouTube video',
        },
        {
            name: 'embed-vimeo',
            action: function(editor) {
                const id = prompt('Vimeo video ID (e.g. 123456789):');
                if (id && id.trim()) insertShortcode(editor, `[vimeo id="${id.trim()}"]`);
            },
            className: 'fa fa-vimeo',
            title: 'Embed Vimeo video',
        },
        {
            name: 'embed-gist',
            action: function(editor) {
                const url = prompt('GitHub Gist URL (e.g. https://gist.github.com/user/abc123):');
                if (url && url.trim()) insertShortcode(editor, `[gist url="${url.trim()}"]`);
            },
            className: 'fa fa-github',
            title: 'Embed GitHub Gist',
        },
        {
            name: 'embed-mastodon',
            action: function(editor) {
                const url = prompt('Mastodon post URL (e.g. https://mastodon.social/@user/123):');
                if (url && url.trim()) insertShortcode(editor, `[mastodon url="${url.trim()}"]`);
            },
            className: 'fa fa-at',
            title: 'Embed Mastodon post',
        },
        {
            name: 'embed-instagram',
            action: function(editor) {
                const url = prompt('Instagram post URL (e.g. https://www.instagram.com/p/ABC123/):');
                if (url && url.trim()) insertShortcode(editor, `[instagram url="${url.trim()}"]`);
            },
            className: 'fa fa-instagram',
            title: 'Embed Instagram post',
        },
        {
            name: 'embed-tweet',
            action: function(editor) {
                const url = prompt('X / Twitter post URL (e.g. https://x.com/user/status/123):');
                if (url && url.trim()) insertShortcode(editor, `[tweet url="${url.trim()}"]`);
            },
            className: 'fa fa-twitter',
            title: 'Embed X / Twitter post',
        },
        {
            name: 'embed-linkedin',
            action: function(editor) {
                const urn = prompt('LinkedIn URN from "Embed this post" (e.g. urn:li:share:1234567890):');
                if (urn && urn.trim()) insertShortcode(editor, `[linkedin urn="${urn.trim()}"]`);
            },
            className: 'fa fa-linkedin',
            title: 'Embed LinkedIn post',
        },
    ];

    window._editor = new EasyMDE({
        element: textarea,
        autoDownloadFontAwesome: false,
        spellChecker: false,
        autofocus: false,
        autosave: { enabled: false },
        onChange: function () {
            const btn = document.getElementById('update-btn');
            if (btn) btn.disabled = false;
        },
        toolbar: [
            'bold', 'italic', 'heading', '|',
            'quote', 'unordered-list', 'ordered-list', '|',
            'link', 'image', 'table', '|',
            'preview', 'side-by-side', 'fullscreen', '|',
            ...embedButtons, '|',
            'guide'
        ],
        minHeight: '360px',
        renderingConfig: { singleLineBreaks: false },
    });
})();

// ── Aside syndication length heads-up ────────────────────────────────────────
// For aside (note) posts, the full content is syndicated natively to Mastodon
// and Bluesky with no link back. Show a live counter so the author knows when
// the note will be truncated on either platform.

(function initAsideLengthHint() {
    const status     = document.getElementById('aside-length-status');
    const kindSelect = document.getElementById('post_kind');
    const textarea   = document.getElementById('content');
    if (!status || !kindSelect || !textarea) return;

    const MASTODON_LIMIT = 500;
    const BLUESKY_LIMIT  = 300;

    const segmenter = (typeof Intl !== 'undefined' && Intl.Segmenter)
        ? new Intl.Segmenter(undefined, { granularity: 'grapheme' })
        : null;

    function plaintextFromMarkdown(md) {
        let t = md;
        t = t.replace(/<[^>]+>/g, '');                      // HTML tags
        t = t.replace(/^#{1,6}[ \t]+/gm, '');               // headings
        t = t.replace(/(\*{1,3}|_{1,3})([\s\S]+?)\1/g, '$2'); // bold/italic
        t = t.replace(/~~([\s\S]+?)~~/g, '$1');             // strikethrough
        t = t.replace(/`+[^`]*`+/g, '');                    // inline code
        t = t.replace(/!\[[^\]]*\]\([^\)]*\)/g, '');        // images
        t = t.replace(/\[([^\]]+)\]\([^\)]*\)/g, '$1');     // links
        return t.trim();
    }

    function graphemeCount(s) {
        if (segmenter) {
            let n = 0;
            for (const _ of segmenter.segment(s)) n++;
            return n;
        }
        return [...s].length;
    }

    function getContent() {
        return (window._editor && typeof window._editor.value === 'function')
            ? window._editor.value()
            : textarea.value;
    }

    function update() {
        if (kindSelect.value !== 'aside') return;
        const text   = plaintextFromMarkdown(getContent());
        const chars  = [...text].length;     // code points — matches PHP mb_strlen
        const graphs = graphemeCount(text);

        const overBsky = graphs > BLUESKY_LIMIT;
        const overMast = chars  > MASTODON_LIMIT;

        let msg = `Bluesky ${graphs}/${BLUESKY_LIMIT} · Mastodon ${chars}/${MASTODON_LIMIT}`;
        if (overBsky || overMast) {
            const services = [overBsky && 'Bluesky', overMast && 'Mastodon']
                .filter(Boolean).join(' and ');
            msg += ` — will be truncated on ${services}`;
            status.style.color = 'var(--color-danger, #c0392b)';
        } else {
            status.style.color = '';
        }
        status.textContent = msg;
    }

    kindSelect.addEventListener('change', update);
    if (window._editor && window._editor.codemirror) {
        window._editor.codemirror.on('change', update);
    } else {
        textarea.addEventListener('input', update);
    }
    update();
})();

// ── Update-button dirty tracking ─────────────────────────────────────────────
// Enables #update-btn (present only on published posts/pages) once any
// change has been made, so it stays disabled until there is something to save.

(function initDirtyTracking() {
    const btn = document.getElementById('update-btn');
    if (!btn) return;

    function enable() { btn.disabled = false; }

    const form = btn.closest('form');
    if (form) {
        form.addEventListener('input',  enable);
        form.addEventListener('change', enable);
    }
})();

// ── Media insert helper ───────────────────────────────────────────────────────

(function initMediaInsert() {
    const grid = document.getElementById('media-insert-grid');
    if (!grid) return;

    grid.addEventListener('click', (e) => {
        // Don't single-insert when gallery-select mode is active.
        if (grid.classList.contains('gallery-select-mode')) return;

        const btn = e.target.closest('[data-url]');
        if (!btn) return;

        const url   = btn.dataset.url;
        const type  = btn.dataset.type;   // image | video | audio
        const name  = btn.dataset.name || '';

        let markdown;
        if (type === 'image') {
            markdown = `![${name}](${url})`;
        } else if (type === 'video') {
            markdown = `<video src="${url}" controls></video>`;
        } else {
            markdown = `<audio src="${url}" controls></audio>`;
        }

        const editor = window._editor;
        if (editor) {
            const cm = editor.codemirror;
            const pos = cm.getCursor();
            cm.replaceRange('\n' + markdown + '\n', pos);
            cm.focus();
        } else {
            // Fallback: append to plain textarea.
            const ta = document.getElementById('content');
            if (ta) {
                const start = ta.selectionStart;
                ta.value = ta.value.slice(0, start) + '\n' + markdown + '\n' + ta.value.slice(start);
            }
        }
    });
})();

// ── Gallery multi-select ──────────────────────────────────────────────────────

(function initGallerySelect() {
    const grid      = document.getElementById('media-insert-grid');
    const toggleBtn = document.getElementById('gallery-select-btn');
    const insertBtn = document.getElementById('gallery-insert-btn');
    if (!grid || !toggleBtn || !insertBtn) return;

    let galleryMode = false;
    const selected  = new Set();

    function updateInsertBtn() {
        if (selected.size >= 2) {
            insertBtn.style.display = '';
            insertBtn.textContent   = `Insert gallery (${selected.size} images)`;
        } else {
            insertBtn.style.display = 'none';
        }
    }

    function enterGalleryMode() {
        galleryMode = true;
        grid.classList.add('gallery-select-mode');
        toggleBtn.textContent = 'Cancel';
        toggleBtn.classList.add('btn--danger');
        toggleBtn.classList.remove('btn--secondary');
    }

    function exitGalleryMode() {
        galleryMode = false;
        selected.clear();
        grid.classList.remove('gallery-select-mode');
        grid.querySelectorAll('.media-thumb--selected').forEach(el => el.classList.remove('media-thumb--selected'));
        toggleBtn.textContent = 'Select for gallery';
        toggleBtn.classList.remove('btn--danger');
        toggleBtn.classList.add('btn--secondary');
        insertBtn.style.display = 'none';
    }

    toggleBtn.addEventListener('click', () => {
        galleryMode ? exitGalleryMode() : enterGalleryMode();
    });

    grid.addEventListener('click', (e) => {
        if (!galleryMode) return;
        const thumb = e.target.closest('.media-thumb[data-type="image"]');
        if (!thumb) return;

        const id = thumb.dataset.id;
        if (!id) return;

        if (selected.has(id)) {
            selected.delete(id);
            thumb.classList.remove('media-thumb--selected');
        } else {
            selected.add(id);
            thumb.classList.add('media-thumb--selected');
        }
        updateInsertBtn();
    });

    insertBtn.addEventListener('click', () => {
        if (selected.size < 2) return;

        const shortcode = `[gallery ids="${[...selected].join(',')}"]`;
        const editor    = window._editor;
        if (editor) {
            const cm  = editor.codemirror;
            const pos = cm.getCursor();
            cm.replaceRange('\n' + shortcode + '\n', pos);
            cm.focus();
        } else {
            const ta = document.getElementById('content');
            if (ta) {
                const start = ta.selectionStart;
                ta.value = ta.value.slice(0, start) + '\n' + shortcode + '\n' + ta.value.slice(start);
            }
        }

        exitGalleryMode();
    });
})();

// ── Tag pill widget ───────────────────────────────────────────────────────────
// Progressively enhances the tags_csv text input into a pill-based tag editor
// with autocomplete against existing tags (window._existingTags).

(function initTagPills() {
    const csvInput = document.querySelector('input[name="tags_csv"]');
    if (!csvInput) return;

    const existingTags = (window._existingTags || []).map(t => t.name || t);

    // Build the widget container.
    const widget = document.createElement('div');
    widget.className = 'tag-pill-widget';
    widget.setAttribute('role', 'combobox');
    widget.setAttribute('aria-haspopup', 'listbox');
    widget.setAttribute('aria-expanded', 'false');

    // Visible text input inside the widget.
    const textInput = document.createElement('input');
    textInput.type = 'text';
    textInput.className = 'tag-pill-widget__input';
    textInput.placeholder = csvInput.placeholder || 'Add a tag…';
    textInput.setAttribute('autocomplete', 'off');
    textInput.setAttribute('aria-autocomplete', 'list');
    widget.appendChild(textInput);

    // Autocomplete dropdown.
    const dropdown = document.createElement('ul');
    dropdown.className = 'tag-autocomplete';
    dropdown.setAttribute('role', 'listbox');
    dropdown.hidden = true;
    widget.appendChild(dropdown);

    // Hide the original input but keep it in the form.
    csvInput.type = 'hidden';
    csvInput.insertAdjacentElement('afterend', widget);

    let tags = csvInput.value
        ? csvInput.value.split(',').map(t => t.trim()).filter(Boolean)
        : [];

    let activeIndex = -1;

    function sync() {
        csvInput.value = tags.join(', ');
    }

    function closeDropdown() {
        dropdown.replaceChildren();
        dropdown.hidden = true;
        activeIndex = -1;
        widget.setAttribute('aria-expanded', 'false');
    }

    function renderDropdown(query) {
        const q = query.trim().toLowerCase();
        dropdown.replaceChildren();
        activeIndex = -1;

        if (!q) { closeDropdown(); return; }

        const matches = existingTags.filter(t =>
            t.toLowerCase().includes(q) && !tags.includes(t)
        );

        if (!matches.length) { closeDropdown(); return; }

        matches.forEach((t) => {
            const li = document.createElement('li');
            li.className = 'tag-autocomplete__item';
            li.setAttribute('role', 'option');
            li.textContent = t;
            li.addEventListener('mousedown', (e) => {
                // mousedown fires before blur — prevent blur from closing first.
                e.preventDefault();
                addTag(t);
                textInput.value = '';
                closeDropdown();
                textInput.focus();
            });
            dropdown.appendChild(li);
        });

        dropdown.hidden = false;
        widget.setAttribute('aria-expanded', 'true');
    }

    function setActive(idx) {
        const items = dropdown.querySelectorAll('.tag-autocomplete__item');
        items.forEach(el => el.classList.remove('tag-autocomplete__item--active'));
        activeIndex = Math.max(-1, Math.min(idx, items.length - 1));
        if (activeIndex >= 0) {
            items[activeIndex].classList.add('tag-autocomplete__item--active');
            items[activeIndex].scrollIntoView({ block: 'nearest' });
        }
    }

    function renderPill(tag) {
        const pill = document.createElement('span');
        pill.className = 'tag-pill';
        pill.textContent = tag;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'tag-pill__remove';
        btn.setAttribute('aria-label', 'Remove ' + tag);
        btn.textContent = '×';
        btn.addEventListener('click', () => {
            tags = tags.filter(t => t !== tag);
            pill.remove();
            sync();
        });

        pill.appendChild(btn);
        widget.insertBefore(pill, textInput);
    }

    function addTag(raw) {
        const name = raw.trim();
        if (!name || tags.includes(name)) return;
        tags.push(name);
        renderPill(name);
        sync();
    }

    // Render initial tags.
    tags.forEach(renderPill);
    tags = [...tags]; // keep reference clean after render

    // Re-sync tags array from pills (avoids duplication on initial render).
    tags = csvInput.value
        ? csvInput.value.split(',').map(t => t.trim()).filter(Boolean)
        : [];

    textInput.addEventListener('input', () => {
        renderDropdown(textInput.value);
    });

    textInput.addEventListener('keydown', (e) => {
        if (!dropdown.hidden) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActive(activeIndex + 1);
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActive(activeIndex - 1);
                return;
            }
            if (e.key === 'Escape') {
                closeDropdown();
                return;
            }
            if ((e.key === 'Enter' || e.key === ',') && activeIndex >= 0) {
                e.preventDefault();
                const items = dropdown.querySelectorAll('.tag-autocomplete__item');
                addTag(items[activeIndex].textContent);
                textInput.value = '';
                closeDropdown();
                return;
            }
        }

        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            addTag(textInput.value);
            textInput.value = '';
            closeDropdown();
        } else if (e.key === 'Backspace' && textInput.value === '' && tags.length) {
            // Remove last pill on backspace when input is empty.
            tags.pop();
            widget.querySelector('.tag-pill:last-of-type')?.remove();
            sync();
        }
    });

    textInput.addEventListener('blur', () => {
        // Delay so mousedown on a suggestion can fire first.
        setTimeout(() => {
            closeDropdown();
            if (textInput.value.trim()) {
                addTag(textInput.value);
                textInput.value = '';
            }
        }, 150);
    });

    // Clicking anywhere in the widget focuses the text input.
    widget.addEventListener('click', (e) => {
        if (!e.target.closest('.tag-pill')) textInput.focus();
    });
})();

// ── Keyboard shortcuts ────────────────────────────────────────────────────────
// Ctrl/Cmd+S → save draft (or update if published)
// Ctrl/Cmd+Shift+P → publish

(function initKeyboardShortcuts() {
    const form = document.getElementById('post-form');
    if (!form) return;

    function triggerSave() {
        setAction('draft');
        const updateBtn = document.getElementById('update-btn');
        if (updateBtn) updateBtn.disabled = false;
        form.submit();
    }

    function triggerPublish() {
        setAction('publish');
        form.submit();
    }

    document.addEventListener('keydown', (e) => {
        const mod = e.ctrlKey || e.metaKey;
        if (!mod) return;

        if (!e.shiftKey && (e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            triggerSave();
        } else if (e.shiftKey && (e.key === 'p' || e.key === 'P')) {
            e.preventDefault();
            triggerPublish();
        }
    });

    // Also register with CodeMirror so shortcuts work when the editor has focus.
    const editor = window._editor;
    if (editor) {
        editor.codemirror.addKeyMap({
            'Ctrl-S':       function() { triggerSave();    return false; },
            'Cmd-S':        function() { triggerSave();    return false; },
            'Ctrl-Shift-P': function() { triggerPublish(); return false; },
            'Cmd-Shift-P':  function() { triggerPublish(); return false; },
        });
    }
})();

// ── Autosave drafts ───────────────────────────────────────────────────────────
// Persists title, slug, content, and excerpt to localStorage every 2 s of
// inactivity.  On page load, detects a stored draft and offers to restore it.

(function initAutosave() {
    const form = document.getElementById('post-form');
    if (!form) return;

    const postId      = document.body.dataset.slugId || 'new';
    const STORAGE_KEY = 'cms_draft_' + postId;
    const DEBOUNCE_MS = 2000;

    let saveTimer = null;
    let statusEl  = null;

    function getFormValues() {
        const editor = window._editor;
        return {
            title:   (document.getElementById('title')?.value   ?? '').trim(),
            slug:    (document.getElementById('slug')?.value    ?? '').trim(),
            content: editor ? editor.value() : (document.getElementById('content')?.value ?? ''),
            excerpt: (document.getElementById('excerpt')?.value ?? '').trim(),
        };
    }

    function getStoredDraft() {
        try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null'); }
        catch { return null; }
    }

    function saveDraft() {
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify({ ...getFormValues(), savedAt: Date.now() })); }
        catch {}
        showStatus('Draft saved locally');
    }

    function clearDraft() {
        try { localStorage.removeItem(STORAGE_KEY); } catch {}
    }

    function showStatus(msg) {
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.style.opacity = '1';
        clearTimeout(statusEl._fadeTimer);
        statusEl._fadeTimer = setTimeout(() => { statusEl.style.opacity = '0'; }, 2500);
    }

    function scheduleSave() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(saveDraft, DEBOUNCE_MS);
    }

    // Status indicator injected at the bottom of the first sidebar panel.
    const firstPanel = form.querySelector('.panel');
    if (firstPanel) {
        statusEl = document.createElement('p');
        statusEl.className = 'form-hint';
        statusEl.id = 'autosave-status';
        statusEl.style.cssText = 'transition:opacity .5s;opacity:0;margin-top:.25rem';
        firstPanel.appendChild(statusEl);
    }

    // Watch for changes.
    ['title', 'slug', 'excerpt'].forEach(id => {
        document.getElementById(id)?.addEventListener('input', scheduleSave);
    });
    window._editor?.codemirror.on('change', scheduleSave);

    // Clear draft when the form is submitted so stale drafts don't resurface.
    form.addEventListener('submit', clearDraft);

    // ── Recovery banner ──────────────────────────────────────────────────────
    const draft = getStoredDraft();
    if (!draft) return;

    const current     = getFormValues();
    const isDifferent = draft.title   !== current.title
        || draft.content !== current.content
        || draft.excerpt !== current.excerpt
        || draft.slug    !== current.slug;

    if (!isDifferent) { clearDraft(); return; }

    const age     = draft.savedAt ? Math.round((Date.now() - draft.savedAt) / 60000) : null;
    const ageText = age === null ? '' : age < 1 ? ' (just now)' : ` (${age} min ago)`;

    const banner = document.createElement('div');
    banner.className = 'alert alert--info';
    banner.style.cssText = 'display:flex;align-items:center;gap:.75rem;flex-wrap:wrap';

    const msg = document.createElement('span');
    msg.textContent = 'Unsaved local draft found' + ageText + '.';
    banner.appendChild(msg);

    const restoreBtn = document.createElement('button');
    restoreBtn.type = 'button';
    restoreBtn.className = 'btn btn--sm';
    restoreBtn.textContent = 'Restore';
    restoreBtn.addEventListener('click', () => {
        const titleEl   = document.getElementById('title');
        const slugEl    = document.getElementById('slug');
        const excerptEl = document.getElementById('excerpt');
        const ed        = window._editor;
        if (titleEl)   titleEl.value   = draft.title;
        if (slugEl)    slugEl.value    = draft.slug;
        if (excerptEl) excerptEl.value = draft.excerpt;
        if (ed)        ed.value(draft.content);
        else { const ta = document.getElementById('content'); if (ta) ta.value = draft.content; }
        const updateBtn = document.getElementById('update-btn');
        if (updateBtn) updateBtn.disabled = false;
        clearDraft();
        banner.remove();
    });

    const discardBtn = document.createElement('button');
    discardBtn.type = 'button';
    discardBtn.className = 'btn btn--secondary btn--sm';
    discardBtn.textContent = 'Discard';
    discardBtn.addEventListener('click', () => { clearDraft(); banner.remove(); });

    banner.appendChild(restoreBtn);
    banner.appendChild(discardBtn);

    const mainEl = document.querySelector('.admin-main');
    if (mainEl) mainEl.insertBefore(banner, form);
})();

// ── Sidebar toggle + tooltip ──────────────────────────────────────────────────

(function initSidebarToggle() {
    const STORAGE_KEY = 'cms_nav_collapsed';
    const MOBILE_BP   = 700; // must match the CSS breakpoint
    const btn = document.getElementById('nav-toggle');
    const nav = document.getElementById('admin-nav');
    if (!btn) return;

    // ── Backdrop (mobile only) ───────────────────────────────────────────────
    const backdrop = document.createElement('div');
    backdrop.className = 'mobile-nav-backdrop';
    document.body.appendChild(backdrop);

    // ── Helpers ──────────────────────────────────────────────────────────────
    function isMobile() {
        return window.innerWidth <= MOBILE_BP;
    }

    function setCollapsed(collapsed) {
        document.body.classList.toggle('nav-collapsed', collapsed);
        try { localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0'); } catch (e) {}
    }

    function closeMobileNav() {
        document.body.classList.remove('mobile-nav-open');
    }

    // ── Toggle button ────────────────────────────────────────────────────────
    btn.addEventListener('click', () => {
        if (isMobile()) {
            document.body.classList.toggle('mobile-nav-open');
        } else {
            setCollapsed(!document.body.classList.contains('nav-collapsed'));
            hideTip();
        }
    });

    // ── Close mobile drawer on backdrop tap ──────────────────────────────────
    backdrop.addEventListener('click', closeMobileNav);

    // ── Close mobile drawer when a nav link is tapped ────────────────────────
    if (nav) {
        nav.addEventListener('click', (e) => {
            if (!isMobile()) return;
            if (e.target.closest('a[href], button[type="submit"]')) {
                // Small delay lets the browser start the navigation first.
                setTimeout(closeMobileNav, 80);
            }
        });
    }

    // ── Clean up mobile state when resizing to desktop ───────────────────────
    window.addEventListener('resize', () => {
        if (!isMobile()) {
            closeMobileNav();
        }
    }, { passive: true });

    // ── Hover tooltips (desktop collapsed mode only) ─────────────────────────
    let tip = null;

    function getTip() {
        if (!tip) {
            tip = document.createElement('div');
            tip.className = 'nav-tooltip';
            document.body.appendChild(tip);
        }
        return tip;
    }

    function showTip(el) {
        if (!document.body.classList.contains('nav-collapsed')) return;
        if (isMobile()) return;
        const label = el.dataset.label;
        if (!label) return;
        const rect = el.getBoundingClientRect();
        const t = getTip();
        t.textContent = label;
        t.style.top  = (rect.top + rect.height / 2) + 'px';
        t.style.left = (rect.right + 10) + 'px';
        t.classList.add('visible');
    }

    function hideTip() {
        if (tip) tip.classList.remove('visible');
    }

    if (nav) {
        nav.addEventListener('mouseover', (e) => {
            const el = e.target.closest('[data-label]');
            if (el) showTip(el);
        });
        nav.addEventListener('mouseout', (e) => {
            const el = e.target.closest('[data-label]');
            if (el) hideTip();
        });
    }
})();
