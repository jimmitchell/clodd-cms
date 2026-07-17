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
- [ ] **Decouple scheduled-post promotion from page load** — `bootstrap.php` promotes scheduled posts on every admin page load; move to a background cron or async trigger
- [x] **Standardize positional vs named query placeholders** — Some queries use `?`, others `:name`; standardize on named placeholders throughout `src/`
- [x] **Server-side slug validation for categories** — `admin/categories.php` auto-generates slugs in JS only; add server-side uniqueness check to match posts/pages behavior

---

## UX / UI

- [x] **Keyboard shortcuts in post editor** — Add `Ctrl+S` / `Cmd+S` to save, `Ctrl+Shift+P` to publish
- [x] **Real-time slug uniqueness check** — Debounced AJAX check on the slug field in post/page edit to flag conflicts before saving
- [x] **Timezone label on publish date picker** — The `datetime-local` input in `admin/post-edit.php` should display the configured site timezone so the user knows what "now" means
- [ ] **Calendar view for scheduled posts** — Visual calendar in the posts list showing upcoming scheduled publish dates
