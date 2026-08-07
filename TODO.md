# Clodd CMS — Improvement TODO List

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
- [ ] **Scheduled post notifications** — Send an email/webhook when a scheduled post is auto-published
- [ ] **Activity log filters** — Add date range + action type filter to the Logs tab (`admin/settings.php?tab=logs`)
- [x] **Micropub `summary` property** — `micropub.php` reads `properties.summary` on create and supports replace/add/delete on update, assigning to `$post->excerpt` so Mastodon/Bluesky syndication and feeds use a client-supplied summary instead of always auto-deriving via `effectiveExcerpt()`.
- [x] **Micropub IndieAuth flow + token scopes** — Self-hosted IndieAuth server: `indieauth.php` (authorization endpoint with consent screen, PKCE S256 required), `token.php` (code exchange, revocation, introspection), `indieauth-metadata.php`, discovery links in `templates/base.php`, scoped tokens (`profile`/`create`/`update`/`delete`/`media`) stored hashed in `indieauth_tokens`, enforced via `MicropubAuth::requireScope()`. Authorized apps are listed/revocable in Settings → Micropub. Manual-token mode (iA Writer) stays as a full-scope fallback.
- [x] **Micropub `q=category` query** — `micropub.php` implements `?q=category`, returning `{categories: [name, …]}` merged from the `categories` and `tags` tables (deduped via `UNION`, sorted `COLLATE NOCASE`) so clients can populate a category/tag picker. `q=config` now advertises supported queries via a `q` array.
- [x] **Micropub indieweb context properties** — `in-reply-to`, `like-of`, `repost-of`, `bookmark-of` persist to a `post_contexts` table (V23), round-trip through create/update/`q=source`, and render as context lines with mf2 `u-*` classes on post pages, list cards, and feeds — so `bin/send-webmentions.php` picks the target URLs up from the built HTML automatically. Titleless context posts become asides. (`syndication`, `location`, `rsvp` still dropped — niche, revisit if a client needs them.)
- [x] **Micropub `action=undelete`** — Posts soft-delete via `posts.deleted_at` (V20); Micropub delete sets it, undelete restores and rebuilds. Deleted posts are excluded from finders, feeds, and builds; the admin post list gains a Deleted filter with Restore / Delete permanently.
- [x] **Micropub `syndicate-to` exposes configured targets** — `q=config` / `q=syndicate-to` return `{uid, name}` for configured Mastodon/Bluesky accounts; create honors `mp-syndicate-to` (when present, only listed targets syndicate; absent keeps auto-POSSE).
- [x] **Micropub `published` updatable on existing posts** — `replace` of `published` is allowed (add/delete stay rejected): the stale output under the old date-path is removed, old- and new-position neighbors rebuild, and the response is `201` + new Location. A future timestamp on a published post flips it to `scheduled` (output removed) until the scheduler promotes it.

---

## Code Quality / Tech Debt

- [x] **Extract pagination helper** — `admin/posts.php` and `admin/pages.php` duplicate the same pagination block; extract to a shared template partial
- [ ] **Split Builder class** — `src/Builder.php` handles posts, pages, feeds, sitemaps, and OG images; split into focused generators (e.g. `FeedGenerator`, `SitemapGenerator`, `OgImageGenerator`). _Done so far: shortcode/embed rendering extracted to `src/ShortcodeRenderer.php` (~300 lines)._
- [ ] **Decouple scheduled-post promotion from page load** — `bootstrap.php`, `admin/api.php` and `micropub.php` each run the scheduler inline, so a post goes live only when someone happens to knock, and that visitor's request pays for 4× `buildPost()`, `rebuildSharedResources()` and two syndication calls. Move to cron; keep a non-blocking web fallback.
  - [ ] **Make promotion atomic** — `Post::promoteScheduled()` does `SELECT` then a separate `UPDATE`, so two concurrent callers can promote the same ids and syndicate twice. Wrap in `BEGIN IMMEDIATE` (not `UPDATE … RETURNING` — don't depend on prod's SQLite version); needs a transaction helper on `Database`. Standalone bug fix, ship first.
  - [ ] **Lock + heartbeat in `Scheduler::run()`** — `flock(LOCK_EX|LOCK_NB)` on `data/scheduler.lock` so a stuck syndication call can't stack up runs; write a `scheduler_ran_at` setting each run; take an optional `ActivityLog` so scheduled publishes appear in Settings → Logs (today they don't). No schema migration — `scheduler_ran_at` is a settings row.
  - [ ] **`bin/publish-scheduled.php`** — CLI runner modelled on `bin/send-webmentions.php`, with `--dry-run` and `--quiet`. Cron every minute; `bin/` is already nginx-denied, so no new public surface.
  - [ ] **Collapse the three call sites to `Scheduler::tick()`** — fresh heartbeat → return; else check for a due post; else `fastcgi_finish_request()` (guarded by `function_exists`) and then `run()`, so no response ever waits on a build.
  - [ ] **Warn when the heartbeat goes stale** — dashboard notice next to the scheduled-posts panel if `scheduler_ran_at` is older than ~15 min and posts are scheduled. Without this a dead cron is invisible until a post is late. _(Open: keep the web fallback at all, or depend on cron outright? The fallback is only worth its keep paired with this warning.)_
  - [ ] **Tests** — `tick()` no-ops on a fresh heartbeat and runs on a stale one; a held lock makes `run()` return `[]` without promoting; two `Database` handles on one file each get an id exactly once; the heartbeat is written even when nothing was promoted.
  - [ ] **Ops + docs** — crontab must belong to the FPM user (`sudo crontab -u www-data -e`), or builder-written HTML ends up owned by the wrong user and the next web-triggered rebuild fails on permissions; docker compose scheduler service inherits `user: ${UID}:${GID}` for the same reason; "Scheduled posts (cron)" section in `INSTALL.md` beside the webmentions one. Deploy needs an FPM reload for the web path (opcache); the CLI runner is unaffected.
  - [ ] **Verify in prod** — schedule a post two minutes out, watch the log, confirm the HTML lands, syndication fires once, and the post page shows the syndication links. Closes out the scheduled-post path still unverified since 1.13.3.
- [x] **Standardize positional vs named query placeholders** — Some queries use `?`, others `:name`; standardize on named placeholders throughout `src/`
- [x] **Server-side slug validation for categories** — `admin/categories.php` auto-generates slugs in JS only; add server-side uniqueness check to match posts/pages behavior

---

## UX / UI

- [x] **Keyboard shortcuts in post editor** — Add `Ctrl+S` / `Cmd+S` to save, `Ctrl+Shift+P` to publish
- [x] **Real-time slug uniqueness check** — Debounced AJAX check on the slug field in post/page edit to flag conflicts before saving
- [x] **Timezone label on publish date picker** — The `datetime-local` input in `admin/post-edit.php` should display the configured site timezone so the user knows what "now" means
- [ ] **Calendar view for scheduled posts** — Visual calendar in the posts list showing upcoming scheduled publish dates
