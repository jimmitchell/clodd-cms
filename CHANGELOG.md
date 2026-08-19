# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.22.0] — 2026-08-18

Schema v30: `posts.featured_image_url` and `posts.featured_image_alt`, both added by `ALTER TABLE` with no backfill.

### Added

- **Featured images for titled posts.** A titled post can now carry a lead picture as real data rather than as the first paragraph of its body. It renders between the post header and the words — where the picture on an imported post already sits — and, because the CMS now knows what it is, it becomes the post's image everywhere else too: `og:image` on the permalink, the thumbnail on home-page and archive cards, the related-posts thumbnail, JSON Feed's `item.image`, and the preview card on Mastodon and Bluesky.

  Settable from four places. The admin editor gets a **Featured image** panel that reuses the existing media grid — Choose puts the grid into a pick mode instead of adding a second browser — with its own alt-text field. Over XML-RPC it reads WordPress's `wp_post_thumbnail` (and `post_thumbnail`), accepting an attachment id, a bare media id, or a URL, and writes the value back out on both outgoing structs so a client can read back what it set. Over Micropub it is the mf2 `featured` property, handled in create, in `replace`/`add`/`delete`, and reported by `q=source`. The admin REST API carries it as `featured_image_url`/`featured_image_alt` in both directions, so the Obsidian client can see it.

  It is styled by *sharing* the body image's rules rather than restating them — same shadow, radius, width and wide-viewport bleed — so the two cannot drift apart. Those rules moved from the deferred half of `theme.css` into the critical half to do it, which also stops body images near the top of a post restyling after first paint, as they used to. Two tests pin it: no rule may target `.post__featured img` without also targeting `.prose img`, and that styling must sit above `=END CRITICAL=`, because the featured image is the Largest Contentful Paint element on a post that has one.

  The markup is `u-featured`, deliberately not `u-photo`: an article's lead picture is not its photo property, and saying otherwise changes how a Micropub client reads the post's type. `Post::save()` clears both columns for a note, so flipping an article to an aside or a photo cannot leave a picture that nothing renders.

- **A derived fallback, so nothing had to change to benefit.** `Post::effectiveFeaturedImage()` returns the stored image, or — on a titled post with none — the body's leading image, mirroring the `effectivePhotos()` pair that already solves this for photo posts. Every post written before the field existed therefore advertises its lead picture immediately, with no migration and no client support: MarsEdit works today whether or not it offers a thumbnail field, because putting the image at the top of the post is enough.

  The contract is the same one `photosOrBodyImages()` carries and is load-bearing in the same way: the helper is for consumers that *report* the image as data. Anything that renders it beside the body reads `featured_image_url` directly, or a derived picture is drawn twice — once in the featured figure and once in the content it was parsed out of. `templates/post.php` reads the raw column; the cards and the related list, which never render a titled post's body, use the helper. `BuilderOutputTest` asserts that from the template source, comments stripped, because the failure is silent and only visible on the page.

- **The picture cannot be drawn twice, whatever a client sends.** Setting a featured image that is *also* the body's leading image is reachable two ordinary ways — a Micropub client round-tripping `q=source` reads back the derived `featured` and may send it as a stored one, and an author can pick a featured image they had already pasted at the top of the post. `Post::contentForRender()` drops the body's copy on an exact URL match, so the featured figure is the only one. It is applied in `Builder` (page and preview) and in all three feeds, so no write path has to know the rule and the page and feeds cannot disagree. A *different* picture at the top of the body is left alone — that is a legitimate thing to have below a lead image. Nothing stored is rewritten; only what is rendered.

- **`bin/promote-featured-images.php`** converts derived to stored: for each titled post whose body begins with an image it moves the picture into the field and out of the content, and clears `content_hash` so the next build rewrites the page. Output is unchanged — the image moves from a `<p>` into the featured figure. Dry run by default, since it rewrites post bodies; `--force` writes, `--limit=N` caps a first pass.

### Fixed

- **Images uploaded from MarsEdit never got their WebP companions.** `xmlrpc_save_media()` wrote the file and the `media` row by hand and skipped `Media::generateWebp()`, which every other upload path calls — so `ImageTag::render()` fell back to a bare `<img>` for anything posted from MarsEdit, and the whole responsive pipeline quietly missed an entire upload route. It now generates them like the rest.

- **The upload response carried no id.** `metaWeblog.newMediaObject` and `wp.uploadFile` answered with a URL alone, which left a WordPress client nothing to put in `wp_post_thumbnail` — the featured-image field was unreachable by construction. Both now return `id`/`attachment_id`, offset the same way `wp.getMediaLibrary` reports them, plus `file` and `type`.

- **The `hidden` attribute did nothing to any button in the admin.** The browser's `[hidden] { display: none }` comes from the UA stylesheet, which any author rule setting `display` outranks — so `.btn { display: inline-flex }` had quietly made the attribute inert on every button on every admin page. (The lone `.tag-autocomplete[hidden]` rule was a local patch for the same thing.) One `[hidden] { display: none !important }` in the reset fixes it everywhere.

- **A card image described a slot eight times too small.** `templates/partials/post-card.php` asked for `sizes="160px"` while `.post-card__photo--thumb` caps the *height* and crops at the card's full width. It was harmless only because a titled post needed a Micropub photo row to have a card image at all; featured images make it the common case, so it now describes the real slot and stops picking a soft candidate for retina screens.

### Notes

- **A featured image is a link card thumbnail, never an attachment.** `Syndication::payload()` uses it in place of the generated `og.png` for the Bluesky card, and the permalink's `og:image` gives Mastodon the same picture. It is deliberately kept out of `images`: a Bluesky record holds exactly one embed and an image embed outranks the external card, so attaching it would silently cost every titled post the link card 1.21.1 added. `SyndicationTest` pins that.

- The generated title card is still built and is still the fallback, so removing a featured image leaves something behind. A stored path whose file is missing is not advertised — an `og:image` is a URL a crawler fetches, and a 404 there is worse than the card that still works.

---

## [1.21.2] — 2026-08-18

No schema change. Finishes the job 1.21.1 started, one release too early.

### Fixed

- **The XML-RPC endpoint also syndicated before it built — in four places.** 1.21.1 fixed `admin/post-edit.php` on the reasoning that Mastodon fetches a permalink for its preview card exactly once and never retries. `XmlRpcServer` had the same inversion at all four of its publish paths (`metaWeblog.newPost`, `metaWeblog.editPost`, `wp.newPost`, `wp.editPost`), so a post filed from MarsEdit still went out ahead of its own page. Its `rebuildPost()` docblock says it "mirrors the logic in `admin/post-edit.php`", which it faithfully did, bug included.

  The first post published after the 1.21.1 deploy proved it: the status was created at 11:42:32.347Z and both `index.html` and `og.png` were written at 11:42:33 — a second late. Mastodon got its 404 and produced no card, and the new Bluesky card came out with no thumbnail, because `Syndication::payload()` looked for an OG image that had not been written yet.

  All four sites now build first. The second pass that picks up the syndication links moved into `syndicatePost()` itself rather than being repeated at each call site, since four copies of a rule is how this got missed twice.

- **A structural guard against the third recurrence.** `XmlRpcServer` builds its own `Builder` and `Syndication`, so there is no seam to observe call order through, and the ordering has no locally visible effect — the copies are only wrong on someone else's server. `XmlRpcTest` now asserts from the source that every `syndicatePost()` call is immediately preceded by `rebuildPost()`. Crude, but it fails on exactly the edit that caused this, which neither of the two previous fixes did.

  Audited repo-wide while here: `Syndication::publish()` has exactly four call sites — `micropub.php`, `src/Scheduler.php`, `admin/post-edit.php` and `src/XmlRpcServer.php` — and all four now build first.

---

## [1.21.1] — 2026-08-18

No schema change. Both fixes are to what leaves the site on first publish; nothing on the public site changes.

### Fixed

- **A post published from the admin editor reached Mastodon before its page existed.** Mastodon fetches the permalink to build its preview card exactly once, seconds after the status is created, and never retries a fetch that failed. `admin/post-edit.php` syndicated at the top of the save handler and did not call `buildPost()` until fifty lines later, so a first publish from a draft — which by the handler's own comment "has nothing on disk" — handed Mastodon a URL that 404'd. The card was then lost for good, on a status that reads correctly and links correctly, which is what made this look like a theming problem rather than an ordering one.

  `micropub.php` and `Scheduler` have run build-then-syndicate since 1.13.3 for exactly this reason; that commit touched neither this handler nor its tests, so the editor path kept the old order for sixteen releases. Posts published from Obsidian got their cards and posts published from the editor did not.

  Syndication now runs after the build, and re-renders the post once when a copy was actually made, so the page still picks up the syndication links it lists. `remove()` and `update()` moved with it to keep the three calls in one place.

### Added

- **Bluesky link cards.** Unlike Mastodon, Bluesky fetches nothing: its AppView renders only what the record carries, so a link posted without an `app.bsky.embed.external` embed shows as bare text forever, no matter what the page's OpenGraph tags say. `Bluesky` only ever built `app.bsky.embed.images`, for photo posts, so every titled post this CMS has ever syndicated arrived as an unadorned URL.

  A titled post now carries its own card — title, excerpt and the OG image the build wrote moments earlier, uploaded as a blob rather than advertised as a URL. The thumbnail is read off local disk rather than fetched back over HTTP from our own web server, which also keeps the outbound-HTTP invariant out of it.

  A record holds exactly one embed, so a photo post's pictures still win; a note has no permalink and is untouched. A thumbnail that will not upload costs the picture and not the card.

  This does not backfill. Mastodon will not re-crawl a status it has already given up on, and a Bluesky card can only be added by a `putRecord` edit, which the AppView does not re-index — see the note in `CLAUDE.md`.

---

## [1.21.0] — 2026-08-18

No schema change. Adds one setting, `show_related_posts`, which defaults to **off** — the public site is unchanged until it is switched on in Settings → Content.

### Added

- **Related posts.** A titled post can now end with up to three others chosen by shared categories and tags, above the existing prev/next pair. Candidates are scored `2 × shared categories + 1 × shared tags`, ties broken by recency, so the closest match wins rather than the most recent one; a shared category counts double because tags are barely used here and tag overlap is the weaker signal. A post with nothing in common renders no block at all, not an empty heading.

  Notes and photo posts neither show the block nor appear in one — they carry no title to label a link with, which is the whole reason the feature is scoped to titled posts. Drafts, scheduled and soft-deleted posts are never candidates.

  The block deliberately carries no microformats classes. `partials/post-card.php` marks each card as an `h-entry` with `u-url` and `p-name`, which is right on a listing page and wrong on a permalink: a nested `h-entry` inside the page's own parses as a *child* entry, so a webmention consumer or Bridgy reading the post would see three phantom replies to it. Hence a separate `partials/related-posts.php` rather than a reuse.

  Visually the items stay at page tone instead of becoming white cards — the theme's rule is that an article lifts off the paper and a note recesses into it, and these are pointers to articles, not three more articles sitting on the page. Each carries its own top hairline, so a row mixing one thumbnail with two bare titles still shares a top edge; three rules abreast directly above `.post-nav`'s single one make the foot of a post one ruled system. One column below 600px.

### Fixed

- **A post's output can now depend on another post without going stale.** `Builder::buildPost()` hashes what it renders into `content_hash` and skips the write when it matches, which assumed a post's output depends only on that post. A related block breaks the assumption: publishing a new post in a shared category changes what every existing post in it should show, with no edit of their own to trigger a rebuild — the same shape as the photo-post bug fixed in 1.20.0.

  `buildPost()` now re-renders every post sharing a term with the one it just built, on the unpublish and soft-delete path as well as the publish path, guarded by a `deferRelated` flag that doubles as the recursion guard and is raised for the duration of `buildAll()` and `rebuildPosts()`. This lives inside `buildPost()` rather than at the call sites on purpose: the prev/next rebuild beside it is duplicated by hand across `admin/post-edit.php`, `admin/posts.php`, `admin/api.php`, `micropub.php`, `XmlRpcServer` and `bin/publish-scheduled.php`, and a rule that has to be remembered in eight places is a rule that will be missed in one.

  One gap is left deliberately: `saveTerms()` has already replaced the junction rows by the time the rebuild runs, so a post that *lost* a term is no longer connected to the posts under it and they keep a stale entry until the next full rebuild. Saving settings forces one, as does `bin/build.php`.

---

## [1.20.0] — 2026-08-16

Schema v29. Adds `posts.bluesky_verify_at` and `posts.bluesky_stale`; the migration runs on the first database open after deploy.

### Fixed

- **An edit sent to Bluesky is written but not shown, and now says so.** Editing an already-syndicated post updates the Mastodon and Pixelfed copies and appears to update the Bluesky one: `com.atproto.repo.putRecord` answers 200, `Bluesky::editPost()` returns true, nothing is logged. But atproto keeps the repo and the service that renders it apart, and Bluesky's AppView does not re-index a post record it has already seen — so bsky.app keeps serving the original text indefinitely. Four records confirmed stale on 2026-08-16, the oldest edited four days earlier; in each the PDS held the new words and the AppView the old ones.

  Nothing here can make Bluesky re-index. What changes is that the CMS stops reporting success it cannot see: a rewrite that lands schedules a check five minutes out, `Syndication::verifyBluesky()` runs from the existing every-minute cron and compares the AppView's cid against the PDS's, and a copy that came back behind is flagged. The post list grows a "Bluesky behind" badge and the editor's Bluesky panel explains what happened. If Bluesky ever starts re-indexing, the check passes and the warning stops appearing on its own — nothing needs unpicking.

  `editPost()` now returns `EDIT_WRITTEN` / `EDIT_UNCHANGED` / `EDIT_FAILED` rather than a bool, because an unchanged record has nothing to verify and must not be queued. Sessions are held for the life of the client, so an edit and the check that follows it sign in once.

- **A lost `swapRecord` race no longer breaks every later edit.** `putRecord` is sent with `swapRecord` set to the cid just read, so a rival write is refused rather than clobbered — but a cid that changed for a benign reason (a `langs` key or a label the PDS added of its own accord) failed the same way, permanently, with no retry. The edit now re-reads the record once and recomposes against what is actually there, which keeps the guard doing its job instead of dropping it.

- **Changing a post's kind left every syndicated copy stale.** `admin/post-edit.php` decides whether to re-syndicate by comparing five fields, and `post_kind` was not among them — yet it is what decides whether the copy links home at all, since a note syndicates as its own words with no trailer. Flipping a post to or from an aside or a photo therefore rewrote what Mastodon, Bluesky and Pixelfed should say while tripping none of the five, so none of them heard about it.

---

## [1.19.1] — 2026-08-15

### Fixed

- **Opening the database no longer strips group access, which took the site down on deploy.** 1.19.0 shipped `Database::restrictFilePermissions()` as a flat `chmod(0600)` on the database and its WAL sidecars. That assumed the file is owned by the PHP-FPM user; prod owns it `deploy:www-data` at `0660` and PHP-FPM reaches it through the **group** bit, so removing group access locked www-data out of the database entirely and every PHP endpoint returned 500 — `/admin/` included. The public site stayed up, being static HTML, which is the only reason it was not worse.

  The vulnerability being fixed was that the database was **world-readable** (`0644`), and clearing the world bits is the whole of it. Owner and group are now left exactly as the deployment set them, so `0644` becomes `0640`, `0664` becomes `0660`, and a mode the operator chose deliberately is preserved rather than normalised. Whether the web user arrives as owner or as group is the install's business; only world access was ever ours to remove. Two tests cover it, one asserting a `0660` deployment survives an open — the case that broke.

  `INSTALL.md` goes back to recommending `chmod 660` with group `www-data`, noting that `600` is correct only where www-data owns the file itself.

---

## [1.19.0] — 2026-08-15

A third security and performance pass over the whole project, following 1.13.0 in July and 1.14.0 in August. The 1.15–1.18 releases added about 2,500 lines — Pixelfed syndication, the Micropub post-list query, photo posts, the lightbox — and none of it had been reviewed.

### Security

- **`league/commonmark` upgraded to 2.10.0**, closing six advisories. Two reach this install: the quadratic-time parse in the core, and the duplicate-footnote denial of service, since `FootnoteExtension` is registered. `^2.4` already allowed the fix, so this is a lock bump with no constraint change. A full rebuild is byte-identical across all 692 posts, so the parser change is invisible in the output.
- **A photo URL could put a `javascript:` link on a public page.** `mp_parse_photo_values()` validated nothing, so a Micropub token with `create` could store any string and `templates/post.php` put it straight into an `href`. Until 1.15.2 that value only ever reached an `<img src>`, where a `javascript:` URI is inert — the lightbox link is what turned it into a live sink. `Helpers::safeUrl()` now holds a URL to absolute `http(s)` or a site-rooted path, Micropub refuses anything else at the boundary, and the post template falls back to an unlinked figure for rows stored before the check existed. The regex is the one `templates/base.php` already had inline for the header avatar, minus a hole: `//evil.example` is protocol-relative and was passing as a site path.
- **Registering a passkey needed no CSRF token and no password.** It was the only state-changing admin POST without a CSRF check, and `totp_disable` asked for the password while this did not — even though a passkey outranks TOTP by signing you in past it. Registration now takes both, tied to the completion step by a short-lived session flag so one password check authorises one registration, and the options call moved to POST so the password stays out of the query string. Removing a passkey is gated the same way. `userVerification` goes from `preferred` to `required` at all three points, so an authenticator that declines to ask for a PIN or biometric no longer yields an admin session from possession alone.
- **The IndieAuth consent screen ignored the idle timeout.** That rule lived only inside `Auth::check()`, which redirects, so the endpoints that answer differently when signed out called `isAuthenticated()` and got no timeout at all — on both the consent render and the approval POST, the two requests that mint `create`-scope tokens. `Auth::sessionIsLive()` is now the shared rule and destroys the session it rejects.
- **`data/cms.db` was world-readable.** It holds `totp_secret` in plaintext along with the Mastodon, Bluesky and Pixelfed tokens, while `config.php` and `data/analytics_salt` had been `0600` for months — the database was simply missed, leaving an nginx deny rule standing in for file permissions. Opening it now takes group and other off it and off its `-wal`/`-shm` sidecars. Best-effort: where PHP does not own the file the chmod fails and the CMS still opens a database it can read.
- **Outbound responses are bounded.** `SafeHttp::request()` buffers a whole response in memory from a host we did not choose, and the cap was left to each caller — `Webmention::sendPing()` set none at all. `CURLOPT_MAXFILESIZE` now has a 5 MB default that callers still override.
- **`metaWeblog.newMediaObject` and `wp.uploadFile` had no size cap**, unlike every other upload path, so `client_max_body_size` was the only ceiling. They also kept a hand-copied MIME allowlist under a comment reading "mirrors Media.php"; both now read `Media::ALLOWED_MIME`.
- **The IndieAuth token endpoint is rate-limited.** Code redemption is unauthenticated and hands back a scoped bearer token, and neither of its two doors carried a lockout scope. `SCOPE_INDIEAUTH` is its own, so a broken client cannot lock the owner out of `/admin/`.
- **`POST /admin/api/media` refuses a cross-origin request.** The JSON routes were already safe — the body is parsed only for `application/json`, which a `<form>` cannot produce — but the media upload reads `$_FILES`, and `multipart/form-data` is a form encoding, so any page could POST to it with the browser attaching cached Basic credentials. Checking `Origin` rather than demanding a token keeps curl and the Obsidian plugin working, since they send none.
- **Settings → Logs shows which surface each login attempt hit.** It selected `ip`, `success` and `attempted_at`, dropping the column that tells an XML-RPC brute force apart from a mistyped admin password.

### Performance

- **Images: 207 MB down to 51 MB across the built site.** `ImageRenderer` is registered against CommonMark's Image *node*, so it only ever fired for Markdown `![]()` syntax — an image written as literal HTML, which is how the whole imported Micro.blog archive is written, went through with no dimensions, no WebP and no `srcset`. Nothing needed converting: the WebP companions already existed for every one of the 134 references. `ResponsiveImages::upgrade()` runs over the HTML after conversion so it catches both syntaxes, and leaves existing `<picture>` blocks alone. The worst page drops from 6.0 MB to under 2.
- **The image the reader is already looking at is no longer lazy-loaded.** `ImageTag` defers everything, which is right for a feed you scroll and wrong for the Largest Contentful Paint element. The lead card on page 1 and the first picture on a photo post now ask to be fetched early — including when the picture is written inline in the body rather than attached as a `u-photo` row, which is the case the homepage actually uses.
- **Saving a post returns the editor immediately.** Syndication and the rebuild ran inline, and a photo post's first publish is 7 to 17 HTTP round-trips at 10 to 30 second timeouts apiece plus a guaranteed five seconds of `sleep()` waiting on Mastodon — against this endpoint's default 60s `fastcgi_read_timeout`, which meant a 504 for the author with the post half-syndicated and the rebuild never run. The redirect now goes out first and the rest runs after `fastcgi_finish_request()`. A save that changes nothing the networks display no longer wakes them at all: they each no-op only *after* fetching the remote copy to compare, which was four round-trips whose answers were thrown away.
- **The stylesheet is no longer sent twice.** `base.php` inlines the critical CSS into every page and then linked `theme.min.css`, which *starts* with that same critical CSS — 4.6 KB of a 10.8 KB gzipped homepage, duplicated. Pages now link `theme.deferred.css`, which holds only what follows the marker. `theme.min.css` stays whole for anything wanting the stylesheet on its own. The cascade is unchanged: the duplicated block was byte-identical, so removing it cannot alter a computed style.
- **Two consecutive builds are now byte-identical.** Empty feeds stamped `date()` into `<updated>`, so five term feeds rewrote themselves on every build and defeated `writeFile()`'s content comparison.
- **`Mastodon::request()` uses `SafeHttp`'s resolver.** Its own guard was `gethostbyname()` plus two filter flags, which missed every range in `SafeHttp::BLOCKED_CIDRS` — `100.64.0.0/10` is carrier-grade NAT, and also Tailscale and several VPS providers' internal network — and saw only the first A record. It was IPv4-only, uncached and had no timeout. Pixelfed inherits the fix.

### Changed

- **Scheduled posts publish from cron.** `bin/publish-scheduled.php` runs every minute; the web entry points keep a fallback that does nothing while the heartbeat is fresh and flushes the response before building when it is not. The dashboard warns when the heartbeat goes stale and posts are waiting, because a fallback nobody notices is a fallback nobody fixes. See INSTALL.md for the crontab entry — it must belong to the PHP-FPM user.
- **`Post::promoteScheduled()` is atomic.** It read the due rows and updated them in two separate statements, so a cron run and a web request arriving together both returned the same ids and the post went out to Mastodon, Bluesky and Pixelfed twice. Now one `BEGIN IMMEDIATE` transaction, covered by a test that runs four real processes against one database behind a barrier file.
- **`renderNoteHtmlMap()` expands shortcodes.** It converted Markdown but never ran the shortcode pass, so a `[youtube]` in an aside rendered as an embed on its permalink and as literal text on the home page. The four body-rendering call sites now share one method.

### Notes

Two findings from the audit did not survive checking and are recorded rather than silently dropped. The webmention avatar `width`/`height` are **not** inverted — `.wm-avatar` is 32px and `.wm-avatar--sm` is 40px in `theme.css`, because the modifier names the reply avatar, which is larger than the reaction avatars around it. And a composite `page_views(timestamp, is_404, url)` index was written, benchmarked and removed: SQLite kept preferring the existing url index even at 200k rows after `ANALYZE`, at 142ms against 151ms, which is noise bought with another index to maintain on every analytics write. **No schema change ships in this release** — it stays at v28.

---

## [1.18.2] — 2026-08-14

### Fixed

- **A page with a code block no longer shifts as it loads.** `theme.js` wrapped every `<pre>` in a `.code-block` div to anchor the copy button, and doing that on load moved the `<pre>` a step down the tree. `.prose > * + *` sets its margins in `em`, so they stopped resolving against the `<pre>`'s `1.1rem` and started resolving against the wrapper's inherited `1rem` — 25.74px became 23.4px top and bottom, and everything below jumped up about 5px per block the moment the deferred script ran. Fenced code now ships with the wrapper already in place from `HighlightFencedCodeRenderer`, so there is nothing to mutate; `theme.js` reuses it and still wraps the cases the renderer does not cover — indented blocks and a `<pre>` written as raw HTML in a post body. `.prose > .code-block` is sized like the `<pre>` so either wrapper carries the same margins.

---

## [1.18.1] — 2026-08-13

### Changed

- **Headings are tracked in by `-0.02em`.** DM Sans sets a little loose above body size, and the gap was widest on the two titles that carry the most weight on a page — the post title and the card titles on the index. The rule sits on the elements (`h1`–`h6`), so the uppercase micro-labels that are headings — `.webmentions__title`, the taxonomy eyebrow — keep the positive tracking their own class sets, which is what uppercase at `.75rem` wants. `.post-card__title` was the one heading already carrying a negative value, at `-0.01em`, and now matches the rest. The site title in the header takes the same tracking by hand — it is an `<a>` rather than a heading, so the element rule does not reach it, but it reads as one.

---

## [1.18.0] — 2026-08-13

### Added

- **Photo posts now syndicate to Pixelfed.** A third POSSE target, set up in **Settings → Pixelfed** with an instance URL and an access token, and gated to one kind of post: `post_kind = photo`. Articles, asides and replies never go, and neither does a titled post that happens to carry images — that is an `article` here, and an article with a screenshot in it is not a photo post. A photo post whose pictures are not in the media library resolves to no attachments and is skipped as well, which is the same thing the server would have said: `statusCreate` refuses a status with no `media_ids` outright. The checkbox in the editor follows the **Post kind** select rather than waiting for a save to reveal itself, and `pixelfed` joins the `syndicate-to` targets Micropub advertises, so an Obsidian or Quill compose screen can pick it too.
- **`bin/pixelfed-token.php`** walks the OAuth flow: it registers the application, prints the authorize URL, exchanges the code you paste back, and verifies the token by naming the account it belongs to. Pixelfed's out-of-band screen answers with `{"code":"def502…","state":null}` rather than the bare string Mastodon displays, so the whole blob is what reaches the clipboard and the code is picked out of it — pasted verbatim it comes back as "Cannot validate the provided authorization code", which describes a wrong code rather than a malformed paste and leads nowhere. A refused exchange re-prompts against the same registered application instead of exiting, since a code is single use and lasts about ten minutes. It prints the token rather than saving it — a CLI writing to the database as the wrong user leaves SQLite's WAL sidecars owned by that user, which takes the site down, and that is not a trade worth making to save a copy-paste.

### Changed

- **`Mastodon` is now the shared client for Mastodon-API servers**, with `Pixelfed` extending it. Pixelfed implements every endpoint syndication uses, so the alternative was a second copy of the DNS-pinned request, the `media_ids[]` encoding quirk and the oversize-image re-encode — three things that have each been a bug once and are not worth having twice. Four hooks carry the differences: the caption limit, the image cap, how a status's text is read back, and how it is compared.
- **Every request to a Mastodon-API server now sends `Accept: application/json`.** Pixelfed is a Laravel application, and Laravel answers a failed validation with a redirect to the web UI unless the request asked for JSON — so a 422 naming the field that was wrong arrived as an opaque 302, in the one place where the log line is all there is to go on. Mastodon was already answering with JSON regardless.

### Fixed

- **A scheduled or Micropub-published post now rebuilds after syndication of any kind.** Both paths compare the syndication URLs before and after publishing to decide whether the page on disk is a version behind, and both compared a pair — Mastodon and Bluesky. A post whose only new copy was the Pixelfed one would have been left with a page that had no link to it until something else rebuilt the post. Found while adding the third network rather than after shipping it, but the pattern is the bug: the tuple has to grow with the list.

### Schema

- **v28** adds `pixelfed_at`, `pixelfed_url`, `pixelfed_status_id` and `pixelfed_skip` to `posts`, mirroring the Mastodon columns exactly — Pixelfed addresses a status the same way, so an edit or a delete follows the same id.

---

## [1.17.2] — 2026-08-13

### Fixed

- **The Bridgy Fed `rel="me"` pointed at a host that does not exist.** The head carried `<link rel="me" href="https://jimmitchell.org.web.brid.gy">`, and that hostname refuses to connect — curl gets no response at all. The string is real, but it is a *handle*: Bridgy Fed's translation table lists `[domain].web.brid.gy` as the atproto handle for a bridged web site, alongside `@[domain]@web.brid.gy` for the fediverse. Neither is addressable over HTTP, so the verification round-trip had nothing to fetch and the link had been doing nothing for as long as it had been there. It now points at the redirector Bridgy Fed actually documents, `https://web.brid.gy/r/<site>/`, which bounces back to the home page — that bounce is what proves the two ends belong to the same owner. The domain comes from `$siteUrl` rather than being written out again, matching the `micropub` and `indieauth-metadata` tags three lines below it, which have always been derived.

---

## [1.17.1] — 2026-08-13

### Fixed

- **The header avatar flashed on every page load.** The circle sat empty for a moment before the face appeared, and the reason was the file behind it: the Settings value points at the original upload — 768×768, 856KB as a PNG — and the header draws it at 32px. Every page was fetching most of a megabyte to fill a circle the width of a word, and the placeholder background was what the reader saw while it arrived. `Builder` now encodes a 64px square (2× for retina) as a WebP data URI and inlines it, so the avatar ships with the markup and there is no request to wait on: about 1.5KB a page, against 856KB fetched once and then cached but paid in full on a first visit. The crop is centred to match what `object-fit: cover` was doing to the original. Only local uploads are inlined — a remote avatar would mean fetching an arbitrary URL during a build — and anything that cannot be encoded falls back to the URL as written, so the setting keeps working when GD is missing.
- **Six templates had to be told about the new variable.** `base.php` reads what `Builder::render()` supplies, but each template forwards an explicit `compact()` list, so a variable not named there never arrives — the same trap that kept `criticalCss` from reaching the page for as long as it existed. The avatar would have silently fallen back to the full-size URL on every page, which is exactly the bug being fixed, and it would have looked like it worked.

---

## [1.17.0] — 2026-08-13

### Added

- **The author avatar now sits beside the site title.** The picture was already in Settings and already went out in feeds as `<byline:avatar>`, but the site itself never showed it — a reader arriving from a feed reader saw a face there and a bare line of text here. It renders inside the existing home link rather than next to it, so the avatar and the title are one target instead of two adjacent ones, and it carries an empty `alt` because the title names the link already; a second reading of the same words is noise to a screen reader. The treatment is the one the webmention avatars use — a 32px circle, cropped to fill, with the code background standing in until the image arrives — so a face in the header and a face under a post are recognisably the same kind of thing. Nothing appears when the setting is empty. The URL is held to absolute `http(s)` or a site-rooted path before it reaches `src`: Settings is owner-written, but this markup is on every public page, and the header is a poor place to discover that a value was never constrained.

### Changed

- **The site is set in DM Sans throughout**, replacing Figtree for the interface and Atkinson Hyperlegible Next for prose. Two families were doing the work of one and the seam showed wherever they met — a card's title in one face above an excerpt in the other. The OG image generator moves with it, so a shared link matches the page it opens.

---

## [1.16.0] — 2026-08-13

### Changed

- **One lightbox, for every picture on the site.** There were two, and which one a picture got depended on how it had been written rather than on anything a reader could see. An image typed into a post's body — how the admin writes a photo post — opened a bare overlay with no way to step to the next picture. Attached `u-photo` rows and the tiled `[gallery]` shortcode opened a second one with arrows and keyboard navigation. A post written in the admin and the same post sent by Micropub therefore behaved differently. The second implementation is now the only one: `theme.js` collects body images alongside the marked-up gallery items in a single pass, and `gallery.js` is gone. Items group by the article they sit in, so the arrows walk one post's pictures and stop there — on an archive page each card is its own group, and a gallery the server split into sibling chunks stays one sequence because the chunks share an article. An image the author wrapped in a link keeps the link: that was the intent, and the old code hijacked the click while following it anyway.
- **The lightbox no longer has to be asked for.** `gallery.js` was loaded only when `Builder` found `data-gallery` in the rendered Markdown, which is why a photo post's attached pictures had no lightbox at all — the template emits those, so the scan could never see them. The same gap was open wider than that: only `post.php` ever forwarded the flag, so a tiled gallery on a Page, a taxonomy archive or the homepage was equally inert. Folding the code into `theme.js`, which every page already loads, removes the detection instead of correcting it — a page's own markup decides what opens, and there is nothing left for the server to get wrong. `data-gallery` on the wrappers went with it; `data-gallery-item` is now the single attribute the lightbox binds to.

---

## [1.15.2] — 2026-08-13

### Fixed

- **A photo post's pictures could not be opened.** Click an image written into a post's body and it fills the screen; click one on a photo post from Micropub and nothing happened at all. The difference was never visible to a reader — both are the same picture on the same kind of post — but the two arrive by different routes. A body image lands inside `.prose`, which is what `theme.js` binds its lightbox to. Attached `u-photo` rows are emitted by `post.php` into `.post__photos` above the body, outside the reach of that selector and of the gallery lightbox too, which looks for `data-gallery-item`. Each photo is now a link to its full-size original carrying that attribute, so a photo post opens into the gallery lightbox rather than the single-image one: with several pictures — which is the case a photo post is for — the arrow keys step between them instead of making the reader close and click again. The link is real, so the picture still opens without JavaScript. `Builder` decides whether to load `gallery.js` by looking for `data-gallery`, and it looked only at the rendered Markdown; attached photos never appear there, so the script has to be asked for from the post's photo rows instead. The lightbox's arrows now hide themselves when there is only one picture on the page, since a lone photo — the common shape for these posts — had two controls that led back to itself.

---

## [1.15.1] — 2026-08-13

### Fixed

- **A photo post written in the admin said it had no photo.** Only Micropub ever wrote to `post_photos`, and the admin's convention is the opposite one — picture in the body, caption in the excerpt — so of the fourteen photo posts on the site, exactly one reported a `photo` property through `q=source`, and the other thirteen shipped no `image` in the JSON feed. A client offering a photo picker got one thumbnail out of fourteen. The photos a post reports are now read through `Post::effectivePhotos()`, which returns the attached rows and, for a photo post that has none, the images in its body — the rule syndication has used to find pictures for Mastodon and Bluesky all along, lifted out of `SyndicationMedia` so there is one definition of it rather than two drifting ones. Nothing was migrated and nothing about the stored posts changed. Only the two places that *report* photos as data moved over: the Micropub source representation and JSON Feed's `item.image`. Everything that renders photos beside the body still reads the raw rows, because a derived photo was parsed out of that body and would otherwise be drawn twice — once in the photos block and once in the content it came from. The fallback is deliberately limited to photo posts: an article with inline illustrations must not start advertising them as its photo property, which would change how a client reads its type.

---

## [1.15.0] — 2026-08-12

### Added

- **A Micropub client can list the posts it is allowed to edit, instead of asking for a URL.** `?q=source` with no `url` now returns `{items: […]}` — the [Query for Post List](https://indieweb.org/Micropub-extensions#Query_for_Post_List) extension, which IndiePass, Micro.blog and Together already consume — so a client can show a picker of recent posts. Until now the only way to reach an existing post through this endpoint was to paste its address in by hand, which meant the edit and delete actions were effectively unreachable from a phone. Each item is a full h-entry built by the same mapper the single-post response uses, so the two can never describe a post differently. The stable `limit` parameter is supported alongside `offset`, and `post-type` and `post-status` filter the list — the first by Post Type Discovery name, using the same seven names `q=config` already advertises. An unrecognised filter value is a `400` rather than the whole archive returned as though the filter matched everything: a typo that silently answers with every draft is worse than one that fails. Page size defaults to 20 and is capped at 100, because each item carries its post's full body. Ordering is newest first, falling back to the creation date for a draft with no publish date yet — SQLite sorts those last under a plain date sort, which would have buried exactly the posts a client opens the list to find — with a stable tiebreak so paging cannot repeat or skip a post. Soft-deleted posts never appear, and the list sits behind the same scope gate as every other query on this endpoint: a token holding only `profile` gets a `403`, not a directory of unpublished work.

### Changed

- **A draft's source now carries the URL a client needs to edit it.** `q=source` gave a post a `url` property only once it was published, on the reasoning that an unpublished post has no page. But `url` is also the handle a client sends back to identify a post, so a draft loaded into an editor could not be saved again — and the create response had already handed the client exactly such a handle in its `Location:` header. Both now come from `Post::addressablePath()`: the date permalink once there is a publish date, the bare slug before it. The URL still 404s for visitors until the post goes live; that is when it starts resolving, not a broken link.

---

## [1.14.4] — 2026-08-12

### Fixed

- **A reply syndicated to Mastodon and Bluesky as a remark about nothing.** The page and all three feeds open a reply, like, repost or bookmark with the line saying what it is about — "↩ In reply to …" — because they share `Post::contextsHtml()`. The two syndication clients composed their own text out of the title, excerpt and URL and never looked at the post's contexts, so that line reached every reader except the ones on the two networks. A reply from a Micropub client is titleless, which makes it an aside, and an aside syndicates with no link home either: the copy was the author's words with nothing to attach them to. `Post::contextsText()` is now the plain-text sibling of `contextsHtml()`, both reading one label table through one sort, so the symbol, the verb and the display order cannot drift apart again. The URL goes out in full rather than trimmed the way the page trims it, because Mastodon linkifies what it finds in the status text and a shortened URL would arrive as unclickable words. Contexts are read back from the database at syndication time: they hang off the post id, and the admin, the API, Micropub, XML-RPC and the scheduler do not all arrive with them hydrated. Re-saving an already-syndicated post rewrites its copies in place, so a reply posted before this can be given its missing line without losing the likes and replies hanging off it.

### Changed

- **One layout for a syndicated post, instead of one per network.** `Mastodon` and `Bluesky` each held a private copy of the same text builder, identical but for the character limit — which is how they came to agree with each other and disagree with the site. Both now call `SyndicationText::compose()`, which lays out any non-empty subset of context, title, excerpt and URL, and lets only the excerpt give ground: the line saying what a post replies to, the title naming it and the URL leading back to it are all worth more than a few more of its own words. That shared version also fixes a fault the duplicated one carried, now reachable because a long reply target eats the budget — with nothing left, the excerpt was cut to a negative length, dropping its last character instead of being dropped itself.
- **Bluesky links every URL in a post, not just the post's own.** A record renders a link only where a facet points at one by byte offset, so a reply carrying two URLs showed one of them as plain text. Facets are now built for the post URL and each context URL together, matched longest-first so a bookmark of a site root cannot claim the bytes of the post URL beneath it, and ordered by position so that rewriting a record whose links have not moved still compares equal and sends no write. They are built from named URLs rather than by scanning the finished text: the excerpt is truncated to fit, and a URL cut short by that would otherwise be linkified into a dead one.

---

## [1.14.3] — 2026-08-12

### Changed

- **The home page's social links are named, not icons alone.** Mastodon, Bluesky and GitHub now print their names beside their glyphs in the h-card above the feed, at a smaller size than before so the row reads as a caption under the greeting. The footer is unchanged — the shared partial takes a `$socialNames` flag, set only by the home intro. Where the name is visible it labels the link, so the `aria-label` is dropped rather than having a screen reader announce the network twice.

---

## [1.14.2] — 2026-08-12

### Changed

- **The radius and header width being run as custom CSS are now the theme's own values.** The site header's inner width goes from 652px to 800px, and the corner radius on content surfaces — cards, images, galleries, code, pills, embeds, webmentions — from 3px to 6px. Rather than nineteen literals, those rules now read a `--radius` token, so the next adjustment is one line. Small chrome keeps its tighter radius: the nav dropdown and the two icon buttons stay at 3px, the search inputs at 4px. Photo cards still zero their own radius, as they have since they stopped drawing a frame — the card there has no border, background or shadow for a radius to round, so it looks the same either way.

---

## [1.14.1] — 2026-08-12

### Fixed

- **The theme toggle appeared a moment after the rest of the header, on every page load.** Moving the public JavaScript out of the page and into a cached, deferred `theme.js` in 1.14.0 took the toggle's icon with it: the button shipped empty and stayed empty until that script ran, so the header painted with a hole in it where the icon belonged and then filled it in. The head script that sets the theme before first paint now also records the three-way preference on `<html>` as `data-theme-pref`; all three glyphs — sun, moon, monitor — ship in the markup, and CSS shows the one that matches. The icon is therefore correct in the first frame, and `theme.js` no longer touches the button's contents, only its `aria-label`. With JavaScript off the monitor icon shows, which is what the default preference has always been.

---

## [1.14.0] — 2026-08-09

A security and performance pass. Six of the security items are real bugs rather
than hardening, and two of those are controls that were designed and then never
wired up. On the performance side a full rebuild went from 12.4s to 1.1s and the
homepage from 1.8 MB of images to 110 KB on a phone.

### Security

- **A sign-in-only token could read every draft.** Every Micropub `POST` action
  checked its scope; the `GET` branch authenticated and then checked nothing. A
  token carrying only `profile` — what IndieAuth issues when you merely sign in
  to a third-party site — could therefore call `q=source` against any URL and
  get back the full body, excerpt, categories and syndication state of any post,
  including drafts and scheduled ones, because the post lookup never filtered on
  status. `q=` now requires a publishing scope.

- **Passkey authentication had no rate limit.** `Auth::SCOPE_PASSKEY` existed and
  failures were recorded under it, but nothing ever asked whether the scope was
  locked out — the constant was written and never read, while every other auth
  surface (`admin`, `totp`, `api`, `xmlrpc`, `micropub`) was wired up correctly.
  Both passkey endpoints are public, so this was an unmetered public-key
  verification anyone could trigger, and an unbounded `login_attempts` table that
  the admin-only pruner could not keep up with. A verification that threw also
  bypassed the counter entirely, so that path records a failure now too.

- **`admin/api.php` leaked filesystem paths to unauthenticated callers.** Alone
  among the public entry points it never turned off `display_errors`, and it
  built the database, the Builder and ran the Scheduler — a full build path —
  *before* authenticating. A database that would not open reported its absolute
  path to anyone who asked. Authentication now happens before that work, the
  connection failure is generic, and there is a shutdown guard for fatals.

- **Webmention author links could run script.** The client-side renderer escaped
  the URLs it received from webmention.io but never checked their scheme, so a
  mention whose `u-url` was `javascript:…` became a working XSS against any
  visitor who clicked the author link. Unlike raw HTML in post bodies — which is
  deliberate and documented — this is unauthenticated third-party input. URLs are
  now allowlisted to `http`/`https`.

- **`tests/` was served as source.** The web root is the project root, so
  `GET /tests/AuthTest.php` returned the file. Denied, along with `phpunit.xml`,
  in both nginx configs. (`apps/` and `docs/` are intentionally public and were
  left alone.)

- **SSRF: the public-IP check missed several ranges.** `100.64.0.0/10` — carrier
  grade NAT, which is also Tailscale's range and the internal network of a number
  of VPS providers — passed as public, as did `192.0.0.0/24`, `192.88.99.0/24`,
  `198.18.0.0/15` and NAT64 addresses, which can embed `127.0.0.1` in what looks
  like a public IPv6 address. Reachable from webmention discovery, IndieAuth
  client lookups and media ingestion. Now an explicit CIDR denylist, with tests
  covering each range and its boundaries.

- **The legacy Micropub token is stored hashed** (schema v27 rewrites an existing
  one in place, so clients keep working). It grants every scope and sat in the
  settings table verbatim while IndieAuth tokens were already hashed.

- **`GET /admin/api/settings` was leaking two secrets.** Its redaction was a
  denylist of four keys and had fallen behind the schema, exposing
  `micropub_token` and `analytics_salt` — the latter being what keeps stored
  visitor IPs unlinkable. Replaced with an allowlist, which fails closed.

- Changing the admin password can now revoke every app token with it, on by
  default. The password is also the REST and XML-RPC credential, so previously
  "change it, I may be compromised" left every issued token working.

- `session.use_strict_mode` is enabled; the 2FA completion regenerates its
  session id like the other two login paths; the admin username is compared with
  `hash_equals` rather than `===`, matching the API and XML-RPC paths; the API
  no longer sends `Access-Control-Allow-Origin: *` before setup; the configured
  upload cap is honoured on the Micropub and API paths instead of silently
  falling back to 50 MB; `config.php` keeps its permissions across a password
  change; and a username containing `$0` no longer corrupts `config.php`.

### Performance

- **A full rebuild spent most of its time rebuilding the same pages.** Building a
  post rebuilds the archives of every term it belongs to — right for a single
  edit, quadratic for a full build, and redundant besides because `buildAll()`
  sweeps every archive at the end anyway. A category holding 105 posts was
  rebuilt 105 times. Deferred during the post loop: **12.4s → 1.1s**, verified
  byte-identical across all 884 generated files.

- **Every build rewrote every file, unchanged or not.** Posts and pages were
  hash-guarded; the index, feeds, sitemap, search index and archives were not, so
  860+ files had their mtime moved on each build — wasted I/O, and enough to make
  rsync and CDN syncs treat the entire site as changed. `writeFile()` now
  compares before writing.

- **Schema v26 adds three indexes.** `posts(status, published_at)` — the separate
  status and date indexes meant SQLite could only use one, so every prev/next
  lookup sorted the whole table with a temp b-tree, ~1,400 times per rebuild.
  Plus term-first indexes on both junction tables. Prev/next across all posts:
  244ms → 49ms. Prev/next also no longer hydrate categories, tags, photos and
  contexts that the templates never read — four queries per call, twice per post.

- **Images: 102 MB of originals now have 30 MB of WebP companions.** WebP
  generation only ever ran on new uploads, so imported images never got one, and
  the companions that did exist were full-resolution re-encodes. Companions are
  now downscaled to a longest edge of 1600px with an 800px variant, and the
  templates emit `<picture>` with a `srcset` and `width`/`height`. The homepage
  went from 1835 KB of images to 279 KB, or 110 KB on a phone. `bin/optimise-media.php`
  backfills existing files; originals are never touched.

- **~11.5 KB of JavaScript was inlined into all 860 pages**, identical every time
  and therefore never cacheable. Split into `/theme.js`, `/gallery.js` and
  `/webmentions.js`, loaded with `defer` and cached — the latter two only on
  pages that need them. Only the two roman fonts are preloaded now; the italics
  were 57 KB competing with the faces actually needed to paint.

- `track.php` sets `busy_timeout` (a build holding the write lock made the beacon
  fail instantly and drop the view) and `synchronous=NORMAL`, removing an fsync
  per request, and caches the analytics salt instead of querying it every time.
  `Database` sets `busy_timeout` too.

- `GET /admin/api/posts` accepts `?page` / `?per_page`. Opt-in: without them it
  still returns everything, because existing clients depend on that.

- The three feed builders share one Markdown converter instead of constructing
  54 identical ones per rebuild, and image dimensions are cached per process
  rather than re-read from disk on every render.

### Changed

- **Retention pruning moved to `bin/prune.php` and needs a cron entry** — see
  INSTALL.md. It previously ran inline on ~1% of admin page loads, so it was tied
  to how often the owner logged in and never ran for the public endpoints that
  fill those tables.

---

## [1.13.3] — 2026-08-02

### Added

- **A syndicated copy now follows the post for the rest of its life.** Mastodon and Bluesky copies were made once, on first publish, and then forgotten: fixing a typo left the wrong words on two other networks, and deleting a post left both copies up, still linking to a page that had stopped existing. Editing a published post now rewrites its copies, and deleting or unpublishing it takes them down — from the posts list, the post editor, `DELETE /admin/api/posts/{id}`, `PUT /admin/api/posts/{id}`, and Micropub `action=update` / `action=delete` alike. A Micropub undelete restores the post here but cannot bring the copies back; the post returns unsyndicated.

  Mastodon is edited through its own edit endpoint. Bluesky has no edit operation, so the record is rewritten in place at the same key with `putRecord`, which keeps the `bsky.app` URL and the likes and replies hanging off it; the write is guarded by `swapRecord` so an edit made in the Bluesky app is not silently overwritten. Both clients read the copy back first and send nothing when it already says what the post says, so a save that only moved a post between categories does not mark the toot as edited. Reading a toot's text back needs `read:statuses`, which a token minted only to post does not carry — without it the edit is sent anyway and the instance decides, and the log line says which scope would quieten it. Photos are only re-uploaded when their number has changed — changing which photos a post carries without changing how many is not detected, and the copies keep the pictures they were published with.

  Schema v25 adds `posts.mastodon_status_id` and `posts.bluesky_rkey`, the handles the two APIs address a copy by. Posts syndicated before the columns existed have theirs recovered from the stored URLs on upgrade, so an old post is editable and deletable too. The **Toot URL** and **Bluesky post URL** fields in the post editor are what an edit or a delete follows, so pointing one at a different status re-points both.

### Changed

- **Composing a syndicated copy now happens in one place.** The admin post form and the Micropub endpoint each assembled their own payload — which words a note contributes, whether to send a link back, which photos ride along — and the two had already drifted once. `CMS\Syndication` now holds that decision, and the admin form, the REST API and Micropub all call through it. A network is only forgotten once its copy is actually gone, so a delete that failed against an instance that was down leaves the id in place rather than losing the only way back to it.
- **The home page h-card now links to the profiles instead of the /now page.** The "Here's what I'm up to" link has been replaced by the same Mastodon, Bluesky and GitHub icons the footer carries, a shade larger since they carry the line on their own. The feed is left out — the home page already advertises it through `<link rel="alternate">` and the footer. Both places render from one new partial, `templates/partials/social-links.php`, so the two link sets cannot drift apart, and the profiles' `rel="me"` now sits inside the representative h-card where an IndieAuth or mf2 parser looks first.

### Fixed

- **A post published over Micropub showed no syndication links until something else rebuilt it.** The endpoint wrote the post's page and then syndicated it, so the copies were made after the only page that links to them had already been written. The links appeared whenever something rebuilt that page next — most often publishing the following post, which rebuilds its neighbours — which put them a post behind. The build still comes first, because Mastodon and Bluesky both fetch the permalink to build their preview cards and a copy made before the page exists links to a 404; the page is now rebuilt once afterwards, and only when a copy was actually made. The admin editor and the MarsEdit endpoint already syndicated before building and were never affected.
- **A scheduled post went live with no copy on Mastodon or Bluesky, and sometimes with no page at all.** Nothing syndicated a post that published itself: promotion built the post and stopped there, so scheduling a post rather than publishing it meant silently opting out of both networks. Worse, of the three entry points that promote due posts, only the admin UI did anything with what it promoted — a REST API or Micropub request that happened to be the first one after the publication time flipped the post to published and built nothing. Because promotion only matches posts that are still scheduled, no later request would pick it up either: the post was live in the database, absent from the site, and stayed that way until it was edited. All three now run `CMS\Scheduler`, which promotes, builds the post and its neighbours, rebuilds the shared pages, and syndicates.
- **A photo post published over Micropub syndicated without its words.** A photo post keeps the picture in its content and the caption in its excerpt, so `Post::noteText()` — what Mastodon and Bluesky are handed, and what the search index stores — read the excerpt. But Micropub has no caption property: a client sends the words as `content` and the picture as `photo`, so a photo post arriving that way has an empty excerpt and its caption in the body. Bluesky got the picture with no text at all, and the search index stored a blank entry. `noteText()` now falls back to the body when a photo post has no excerpt, which still yields nothing for a post written in the admin whose body is only the image.
- **A photo post reached Mastodon with no picture, or not at all.** Attachment ids were form-encoded as `media_ids[0]`, `media_ids[1]` — what `http_build_query()` emits for an array — and Rails reads that as a hash, which `permit(media_ids: [])` then drops. The status posted with the photos missing; when the words were missing too, as they were for every Micropub photo post, the instance refused the empty status and nothing was posted. The ids now go as repeated `media_ids[]` keys.
- **A failed Mastodon syndication said nothing at all.** Syndication runs on the publish path, where an error cannot be shown to anybody and must not take the publish down with it, so every failure returned quietly and a copy that never arrived left nothing to read. Each one now logs what happened — the HTTP status and a trimmed body for a refused upload or status POST, the cURL error when the request never completed, the resolved address when the DNS guard rejects a host, and a count when a status posts with fewer attachments than were sent. `bin/check-mastodon-media.php` walks the same steps for one post and prints what the instance said, without posting anything.

### Documentation

- **The Mastodon token needs `write:media` as well as `write:statuses`.** Uploading an attachment is a scope of its own, so a token made before photo syndication existed toots the words and is refused the picture — which reads as a post that simply lost its images. Both the setup notes and the hint under the token field said `write:statuses` was all it took. Changing the scopes on an application already made means regenerating its token.

---

## [1.13.2] — 2026-07-31

### Fixed

- **A post re-published unchanged was never written back to disk.** To avoid rewriting files that have not changed, the builder compares a hash of the rendered page against the hash it stored on the last build — but that hash describes what was last rendered, not what is actually on disk, and unpublishing a post removes its page while leaving the hash behind. Re-publish with the same content and the same date, which the editor pre-fills, and the render comes out identical to the stored hash, so nothing was written: the post reappeared in the home page, feeds and sitemap, and every one of those links led to a 404. Both posts and pages now check that the file is actually there, the way the OG image generator already did. Anything generated that goes missing — deleted by hand, lost to a failed write — now comes back on the next build instead of needing the whole site rebuilt from scratch.
- **Putting a live post back on the schedule left it published.** Pushing a post's date into the future takes it off the site until that date comes round, but the admin editor decided whether to touch the generated files by looking at which button was pressed rather than at where the post ended up — and "publish, but later" fell through every branch. Nothing was rebuilt: the article kept answering its URL, and the home page, feeds and sitemap kept listing it, while the admin showed it as scheduled. It is reachable without doing anything unusual, because a scheduled post whose time arrives is promoted and built on the next admin request, so a post can go live in the moment between opening the editor and pressing Publish on a date you have since moved. The decision is now made on the post's status rather than the button, so anything that is public — or was public a moment ago — gets the same rebuild pass.

---

## [1.13.1] — 2026-07-30

### Fixed

- **Renaming a published post left the old URL serving the old copy.** The builder works out where to write a post from its current slug and date, so it had no way to know about a location the post had moved away from — changing either left the previous directory in place, still serving the previous version of the article indefinitely, and search engines and anyone holding the old link kept finding it. Only the Micropub endpoint cleaned up after itself; the same edit made in the admin, through the internal API, or over XML-RPC from MarsEdit did not. All four now clear the vacated directory, and the path formula they each need — along with the timezone it depends on — lives in one place rather than being spelled out again at every call site.
  - Existing strays are not swept up automatically. If a published post has been renamed at some point, the old directory is still on disk and still being served; removing it is a manual job.

---

## [1.13.0] — 2026-07-30

A full code and security review of the repository, plus the fixes and refactors
it turned up. **This release migrates the database to schema v24**, which happens
automatically on the first request after deploy. It also adds rules to
`nginx.conf.example` that have to be applied to the server config by hand — until
they are, the exposure described under Security below is still open.

### Security

- **A WordPress import could write files anywhere the web server could reach.** `<wp:post_name>` from a WXR file became a post's slug verbatim, skipping the `slugify()` the title branch alongside it already called. That slug is spliced into the output path, so an import item naming `../../../../var/www/evil` wrote its rendered HTML there — an arbitrary file write from an untrusted import file, in a directory tree that is also served. Both assignments now sanitise, and the import's private slug-dedupe helper was dropped for the canonical `Post::resolveUniqueSlug()`, which always has. `Builder`'s write and delete paths grew a containment check against the output root as a backstop, so no future slug can escape even if a sanitiser is missed.
- **Two places fetched attacker-supplied URLs with no SSRF guard**, while a careful one already existed elsewhere in the tree for IndieAuth client discovery. Importing media harvests URLs out of post `<img src>` attributes — content that can arrive from a WXR file or a Micropub client — and followed redirects with no private-address check, so it could be pointed at cloud metadata endpoints or anything else on the internal network. Webmention was worse, because the destination itself came from a remote page: `discoverEndpoint()` reads the target out of a *foreign* server's `Link:` header, then POSTs to it, so linking to a hostile site from any post was enough to get a blind POST to an arbitrary internal address. The existing guard is now `CMS\SafeHttp` and all three callers go through it: DNS pinned via `CURLOPT_RESOLVE` so the address checked is the address connected to, redirects followed manually with every hop revalidated, and the protocol allowlist fixed to HTTP and HTTPS.
- **The login form accepted a cross-site POST with no CSRF token at all.** `hash_equals('', '')` is `true`, and the login POST is verified before a session token exists, so a request carrying no cookie and an empty `csrf_token` compared empty against empty and passed. Both sides are now rejected when empty.
- **The IndieAuth consent screen validated the client on display but not on submit.** The GET path did the full job — scheme allowlist, fragment rejection, and a same-origin-or-registered check on `redirect_uri` against the client's published metadata. The POST path that actually mints the authorization code trusted its own hidden form fields, so a forged submission could carry any `redirect_uri` and have a valid code delivered to it. Both branches now run the same validation before anything is issued, and `client_name` is re-derived from discovery rather than read back from the form.
- **Anyone who knew the admin username could lock the account out from every address at once.** Failed logins recorded a per-user counter as well as a per-IP one, and the login gate honoured both, so five requests from anywhere denied the owner access from everywhere. The per-user counter is still recorded for the activity log but no longer gates login; IP lockout plus two-factor is the control.
- **Four authentication surfaces shared one rate-limit bucket** keyed only on IP — `/admin/`, Micropub, the internal API, and XML-RPC. A Micropub client looping on a stale token locked the owner out of the admin, and vice versa. `login_attempts` now carries a `scope` column (**schema v24**) and each surface counts independently. Existing rows migrate to `admin`; the two string-prefix schemes previously used for the same purpose are folded into the column so there is one mechanism.
- **An anonymous caller could make the server issue an HTTP GET to any public URL** by hitting the IndieAuth endpoint, which performed client discovery above the login gate. The fetch moved below it.
- **Micropub resolved post URLs by their last path segment alone**, ignoring scheme and host, so a URL on somebody else's domain that happened to end in a local slug resolved to the local post — for `q=source`, `action=update` and `action=delete` alike. Absolute URLs must now match the configured site origin.
- **The project root is web-servable, and only some of it was denied.** `composer.json`, `composer.lock`, `VERSION`, the `Dockerfile`, `docker-compose.yml`, `nginx.conf.example`, every `.md` file, the committed 3.2 MB composer PHAR, and — where present on the server — the production nginx config itself were all reachable. Everything under `admin/partials/` was directly executable too, running handler fragments with no bootstrap and so no authentication check; they fatal rather than doing anything useful, but they should not be reachable. Deny rules for both are in `nginx.conf.example`. **These need applying to the live server config manually.**
- `LIBXML_NONET` on the WXR import parser, matching the XML-RPC parser. `display_errors` is now switched off centrally rather than in three files, so a fatal in an admin page or a public endpoint no longer prints filesystem paths.
- Admin sessions now expire server-side against `admin.session_lifetime` on a sliding idle timeout. Previously the setting was only a cookie hint, which the client controls.
- The composer PHAR is no longer committed. The `.gitignore` entries meant to exclude it carried trailing `#` comments, which gitignore does not support, so they were literal patterns matching nothing.

### Added

- **A test suite** — PHPUnit, 140 tests, `php composer test`. It covers the places where a regression is silent and expensive: the slug-to-path chain end to end, `SafeHttp`'s address classification across loopback, RFC1918, link-local, multicast and IPv6 ULA, the IndieAuth PKCE and code-redemption guards, XML-RPC value round-tripping, WXR export escaping, and the CSRF comparison. Each test was validated by reintroducing the bug it covers and confirming it fails.
- `CMS\SafeHttp` — the single boundary for outbound HTTP to a URL the CMS did not choose.
- A **Security invariants** section in `CLAUDE.md` recording the five rules above that have each been a real bug here, and an explicit note on the accepted risk of allowing raw HTML in post bodies.

### Changed

- **`admin/xmlrpc.php` split from 1731 lines to 81.** The helpers and all 38 method handlers moved into `src/XmlRpcServer.php`, grouped by API family, with the `global $db, $auth, $config` usage replaced by constructor injection; what remains is a front controller that reads the body, parses, dispatches and emits. Verified against a recorded baseline of 42 XML-RPC calls captured before the move and byte-identical after — which caught one regression that no test would have, a file-scope constant becoming a class constant while its reference stayed unqualified, silently emptying `wp.getPostFormats`.
- Slug resolution consolidated on `Post::resolveUniqueSlug()`; `Page` gained the equivalent, and the two places that reimplemented it now call it.
- The "first of `file[]`" upload normalisation, duplicated between the Micropub and media endpoints, moved to `MicropubAuth::firstUploadedFile()`.

### Fixed

- **A post whose rendered HTML contained the literal `]]>` corrupted the WXR export.** Content was wrapped in CDATA without escaping the one sequence that terminates it, so a post about CDATA — or any code block containing it — ended its own section early and spilled markup into the document. Neighbouring fields had the inverse bug: titles and excerpts were HTML-escaped *inside* CDATA, so an `&` in a title came back as `&amp;` on reimport. One `Helpers::cdata()` helper now handles all of it.
- **A Micropub draft with no publication date was handed a URL its own endpoint could not resolve.** With `published_at` null there was no permalink to return, so the `Location` header carried the admin edit URL instead. Clients store that as the post's identity and send it back, and resolution takes the last path segment — `post-edit.php`, which is nobody's slug — so every later update or delete of that draft failed with a 404. A dateless post now reports its slug path, which resolves; publishing it later still returns the real permalink.
- **Unpublishing or deleting a post orphaned its OG image.** The removal path unlinked `index.html` but not the `og.png` generated beside it, and because the directory prune only fires on an empty directory, the directory survived too. The same leftover existed in the Micropub rename cleanup, which reached for raw `unlink`/`rmdir` and so also bypassed the new containment check; both now go through one guarded helper.
- `Database`'s settings cache was `static`, so it was shared across every instance in a process, and it cached misses — meaning a setting written through one instance was invisible to another that had already read it as absent. Harmless under PHP-FPM, wrong for the CLI builder.
- `Helpers::readingTime()` counted words with `str_word_count()`, which miscounts non-ASCII text.
- **The search form flashed unstyled at the foot of every page.** Its overlay markup ships on every document but the rules hiding it sat below the critical-CSS marker, so they arrived with the deferred stylesheet rather than the inlined head block. The search results page moved across too — that form is above the fold and autofocuses, so the reflow shifted the control the cursor was already in. 135 bytes gzipped.
- **The footer social icons rendered enormous before the stylesheet landed.** Same cause, and the inline SVGs carry a `viewBox` but no dimensions, so until the CSS arrived they drew at their intrinsic 300×150 — a giant Mastodon icon on every search submit. The block moved above the marker and the icons now carry explicit dimensions, so they cannot balloon even with no CSS at all.
- `color-scheme` is now declared, so the browser paints its own furniture to match: Chrome was drawing a light scrollbar under every dark code block. Syntax-highlighted blocks stay dark in light mode, so they declare it themselves.
- The header inner width sat at 750px while the content column below it was 632px, so the header ran wider than everything else on the page.
- Body leading set to 1.6667, which at the 18px root lands on a round 30px instead of 28.8.

---

## [1.12.2] — 2026-07-26

### Fixed

- **Photo posts now syndicate with their picture.** A photo post published to Mastodon or Bluesky arrived as a caption and a link with no image attached: the syndicators only ever sent text, so the one thing the post was about was the one thing that didn't travel. Both now attach the post's images, with alt text, up to the four each network allows. Pictures come from the `u-photo` rows a Micropub client attaches, falling back to the images in the body — Markdown or `<img>` — which is where a photo post written in the admin or in MarsEdit keeps them. Only files in the site's own media directory are attachable, so a body pointing at somebody else's server syndicates as text rather than turning a publish into an outbound fetch.
  - Bluesky caps an image blob at slightly under a megabyte, which most photos off a phone exceed, so an oversize image is re-encoded and progressively scaled down until it fits rather than being dropped. Transparency is flattened onto white, since the re-encode is JPEG. Mastodon takes the file as stored unless it is over the instance's own cap, which the media library here comfortably exceeds.
  - Uploads that Mastodon reports as still processing are now waited on before the status is posted, instead of being attached to a status the instance would reject.
  - A photo post with no caption used to be skipped as having nothing to say. It now syndicates on the strength of the picture alone — the check only skips a post with neither words nor images, which still covers a bare like or repost.

---

## [1.12.1] — 2026-07-26

### Security

- **`spomky-labs/otphp` upgraded from 11.4.2 to 11.5.0**, clearing two advisories against the TOTP library behind two-factor authentication: an uncaught `DivisionByZeroError` from an unbounded `digits` parameter (high), and a mass-assignment in `Factory::loadFromProvisioningUri` (medium). Neither was reachable here — both are entered by parsing a hostile provisioning URI, and this CMS only ever *generates* one for the enrolment QR code, building every TOTP object from a secret it generated itself. Existing enrolments are unaffected: secrets are stored as-is and still verify.

---

## [1.12.0] — 2026-07-26

### Added

- **`php bin/build.php --css`** (alias `--styles`) — rebuild the stylesheets without re-rendering the site. Editing `theme.css` previously meant a full rebuild, because the critical CSS subset is inlined into every page's `<head>` at render time and only a re-render could refresh it. The flag regenerates `theme.min.css` and `theme.critical.css`, then patches the inlined `<style>` block directly in the generated HTML — but only when the critical subset actually changed, so an edit below the marker touches no pages at all. It locates the block by the preload link that always follows it, leaving the separate Custom CSS `<style>` tag alone. On a site of 849 pages this is a sub-second operation against roughly eighteen seconds for the full rebuild.
- **`post-types` in the Micropub `q=config` response** — a Micropub extension that lets clients populate a type picker instead of guessing what the endpoint accepts. Advertises the seven types the server stores end-to-end: `note`, `article`, `photo`, `reply`, `repost`, `like`, and `bookmark`. Video, audio, RSVP, and check-in are deliberately left out, since those properties are currently dropped on create.
- **The Gallery and Link post formats over XML-RPC**, so MarsEdit can produce every kind of post the site can hold. Neither is a new `post_kind`; both map onto what was already there, which keeps a post built in MarsEdit identical to the same post built over Micropub.
  - **Gallery** is the `photo` kind carrying more than one picture. Pictures attached as `u-photo` rows and images written inline in the body both count, so a gallery reads as a gallery whichever client built it — MarsEdit embeds its uploads in the body rather than attaching them. A photo post travels as `gallery` when it holds two or more, `image` when it holds one.
  - **Link** is a post carrying a `bookmark-of` context, so a bookmark made in MarsEdit and one made over Micropub are the same object. Since no MetaWeblog or WordPress struct carries a URL field, the target is parsed from the first `http(s)` link in the body — HTML anchor, Markdown link, autolink, or bare URL — which is how WordPress derives it too. Switching a post off the Link format drops the bookmark; reply, like, and repost contexts are never touched, having no WordPress format to be edited through.
- **A gallery layout on post permalinks** — a post with two or more attached photos now pairs them into two columns instead of stacking them full width, collapsing to one column under 600px. The pictures are not cropped, unlike the card gallery: a card is a teaser and can crop to a tidy grid, but a reader who opened the post came to look at the pictures.

### Changed

- **Search opens over the page instead of navigating to it** — the header magnifier used to be a link that discarded whatever you were reading in exchange for an empty page holding a text field. It now opens an overlay: the page blurs behind a single field in the upper third, Enter takes you to `/search/?q=…` as before, and Esc returns you to exactly where you were. The field is set in Atkinson Hyperlegible Next, the site's reading face, so the query looks like the prose it searches. The results page is unchanged and keeps its own form for refining a search. Nothing here depends on JavaScript to reach results — the overlay is a plain `GET` form, and with scripting off the magnifier is still a link to `/search/`.

- **Less CSS inlined into every page** — the critical marker sat near the end of `theme.css`, making the inlined block 81% of the whole stylesheet. 490 lines that render below the fold, behind an interaction, or only on one page type — the search overlay and results page, syntax highlighting, notices, button links, pagination, footnotes, the syndication footer, post navigation, the site footer, and the 404 page — now sit after the marker and arrive with the linked stylesheet instead. The Responsive block deliberately stays critical: it is entirely above-the-fold, and media queries have to follow the rules they override. Sections keep their relative order, so no rule changed which one wins; the inlined block fell 35%, from 25,392 to 16,561 bytes.

### Fixed

- **Critical CSS never actually reached the page** — `base.php` has long had a branch to inline the above-the-fold styles and preload the rest, but every template that renders it passed an explicit `compact()` list that omitted `criticalCss`. The branch could therefore never fire: `theme.critical.css` was generated on every build and never used, and each page shipped a plain blocking stylesheet link. All six templates now forward it.
- **`composer.lock` was out of date with `composer.json`** — because `composer.json` carries a `version` field, every release bump changes its content hash and invalidates the lock, which `composer validate` reported as an error. The lock is regenerated here; no dependency versions changed.
- **XML-RPC now speaks WordPress's post-format vocabulary** — the `photo` post kind added in 1.11.0 was emitted to clients as `post_format: "photo"`, which is not a WordPress format, and `wp.getPostFormats` advertised only `standard` and `aside`. Photo posts were therefore invisible in MarsEdit's format picker and unidentifiable in its post list. The kind is now translated at the XML-RPC boundary, `wp.getPostFormats` advertises the full set, and `status` is accepted as an alias for `aside`. Storage is unchanged — the internal name is still `photo`.
- **Editing a post over XML-RPC no longer resets its kind** — the post format was applied unconditionally from the incoming struct, so any client that edited a post without restating its format silently demoted an aside or photo post to standard. An absent format now leaves the existing kind alone, matching Micropub update, which likewise never re-derives it.

---

## [1.11.0] — 2026-07-25

### Added

- **Full W3C Micropub compliance and a self-hosted IndieAuth server** — `indieauth.php` (authorization endpoint), `token.php` (token endpoint), and `indieauth-metadata.php` (metadata document) replace the dependency on an external IndieAuth provider. PKCE is supported, and PKCE-less authorization requests from legacy clients are still accepted. The Micropub endpoint advertises `q=config`, `q=source`, `q=syndicate-to`, and `q=category`.
- **Micropub context post kinds** — `in-reply-to`, `like-of`, `repost-of`, and `bookmark-of` are stored per post and rendered as a context line on cards and permalinks. `published` is updatable.
- **A `photo` post kind** — `post_kind` now accepts `photo` alongside `standard` and `aside`. No migration is needed; the column has no CHECK constraint, so the valid set was only ever enforced in PHP. Micropub assigns it automatically when an entry arrives with photos and no `name` (a titled post keeps its photos as illustration and stays `standard`), XML-RPC maps it from the WordPress `image` post format, and the admin editor offers it as a third option. Photo posts are notes: no `<h1>`, no byline, no reading time, and they syndicate to Mastodon and Bluesky as native notes with no link back. `Micropub update` does not re-derive the kind — adding a photo to an existing post leaves it alone, so a post can't silently change shape under an edit. The search index stores a photo post's excerpt as its body text, since its content strips to nothing and it would otherwise be unfindable.
- **A representative `h-card` on the home page** — the site previously had no h-card anywhere, so IndieAuth clients, webmention senders, and mf2 parsers had no author to discover for any entry on the page. A text-only introduction now sits above the feed on page 1, with `u-url` and `u-uid` both resolving to the home page (what makes an h-card *representative* rather than incidental) plus `rel="me author"`. Driven by a new **Home page intro** setting, falling back to the author bio; it renders nothing without an author name. The `/now` link appears only when a page with that slug exists.
- **`h-feed` on the home page and taxonomy archives**, each carrying a `p-name`.
- **A focus ring and a `prefers-reduced-motion` block in the public theme** — neither existed, so keyboard focus was invisible and `scroll-behavior: smooth` was ungated.
- **A `rel="me"` link for Bridgy Fed verification.**
- **Post cards are fully clickable**, and the prev/next post navigation shows the date and time of the target post.
- **A Micropub publisher app page**, describing the client used to post to this site.

### Changed

- **The three post kinds are now visually distinct in post lists** — asides, photo posts, and long-form posts previously rendered through a pixel-identical card, differing only in whether a title happened to be present. Asides and long-form posts stay one card family, told apart by fill, padding, and structure rather than colour: asides get a recessed tint and tighter gutters, long-form posts a larger title in the reading face plus a footer rule carrying the date and reading time. Photo posts drop the card entirely — no border, no fill — so the picture sits directly on the page and breaks the feed's card rhythm. Palette is unchanged.
- **A photo post's picture always leads, whichever way it was authored** — the image can come from attached `u-photo` rows (rendered as a gallery) or be written inline in the post body, and both produce the same reading order. Inline images on a photo post pick up the gallery's full width, radius, and 520px ceiling.
- **A photo post splits the picture from its words** — the content field holds only the image or gallery; the excerpt holds the caption. The home page card shows the picture and the date and nothing else, so a run of photo posts reads as a stream of images. The caption appears on the permalink beneath the picture (inside `e-content`, marked `p-summary`) and in the RSS, Atom, and JSON feeds, so subscribers get the same thing a visitor does.
- **Photo posts syndicate their excerpt** — POSSE builds a note's text from the plaintext of its content, and `Post::plaintextFromMarkdown()` strips images, so a photo post would have syndicated an empty status to Mastodon and Bluesky. `Post::noteText()` now supplies the excerpt for photo posts and the body for asides, and syndication is skipped outright when a note resolves to no text rather than publishing a blank. The photo itself is still not uploaded — neither integration supports media yet.
- **List card markup lives in one place** — `templates/partials/post-card.php` replaces the copy in `index.php` and `taxonomy.php`. `search.json` now carries `kind` instead of `is_aside` so the search page branches the same three ways the server does.

- **Every titleless Micropub post is now an aside** — previously a post became an aside only when it had both an empty `name` *and* a context property (`in-reply-to` / `like-of` / `repost-of` / `bookmark-of`). A plain titleless note fell through to a fallback that invented a title from the first 80 characters of the body — or literally `'Untitled'` — and published it as a standard post with an `<h1>`. In Micropub the absence of `name` *is* the note/article distinction, so the context requirement is gone and the title fallback is deleted.
- **Aside slugs derive from the post body instead of the post id** — asides now get readable URLs like `/2026/07/22/this-is-a-new-post/` rather than `/2026/07/22/234/`, built from the first five words of the body (capped at 60 characters, trimmed to a word boundary). `mp-slug` still wins when supplied. An aside with nothing to slug from — a bare `like-of` or a photo-only note — still falls back to the autoincrement id. Existing asides keep their numeric slugs; there is no retroactive migration, since re-slugging would break every already-syndicated URL. Digit-only slugs stay reserved so a new post can't land on a legacy aside's URL.
- **The aside slug is now editable in the admin post editor** — the slug field was `readonly` for asides and any non-numeric value was silently discarded on save. It now behaves like a standard post's: type one, or leave it blank to derive from the note body.

- **Theme refinements throughout** — content and header widths, body padding and line height, border radii, dark-mode link colour, and header/footer treatment (backgrounds matched to the page body, border rules and the sticky header removed) were all adjusted over the course of this release.

### Fixed

- **Saving an aside no longer destroys a non-numeric slug** — `admin/post-edit.php` and both XML-RPC struct handlers reset any aside slug that wasn't digit-only back to empty, which under the new slugs would have wiped a live URL the first time a note was opened in the editor or synced from MarsEdit.
- **IndieAuth authorization codes no longer expire instantly under a non-UTC PHP timezone.**
- **A `client_id` page title is no longer misparsed by IndieAuth servers.**
- **An access token sent in both the header and the request body is strictly rejected again**, per spec, after a period of tolerating the duplicate.
- **Generating or revoking a Micropub token no longer times out.**
- **Saving settings runs its site rebuild in the background** instead of blocking the response.
- **Orphaned `og.png` files in legacy flat post directories are cleaned up.**
- **Category and tag pills show on aside post pages.**
- **Feeds report the real version in their generator tag** — `bin/build.php` never defined `CMS_VERSION`, unlike every other entry point, so the static build fell through to the `'1.0.0'` default and every published feed advertised that regardless of the actual release.

### Internal

- Shortcode rendering is extracted into `ShortcodeRenderer`.
- Nginx gains gzip, `open_file_cache`, and a 7-day cache for theme and admin assets.
- Slug derivation and uniqueness resolution are centralized as `Post::slugFromContent()` and `Post::resolveUniqueSlug()`, replacing four near-identical implementations across `micropub.php`, `admin/post-edit.php`, `admin/xmlrpc.php`, and the WXR importer. Because a content-derived slug is known before insert, the two-phase "save, read the autoincrement id, save again" dance is gone from every path except the id fallback — including the importer's `__import_` random-placeholder workaround for `slug`'s `UNIQUE NOT NULL` constraint.

---

## [1.10.0] — 2026-05-11

### Added

- **Micropub `q=source` query** — `GET /micropub.php?q=source&url=<post URL>` returns the post as an h-entry source object (`{type:["h-entry"], properties:{…}}`), enabling round-trip editing in Micropub clients that load existing posts into their editor. Supports optional `properties[]=name&properties[]=content&…` filtering, in which case the response omits the `type` wrapper and returns only `{properties:{…}}` as the spec prescribes. Returned properties: `name`, `content`, `summary`, `mp-slug`, `post-status`, `published` (ISO 8601 in the site's timezone), `category` (flat list of category + tag names), and `url` for published posts.
- **Micropub `summary` property** — clients can now send `summary` on create and update; the value is stored as the post's `excerpt` so Mastodon/Bluesky syndication and feeds use it instead of auto-deriving via `effectiveExcerpt()`. Update supports `replace`, `add` (treated as replace since the field is single-valued), and `delete` (clears the excerpt). Also surfaced in `q=source` responses.

---

## [1.9.1] — 2026-05-11

### Fixed

- **Full site rebuild no longer times out** — the dashboard's **Rebuild entire site** button used to hold the FastCGI request open for the entire rebuild, which on larger sites exceeded the default `fastcgi_read_timeout` (60s) and surfaced as an nginx 504. The handler now flashes "Rebuild started", sends the redirect, and calls `ignore_user_abort(true); set_time_limit(0); session_write_close(); fastcgi_finish_request();` before invoking `Builder::buildAll()`, so nginx returns the response instantly while the build continues in the background. Completion is recorded in the activity log as before. The long-running regex location in both nginx configs now also matches `dashboard.php` with `fastcgi_read_timeout 3600s` as a safety net for SAPIs without `fastcgi_finish_request()`.

---

## [1.9.0] — 2026-05-11

### Changed

- **Admin nav consolidation** — left sidebar dropped from 14 items to 9. Three data-movement pages (`import`, `import-media`, `export`) now live as tabs under a new **Tools** entry at `/admin/tools.php`. Four admin/configuration pages (`settings`, `micropub`, `account`, `login-log`) are unified as tabs under **Settings** at `/admin/settings.php` (General / Micropub / Account / Logs). Categories, Tags, Analytics, and Dashboard remain separate.
- **Tabbed page pattern** — new `.page-tabs` style and `partials/page-tabs.php` partial provide an underlined, top-of-page tab strip distinct from the existing `.filter-tabs` used inside listing toolbars. Tabs use `?tab=<slug>` for deep-linking, bookmarks, and back-button support; the active sidebar entry highlights for any of its consolidated routes via a new `match` array in `nav.php`. Each tab partial splits into a `.handler.php` (POST handling + GET-side data prep that may exit early) and a `.view.php` (HTML body) so long-running POSTs like WXR import still send `header()` before any output.

### Deprecated

- **Legacy admin URLs** — the six absorbed paths (`/admin/import.php`, `/admin/import-media.php`, `/admin/export.php`, `/admin/micropub.php`, `/admin/account.php`, `/admin/login-log.php`) are replaced by three-line `Location` 301 redirect stubs that forward to the right tab on the new host. Existing bookmarks keep working.

---

## [1.8.1] — 2026-05-11

### Changed

- **Admin post listing** — aside posts now show a violet `Aside` badge before the title so post kind is visible at a glance; standard posts stay unbadged. Posts with an empty title (typical for asides published from MarsEdit or imported from Micro.blog) render the first ~80 characters of stripped content in italic muted text where the title would go, instead of an empty link.

---

## [1.8.0] — 2026-05-10

### Added

- **WordPress XML import** — new `/admin/import.php` accepts a WordPress eXtended RSS (WXR) file and inserts each `<item>` as a post. Built for migrating from Micro.blog (titleless short-form notes) but works with any WXR export. Per-import dropdown selects how items become posts: **Auto** (aside if `<title>` is empty, else standard), **All asides**, or **All standard**. Items with `<wp:status>` of `publish` map to `published`; `draft`/`future`/`pending`/`private` map to `draft`; `trash` is skipped. Non-`post` types (pages, attachments) are skipped. Categories and tags from `<category>` elements are auto-created (matched on `nicename` slug) and attached. Imported posts always set `mastodon_skip = 1` and `bluesky_skip = 1` so syndication never fires for backlog. Re-uploads are safe — items are deduped by `<guid>` against the new `posts.import_guid` column. The whole import runs in one SQLite transaction; site rebuild deferred to a single pass at the end. Schema bumped to v18.
- **Media re-hosting** — new `/admin/import-media.php` scans every post for external `<img>` URLs, downloads each into `content/media/`, and rewrites the post HTML to point at local `/media/` paths. Designed for the Micro.blog migration (`https://*.micro.blog/uploads/...` images keep loading even if the source goes away) but works on any externally-hosted images. Idempotent — re-runs only fetch what's missing, deduped via the new `media.source_url` column. WXR import gains a "Download remote images locally" checkbox that runs the same fetch/rewrite per imported post inline. cURL streams to a temp file (PHP RAM stays flat regardless of image size), MIME is validated via `finfo` against the existing media allowlist, and JPEG/PNG downloads get a WebP companion. Failures are logged and the original URL is left in place; re-running retries only those. Schema bumped to v19.
- **Long-running import nginx location** — `nginx.conf.example` adds a regex location for `/admin/(import|import-media).php` with `fastcgi_read_timeout` / `fastcgi_send_timeout` of 3600s, so a large media re-host run isn't cut off by the default 60s proxy timeout. Repeats the existing admin CSP and security headers (nginx does not inherit `add_header` into sibling locations).

---

## [1.7.0] — 2026-05-10

### Added

- **Aside notes** — new `post_kind` column (`'standard'` or `'aside'`) lets MarsEdit and other clients publish WordPress-style titleless notes. List views render the full Markdown body inside the existing `.post-card` frame; single-aside pages drop the title, byline, reading time, and term pills, keep the date and syndication links, and emit a proper IndieWeb h-entry without `p-name`. Slug stores the autoincrement id so URLs stay `/YYYY/MM/DD/{id}/`. Atom emits an empty `<title/>`; RSS and JSON Feed omit the title element entirely. OG image generation is skipped (nothing to render). Schema bumped to v17.
- **POSSE for asides** — asides syndicate to Mastodon and Bluesky as native-looking notes: full plaintext content, no title, no link back to the site. `buildText` joins any non-empty subset of {title, excerpt, url} so omitted parts don't leave stray newlines, and Bluesky skips facet construction when the URL is empty. The post editor gains a live counter under aside content showing Bluesky grapheme and Mastodon character counts, with a truncation warning when either limit is exceeded.
- **Wayback Machine 404 fallback** — the 404 page queries the Wayback availability API for the requested URL and reveals a link to the closest archived snapshot when one exists.
- **Byline spec in feeds** — new RSS 2.0 feed at `/feed.rss` with [Byline](https://bylinespec.org/1.0) elements at channel and item level; the same elements are injected into the existing Atom feed. Byline-aware readers can now render author name, bio, avatar, and verified Mastodon/Bluesky/GitHub links from any subscription. Adds optional `author_bio` and `author_avatar_url` settings; mirrors RSS to per-term taxonomy archives; updates nginx and HTML `<head>` for content-type and discovery.
- **Mondrian-style gallery** — the `[gallery ids="..."]` shortcode now emits curated CSS Grid templates per image count (1–7), with images cropped to fit cells via `object-fit: cover`. Galleries of 8+ images are chunked into sibling blocks (e.g. 11 → 6+5) that visually merge into one continuous tile field. The JS masonry layout is removed; the existing lightbox is unchanged.
- **Callout styles** — three callout classes (blue notice, yellow information, red warning) using `color-mix` to derive a soft tinted background from each border colour.
- **`.button-link` class** — style links as primary-action buttons matching the search form's blue fill, white text, and darken-on-hover behaviour.
- **Footer feed link** — RSS feed link in the site footer, styled to match the social icons.

### Changed

- **Theme** — theme-aware post-card hover border; rounded corners on webmention reply cards and post cards; gallery gaps 4px → 6px; image figure borders removed; tighter borders and margins on callouts and image figures; reduced mobile top margin on site main; tightened content spacing throughout.

### Fixed

- **Webmention cache** — render cached webmentions without a redundant `JSON.parse` that could double-decode already-parsed payloads.
- **Mobile nav** — submenu no longer blocks clicks on content below the header.
- **404 Wayback link** — hidden via inline style so the `.btn` class can't override its default state.

---

## [1.6.0] — 2026-05-04

### Added

- **Sub-pages in navigation** — pages can now be associated with a single top-level parent page via a new "Parent page" select in the page editor. On desktop, sub-pages appear in a CSS-only dropdown (`:hover` / `:focus-within`) below their parent in the header nav; on mobile, they appear indented below the parent inside the existing hamburger menu. URLs stay flat (`/{slug}/`) — `parent_id` only affects nav grouping, so reparenting never breaks links. One level of nesting only; pages with sub-pages cannot themselves become sub-pages. Deleting a parent that still has sub-pages is blocked. The pages list gains a "Parent" column. Schema bumped to v16 (adds `parent_id INTEGER` + index on `pages`).

---

## [1.5.3] — 2026-05-03

### Changed

- **Theme** — taxonomy archives (tag and category pages) now also render post tiles in the same single-column, post-width layout as the home and search pages. No template still uses the wide two-column post-list grid.

---

## [1.5.2] — 2026-05-03

### Changed

- **Theme** — home and search pages now render post tiles in a single column matching the post content width, instead of a wider two-column grid. Taxonomy archives keep the two-column wide layout. Container widths fine-tuned: `--max-content` 750px → 740px, `--max-wide` 900px → 820px.

---

## [1.5.1] — 2026-04-28

### Added

- **Micropub media endpoint** — multipart POSTs with a `file` field (and no h-entry / no action) are now treated as media-endpoint uploads. The file is stored via `Media::upload` and the response is `201 Created` with a `Location` header pointing at the stored URL, letting Micropub clients upload images independently of post creation and reference the returned URL in a subsequent JSON h-entry.

---

## [1.5.0] — 2026-04-28

### Added

- **Micropub update + delete actions** — `micropub.php` now dispatches on the spec's `action` field in addition to create. `action=delete` (form-encoded or JSON) accepts a post URL, removes the post, deletes its rendered output, and rebuilds neighbors and shared resources. `action=update` (JSON only) supports `replace`, `add`, and `delete` operations on `name`, `content`, `mp-slug`, `category`, and `post-status`; `published` is intentionally frozen on existing posts. Slug changes also clean up the stale rendered file under the old date-path. Both actions reuse the bearer-token auth and rate-limit flow already used for create.
- **Post URL → Post resolution** — new `mp_resolve_post_by_url()` parses an incoming post URL and looks the post up by its final path segment via `Post::findBySlug()`. Slugs are unique across posts so the date portion of the URL is informational only.

### Changed

- **Theme** — content column narrowed from 830px to 750px (`--max-content`). Site footer social icons shrunk from 1rem to 0.9rem. Removed extra spacing between adjacent prose list items.

---

## [1.4.0] — 2026-04-27

### Added

- **Micropub endpoint** — new public `/micropub.php` accepts new posts from any [W3C Micropub](https://www.w3.org/TR/micropub/) client (iA Writer, Quill, MarsEdit-via-Micropub, Drafts, etc.). Supports `application/x-www-form-urlencoded`, `application/json`, and `multipart/form-data` (with inline `photo` uploads routed through the existing `Media::upload()` validator). Maps Micropub `category[]` values to existing CMS categories by slug match, falling back to creating tags. Honors `published`, `post-status`, and `mp-slug`. Reuses the same publish flow as the admin UI — static page is built, neighbors and shared resources are rebuilt, and Mastodon/Bluesky syndication runs on first publish.
- **Micropub admin page** — new `/admin/micropub.php` (with sidebar nav link) generates, replaces, or revokes the bearer token; token operations trigger a full static rebuild so the discovery `<link rel="micropub">` propagates immediately. Includes iA Writer setup instructions and an explicit warning that the **site root URL** (not the endpoint URL) is what the client must be configured with.
- **Micropub discovery tag** — `templates/base.php` emits `<link rel="micropub" href="…">` in the `<head>` of every public page when a token is configured, so Micropub clients can auto-discover the endpoint from the site root.
- **Nginx `location = /micropub.php`** block in both `docker/nginx.conf` and `nginx.conf.example`: limited to `GET POST`, with `HTTP_AUTHORIZATION` explicitly forwarded to PHP-FPM (the default `fastcgi_params` does not pass it).

### Auth

- Single long-lived bearer token stored in `settings.micropub_token` (32 random bytes, base64url). Compared with `hash_equals()`. Failed-auth attempts share the existing `login_attempts` table so they're subject to the same per-IP rate limit and lockout window as the admin login.

---

## [1.3.2] — 2026-04-26

### Fixed

- **CSS minifier preserves `calc()` operator spacing** — `Builder::minifyCss()` was stripping the spaces around `+` (and would have corrupted `~`) inside `calc()`, `clamp()`, `min()`, and `max()` because `+~` are also selector combinators in the structural-character regex. These functions are now tokenised to `\0CSSFN<n>\0` placeholders before whitespace collapse and restored at the end via `strtr`, so expressions like `calc(100% + 4rem)` survive minification intact

### Changed

- **Theme polish** in `theme.css`: content column widened to 830px (`--max-content`); pill-shaped tag/syndication/kudos chips (15px radius, slightly wider padding, tighter row gap); square corners on prose images, code blocks, blockquotes, post cards, embedded videos, and webmention replies; new wide-bleed treatment for in-prose images at ≥880px (extends `2rem` past the column on each side via `calc(100% + 4rem)`); blockquote inner text gets a subtle background-coloured `text-shadow` and `font-style: normal`; taxonomy archive header collapsed (smaller title, no bottom margin); footer inner width derived from `--max-content`; mobile `.site-main` top margin bumped to 2.5rem

---

## [1.3.1] — 2026-04-26

### Removed

- **Newsletter signups** — entire feature removed before any prod deploy: `subscribe.php`, `templates/partials/newsletter-form.php`, `admin/subscribers.php`, the Settings → Newsletter panel, the `.newsletter*` CSS, and the Nginx `subscribe` rate-limit zone + `/subscribe.php` location (in `docker/nginx.conf`, `nginx.conf.example`, and `jimmitchell.org.nginx.conf`). The schema v15 slot is retained as a no-op tombstone so `SCHEMA_VERSION` stays monotonic; existing dev DBs keep the orphan `newsletter_subscribers` table (delete `data/cms.db` to drop it cleanly). Direction change: if newsletter signup is wanted later, an embedded SaaS form (e.g. EmailOctopus) avoids the operational cost of running a transactional mail sender.

---

## [1.3.0] — 2026-04-23

### Added

- **Newsletter signups** — new public `/subscribe.php` endpoint and a signup form partial rendered at the bottom of each post via `templates/partials/newsletter-form.php`; stores addresses in a new `newsletter_subscribers` table (schema v15) with honeypot, per-IP hourly rate limit, and HMAC-hashed IPs (reusing the analytics salt); admin page at `/admin/subscribers.php` lists subscribers with filter tabs (all / active / unsubscribed), unsubscribe/resubscribe/delete actions, and a CSV export that prefixes `'` to any cell starting with `=`, `+`, `-`, `@`, `\t`, or `\r` to prevent spreadsheet formula injection
- **Newsletter toggle** — Settings → Newsletter checkbox (`newsletter_enabled`) controls whether the form is emitted during site rebuild; when off, the form is omitted from regenerated posts and `/subscribe.php` returns 404; existing subscriber records are always preserved so the list can be paused and resumed without loss
- **Nginx hardening for `/subscribe.php`** — POST-only, dedicated rate-limit zone (`subscribe`, 1 r/m with burst 5), 4 KB request-body cap, `X-Content-Type-Options: nosniff`, and `Cache-Control: no-store`; mirrored in both `docker/nginx.conf` and `nginx.conf.example`

### Rollback

- **Pre-feature commit:** `dfa3b2647955a992810b5d376a80f58dfc14fa84` — checkout this commit to abandon the newsletter feature and return the tree to the state before it was added

---

## [1.2.23] — 2026-04-20

### Added

- **Draft preview** — a **Preview** button in the post editor sidebar opens any saved draft (or published post) rendered through the full public theme in a new tab; no publishing, no static file written to disk; the preview endpoint (`admin/post-preview.php`) is auth-gated and tagged `X-Robots-Tag: noindex, nofollow`
- **Email reply pill** — a new **Email Reply** panel in Settings accepts an optional reply-to address; when set, an **Email** pill appears at the bottom of each post with a `mailto:` link pre-filled with `Re: [post title]` as the subject
- **Post footer pill order** — reordered to Mastodon → Bluesky → Email → Kudo button

---

## [1.2.22] — 2026-04-19

### Added

- **Custom favicon** — Settings → Site identity now has a "Favicon URL" field; upload a PNG (or any image) to the Media Library, paste its URL, and the site favicon updates on next rebuild; MIME type is inferred from the file extension; falls back to the default `/favicon.svg` when left blank

### Fixed

- **PNG upload crash** — `Media::generateWebp()` now checks `function_exists('imagewebp')` before attempting WebP conversion; previously, environments where GD is loaded but built without WebP support threw an uncaught fatal error that corrupted the JSON upload response
- **Deprecated `imagedestroy()` call** removed from `Media::generateWebp()`; GD images are freed automatically in PHP 8

---

## [1.2.21] — 2026-04-19

### Added

- **Autosave drafts** — the post editor now saves title, slug, content, and excerpt to `localStorage` with a 2 s debounce after any change; a fading "Draft saved locally" indicator appears in the Publish panel; on re-open a banner offers to restore or discard the stored draft (with age in minutes); the draft is cleared automatically on form submit
- **Page search** — `admin/pages.php` now has a title search form matching the existing posts search; `?q=` is preserved across status-tab links and the empty-state message distinguishes "no results" from "no pages"
- **Timezone label on publish date picker** — the "Publish date" label in the post editor now shows the configured site timezone (e.g. `America/New_York`) so the user knows what "now" means; hidden when no timezone is set

### Changed

- **Pagination partial** — the pagination block in `admin/posts.php` extracted to `admin/partials/pagination.php`; the partial is generic (`$_paginTotal`, `$_paginLabel`) so `admin/pages.php` can include it when needed

---

## [1.2.20] — 2026-04-18

### Added

- **Keyboard shortcuts in post editor** — `Ctrl/Cmd+S` saves (draft or update depending on post status); `Ctrl/Cmd+Shift+P` publishes; shortcuts work both when the Markdown editor has focus (registered via CodeMirror keymap) and when any other field is active (registered via `document` keydown)
- **Real-time slug uniqueness check** — the slug field in the post and page editors now shows an inline ✓ / ✗ indicator after a 350 ms debounce; resolved via a new session-authenticated `admin/slug-check.php` endpoint; correctly treats the current record's own slug as available when editing

---

## [1.2.19] — 2026-04-18

### Added

- **Tag autocomplete** — the tag input in the post editor is now a pill-style picker; typing filters existing tags in a dropdown (keyboard navigable with ↑↓/Enter/Escape); new tags not in the list are still created on Enter or comma; existing tags are injected server-side as `window._existingTags` and never fetched asynchronously

### Changed

- **Named query placeholders** — all `?` positional placeholders in `src/` and `admin/` standardised to `:name` style (`src/Post.php`, `src/Builder.php`, `admin/post-edit.php`, `admin/tags.php`, `admin/categories.php`, `admin/xmlrpc.php`); dynamic `IN (…)` batch queries retain `?` as PDO has no named equivalent for variadic lists

---

## [1.2.18] — 2026-04-17

### Changed

- **Typography — Inter** — replaced Figtree (sans-serif) and Crimson Pro (serif) with [Inter](https://rsms.me/inter/) as the sole typeface; Inter is self-hosted as a variable font (`Inter-Variable.woff2` / `Inter-Variable-Italic.woff2`, OFL license) covering weight 100–900; prose content switches from serif to sans-serif at `1.1rem`
- **OG image font** — `src/OgImage.php` updated to use `Inter-Regular.ttf` / `Inter-Bold.ttf` for server-side PNG generation; existing OG images regenerate automatically on next build

---

## [1.2.17] — 2026-04-16

### Security

- **Custom CSS XSS fix** — `</style>` escape in `templates/base.php` changed from case-sensitive `str_replace` to `str_ireplace`; previously a payload using uppercase `</STYLE>` bypassed the filter and could break out of the style block on every public page

---

## [1.2.16] — 2026-04-16

### Security

- **API CORS restricted** — `Access-Control-Allow-Origin: *` replaced with an origin-matched header derived from the configured `site_url`; falls back to `*` only when `site_url` is unset (initial setup); `Vary: Origin` added alongside; native app clients (iOS, Xcode simulator) are unaffected as they do not send `Origin` headers
- **CSP `img-src` broadened** — changed from `https://avatars.webmention.io` to `https:` across all Nginx configs so external images embedded in post content (Markdown or raw HTML) are not silently blocked by the policy
- **Nginx `/fonts/` location hardened** — added explicit CSP (`default-src 'none'; font-src 'self'`) and security headers to the `/fonts/` location in `docker/nginx.conf`, syncing it with `nginx.conf.example`

---

## [1.2.12] — 2026-04-14

### Fixed

- **Post date slug** — posts published in the late evening (in negative-UTC-offset timezones) no longer get a slug one day ahead; `datePath()` now converts the stored UTC timestamp to the configured site timezone before extracting the `YYYY/MM/DD` path segment

---

## [1.2.11] — 2026-04-12

### Fixed

- **Code copy button** — button now turns green immediately on click and stays green (with white checkmark) regardless of mouse position until the 2-second reset; previously the green state was only visible after mousing away from the button

---

## [1.2.10] — 2026-04-12

### Added

- **WordPress XML export** — new Admin → Export page downloads all posts (published, and optionally drafts/scheduled) as a WXR 1.2 file; includes categories, tags, and post content rendered to HTML; importable into any WordPress site via Tools → Import

---

## [1.2.4] — 2026-03-30

### Changed

- **Analytics** — 404 errors now sorted by most recent first; default date range changed from 30 to 7 days; "Top pages" and "Device types" panels stack vertically on mobile; "Last seen" column is right-aligned

---

## [1.2.3] — 2026-03-29

### Changed

- **Code simplification** — migration loop, settings helpers, query patterns, slug generation, syndication logic, and build calls consolidated for clarity and consistency
- **Standardised output escaping** — `dashboard.php` and `analytics.php` now use `Helpers::e()` consistently with the rest of the admin
- **page-edit delete handler** — moved before save block so delete action is reachable (was previously unreachable)

### Fixed

- **XSS** — `$post->status` and `$page->status` now escaped with `Helpers::e()` in badge output
- **Session cookie `secure` flag** — now also set when behind a TLS-terminating reverse proxy via `HTTP_X_FORWARDED_PROTO`
- **WebAuthn rpId** — derived from canonical `site_url` setting instead of attacker-controllable `HTTP_HOST` header
- **Migration seed SQL injection** — settings seed now uses a prepared statement instead of string interpolation
- **DNS rebinding (Mastodon SSRF)** — hostname resolved immediately before curl connects and pinned via `CURLOPT_RESOLVE`

---

## [1.2.2] — 2026-03-28

### Fixed

- **Analytics timezone** — daily chart grouping, chart axis labels, and 404 "last seen" timestamps now respect the timezone configured in Settings instead of always using UTC

---

## [1.2.1] — 2026-03-28

### Added

- **Built-in analytics beacon** — `track.php` at the web root accepts `navigator.sendBeacon` POST requests with `{url, referrer, is404}` JSON; no cookies or third-party services used
- **`page_views` database table** (schema v13) — stores url, referrer, device_type, is_404, ip_hash, and timestamp; auto-migrates on boot
- **IP privacy** — client IP addresses are stored as HMAC-SHA256 hashes using a server-side salt; raw IPs are never persisted
- **Rate limiting** — PHP-level limit of 30 requests/minute per IP in `track.php`; Nginx `limit_req_zone` at 2 r/s with burst 20 in `location = /track.php`
- **Analytics dashboard** (`admin/analytics.php`) — Chart.js graphs for views/day, top pages, device breakdown, and referrers; 404 error table; 7/30/90-day range selector; owner opt-out URL shown
- **Chart.js 4.4.7** vendored locally at `admin/assets/chart.min.js` — no external CDN dependency
- **Beacon JS in public templates** — `templates/base.php` now includes a small inline script that fires `sendBeacon` on page load; visit `/?ti=exclude` to set a localStorage opt-out flag, `/?ti=include` to re-enable
- **404 tracking** — `templates/404.php` sets an `analyticsIs404` flag so 404 hits are recorded separately in the dashboard
- **Automatic data pruning** — `admin/bootstrap.php` deletes `page_views` rows older than 90 days on ~1% of admin requests
- **Nginx config for beacon** — `docker/nginx.conf` and `nginx.conf.example` both include a `location = /track.php` block with `limit_req`, `limit_except POST`, `client_max_body_size 4k`, and security headers

---

## [1.1.1] — 2025-xx-xx

### Changed

- Updated `league/commonmark` to v2.8.2

---

## [1.1.0] — 2025-xx-xx

### Added

- **Passkey (WebAuthn) authentication** — admin login now supports passkeys as an alternative to password + TOTP; manage passkeys from Admin → Account

---

## [1.0.0] — Initial release

### Added

- Static-output CMS with PHP/SQLite admin panel
- Markdown editor (EasyMDE) with GitHub-flavored Markdown, footnotes, and server-side syntax highlighting
- Posts and pages with draft, published, and scheduled statuses
- Date-based post URLs (`/YYYY/MM/DD/{slug}/`)
- Categories and tags taxonomy with archive pages
- Media library with drag-and-drop uploads
- Image galleries with masonry layout and lightbox
- Atom feed and JSON Feed 1.1
- Open Graph image generation (GD + FreeType)
- JSON-LD structured data (BlogPosting schema.org)
- Mastodon and Bluesky auto-syndication
- Incoming webmentions via webmention.io (client-side display)
- Outgoing webmentions CLI script (`bin/send-webmentions.php`)
- WordPress-compatible XML-RPC API (MarsEdit support)
- REST API with HTTP Basic Auth
- Client-side full-text search
- TOTP two-factor authentication
- Activity log and login attempt history
- Google Analytics GA4 integration (optional)
- Tinylytics integration (optional)
- Dark/light mode with system-preference detection
- Custom CSS via Settings panel
- Collapsible admin sidebar
- Docker local development setup
- Production Nginx configuration example with CSP headers and TLS
