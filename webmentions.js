/* Webmention fetch + render. Loaded only when a webmention domain is set. */
(function () {
    var section = document.getElementById('webmentions');
    if (!section) return;

    var target = section.dataset.url;
    if (!target) return;

    /* The canonical plus every known link-tag variant, asked for in one request.
       webmention.io matches and stores a target verbatim, so a mention from a blog
       that tags its outbound links — Ghost appends ?ref=its-own-domain — is filed
       under an address this page would otherwise never ask about, and is accepted
       and invisible. The tags come from the webmention_link_tags setting.

       The cache stays keyed on the canonical alone: the variants change what is
       asked, not which post this is. */
    function withTag(url, tag) {
        return url + (url.indexOf('?') === -1 ? '?' : '&') + tag;
    }

    var _wmQuery = 'target[]=' + encodeURIComponent(target);
    (section.dataset.linkTags || '').split(/\s+/).forEach(function (tag) {
        if (tag) { _wmQuery += '&target[]=' + encodeURIComponent(withTag(target, tag)); }
    });

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

    // esc() stops attribute breakout but says nothing about the scheme, and
    // every URL below is authored by whoever sent the webmention. Without this
    // allowlist a u-url of "javascript:..." becomes a working XSS for anyone
    // who clicks the author link. Returns '' when the URL is not safe to emit.
    function safeUrl(u) {
        u = String(u || '');
        return /^https?:\/\//i.test(u) ? u : '';
    }

    function hostOf(url) {
        try { return new URL(url).hostname.replace(/^www\./, ''); } catch (e) { return ''; }
    }

    /* A page with no h-card comes back from webmention.io with an empty author,
       and "Anonymous" says nothing about a mention that plainly came from
       somewhere. The site it came from is both true and more use to a reader, so
       that is the fallback; "Anonymous" is kept for when even the source will not
       parse. The author's own URL is preferred over the mention permalink, which
       for a Bridgy mention would otherwise read "brid.gy". */
    function displayName(m) {
        var a = m.author || {};
        return a.name
            || hostOf(safeUrl(a.url) || safeUrl(m.url) || m['wm-source'] || '')
            || 'Anonymous';
    }

    // Note the sizes read backwards and are not: .wm-avatar is 32px and
    // .wm-avatar--sm is 40px in theme.css. The modifier names the *reply*
    // avatar, which is larger than the reaction avatars it sits among. The
    // width/height below match the stylesheet — keep them in step, they are
    // what stops the list reflowing as the images arrive.
    function buildAvatar(m, size) {
        var a = m.author || {};
        var cls = 'wm-avatar' + (size === 'sm' ? ' wm-avatar--sm' : '');
        var name = displayName(m);
        var initial = name.charAt(0).toUpperCase();
        var photo = safeUrl(a.photo);
        if (photo) {
            return '<img class="' + cls + '" src="' + esc(photo) + '" alt="' + esc(name) + '" width="' + (size === 'sm' ? 40 : 32) + '" height="' + (size === 'sm' ? 40 : 32) + '" loading="lazy">';
        }
        return '<span class="' + cls + ' wm-avatar--fallback">' + esc(initial) + '</span>';
    }

    function renderMentions(data) {
        var all      = data.children || [];

        /* Asking about several targets can return the same mention twice — one
           page verified against both the clean address and a tagged one. Same
           source, same property, one entry. Keys are prefixed so a source that
           happens to read like "__proto__" cannot poison the lookup. */
        var seen = {};
        all = all.filter(function (m) {
            var key = '#' + (m['wm-source'] || m.url || '') + '|' + (m['wm-property'] || '');
            if (seen[key]) { return false; }
            seen[key] = true;
            return true;
        });

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
                var link = safeUrl(a.url) || safeUrl(m.url) || '#';
                html += '<a href="' + esc(link) + '" target="_blank" rel="noopener noreferrer" title="' + esc(displayName(m)) + '" class="wm-avatar-link">' + buildAvatar(m, 'lg') + '</a>';
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
                var name    = displayName(m);
                var srcLink = safeUrl(a.url) || safeUrl(m.url) || '#';
                var pub     = m.published || m['wm-received'] || '';
                var dateStr = '';
                if (pub) {
                    try { dateStr = new Date(pub).toLocaleDateString('en', { year: 'numeric', month: 'short', day: 'numeric' }); } catch (e) {}
                }
                // Emoji here sometimes arrive as runs of "?" \u2014 that corruption is
                // already in what webmention.io serves, not something we introduce.
                // Their column is utf8mb3, so 4-byte characters are replaced on
                // insert while 3-byte ones (curly quotes, dashes) survive intact.
                // The bytes never reached their database, so nothing on this side
                // can recover them. Upstream: aaronpk/webmention.io#203.
                var text = (m.content && m.content.text) ? m.content.text : '';
                if (text.length > 300) text = text.slice(0, 300) + '\u2026';

                html += '<article class="wm-reply">';
                html += '<header class="wm-reply__header">';
                html += '<a href="' + esc(srcLink) + '" target="_blank" rel="noopener noreferrer" class="wm-avatar-link">' + buildAvatar(m, 'sm') + '</a>';
                html += '<div class="wm-reply__meta">';
                html += '<a href="' + esc(srcLink) + '" target="_blank" rel="noopener noreferrer" class="wm-reply__author">' + esc(name) + '</a>';
                if (dateStr) {
                    html += '<a href="' + esc(safeUrl(m.url) || '#') + '" target="_blank" rel="noopener noreferrer" class="wm-reply__date-link"><time class="wm-reply__date" datetime="' + esc(pub) + '">' + esc(dateStr) + '</time></a>';
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
        fetch('https://webmention.io/api/mentions.jf2?' + _wmQuery + '&per-page=100')
            .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
            .then(function (data) {
                try { sessionStorage.setItem(_wmCacheKey, JSON.stringify({ts: Date.now(), data: data})); } catch (e) {}
                renderMentions(data);
            })
            .catch(function () { /* fail silently */ });
    }
}());

/* Manual webmention submission.
 *
 * A second, independent initialiser rather than more code inside the one above:
 * that block returns early when #webmentions is missing, and .webmentions stays
 * display:none until mentions load — so on a post with none, which is exactly
 * where a submission is worth most, it would never run.
 *
 * This only upgrades a form that already works. With JS off, or running a copy
 * of this file cached before the feature existed, the native POST still lands
 * and the browser ends up on webmention.io's own confirmation page.
 *
 * The endpoint answers every case with JSON and sends `access-control-allow-origin: *`
 * on all of them — the 201, the 400, the 404, and the per-mention status URL it
 * hands back. (Its GET and OPTIONS do not, which is misleading if you probe those
 * first and conclude the reply is unreadable.) So this reports what actually
 * happened rather than guessing:
 *
 *   POST   201 {status: "queued", location: "…"}   → poll that location
 *          4xx {error, error_description}          → say what was wrong
 *   status     {status: "queued"}                  → still verifying, poll again
 *              {status: "success"}                 → stored
 *              {status: "no_link_found", summary}  → the source does not link here
 *
 * That last one is the common failure and the reason this exists: a Ghost blog
 * tags its outbound links with ?ref=, so a page can link to this post and still
 * not link to the URL being submitted. Fire-and-forget could not tell anyone.
 *
 * Everything reaches the page through textContent — no innerHTML, so webmention.io's
 * own error strings are inert and none of this depends on the esc() contract above.
 */
(function () {
    var form = document.querySelector('.wm-submit__form');
    if (!form || !window.fetch || !window.URLSearchParams) return;

    var status = form.querySelector('.wm-submit__status');
    var button = form.querySelector('.wm-submit__send');
    if (!status || !button) return;

    // Verification took about three seconds in testing; this waits roughly twenty
    // before giving up on a verdict and saying so.
    var POLL_DELAY    = 2000;
    var POLL_ATTEMPTS = 10;

    /* The link tags this site already knows to ask about, from the same data
       attribute the block above reads. Used only to decide what to promise: a
       mention filed under a tag that is not on this list is genuinely accepted
       and genuinely will not appear, so it must not be followed by "reload". */
    var wmSection = document.getElementById('webmentions');
    var knownTags = ((wmSection && wmSection.dataset.linkTags) || '').split(/\s+/)
        .filter(function (t) { return t !== ''; });

    function say(text, offerEndpoint) {
        status.textContent = text;
        if (offerEndpoint) {
            status.appendChild(document.createTextNode(' '));
            var a = document.createElement('a');
            a.href = form.action;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.textContent = 'Send it at webmention.io';
            status.appendChild(a);
        }
        status.hidden = false;
    }

    /* A failure the reader can act on leaves the form up with their URL still in
       it, so correcting a typo does not mean typing the whole thing again. Only
       success takes the form away. */
    function fail(text) {
        button.disabled = false;
        button.textContent = 'Send';
        say(text);
    }

    function settled(text) {
        form.classList.add('is-sent');
        say(text);
    }

    function done(ctx) {
        // Drop the 5-minute cache the block above writes, so the reload that
        // shows the new mention actually refetches.
        try { sessionStorage.removeItem('wm:' + ctx.canonical); } catch (err) {}

        if (!ctx.tag || knownTags.indexOf(ctx.tag) !== -1) {
            return settled('Accepted. Reload the page and it\u2019ll be here.');
        }
        // Stored under a tag this page does not query, so it is real but will not
        // show until the tag is added to the site's settings. Thank them and stop
        // rather than promise a reload that would show nothing.
        settled('Accepted \u2014 thanks for the link.');
    }

    function readJson(url, options) {
        return fetch(url, options).then(function (r) { return r.json(); });
    }

    /* Ghost and friends tag outbound links with their own domain, so a page can
       link to this post and still not link to the URL just submitted. The tag is
       derivable from what the reader typed — it is their own host — so rather
       than report a dead end the form tries once more with the tagged address.
       Returns '' when there is nothing sensible to retry with. */
    function tagFromSource(sourceUrl) {
        var host = '';
        try { host = new URL(sourceUrl).hostname; } catch (err) { return ''; }
        return host ? 'ref=' + host : '';
    }

    function poll(location, ctx, attemptsLeft) {
        readJson(location, {headers: {'Accept': 'application/json'}}).then(function (body) {
            var state = body && body.status;

            if (state === 'success') { return done(ctx); }

            if (state === 'queued') {
                if (attemptsLeft > 1) {
                    setTimeout(function () { poll(location, ctx, attemptsLeft - 1); }, POLL_DELAY);
                } else {
                    // Not a failure — webmention.io is just slow today. Say only that.
                    settled('Sent. webmention.io is still checking it; it\u2019ll appear here if your page links back.');
                }
                return;
            }

            if (state === 'no_link_found') {
                var tag = ctx.tag ? '' : tagFromSource(ctx.source);
                if (tag) {
                    ctx.tag    = tag;
                    ctx.target = ctx.canonical + (ctx.canonical.indexOf('?') === -1 ? '?' : '&') + tag;
                    return send(ctx);
                }
                return fail('That page doesn\u2019t link here. Some sites tag outbound links — Ghost adds ?ref= — and the address has to match this page\u2019s exactly.');
            }

            fail(body && body.summary ? body.summary : 'webmention.io could not verify that page.');
        }).catch(function () {
            // The mention is queued and will be verified with or without us; only
            // the verdict is lost, so do not call it a failure.
            settled('Sent. It\u2019ll appear here once webmention.io has checked that your page links back.');
        });
    }

    function send(ctx) {
        // URLSearchParams sets application/x-www-form-urlencoded itself and Accept
        // is a safelisted header, which together keep this a CORS-simple request.
        // It has to stay that way: the endpoint 404s on OPTIONS, so anything that
        // provokes a preflight fails before it is sent.
        return readJson(form.action, {
            method: 'POST',
            headers: {'Accept': 'application/json'},
            body: new URLSearchParams({source: ctx.source, target: ctx.target})
        }).then(function (body) {
            if (body && body.error) {
                return fail(body.error_description || 'webmention.io refused that submission.');
            }
            if (body && body.location) {
                button.textContent = 'Checking\u2026';
                return poll(body.location, ctx, POLL_ATTEMPTS);
            }
            // Queued but no status URL to watch — accepted as far as we can tell.
            settled('Sent. It\u2019ll appear here once webmention.io has checked that your page links back.');
        }).catch(function () {
            button.disabled = false;
            button.textContent = 'Send';
            say('Couldn\u2019t reach webmention.io — check your connection and try again, or:', true);
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        var source = form.elements.source ? form.elements.source.value.trim() : '';
        var target = form.elements.target ? form.elements.target.value : '';
        if (!source || !target) return;

        button.disabled = true;
        button.textContent = 'Sending\u2026';
        status.hidden = true;

        send({source: source, canonical: target, target: target, tag: ''});
    });
}());
