# Clodd CMS — Improvement TODO List

## Bugs

- [ ] **A Bluesky edit is written but never seen.** Not a bug in this codebase and not fixable here: `putRecord` rewrites the record in the repo and answers 200, but Bluesky's AppView does not re-index a post record it has already indexed, so bsky.app keeps serving the original text. Confirmed 2026-08-16 across four records, the oldest edited four days earlier — the PDS held the new words in every one, the AppView the old ones.

  1.20.0 stops the CMS claiming success it cannot see: the write is checked against the AppView five minutes later from cron, and a copy that came back behind gets a **Bluesky behind** badge on the post list and an explanation in the editor. Left open because the underlying behaviour is still Bluesky's, and the only thing that would actually surface an edit is delete-and-repost — a new URL, and the likes and replies on the old record abandoned. Not worth it; decide case by case when the badge appears.

  Diagnosis takes two unauthenticated requests — compare what the repo holds against what readers see:

  ```
  DID=did:plc:fwhnzbkc5lnmvbtyqwut5nwy
  curl -s "https://bsky.social/xrpc/com.atproto.repo.getRecord?repo=$DID&collection=app.bsky.feed.post&rkey=RKEY"
  curl -s -A "facebookexternalhit/1.1" "https://bsky.app/profile/jimmitchell.org/post/RKEY" | grep og:description
  ```

- [ ] **Backfill preview cards on posts syndicated before 1.21.2.** Neither fix in 1.21.1/1.21.2 reaches backwards, so every copy made before the deploy is still missing the card it should have had. Two different problems with two different prospects, so decide them separately.

  **Scope, measured 2026-08-18** against the last 133 statuses (back to 2025-09-19): 27 link back to jimmitchell.org, and **13 of those have no Mastodon preview card**. The other 14 do — which confirms the diagnosis, because a race is exactly what an intermittent result looks like. Where the build happened to finish before Mastodon's crawler arrived the card came out fine. On Bluesky the picture is simpler and worse: **no titled post has ever had a card**, since `app.bsky.embed.external` did not exist here until 1.21.1.

  **Mastodon — probably possible, unverified.** Mastodon re-runs its link crawler when a status is *edited*, so touching each of the 13 statuses may regenerate the card from OG tags that are now served correctly. This is a guess about Mastodon's internals and has not been tested; **try it by hand on one old post before writing anything**. The machinery already exists — `Mastodon::editPost()` via `Syndication::update()` — so if the guess holds, the work is a small script over posts whose `mastodon_url` is set and whose status has `card: null`, not new plumbing. The cost is that each one gains an "edited" marker for a change no reader asked for, which may not be worth it for a card on a post from last year. Consider capping it at the recent ones.

  **Bluesky — not possible, don't try.** Adding an embed means `putRecord`, and the AppView does not re-index a record it has already seen (the entry above, and `CLAUDE.md`). The record would gain a correct card that nobody can see. Delete-and-repost is the only lever that works and it costs the URL plus the likes and replies on the old record — not worth it for a thumbnail. Leave these alone.

  Check what a given status actually has before touching it — do not judge by eye in the web UI, which renders a cached card either way:

  ```
  curl -s https://indieweb.social/api/v1/statuses/STATUS_ID \
    | python3 -c "import sys,json;print(json.load(sys.stdin)['card'])"

  curl -s "https://public.api.bsky.app/xrpc/app.bsky.feed.getPostThread?uri=at://jimmitchell.org/app.bsky.feed.post/RKEY&depth=0" \
    | python3 -c "import sys,json;print(json.load(sys.stdin)['thread']['post']['record'].get('embed'))"
  ```

- [x] **A scheduled post sent over XML-RPC publishes immediately.** Confirmed on prod 2026-08-15, right after the 1.19.0 deploy. **Fixed in 1.19.3** — the cause was the second suspect below: the `_gmt` date variants most WordPress clients send were never read, so `$pubAt` came back null and fell through to *now*. Verified in prod with MarsEdit 2026-08-16. The admin path schedules correctly, so this is specific to XML-RPC — and it is almost certainly pre-existing rather than caused by 1.19.0, since none of that release touched date parsing.

  The status ternary is **not** the bug. Both write paths already read the same way and both look right:
  - `XmlRpcServer::applyStruct()` (metaWeblog) — `$post->status = strtotime($effectivePubAt) > time() ? 'scheduled' : 'published';`
  - `XmlRpcServer::applyWpPostStruct()` (wp.newPost) — identical line.

  The suspect is one line above each, where `$pubAt` is derived:
  - metaWeblog reads **only** `$struct['dateCreated']`
  - wp.newPost reads **only** `$struct['post_date']`

  When the field is absent, `$pubAt` is null and `$effectivePubAt` falls back to `date('Y-m-d H:i:s')` — *now* — so the post publishes immediately. That is exactly the observed symptom. Most WordPress clients send **`date_created_gmt`** (and `post_date_gmt`) alongside or *instead of* the local-time field, and neither path looks at the GMT variant at all.

  Second, less likely suspect: `XmlRpc::parseDate()` treats a naked timestamp as site-local. A client sending UTC without a `Z` would be misread, though for most timezones that pushes the date further into the future rather than into the past, so it would not produce an immediate publish on its own.

  To fix: log `$rawDate` and the resolved `$effectivePubAt` for one scheduled call to see which field the client actually sends, then accept the `_gmt` variants (parsing those as UTC regardless of site timezone). Worth a test that a struct carrying only `date_created_gmt` still yields `scheduled`.

  Which client was used matters — MarsEdit vs something else — since it decides which of the two paths ran.

## Security

- [x] **Atomic config.php writes** — `admin/account.php` writes password changes with a temp file + rename pattern; wrap with `flock()` to prevent race conditions during concurrent reads
- [x] **Restrict CORS on API** — `admin/api.php` sends `Access-Control-Allow-Origin: *`; lock down to a known trusted origin or remove if API is server-only
- [x] **Add Content-Security-Policy headers** — No CSP headers exist; add via nginx or PHP `header()` to mitigate XSS
- [x] **Move config secrets to env vars** — `config.php` is already gitignored and nginx-blocked; API tokens (Mastodon, Bluesky) are in the SQLite DB which is also gitignored. Risk already mitigated.
- [x] **Sanitize custom CSS input** — `templates/base.php` `</style>` escape changed from case-sensitive `str_replace` to `str_ireplace`; prevents `</STYLE>` bypass (admin-only field, no further sandboxing needed for single-author CMS)

---

## Performance

- [x] **Add missing DB indexes** — `src/Database.php` schema is missing indexes on: `posts.published_at`, `posts.slug`, `post_categories.post_id`, `post_tags.post_id`, `categories.slug`, `tags.slug` — add in a new schema migration
- [x] **Cache Settings queries** — `$db->getSetting()` is called repeatedly per request; cache results in a static property after first load
- [x] **Incremental site rebuild** — `admin/post-edit.php` now skips neighbor rebuilds and index/sitemap when only post body changed; also fixed stale archives for removed categories/tags

---

## Features

- [ ] **Post revisions** — Store previous versions of post content on save; allow viewing history and rolling back
- [x] **Autosave drafts** — Save editor content to `localStorage` periodically; show recovery prompt on re-open
- [ ] **Bulk actions on post/page lists** — Add checkboxes + bulk publish/unpublish/delete to `admin/posts.php` and `admin/pages.php`
- [ ] **Post clone** — "Duplicate as draft" action on post list and edit page
- [x] **Draft preview** — Generate a temporary HTML preview of a draft without publishing or running a full site rebuild
- [x] **Tag autocomplete** — Replace raw comma-separated tag input with a pill-style picker with autocomplete against existing tags
- [x] **Related posts** — 1.21.0. Opt-in via Settings → Content (`show_related_posts`, default off). Up to three titled posts scored `2 × shared categories + 1 × shared tags`, ties by recency; `Post::findRelated()` / `findRelatedNeighbours()`, rendered by `templates/partials/related-posts.php` with no mf2 classes so it cannot be parsed as child entries. `Builder::buildPost()` re-renders term-sharing neighbours so a new post does not leave existing lists stale.

  Verified on prod 2026-08-18: **all 10 titled posts on the first page of `/category/posts/` carry a block**, and the picks are topical rather than merely recent — *why-i-blog-the-way-i-do* draws *Social Media Hasn't Killed the Personal Blog Yet*, *Thousands of Niche Open Web Apps* and *Micro.blog Question Challenge*. Exactly one `h-entry` per page, so the block introduces no nested child entries.

  Note for anyone re-measuring: `data/cms.db` in a dev checkout is **not** representative. It is full of *Test Post* / *Lorem Ipusm* rows, holds 12 `post_tags` sitewide, and its `post_categories` is dominated by *Photos* — measuring coverage against it predicted that 45 of 56 titled posts would show nothing, which is wrong for prod, where the tags are real and in active use. Sample the live site instead.

- [ ] **Scheduled post notifications** — Send an email/webhook when a scheduled post is auto-published
- [ ] **Activity log filters** — Add date range + action type filter to the Logs tab (`admin/settings.php?tab=logs`)
- [x] **Micropub `summary` property** — `micropub.php` reads `properties.summary` on create and supports replace/add/delete on update, assigning to `$post->excerpt` so Mastodon/Bluesky syndication and feeds use a client-supplied summary instead of always auto-deriving via `effectiveExcerpt()`.
- [x] **Micropub IndieAuth flow + token scopes** — Self-hosted IndieAuth server: `indieauth.php` (authorization endpoint with consent screen, PKCE S256 required), `token.php` (code exchange, revocation, introspection), `indieauth-metadata.php`, discovery links in `templates/base.php`, scoped tokens (`profile`/`create`/`update`/`delete`/`media`) stored hashed in `indieauth_tokens`, enforced via `MicropubAuth::requireScope()`. Authorized apps are listed/revocable in Settings → Micropub. Manual-token mode (iA Writer) stays as a full-scope fallback.
- [x] **Micropub `q=category` query** — `micropub.php` implements `?q=category`, returning `{categories: [name, …]}` merged from the `categories` and `tags` tables (deduped via `UNION`, sorted `COLLATE NOCASE`) so clients can populate a category/tag picker. `q=config` now advertises supported queries via a `q` array.
- [x] **Micropub indieweb context properties** — `in-reply-to`, `like-of`, `repost-of`, `bookmark-of` persist to a `post_contexts` table (V23), round-trip through create/update/`q=source`, and render as context lines with mf2 `u-*` classes on post pages, list cards, and feeds — so `bin/send-webmentions.php` picks the target URLs up from the built HTML automatically. Titleless context posts become asides. (`syndication`, `location`, `rsvp` still dropped — niche, revisit if a client needs them.)
- [x] **Micropub `action=undelete`** — Posts soft-delete via `posts.deleted_at` (V20); Micropub delete sets it, undelete restores and rebuilds. Deleted posts are excluded from finders, feeds, and builds; the admin post list gains a Deleted filter with Restore / Delete permanently.
- [x] **Micropub `syndicate-to` exposes configured targets** — `q=config` / `q=syndicate-to` return `{uid, name}` for configured Mastodon/Bluesky accounts; create honors `mp-syndicate-to` (when present, only listed targets syndicate; absent keeps auto-POSSE).
- [x] **Micropub post list (`q=source` with no `url`)** — The [Query for Post List](https://indieweb.org/Micropub-extensions#Query_for_Post_List) extension: `{items: [h-entry, …]}`, newest first, drafts and scheduled posts included, so a client can offer a post picker instead of demanding a pasted URL. `limit` (default 20, cap 100), `offset`, `post-type` (PTD name) and `post-status` filter it; unknown filter values are a `400`. `Post::micropubType()` derives the PTD name from the hydrated contexts and `Post::findForMicropub()` mirrors that ladder in SQL — `tests/PostMicropubQueryTest.php` asserts the two agree. `q=source` also now emits a `url` for unpublished posts (`addressablePath()`), matching the create/update `Location:`. (`filter=`, `after`/`before` cursors, `order=` still unimplemented — all still brainstorming upstream.)
- [x] **Micropub `published` updatable on existing posts** — `replace` of `published` is allowed (add/delete stay rejected): the stale output under the old date-path is removed, old- and new-position neighbors rebuild, and the response is `201` + new Location. A future timestamp on a published post flips it to `scheduled` (output removed) until the scheduler promotes it.

---

## Code Quality / Tech Debt

- [x] **Extract pagination helper** — `admin/posts.php` and `admin/pages.php` duplicate the same pagination block; extract to a shared template partial
- [ ] **Split Builder class** — `src/Builder.php` handles posts, pages, feeds, sitemaps, and OG images; split into focused generators (e.g. `FeedGenerator`, `SitemapGenerator`, `OgImageGenerator`). _Done so far: shortcode/embed rendering extracted to `src/ShortcodeRenderer.php` (~300 lines)._
- [x] **Decouple scheduled-post promotion from page load** — shipped in 1.19.0. Cron (`bin/publish-scheduled.php`) publishes; the web entry points keep a heartbeat-gated fallback.
  - [x] **Make promotion atomic** — `Post::promoteScheduled()` now runs its `SELECT` and `UPDATE` inside `Database::transaction()` (`BEGIN IMMEDIATE`), and the `UPDATE` repeats the status predicate. Covered by a test that runs four real processes against one database behind a barrier file — two calls in one process cannot race, so an in-process test passed with and without the fix and was thrown away.
  - [x] **Lock + heartbeat in `Scheduler::run()`** — `flock(LOCK_EX|LOCK_NB)` on `data/scheduler.lock`; `scheduler_ran_at` written on every completed run whether or not anything was promoted; optional `ActivityLog` so scheduled publishes show in Settings → Logs.
  - [x] **`bin/publish-scheduled.php`** — with `--dry-run` and `--quiet`. Loud on failure even under `--quiet`.
  - [x] **Collapse the three call sites to `Scheduler::tick()`** — and in `micropub.php` it moved *below* `authenticate()`, which also closed a pre-auth build/syndication path an anonymous request could drive.
  - [x] **Warn when the heartbeat goes stale** — dashboard notice in the Scheduled panel. _(Decision: the web fallback stays, paired with the warning.)_
  - [x] **Tests** — 16 in `tests/SchedulerTest.php`. Writing them found a real bug: `PDO::inTransaction()` does not track a raw `BEGIN`, so the nesting guard in `Database::transaction()` was a no-op and nested calls threw.
  - [x] **Ops + docs** — "Scheduled posts (cron)" in `INSTALL.md`, docker compose `scheduler` service inheriting `user: ${UID}:${GID}`.
  - [x] **Verify in prod** — confirmed 2026-08-16: a post scheduled from MarsEdit over XML-RPC was promoted by cron at its scheduled time, and the publish date/time read correctly in admin. Closes out the scheduled-post path unverified since 1.13.3. _(Verified locally end-to-end in 1.19.0: published exactly once, HTML + og.png written, activity logged, second run a no-op.)_ The prod run doubled as the regression check for the 1.19.3 XML-RPC scheduling fix — before it, a MarsEdit-scheduled post published immediately. **Not separately confirmed in prod:** syndication firing exactly once and the syndication links appearing on the post page — worth an eye on the next scheduled post.
- [x] **Standardize positional vs named query placeholders** — Some queries use `?`, others `:name`; standardize on named placeholders throughout `src/`
- [x] **Server-side slug validation for categories** — `admin/categories.php` auto-generates slugs in JS only; add server-side uniqueness check to match posts/pages behavior

---

## UX / UI

- [x] **Keyboard shortcuts in post editor** — Add `Ctrl+S` / `Cmd+S` to save, `Ctrl+Shift+P` to publish
- [x] **Real-time slug uniqueness check** — Debounced AJAX check on the slug field in post/page edit to flag conflicts before saving
- [x] **Timezone label on publish date picker** — The `datetime-local` input in `admin/post-edit.php` should display the configured site timezone so the user knows what "now" means
- [ ] **Calendar view for scheduled posts** — Visual calendar in the posts list showing upcoming scheduled publish dates
