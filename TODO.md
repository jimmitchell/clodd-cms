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
- [ ] **Micropub IndieAuth flow + token scopes** — `micropub.php` only supports a single long-lived shared bearer token; clients like Quill, Indigenous, and Micropublish expect the IndieAuth dance (discover `authorization_endpoint` / `token_endpoint`, exchange code for a scoped token, verify via introspection). Add `<link rel="authorization_endpoint">` / `<link rel="token_endpoint">` discovery in `templates/base.php`, run token introspection in `mp_authenticate()`, and honor per-token scopes (`create` / `update` / `delete` / `media`). Manual-token mode (iA Writer) stays as a fallback.
- [ ] **Micropub `q=category` query** — `micropub.php:226` returns `[]` for unknown queries; implement `?q=category` returning `{categories: [name, …]}` from the `categories` table so clients can populate a category picker instead of asking users to type blind.
- [ ] **Micropub indieweb context properties** — `micropub.php` silently drops `in-reply-to`, `like-of`, `repost-of`, `bookmark-of`, `syndication`, `location`, `rsvp` on create and update (default case in the property switch at `micropub.php:515-517`). Add a `post_contexts` table (post_id, kind, url) and persist/render these so reply/like/bookmark posts round-trip through Micropub clients and webmentions can be sent on publish.
- [ ] **Micropub `action=undelete`** — `micropub.php:594` rejects undelete with `invalid_request`. Requires soft-delete first (deletes are currently hard via `Post::delete()`); add a `deleted_at` column, change delete to set it, exclude soft-deleted posts from listings/builds, and reverse on undelete.
- [ ] **Micropub `syndicate-to` exposes configured targets** — `micropub.php:188,192` always returns an empty array. When Mastodon or Bluesky credentials exist, surface them as syndication targets (`{uid, name}`) so clients can opt in/out per post via the `mp-syndicate-to` property; skip the automatic POSSE path when the client explicitly omits a target.
- [ ] **Micropub `published` updatable on existing posts** — `micropub.php:422-431` rejects `replace`/`add`/`delete` of `published`. Allow updating it (and rebuild the date-path output, removing the stale file under the old date-path the way `mp-slug` already does) so backdating or correcting an existing post's publish time works via Micropub.

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
