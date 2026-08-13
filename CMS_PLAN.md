# PHP Flat-File CMS — Development Plan

## Overview

A lightweight, static-output CMS written in PHP, inspired by Kirby. Content is authored in Markdown through a secure admin panel and published as pre-rendered HTML files on disk. The server never runs PHP at page-browse time — only during admin operations and builds.

**Key characteristics:**

- PHP admin panel + build engine; pure HTML output for visitors
- SQLite for content metadata; Markdown source stored in the database
- Smart incremental rebuilds — only changed content re-renders
- Deployed on a Linux VPS (Digital Ocean / Hetzner); Nginx + PHP-FPM
- Single admin user (credentials in config file)
- Posts and static pages, both authored in Markdown
- Media uploads: images, video, audio
- Minimalistic one-column theme
- Atom feed, paginated post index, draft/scheduled posts

---

## Technology Choices

| Concern | Choice | Rationale |
|---|---|---|
| Language | PHP 8.3 | Current stable; ships in Ubuntu 24.04 PPA |
| Web server | Nginx 1.24+ | Efficient static file serving; PHP-FPM for admin |
| PHP process | PHP-FPM 8.3 | Standard Nginx/PHP integration |
| Database | SQLite 3 (via PDO) | Zero-config, file-based, no server needed |
| TLS | Let's Encrypt + Certbot | Free, auto-renewing certificates |
| Markdown | `league/commonmark` | CommonMark compliant, actively maintained |
| Syntax highlighting | `scrivo/highlight.php` | Server-side, no JS; xcode-dark palette |
| Admin editor | EasyMDE (SimpleMDE fork) | Browser-based Markdown editor, no build step |
| Dependency management | Composer | Standard for PHP |
| Templating | Plain PHP templates | No extra engine, easy to customise |
| CSS (admin) | Vanilla CSS | No framework, minimal footprint |
| CSS (theme) | Vanilla CSS | Output pages need zero JavaScript |

---

## Directory Structure

```
/var/www/cms/               ← document root on VPS (owned by deploy user, readable by www-data)
│
├── admin/                  ← Admin panel (PHP, password-protected)
│   ├── index.php           ← Login page / dashboard redirect
│   ├── dashboard.php
│   ├── posts.php           ← Post list with status tabs and title search
│   ├── post-edit.php       ← Create / edit post
│   ├── pages.php           ← Static page list
│   ├── page-edit.php       ← Create / edit static page
│   ├── media.php           ← Media library & uploader
│   ├── settings.php        ← Site-wide settings
│   ├── account.php         ← Change admin password + TOTP 2FA management
│   ├── analytics.php       ← Analytics dashboard (views/day, top pages, devices, referrers, 404s)
│   ├── api.php             ← REST API endpoint (HTTP Basic Auth; posts, pages, media, categories, tags, settings)
│   ├── xmlrpc.php          ← WordPress + MetaWeblog XML-RPC API endpoint
│   ├── login-log.php       ← Activity log + login attempts viewer
│   └── assets/
│       ├── admin.css
│       ├── admin.js
│       ├── media.js
│       ├── chart.min.js    ← Chart.js 4.4.7 (vendored)
│       ├── easymde.min.*   ← Markdown editor (vendored)
│       ├── font-awesome.min.css
│       └── fonts/          ← Font Awesome icon fonts (self-hosted)
│
├── content/                ← BLOCKED: Nginx denies all access
│   └── media/              ← Uploaded files (images, video, audio)
│
├── data/                   ← BLOCKED: Nginx denies all access
│   └── cms.db              ← SQLite database
│
├── fonts/                  ← Public: DM Sans WOFF2 files
│   └── og/                 ← OG image fonts (DMSans-Regular/Bold.ttf)
│
├── src/                    ← BLOCKED: Nginx denies all access
│   ├── ActivityLog.php     ← Admin activity logger (writes to activity_log table)
│   ├── Auth.php
│   ├── Bluesky.php         ← Bluesky AT Protocol API client
│   ├── Builder.php
│   ├── Database.php
│   ├── Feed.php            ← Atom 1.0 feed generator
│   ├── Helpers.php
│   ├── HighlightFencedCodeRenderer.php  ← league/commonmark renderer for syntax highlighting
│   ├── ImageRenderer.php   ← league/commonmark renderer: lazy load, WebP <picture>, dimensions
│   ├── JsonFeed.php        ← JSON Feed 1.1 generator
│   ├── Mastodon.php        ← Mastodon API client
│   ├── Media.php
│   ├── OgImage.php         ← GD-based OG image generator
│   ├── Page.php
│   ├── Post.php
│   ├── Webmention.php      ← Outgoing webmention discovery and sending
│   └── XmlRpc.php          ← XML-RPC request parser and response encoder
│
├── templates/              ← BLOCKED: Nginx denies all access
│   ├── 404.php             ← 404 Not Found error page
│   ├── base.php            ← Shared HTML shell
│   ├── index.php           ← Post listing / pagination
│   ├── page.php            ← Static page
│   ├── post.php            ← Single post
│   ├── search.php          ← Client-side search page
│   └── taxonomy.php        ← Category / tag archive listing
│
├── vendor/                 ← BLOCKED: Nginx denies all access
│
├── media/                  ← PUBLIC — served via Nginx alias to content/media/
│
├── posts/                  ← Generated: date-based subdirectory per post
│   └── YYYY/MM/DD/{slug}/
│       ├── index.html
│       └── og.png
│
├── pages/                  ← Generated: one subdir per static page
│   └── {slug}/
│       └── index.html      ← served at /{slug}/ via Nginx @page fallback
│
├── page/                   ← Generated: paginated index
│   └── 2/
│       └── index.html
│
├── search/                 ← Generated: client-side search page
│   └── index.html
│
├── index.html              ← Generated: post listing page 1
├── search.json             ← Generated: search index (title, excerpt, date, URL)
├── feed.xml                ← Generated: Atom 1.0 feed
├── theme.css               ← Public stylesheet
├── theme.min.css           ← Auto-generated minified CSS (not committed)
│
├── config.php              ← BLOCKED: Nginx denies access; site config + admin credentials
├── composer.json
├── composer.lock
├── Dockerfile
├── docker-compose.yml
├── favicon.svg             ← SVG favicon (blue rounded square, served publicly)
├── nginx.conf.example      ← Production Nginx template
├── track.php               ← Analytics beacon endpoint (public, POST only)
└── INSTALL.md
```

> **Note on `media/`:** Uploaded files live in `content/media/` (blocked from web). Nginx's `alias` directive maps `/media/` requests directly to `content/media/` inside the server block — no symlinks or rewrites needed.

---

## Database Schema (SQLite)

### `posts`

```sql
CREATE TABLE posts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    title        TEXT    NOT NULL,
    slug         TEXT    UNIQUE NOT NULL,
    content      TEXT    NOT NULL,          -- Markdown source
    excerpt      TEXT,                      -- Optional hand-written summary
    status       TEXT    NOT NULL DEFAULT 'draft',  -- draft | published | scheduled
    published_at DATETIME,                  -- Actual or scheduled publish time
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    built_at     DATETIME,                  -- Last time HTML was generated
    content_hash TEXT,                      -- SHA-256 of rendered HTML; change detection
    og_image_hash TEXT,                     -- Hash used to cache OG image generation
    tooted_at    DATETIME,                  -- Set when post is syndicated to Mastodon
    mastodon_url TEXT,                      -- Canonical URL of the Mastodon toot
    mastodon_skip INTEGER DEFAULT 0,        -- 1 = skip Mastodon syndication
    bluesky_at   DATETIME,                  -- Set when post is syndicated to Bluesky
    bluesky_url  TEXT,                      -- Canonical URL of the Bluesky post
    bluesky_skip INTEGER DEFAULT 0          -- 1 = skip Bluesky syndication
);
```

### `pages`

```sql
CREATE TABLE pages (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    title        TEXT    NOT NULL,
    slug         TEXT    UNIQUE NOT NULL,
    content      TEXT    NOT NULL,          -- Markdown source
    nav_order    INTEGER DEFAULT 0,         -- Position in navigation
    status       TEXT    NOT NULL DEFAULT 'draft',  -- draft | published
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    built_at     DATETIME,
    content_hash TEXT
);
```

### `media`

```sql
CREATE TABLE media (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    filename      TEXT    NOT NULL,         -- Stored filename (possibly renamed)
    original_name TEXT    NOT NULL,
    mime_type     TEXT    NOT NULL,
    size          INTEGER NOT NULL,         -- Bytes
    uploaded_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### `settings`

```sql
CREATE TABLE settings (
    key        TEXT PRIMARY KEY,
    value      TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
-- Keys include: site_title, site_description, site_url, footer_text,
--               posts_per_page, feed_post_count, locale, timezone,
--               mastodon_handle, mastodon_instance, mastodon_token,
--               bluesky_handle, bluesky_url, bluesky_app_password,
--               github_url,
--               webmention_domain,
--               ga_measurement_id,
--               tinylytics_code,
--               tinylytics_kudos_emoji
```

### `login_attempts`

```sql
CREATE TABLE login_attempts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    ip           TEXT    NOT NULL,
    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    success      INTEGER DEFAULT 0
);
CREATE INDEX login_attempts_ip_time ON login_attempts(ip, attempted_at);
```

### `activity_log` (schema v10)

```sql
CREATE TABLE activity_log (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    action      TEXT    NOT NULL,   -- create | update | publish | unpublish | schedule |
                                    -- delete | upload | settings | password | rebuild
    object_type TEXT    NOT NULL,   -- post | page | media | settings | account | site
    object_id   INTEGER,            -- DB id of the affected record (NULL for settings/account/site)
    detail      TEXT    NOT NULL DEFAULT '',  -- post title, filename, etc.
    ip          TEXT    NOT NULL DEFAULT '',
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);
-- Pruned probabilistically (~1% of requests); entries older than 90 days are deleted.
```

### `page_views` (schema v13)

```sql
CREATE TABLE page_views (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    url         TEXT    NOT NULL,
    referrer    TEXT    NOT NULL DEFAULT '',
    device_type TEXT    NOT NULL DEFAULT 'unknown',  -- desktop | mobile | tablet | unknown
    is_404      INTEGER NOT NULL DEFAULT 0,
    ip_hash     TEXT    NOT NULL DEFAULT '',          -- HMAC-SHA256 of client IP with server-side salt
    timestamp   DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX page_views_timestamp ON page_views(timestamp);
-- Populated by track.php beacon (raw PDO, no autoloader).
-- Pruned to 90 days on ~1% of admin requests in bootstrap.php.
```

---

## Configuration File (`config.php`)

```php
<?php
return [
    'admin' => [
        'username'      => 'admin',
        'password_hash' => '',   // bcrypt hash generated at setup
        'session_name'  => 'cms_session',
        'session_lifetime' => 3600,         // seconds
    ],
    'paths' => [
        'data'      => __DIR__ . '/data',
        'content'   => __DIR__ . '/content',
        'output'    => __DIR__,             // Web root is project root
        'templates' => __DIR__ . '/templates',
    ],
    'security' => [
        'max_login_attempts' => 5,
        'lockout_minutes'    => 15,
    ],
];
```

---

## Core Classes

### `Database`
Thin PDO/SQLite wrapper. Handles:
- Connection and schema creation on first run
- Prepared statement helpers (`select`, `selectOne`, `insert`, `update`, `delete`, `exec`)
- Migration runner (versioned via `schema_version` key in `settings`)

### `Auth`
- `login(username, password)` — checks credentials, enforces rate limit, issues session
- `logout()`
- `check()` — redirects to login if session is invalid
- `csrfToken()` / `verifyCsrf(token)` — per-session CSRF tokens
- `startSession()` — sets cookie params (HttpOnly, Secure, SameSite=Strict) then calls session_start()
- `flash()` / `getFlash()` — session-based PRG flash messages
- `isLockedOut(ip)` — rate-limit check used by both web and XML-RPC auth

### `Post` / `Page`
Active-record-style models:
- `findAll(status)`, `findBySlug(slug)`, `findById(id)`
- `save()`, `delete()`
- `needsRebuild()` — compares current `content_hash` to what would be rendered
- `Post::promoteScheduled(db)` — promotes due scheduled posts to published
- `Post::datePath(published_at, slug)` — returns `YYYY/MM/DD/{slug}` path segment

### `Builder`
The rebuild engine. Core methods:
- `buildPost(post)` — render one post to `posts/YYYY/MM/DD/{slug}/index.html`
- `buildPage(page)` — render one static page to `pages/{slug}/index.html`
- `buildIndex()` — render all paginated index pages + search.json
- `buildFeed()` — render Atom feed
- `buildOgImage(post)` — generate OG PNG via GD+FreeType
- `buildCss()` — write theme.min.css from theme.css
- `buildAll()` — full site rebuild
- `migrateOldPostPaths()` / `migrateOldPagePaths()` — clean up legacy output paths

### `XmlRpc`
Static XML-RPC parser and response encoder:
- `parseRequest(body)` — SimpleXML parse → `['method' => string, 'params' => array]`
- `encodeValue(value)` — PHP → XML-RPC typed value string
- `encodeResponse(value)` — wraps in `<methodResponse><params>` envelope
- `encodeFault(code, message)` — wraps in `<methodResponse><fault>` envelope
- `isoDate(datetime)` — UTC datetime → `YYYYMMDDThh:mm:ss`
- `parseDate(iso, timezone)` — MarsEdit ISO8601 → UTC `Y-m-d H:i:s`

### `Media`
- `upload(file)` — validates type/size, stores file, inserts DB record
- `delete(id)` — removes file and DB record
- `all()` — list media for the library UI
- Allowed MIME types: JPEG, PNG, GIF, WebP, SVG, MP4, WebM, MP3, OGG
- Max upload size: 50 MB; filenames: `{stem}_{8hex}.{canonical_ext}`

### `Feed`
Renders `feed.xml` (Atom 1.0) from the N most recently published posts, with optional Tinylytics pixel tracking per entry.

### `JsonFeed`
Renders `feed.json` (JSON Feed 1.1) from the N most recently published posts. Linked in `<head>` for feed reader discovery.

### `ImageRenderer`
Custom `league/commonmark` renderer for inline images. Adds:
- `loading="lazy"` and `decoding="async"` on every image
- `width`/`height` attributes (prevents CLS) for local `/media/` images
- `<picture>` + `<source type="image/webp">` wrapping when a `.webp` sibling exists for JPEG/PNG uploads

External images receive lazy/async only; no dimension enrichment or WebP wrapping.

### `Webmention`
Static utility class for outgoing webmention support:
- `extractUrls(html, siteUrl)` — extract all external HTTP(S) links from post HTML
- `discoverEndpoint(targetUrl)` — discover a webmention endpoint via HTTP `Link` header or HTML `<link rel="webmention">`
- `sendPing(source, target, endpoint)` — send a webmention POST and return success/failure

Used by `bin/send-webmentions.php`.

### `Mastodon` / `Bluesky`
API clients for social syndication:
- `Mastodon::tootPost(title, excerpt, url)` — posts a status via Mastodon API; returns `?string` (canonical toot URL on success, `null` on failure)
- `Bluesky::postToBluesky(title, excerpt, url)` — posts via Bluesky AT Protocol; returns `?string` (canonical `bsky.app` URL on success, `null` on failure)
- Returned URLs are stored on the post (`mastodon_url` / `bluesky_url`) and displayed as "Also on:" links on the public post page

### `OgImage`
Generates 1200×630 PNG Open Graph images using GD + FreeType. Caches by `og_image_hash` stored on the post; regenerates only when title or site name changes.

### `ActivityLog`
- `log(action, objectType, objectId, detail)` — inserts a row into `activity_log` with the calling IP
- Used by all admin POST handlers: post/page create, update, publish, unpublish, schedule, delete; media upload/delete; settings save; password change; manual site rebuild
- Instantiated as `$activityLog` in `admin/bootstrap.php`, available on every admin page

### `Helpers`
- `slugify(title)` — URL-safe slug generation
- `truncate(html, length)` — post excerpt fallback
- `formatDate(datetime, format, default, timezone)` — timezone-aware date formatting
- `e(string)` — `htmlspecialchars` shorthand

---

## Smart Rebuild Logic

When a post or page is **published or updated**:

```
1. Render the item's HTML from current Markdown + template
2. Hash the rendered HTML
3. Compare to stored content_hash
4. If different:
     a. Write file to disk
     b. Update content_hash and built_at in DB
5. If post listing is affected (new post, post unpublished, slug changed):
     a. Rebuild all paginated index pages + search.json
     b. Rebuild feed.xml
   Else if only content changed:
     a. Rebuild feed.xml (excerpt or title may appear there)
     b. Skip index pages (order and count unchanged)
```

**Scheduled posts:** On every admin page load, a lightweight check queries:
```sql
SELECT id FROM posts
WHERE status = 'scheduled' AND published_at <= CURRENT_TIMESTAMP
```
Any due posts are flipped to `published` and their rebuild is triggered automatically.

**Manual rebuild:** The admin dashboard has a "Rebuild entire site" button that calls `Builder::buildAll()`. Useful after theme changes.

---

## URL Structure

Posts use date-based URLs: `/YYYY/MM/DD/{slug}/` → `posts/YYYY/MM/DD/{slug}/index.html`

Pages use slug-based URLs: `/{slug}/` → `pages/{slug}/index.html` via an Nginx `@page` named-location fallback.

The Nginx config uses two rewrites for the date-URL block:
1. Asset files (og.png etc.): `rewrite "^/([0-9]{4}/[0-9]{2}/[0-9]{2}/.+\.[^/]+)$" /posts/$1 break;`
2. Slug paths: `rewrite ^/(.+?)/?$ /posts/$1/index.html break;`

---

## Security Checklist

| Area | Measure |
|---|---|
| Authentication | bcrypt password hash in config; session-based auth |
| Session | Regenerate session ID on login; `HttpOnly` + `SameSite=Strict` cookie; `Secure` flag on HTTPS |
| CSRF | Token in every admin form; verified on every POST |
| Brute-force | IP-based lockout after N failures (stored in SQLite); applies to XML-RPC auth too |
| File access | Nginx `deny all` location blocks on `src/`, `data/`, `content/`, `templates/`, `vendor/`, `config.php` |
| File upload | MIME type whitelist server-side; no executable extensions allowed; files stored outside web root in `content/media/` |
| SQL injection | PDO prepared statements throughout |
| XSS | All admin output passed through `htmlspecialchars()`; league/commonmark default escaping |
| Path traversal | Media filenames sanitised with `basename()` before any filesystem operation |
| Error display | `display_errors = Off` in production; errors logged to file |
| CSP | Separate Content-Security-Policy for admin (allows `unsafe-inline`) and public pages (strict) |

---

## Development Phases

### Phase 1 — Foundation ✓
- [x] Composer setup, directory scaffold, `config.php`
- [x] `Database` class + schema creation + migration runner
- [x] `Auth` class (login, session check, CSRF helpers)
- [x] Admin login page + session guard middleware
- [x] Nginx server block with security location blocks

### Phase 2 — Content Management ✓
- [x] `Post` and `Page` models
- [x] `Helpers::slugify()`, `Helpers::formatDate()`
- [x] Admin: posts list, post editor (save draft, delete)
- [x] Admin: pages list, page editor
- [x] EasyMDE integration in editor

### Phase 3 — Media ✓
- [x] `Media` class (upload, validate, delete, list)
- [x] Admin: media library UI
- [x] Media insert helper in post/page editor sidebar
- [x] `content/media/` → public `media/` routing via Nginx `alias`

### Phase 4 — Static Build System ✓
- [x] `Builder` class: render post, page, index, feed
- [x] PHP templates: `base.php`, `post.php`, `page.php`, `index.php`
- [x] Content-hash diffing (skip unchanged files)
- [x] Pagination logic in index build
- [x] Build triggered on publish/unpublish/settings save
- [x] `Feed` class (Atom 1.0 XML)

### Phase 5 — Publish Controls & Scheduling ✓
- [x] Publish now, save draft, schedule (date picker), unpublish
- [x] Scheduled post check on admin page load
- [x] Status badges and filter tabs on posts list

### Phase 6 — Theme ✓
- [x] `base.php` layout with header, nav, footer
- [x] Single post template with Open Graph meta
- [x] Static page template
- [x] Index/listing template with pagination
- [x] Responsive CSS (single column; DM Sans throughout)

### Phase 7 — Admin Polish & Settings ✓
- [x] Settings screen + DB-backed site config
- [x] Dashboard with stats + "Rebuild site" button
- [x] Login rate limiting using `login_attempts` table
- [x] Flash messages (PRG pattern)
- [x] Setup script to hash initial admin password

### Phase 8 — Hardening & Deployment ✓
- [x] INSTALL.md (VPS guide, Nginx, PHP-FPM, Let's Encrypt, UFW, backups)
- [x] `nginx.conf.example` with CSP headers, TLS placeholders
- [x] Dockerfile + docker-compose.yml for local dev
- [x] Filesystem permissions documented

### Phase 9 — Account Management ✓
- [x] `admin/account.php` — change password (verifies current, 12-char minimum)

---

## Post-v1 Additions

Features added after the initial build phases:

| Feature | Files |
|---|---|
| Date-based post URLs (`/YYYY/MM/DD/{slug}/`) | `src/Post.php`, `src/Builder.php`, `nginx.conf.example` |
| OG image generation | `src/OgImage.php`, `src/Builder.php`, `fonts/og/` |
| Syntax highlighting | `src/HighlightFencedCodeRenderer.php`, `theme.css` |
| Lazy image loading, WebP `<picture>`, CLS-safe dimensions | `src/ImageRenderer.php`, `src/Builder.php` |
| Categories & Tags taxonomy | `src/Post.php`, `src/Builder.php`, `src/Database.php` (v9), `templates/taxonomy.php`, `admin/categories.php`, `admin/tags.php`, `admin/post-edit.php`, `admin/xmlrpc.php` |
| TOTP two-factor authentication | `src/Auth.php`, `src/Database.php` (v11), `admin/index.php`, `admin/account.php` |
| Mastodon auto-syndication | `src/Mastodon.php`, `admin/post-edit.php`, `admin/settings.php` |
| Bluesky auto-syndication | `src/Bluesky.php`, `admin/post-edit.php`, `admin/settings.php` |
| Syndication URL storage + display | `src/Mastodon.php`, `src/Bluesky.php`, `src/Post.php`, `src/Database.php` (v7), `templates/post.php`, `theme.css` |
| WordPress XML-RPC API | `src/XmlRpc.php`, `admin/xmlrpc.php` |
| REST API (HTTP Basic Auth) | `admin/api.php` |
| JSON Feed 1.1 | `src/JsonFeed.php`, `src/Builder.php`, `templates/base.php` |
| Outgoing webmentions (CLI) | `src/Webmention.php`, `bin/send-webmentions.php` |
| Client-side search | `src/Builder.php` (search.json), `templates/search.php` |
| Admin post search | `admin/posts.php` |
| Admin posts pagination | `admin/posts.php` |
| Shortcode embeds (YouTube, Vimeo, Gist, Mastodon, Instagram, X, LinkedIn) | `src/Builder.php`, `admin/assets/admin.js`, `theme.css` |
| Image galleries with lightbox | `src/Builder.php`, `templates/post.php`, `theme.css`, `admin/assets/media.js` |
| Custom CSS (Settings) | `admin/settings.php`, `templates/base.php` |
| Collapsible admin sidebar | `admin/assets/admin.css`, `admin/assets/admin.js`, `admin/partials/nav.php` |
| Tinylytics analytics + Kudos button | `templates/base.php`, `templates/post.php`, `src/Feed.php`, `admin/settings.php`, `theme.css` |
| Google Analytics (GA4) | `templates/base.php`, `admin/settings.php` |
| Webmention.io (incoming, client-side display) | `templates/base.php`, `templates/post.php`, `admin/settings.php`, `theme.css` |
| Microformats2 (h-entry) | `templates/post.php`, `templates/index.php` |
| JSON-LD structured data (BlogPosting) | `templates/post.php` |
| Reading time estimate | `templates/post.php` |
| Favicon | `favicon.svg`, `templates/base.php` |
| Lightbox for inline post images | `theme.css`, `templates/base.php` |
| Dark / light mode toggle | `theme.css`, `templates/base.php` |
| 404 Not Found page template | `templates/404.php` |
| Probabilistic DB cleanup | `admin/bootstrap.php` |
| Self-hosted Font Awesome (admin) | `admin/assets/font-awesome.min.css`, `admin/assets/fonts/` |
| DM Sans public typeface | `fonts/`, `templates/base.php`, `theme.css` |
| CSP + security headers | `nginx.conf.example`, `docker/nginx.conf` |
| Pages at `/pages/{slug}/` via Nginx | `src/Builder.php`, `nginx.conf.example` |
| `theme.min.css` auto-generation | `src/Builder.php`, `admin/bootstrap.php` |
| Activity logging | `src/ActivityLog.php`, `src/Database.php` (v10), `admin/bootstrap.php`, `admin/post-edit.php`, `admin/page-edit.php`, `admin/media.php`, `admin/settings.php`, `admin/account.php`, `admin/dashboard.php` |
| Logs admin page (activity + login attempts) | `admin/login-log.php` |
| Built-in analytics beacon | `track.php`, `src/Database.php` (v13), `templates/base.php`, `templates/404.php`, `docker/nginx.conf`, `nginx.conf.example` |
| Analytics dashboard (views/day, top pages, devices, referrers, 404s) | `admin/analytics.php`, `admin/assets/chart.min.js`, `admin/bootstrap.php` |

---

## Dependencies (`composer.json`)

```json
{
    "require": {
        "php": ">=8.1",
        "league/commonmark": "^2.4",
        "scrivo/highlight.php": "^9.18",
        "spomky-labs/otphp": "^11.0",
        "bacon/bacon-qr-code": "^2.0"
    }
}
```

EasyMDE and Font Awesome are vendored as static assets in `admin/assets/` (no npm build step).

`spomky-labs/otphp` provides RFC 6238 TOTP support for 2FA. `bacon/bacon-qr-code` generates the QR code shown during 2FA setup.

---

## Server Requirements

### Minimum Server Spec

| Resource | Minimum | Recommended |
|---|---|---|
| CPU | 1 vCPU | 1 vCPU |
| RAM | 512 MB | 1 GB |
| Disk | 10 GB SSD | 25 GB SSD |
| OS | Ubuntu 22.04 LTS | **Ubuntu 24.04 LTS** |

### PHP Extensions Required

| Extension | Package | Purpose |
|---|---|---|
| `pdo_sqlite` | `php8.3-sqlite3` | SQLite database |
| `fileinfo` | `php8.3-fileinfo` | Upload MIME validation |
| `mbstring` | `php8.3-mbstring` | Required by league/commonmark |
| `simplexml` | `php8.3-xml` | XML-RPC API request parsing |
| `gd` | `php8.3-gd` | OG image generation (requires FreeType) |
| `curl` | `php8.3-curl` | Mastodon & Bluesky API calls |
| `intl` | `php8.3-intl` | Locale-aware string handling |
| `session` | built-in | Admin sessions |
| `hash` | built-in | Content-hash diffing |
| `json` | built-in | Config/settings |
| `openssl` | built-in | Session security |

---

## Out of Scope (v1) — Status

| Item | Status |
|---|---|
| Multi-user accounts and roles | Out of scope |
| Categories, tags, or any taxonomy | **Implemented** (post-v1) |
| Comments | Out of scope |
| Search | **Implemented** (client-side, post-v1) |
| Image resizing / thumbnail generation | Out of scope |
| CDN integration | Out of scope |
| Two-factor authentication (TOTP) | **Implemented** (post-v1) |
| Git-based content versioning | Out of scope |
| Social syndication (Mastodon, Bluesky) | **Implemented** (post-v1) |
| Remote publishing API (XML-RPC) | **Implemented** (post-v1) |
| REST API (HTTP Basic Auth) | **Implemented** (post-v1) |
| Outgoing webmentions | **Implemented** (post-v1) |
