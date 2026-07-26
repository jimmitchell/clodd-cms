# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

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
