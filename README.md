# Clodd CMS

![Clodd CMS](docs/images/logo.png)

A lightweight flat-file CMS with a PHP/SQLite admin panel and a fully static HTML output layer. Write posts and pages in Markdown, publish them, and the CMS generates clean static HTML that Nginx serves directly — no PHP in the request path for public visitors.

---

## Features

- **Static output** — generates plain HTML files; public pages need no PHP at serve time
- **Markdown editor** — EasyMDE with GitHub-flavored Markdown, footnotes, and server-side syntax highlighting (xcode-dark palette)
- **Posts & pages** — separate content types; pages can appear in site navigation, with optional one-level sub-pages that render as a desktop dropdown and indented mobile menu items
- **Aside notes** — titleless short-form posts (`post_kind = aside`) for IndieWeb-style notes; render the full Markdown body in place of title/excerpt on listings, drop the title and term pills on single pages, and emit a proper h-entry without `p-name`. URLs slug from the note's opening words (`/YYYY/MM/DD/kettle-boiled-over-again/`); a note with no body to slug from falls back to the post id
- **Date-based post URLs** — posts live at `/YYYY/MM/DD/{slug}/` for clean, chronological permalinks
- **Draft preview** — preview any saved draft (or published post) in the full public theme by clicking **Preview** in the post editor sidebar; opens in a new tab without publishing or triggering a site rebuild
- **Scheduling** — set a future publish date; posts promote automatically on next admin load
- **Categories & tags** — full taxonomy system; posts can belong to multiple categories and tags; archive pages generated at `/category/{slug}/` and `/tag/{slug}/`; tag input is a pill-style picker with autocomplete against existing tags
- **Media library** — drag-and-drop uploads with MIME validation; images, video, and audio supported (50 MB limit)
- **Image galleries** — select multiple images in the post editor and insert a `[gallery]` shortcode; renders as a Mondrian-style tiled CSS Grid (curated layouts per image count 1–7; larger sets chunk into stacked sibling blocks that visually merge into one continuous tile field) with a looping lightbox
- **Atom feed** — generated automatically at `/feed.xml`; embeds [Byline](https://bylinespec.org/1.0) elements so feed readers can show your name, bio, avatar, and verified social links
- **RSS feed** — `/feed.rss` (RSS 2.0) with the same Byline embedding; mirrored to per-term taxonomy archives
- **JSON Feed** — generated automatically at `/feed.json` (JSON Feed 1.1); linked in `<head>` for feed reader discovery
- **OG images** — auto-generated 1200×630 PNG per post (requires GD + FreeType)
- **JSON-LD structured data** — `BlogPosting` schema.org markup in every post's `<head>` for richer search results; author name configurable in Settings
- **Reading time** — estimated minutes-to-read displayed inline with the post date
- **Microformats2 (h-entry)** — posts and index items carry MF2 classes for IndieWeb parsers and readers
- **Mastodon, Bluesky & Pixelfed** — optional auto-post on first publish (Pixelfed takes photo posts only), with the copies kept in step afterwards: editing the post rewrites them, deleting or unpublishing it takes them down; the URL of the remote post is stored and displayed as an "Also on:" link at the bottom of each post; per-post skip checkbox for each platform. Aside notes use [POSSE](https://indieweb.org/POSSE) — they syndicate as native-looking notes (full plaintext body, no title, no link back), with a live Bluesky-grapheme/Mastodon-character counter and over-limit warning under the editor
- **Incoming webmentions** — display likes, reposts, and replies on posts via webmention.io; client-side fetch with avatar grid for reactions and threaded reply cards
- **Outgoing webmentions** — CLI script (`bin/send-webmentions.php`) discovers endpoints and sends pings for all external links in published posts; safe to schedule via cron
- **WordPress XML export** — download all posts (with categories, tags, and optional drafts) as a WXR file importable via WordPress Tools → Import
- **WordPress XML import** — upload a WXR file (e.g. a Micro.blog export) to bulk-create posts; titleless items can land as `aside` notes; categories and tags are auto-created; re-imports are deduped by `<guid>`; syndication is suppressed for the entire backlog so importing years of posts won't flood Mastodon or Bluesky
- **Media re-hosting** — scan every post for external `<img>` URLs, download each into the local media library, and rewrite the post HTML to point at `/media/` paths; idempotent (re-runs skip what's already local); also available as a checkbox during WXR import
- **MarsEdit support** — full WordPress XML-RPC API at `/admin/xmlrpc.php`; write and publish from MarsEdit with post and page management
- **Micropub / iA Writer support** — W3C Micropub endpoint at `/micropub.php`; bearer-token auth, JSON / form / multipart payloads, inline photo uploads, automatic endpoint discovery via `<link rel="micropub">`. Works with iA Writer, Quill, Drafts, and other Micropub clients
- **Google Analytics** — optional GA4 integration; add a measurement ID in Settings to inject the tracking script
- **Built-in analytics** — first-party page-view tracking via `navigator.sendBeacon`; no cookies or third-party services; IP addresses stored as HMAC-SHA256 hashes; data retained 90 days; dashboard shows views/day, top pages, device breakdown, referrers, and 404 errors; visit `/?ti=exclude` to opt out your own browser
- **Custom CSS** — paste override styles directly in Settings; injected as a `<style>` block on every public page after the main stylesheet
- **Dark / light mode** — system-preference aware with manual toggle; no flash on load
- **Search** — client-side full-text search of posts at `/search/`; no server-side PHP required
- **404 page** — themed Not Found page that queries the [Wayback Machine](https://archive.org/web/) availability API for the requested URL and reveals a link to the closest archived snapshot when one exists
- **Favicon** — SVG favicon matching the site theme color
- **Collapsible admin sidebar** — sidebar collapses to icon-only mode to maximize editor space; preference stored in localStorage
- **Activity log** — every content and settings change is recorded with action, object, and IP; viewable in Admin → Logs
- **Two-factor authentication** — optional TOTP 2FA (Google Authenticator, Authy, 1Password, etc.); setup via Admin → Account; backup codes generated on enable
- **Single admin user** — bcrypt password, CSRF protection, IP-based rate limiting; password and 2FA managed from within the admin panel
- **Docker-ready** — one command to run locally

---

## Requirements

| Component | Version |
|-----------|---------|
| PHP | 8.1+ (8.3 recommended) |
| Extensions | `pdo_sqlite`, `mbstring`, `simplexml`, `gd` (with FreeType for OG images) |
| Nginx | 1.18+ |
| Composer | 2.x |
| SQLite | 3.x (bundled with PHP) |

> **Note:** `simplexml` is in the `php8.3-xml` package on Ubuntu/Debian — it is not automatically installed with `php8.3-fpm`. See [INSTALL.md](INSTALL.md).

---

## Quick Start (Docker)

```bash
git clone https://github.com/jimmitchell/clodd-cms.git
cd clodd-cms

# Start PHP-FPM + Nginx
docker compose up --build

# In a second terminal: create your admin password and initialize the DB
docker compose exec php php bin/setup.php
```

Visit **http://localhost:8080/admin/** and log in.

The setup script prompts for a username and password, writes both to `config.php`, and seeds the SQLite database. Generated HTML, uploaded media, and the database are written to the project directory (not inside the container), so they persist across restarts.

---

## Production Deployment

See [INSTALL.md](INSTALL.md) for the full VPS guide (Ubuntu 22.04, Nginx, PHP-FPM, Let's Encrypt, UFW, and daily SQLite backups). The short version:

```bash
# Clone and install dependencies
git clone https://github.com/jimmitchell/clodd-cms.git /var/www/cms
cd /var/www/cms
composer install --no-dev --optimize-autoloader

# Initialize (prompts for password, seeds DB)
php bin/setup.php

# Permissions
chown -R deploy:www-data .
chmod -R 775 data/ content/media/

# Nginx
cp nginx.conf.example /etc/nginx/sites-available/cms
# Edit domain and PHP-FPM socket path, then:
ln -s /etc/nginx/sites-available/cms /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx

# TLS (optional but recommended)
certbot --nginx -d example.com
```

---

## Configuration

`config.php` lives at the project root and is blocked by Nginx. It is **not** committed to version control. It is created (or updated) by `bin/setup.php`.

```php
return [
    'admin' => [
        'username'         => 'admin',
        'password_hash'    => '$2y$12$...',  // set by bin/setup.php
        'session_name'     => 'cms_session',
        'session_lifetime' => 3600,
    ],
    'paths' => [
        'data'      => __DIR__ . '/data',
        'content'   => __DIR__ . '/content',
        'output'    => __DIR__,              // web root
        'templates' => __DIR__ . '/templates',
    ],
    'security' => [
        'max_login_attempts' => 5,
        'lockout_minutes'    => 15,
    ],
];
```

Runtime settings are stored in the SQLite `settings` table and edited through **Admin → Settings**. The Settings page is organized into panels:

| Panel | Settings |
|-------|----------|
| Site identity | Title, author name, author bio, author avatar URL, description, URL, footer text, timezone, locale |
| Content | Posts per page, feed post count |
| Mastodon | Handle, instance URL, access token |
| Bluesky | Profile URL, handle, app password |
| Pixelfed | Profile URL, instance URL, access token |
| Email reply | Reply-to email address for post footer pill |
| IndieWeb | webmention.io domain |
| Analytics | Tinylytics site ID, Tinylytics Kudos emoji, Google Analytics measurement ID |
| Custom CSS | Freeform CSS injected into every public page |

---

## Admin Panel

| Page | Path | Description |
|------|------|-------------|
| Login | `/admin/` | Two-step login: password then TOTP code (if 2FA is enabled) |
| Dashboard | `/admin/dashboard.php` | Stats, scheduled posts due soon, full site rebuild |
| Posts | `/admin/posts.php` | List with status filter tabs, title search, inline delete; aside posts get an `Aside` kind badge and titleless posts show a truncated content stand-in where the title would go |
| Post editor | `/admin/post-edit.php` | Title, slug (with real-time uniqueness check), Markdown editor, status, schedule date, categories, tags, image gallery insert; keyboard shortcuts: **Ctrl/Cmd+S** save, **Ctrl/Cmd+Shift+P** publish; **Preview** button opens a read-only themed preview in a new tab without publishing |
| Pages | `/admin/pages.php` | List with inline delete |
| Page editor | `/admin/page-edit.php` | Same as post editor + nav order field; slug field also has real-time uniqueness check |
| Categories | `/admin/categories.php` | Create, edit, and delete post categories |
| Tags | `/admin/tags.php` | Create, edit, bulk-add, and delete post tags |
| Media | `/admin/media.php` | Upload (drag-and-drop), library, copy URL to clipboard |
| Tools | `/admin/tools.php` | Tabbed page consolidating data movement: **Import** (WordPress XML / WXR upload — titleless items can land as asides; categories/tags auto-created; syndication suppressed; re-imports deduped by `<guid>`), **Import media** (scan every post for external `<img>` URLs, download to local media library, rewrite post HTML to `/media/` paths; idempotent), **Export** (download all posts and pages as WXR) |
| Settings | `/admin/settings.php` | Tabbed page: **General** (site identity, content options, social/analytics credentials, custom CSS), **Micropub** (generate / revoke the bearer token for the public Micropub endpoint at `/micropub.php`), **Account** (change admin password; set up, manage, or disable TOTP 2FA; manage passkeys), **Logs** (login attempt history and admin activity log) |
| Analytics | `/admin/analytics.php` | Views/day, top pages, devices, referrers, 404 errors; 7/30/90-day range |
| Post preview | `/admin/post-preview.php?id={id}` | Renders any saved post through the full public theme without publishing; auth-gated, search-engine blocked |
| XML-RPC API | `/admin/xmlrpc.php` | WordPress-compatible API for MarsEdit and similar clients |
| REST API | `/admin/api/{resource}` | HTTP Basic Auth REST API for posts, pages, media, categories, tags, and settings |

### Security

- CSRF token on every form POST
- Passwords hashed with bcrypt cost 12
- IP-based and username-based login rate limiting: 5 attempts → 15-minute lockout (applies to TOTP verification and XML-RPC auth too)
- Sessions: `HttpOnly`, `Secure`, `SameSite=Strict`
- Optional TOTP two-factor authentication (RFC 6238); pending state expires after 5 minutes; backup codes generated on setup; rate-limited independently from the password step
- Nginx blocks direct access to `src/`, `templates/`, `data/`, `content/`, `vendor/`, `bin/`, and `config.php`
- Separate Content-Security-Policy headers for admin (allows `unsafe-inline` for EasyMDE) and public pages (strict)

---

## Content Management

### Posts

Posts have a **status** of `draft`, `published`, or `scheduled`. Saving a post as `published` immediately triggers a static build for that post. Scheduling sets a future `published_at` date; scheduled posts are promoted to `published` automatically the next time any admin page loads.

Each published post generates:
- `posts/YYYY/MM/DD/{slug}/index.html` — the post page, served at `/YYYY/MM/DD/{slug}/`
- `posts/YYYY/MM/DD/{slug}/og.png` — Open Graph image (if GD is available)

The paginated index (`index.html`, `page/2/index.html`, …) and all three feeds (`feed.xml`, `feed.rss`, `feed.json`) are rebuilt on publish and when settings change.

### Aside notes

Asides are titleless short-form posts intended for IndieWeb-style notes. In the post editor, set **Post kind** to *Aside*; the title field becomes optional, the slug is hidden (it's auto-assigned from the post id on save), and a live character counter appears under the content showing both Bluesky-grapheme and Mastodon-character counts.

On the public site, asides:

- Use the URL `/YYYY/MM/DD/{id}/` (numeric id, not a slug)
- Render the full Markdown body in place of title + excerpt on listings, the home page, taxonomy archives, and search results — sharing the same `.post-card` frame as standard posts
- Drop the title, byline, reading time, and term pills on their single page; keep the date and syndication links; emit a proper IndieWeb `h-entry` without `p-name`
- Skip OG image generation (no headline to render)
- Are emitted with an empty `<title/>` in Atom and the title element omitted entirely in RSS and JSON Feed
- Syndicate to Mastodon and Bluesky as native-looking notes — see [POSSE for asides](#posse-for-asides) below

### Pages

Pages work the same as posts but without scheduling. The **nav order** field controls whether a page appears in the site header navigation and in what order (`0` = hidden from nav and sorted to the bottom of the pages list).

Published pages are output to `pages/{slug}/index.html` and served at `/{slug}/` via an Nginx named location fallback.

### Categories & Tags

Posts can be assigned to any number of **categories** and **tags** from the post editor. Categories are a hierarchical taxonomy; tags are flat.

Manage them in **Admin → Categories** and **Admin → Tags** (the Tags page has a bulk-add textarea for quickly creating multiple tags at once). When a post is published, the CMS rebuilds archive pages for every category and tag the post belongs to:

- `/category/{slug}/` → `category/{slug}/index.html`
- `/tag/{slug}/` → `tag/{slug}/index.html`

Categories and tags are displayed as styled pills in the post header on public post pages.

### Media

Files are uploaded to `content/media/` and served through a Nginx alias at `/media/`. Filenames are sanitized to `{stem}_{8hex}.{canonical_ext}`. Accepted MIME types: JPEG, PNG, GIF, WebP, SVG, MP4, WebM, MP3, OGG. Maximum size: 50 MB.

### Shortcode Embeds

The post editor toolbar includes embed buttons (YouTube, Vimeo, GitHub Gist, Mastodon, Instagram, X/Twitter, LinkedIn) that insert the appropriate shortcode at the cursor. You can also type shortcodes directly. Shortcodes must appear alone on their own line.

| Shortcode | Description |
|-----------|-------------|
| `[youtube id="dQw4w9WgXcQ"]` | YouTube video (privacy-enhanced via youtube-nocookie.com) |
| `[vimeo id="123456789"]` | Vimeo video (privacy-friendly with `dnt=1`) |
| `[gist url="https://gist.github.com/user/abc123"]` | GitHub Gist (optional: `file="foo.php"`) |
| `[mastodon url="https://mastodon.social/@user/123456789"]` | Mastodon post |
| `[instagram url="https://www.instagram.com/p/ABC123/"]` | Instagram post |
| `[tweet url="https://x.com/user/status/123456789"]` | X / Twitter post (also accepts `twitter.com` URLs) |
| `[linkedin urn="urn:li:share:1234567890"]` | LinkedIn post — get the URN from LinkedIn's "Embed this post" option |

**Notes:**
- YouTube and Vimeo render as responsive 16:9 iframes with no cookies (YouTube nocookie, Vimeo dnt=1).
- GitHub Gist uses the static `.pibb` render — no external JavaScript is loaded into your page.
- Mastodon uses each instance's native `/embed` URL — no external JavaScript needed.
- Instagram and X/Twitter inject their respective embed scripts (`embed.js`, `widgets.js`). If a page has multiple embeds of the same type, the script is deduplicated automatically. Both degrade gracefully to a plain link if the script fails to load.
- LinkedIn embeds require the post's URN, which you can find in the `<iframe src>` shown by LinkedIn's "Embed this post" feature.
- Shortcodes work in both posts and pages.

### Image Galleries

You can insert a tiled image gallery into any post directly from the post editor:

1. Open a post in **Admin → Post editor**
2. In the **Insert media** sidebar panel, click **Select for gallery**
3. Click two or more images to select them (selected images show a blue outline)
4. Click **Insert gallery (N images)** — a `[gallery ids="…"]` shortcode is inserted at the cursor position
5. Save or publish the post; the gallery renders automatically

The shortcode format is:

```
[gallery ids="8,5,1,6"]
```

IDs correspond to media library records and are stored in the order you selected them, which controls the display order.

**Display:** Mondrian-style tiled layout using CSS Grid. Each image count from 1 to 7 has its own curated grid template; images are cropped to fit cells via `object-fit: cover`. Galleries of 8 or more images are chunked into stacked sibling blocks (e.g. 11 → 6+5) that visually merge into one continuous tile field thanks to identical 6 px gaps between siblings. No JavaScript layout step — the grid is fully server-rendered.

**Lightbox:** clicking any gallery image opens a full-screen lightbox. Prev/Next buttons and ← → arrow keys navigate through the gallery with looping (wraps from last image back to first and vice versa). Click the backdrop or press Escape to close. The lightbox JavaScript is injected inline only on post pages that contain a gallery — pages without a gallery load no extra script.

---

## Markdown

The Markdown renderer uses [league/commonmark](https://commonmark.thephpleague.com/) with:

- CommonMark + GitHub-flavored Markdown (tables, strikethrough, task lists)
- Footnotes
- Server-side syntax highlighting via [scrivo/highlight.php](https://github.com/scrivo/highlight.php) using the **xcode-dark** color palette
- Raw HTML pass-through (trusted admin only — allows embedding `<video>` and `<audio>`)

Fenced code blocks with a language tag are highlighted automatically:

````markdown
```php
echo "Hello, world!";
```
````

Code blocks without a language tag receive auto-detection and fall back to plain output if detection fails.

---

## Two-Factor Authentication

TOTP-based 2FA can be enabled per account from **Admin → Account**.

**Setup:**
1. Click **Set up two-factor authentication**
2. Scan the QR code with your authenticator app (Google Authenticator, Authy, 1Password, etc.) or enter the manual key
3. Enter the 6-digit verification code to confirm
4. Save the 8 one-time backup codes that are displayed — they will not be shown again

**Logging in with 2FA enabled:**
1. Enter your username and password as usual
2. Enter the 6-digit code from your authenticator app (or a backup code)

**Managing 2FA:**
- Regenerate backup codes at any time from the Account page (requires password confirmation)
- Disable 2FA from the Account page (requires password confirmation)

TOTP verification attempts are rate-limited separately from the password step using the same thresholds (5 failures → 15-minute lockout per IP).

---

## Search

The CMS generates a `/search.json` file alongside every index rebuild. The search page at `/search/` fetches this file client-side and filters posts by title and excerpt — no server-side PHP or external service required.

A magnifying-glass icon in the site header links to the search page. Results display as post cards with title, date, and excerpt.

---

## Feeds

Three feed formats are generated and rebuilt whenever a post is published/unpublished or settings are saved:

| Path | Format | Notes |
|------|--------|-------|
| `/feed.xml` | Atom 1.0 | Linked from `<head>` for reader discovery; embeds Byline elements |
| `/feed.rss` | RSS 2.0 | Embeds Byline elements; mirrored to each `/category/{slug}/feed.rss` and `/tag/{slug}/feed.rss` |
| `/feed.json` | JSON Feed 1.1 | Linked from `<head>` |

All three include the most recent N posts (configurable in **Settings → Content**, default 20). Asides are emitted with empty/omitted titles so feed readers render them as bare-content notes rather than headlined articles.

### Byline

Both the Atom and RSS feeds embed [Byline 1.0](https://bylinespec.org/1.0) elements at channel and item level, so Byline-aware readers can render your name, bio, avatar, and verified Mastodon / Bluesky / GitHub links from any subscription. The values are sourced from `author_name`, `author_bio`, `author_avatar_url`, and the existing Mastodon/Bluesky/GitHub settings.

---

## Open Graph Images

When PHP's GD extension is compiled with FreeType support, the CMS generates a 1200×630 PNG for each published post. The image includes the post title and site name rendered in DM Sans. Images are cached by a hash of the title + site name; they regenerate only when either changes.

The font files at `fonts/og/` must be present. The Docker image includes FreeType.

---

## Mastodon, Bluesky & Pixelfed Integration

### Mastodon

Set your handle (`@user@instance.social`), instance URL, and an API access token in **Settings → Mastodon**. The token needs the `write:statuses` and `write:media` scopes — without the second, a post carrying photos is tooted with its words and no pictures, since uploading an attachment is a separate permission. `read:statuses` is optional and only affects editing; see [keeping the copies in step](#keeping-the-copies-in-step). Changing the scopes on an application you already made means regenerating its token. When both fields are saved, new posts are automatically tooted on first publish. Individual posts have a **Skip Mastodon** checkbox to suppress tooting.

The handle also adds a `fediverse:creator` meta tag to every page and renders a Mastodon icon link in the footer.

### Bluesky

Set your Bluesky handle and an app password in **Settings → Bluesky**. New posts are automatically cross-posted on first publish. Individual posts have a **Skip Bluesky** checkbox.

### Pixelfed

Set the instance URL and an access token in **Settings → Pixelfed**. Run `php bin/pixelfed-token.php` to get a token — it registers the application, sends you to the browser to approve it, and trades the code you get back. The token needs the `write` scope, which on Pixelfed covers both the caption and the pictures. (Pixelfed's own personal-access-token screen has a history of issuing tokens with the wrong scopes; approving an application is also known to fail on accounts with 2FA enabled.)

**Pixelfed only receives photo posts** — posts with **Post kind** set to *Photo*. Articles, asides, and replies never go there: it is a photo account, and the Pixelfed API rejects a status with no picture on it. A photo post whose images are not in the media library has nothing to attach and is skipped too. The **Post to Pixelfed on publish** checkbox appears in the editor only when the post kind is *Photo*.

Pixelfed speaks the Mastodon API, so the copy is created, edited and deleted exactly as a toot is — with one limit worth knowing: **a Pixelfed post can be edited ten times, ever**. Because Pixelfed does not serve the endpoint that returns a status's original text, the caption is read back out of the rendered post to compare against; a save that only changes line breaks is treated as no change, and does not spend an edit.

All three platforms are independent — enable any, all, or none.

### Keeping the copies in step

A syndicated copy follows the post for the rest of its life:

- **Editing a published post** rewrites the copies to match. Mastodon is edited through its own edit endpoint; Bluesky has no edit operation, so the record is rewritten in place at the same key, which keeps the `bsky.app` URL and the likes and replies on it. A save that leaves the syndicated words unchanged sends nothing, so fixing a category or a tag does not mark the toot as edited.
- **Deleting a post** deletes the copies — from the posts list, the post editor, `DELETE /admin/api/posts/{id}`, or a Micropub `action=delete`. A Micropub undelete restores the post here but cannot bring the copies back; the post returns unsyndicated.
- **Unpublishing a post** deletes the copies too, so nothing is left pointing readers at a page that has stopped existing. Publishing it again syndicates afresh if the checkboxes are ticked.

Photos are only re-uploaded when their number has changed, so editing the words of a photo post leaves the pictures alone. Changing which photos a post carries without changing how many — swapping one for another, or reordering them — is not detected, and the copies keep the pictures they were published with.

Editing and deleting need no scope beyond the `write:statuses` and `write:media` the Mastodon token already has, and Bluesky uses the same app password. Adding `read:statuses` is worth it though: it lets the toot be read back before an edit is sent, so a save that changed nothing a reader can see stops marking the toot as edited. Without it every save of a published post sends its edit and the instance decides what to do with it.

Copies made before this existed are still reachable: the ids are recovered from the stored URLs on upgrade. The **Toot URL**, **Bluesky post URL** and **Pixelfed post URL** fields in the post editor are what an edit or delete follows, so pointing one at a different status re-points both.

### GitHub

Set your GitHub profile URL in **Settings → GitHub** (e.g. `https://github.com/username`). When set, a GitHub icon link appears in the site footer between the Bluesky icon and the RSS icon, with `rel="me noopener"` for IndieAuth compatibility.

When a post is syndicated, the URL of the Mastodon toot, Bluesky post or Pixelfed post is stored and displayed at the bottom of the public post page. Pills appear in this order: **Mastodon**, **Bluesky**, **Pixelfed**, **Email**, then the Tinylytics Kudo button.

### POSSE for asides

Aside notes (`post_kind = aside`) follow the [POSSE](https://indieweb.org/POSSE) pattern: they syndicate as native-looking notes — full plaintext body, no title, no link back to your site. The post editor shows a live counter under the content field with both Bluesky grapheme and Mastodon character counts and a warning when either limit is exceeded. The same behavior applies whether the aside is published from the admin UI, MarsEdit (XML-RPC), or a Micropub client.

### Email Reply

Set a reply-to address in **Settings → Email Reply**. When set, an **Email** pill appears at the bottom of every post. Clicking it opens the reader's mail client with the `To:` address pre-filled and the subject set to `Re: [post title]`. Leave the field blank to hide the pill entirely.

---

## Webmentions

### Incoming (webmention.io)

The CMS supports [webmention.io](https://webmention.io/) for receiving and displaying incoming webmentions (IndieWeb interactions from other sites). To enable:

1. Sign in to webmention.io with your site URL
2. Enter your domain (e.g. `example.com`) in **Admin → Settings → IndieWeb**

The CMS will:
- Add `<link rel="webmention">` and `<link rel="pingback">` tags to every page `<head>` so other sites can send webmentions to you
- Fetch and render incoming webmentions client-side on each post page

Webmentions are grouped by type:
- **Likes and reposts** — displayed as a compact avatar grid with reaction counts
- **Replies and mentions** — displayed as individual reply cards with author, date, and content

### Outgoing

The CMS can send webmention pings to every external URL linked from your published posts. This runs as a CLI script (not in the web request) to avoid timeouts:

```bash
php bin/send-webmentions.php           # send for posts updated since last run
php bin/send-webmentions.php --force   # re-send for all published posts
```

Add to cron for daily sending:

```
0 2 * * * /usr/bin/php /var/www/cms/bin/send-webmentions.php >> /var/www/cms/storage/webmentions.log 2>&1
```

The script is idempotent — it skips posts whose content has not changed since webmentions were last sent.

---

## MarsEdit Integration

The CMS exposes a WordPress-compatible XML-RPC API at `/admin/xmlrpc.php`. In MarsEdit:

1. **Add Blog** → choose **WordPress**
2. **Endpoint URL:** `https://example.com/admin/xmlrpc.php`
3. **Username / Password:** your admin credentials

MarsEdit will show both a **Posts** and a **Pages** section. All post and page CRUD operations, media uploads, and the media library work from MarsEdit. The endpoint also supports the MetaWeblog API (for clients that prefer it) at the same URL.

---

## Micropub / iA Writer

The CMS exposes a [W3C Micropub](https://www.w3.org/TR/micropub/) endpoint at `/micropub.php` for publishing from iA Writer and other Micropub clients (Quill, Drafts, etc.). Auth is a single long-lived bearer token issued from the admin panel.

### Setup

1. **Generate a token** — open **Settings → Micropub** and click **Generate token**. Copy the token immediately (it is not shown again). The page also triggers a static rebuild so the discovery `<link rel="micropub">` propagates into every published page.
2. **Set Site URL** — in **Settings**, make sure `Site URL` is set to the public absolute URL of the site. Micropub clients use this URL for endpoint discovery; entering the endpoint URL directly will hang iA Writer.
3. **Add the account in iA Writer** — Preferences → Accounts → Add Account → **Micropub**. URL is the **site root URL** (not the endpoint). When prompted, choose **Enter Token Manually** and paste the token.

### Request shapes

The endpoint accepts all three Micropub content types:

| Content-Type | Format |
|--------------|--------|
| `application/x-www-form-urlencoded` | `h=entry`, `name=…`, `content=…`, `summary=…`, `category[]=…`, `mp-slug=…`, `published=…`, `post-status=draft\|published` |
| `application/json` | `{type: ["h-entry"], properties: {name: […], content: […], summary: […], category: […], …}}` |
| `multipart/form-data` | Same as form-encoded plus one or many `photo` file parts (uploaded via the existing media validator and prepended to the post body as Markdown image lines) |

A client-supplied `summary` is stored as the post's excerpt and used verbatim by Mastodon/Bluesky syndication and feeds, replacing the auto-derived fallback.

### Queries

| Query | Returns |
|-------|---------|
| `?q=config` | Configuration object including `media-endpoint` and `syndicate-to` |
| `?q=syndicate-to` | Configured syndication targets (currently empty) |
| `?q=source&url=<post URL>` | Full h-entry source: `{type:["h-entry"], properties:{name, content, summary, mp-slug, post-status, published, category, url}}`. Add `&properties[]=name&properties[]=content` to limit the response to specific properties (in which case the `type` wrapper is omitted). Use this to load an existing post into a client's editor for round-trip editing. |
| `?q=source` (no `url`) | Post list: `{items:[{type:["h-entry"], properties:{…}}, …]}`, newest first, drafts and scheduled posts included. See below. |

#### Post list

Omitting `url` from `q=source` returns a list of posts — the [Query for Post List](https://indieweb.org/Micropub-extensions#Query_for_Post_List) extension — so a client can offer a picker of existing posts to edit instead of asking for a URL.

| Parameter | Effect |
|-----------|--------|
| `limit` | Page size. Defaults to `20`, capped at `100`. A missing or unparseable value falls back to the default. |
| `offset` | Rows to skip. Usable on its own, since a default `limit` always applies. |
| `post-type` | Post Type Discovery filter: `note`, `article`, `photo`, `reply`, `repost`, `like`, `bookmark` — the same list `q=config` advertises under `post-types`. An interaction wins over `article`/`note`, so a titled reply filters as `reply`. |
| `post-status` | `published`, or `draft` (which also covers scheduled posts, matching the `post-status` each item reports). |
| `properties[]` | Applied per item. Unlike the single-post response, list items keep their `type` wrapper — a list of bare property bags is not parseable mf2. |

An unrecognised `post-type` or `post-status` returns `400`, rather than quietly answering with the whole archive.

Every item carries a `url`, published or not: the date permalink once the post has a publish date, the bare slug before. That is the same value the create and update responses return in `Location:`, so a client can send it straight back as `url=` on a later `q=source`, `update` or `delete`. An unpublished post has no page on disk, so its URL 404s for visitors until it goes live.

A photo post reports a `photo` property whichever way it was written. Micropub stores the picture as a `post_photos` row; a photo post written in the admin keeps it in the body instead — the editor's convention is image in the content, caption in the excerpt — so for those the body's images are read back as the property. Only `photo` posts do this: an article with inline illustrations does not advertise them as its photos. A `[gallery ids="…"]` body names ids rather than URLs, so it reports no photos.

Ordering is newest first by publish date, falling back to creation date for undated drafts, with a stable tiebreak so paging never repeats or skips a post. Soft-deleted posts never appear.

### Update and delete

The endpoint also accepts the spec's `action` field for managing existing posts. The `url` field must be a public post URL — the slug (final non-empty path segment) is what's matched.

| Action | Content-Type | Body |
|--------|--------------|------|
| `delete` | form-encoded **or** JSON | `action=delete`, `url=<post URL>` |
| `update` | **JSON only** | `{"action": "update", "url": "<post URL>", "replace": {…}, "add": {…}, "delete": {…}}` |

`update` supports `replace`, `add`, and `delete` operations against `name`, `content`, `summary`, `mp-slug`, `category`, and `post-status` (`draft`/`published`). `summary` is single-valued, so `add` behaves like `replace` and `delete` clears the excerpt. `published` is frozen on existing posts. Renaming via `mp-slug` also removes the stale rendered file under the old date-path. Successful delete returns `204 No Content`; successful update returns `200 OK` with a `Location:` header pointing at the (possibly new) post URL.

### Category mapping

Micropub clients send all taxonomy as flat `category` values. For each value, the endpoint:

1. Slugifies the value.
2. If a row exists in `categories` with that slug → attaches as a category.
3. Otherwise → attaches as a tag (creating the tag row if needed).

Pre-create your categories in **Admin → Categories** to have matching client-supplied tags promoted automatically.

### Response

| Status | Meaning |
|--------|---------|
| `201 Created` + `Location: <post URL>` | Post saved (drafts return the admin edit URL) |
| `400 Bad Request` | Malformed payload, missing `content`/`photo`, or unsupported `h` type |
| `401 Unauthorized` | Missing or invalid bearer token (also `WWW-Authenticate: Bearer realm="Micropub"`) |
| `429 Too Many Requests` | Per-IP lockout from too many failed auth attempts (shares `login_attempts` with the admin login) |

---

## REST API

The CMS exposes a lightweight REST API at `/admin/api/`. Authentication is HTTP Basic with the same admin credentials used for the panel. Rate-limiting reuses the same `login_attempts` table and lockout rules as the web login.

**Base URL:** `/admin/api/{resource}/{id}`

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/admin/api/posts` | List posts (optional `?status=draft\|published\|scheduled`) |
| `GET` | `/admin/api/posts/{id}` | Get a single post |
| `POST` | `/admin/api/posts` | Create a post |
| `PUT` | `/admin/api/posts/{id}` | Update a post |
| `DELETE` | `/admin/api/posts/{id}` | Delete a post |
| `GET` | `/admin/api/pages` | List pages |
| `GET` | `/admin/api/pages/{id}` | Get a single page |
| `POST` | `/admin/api/pages` | Create a page |
| `PUT` | `/admin/api/pages/{id}` | Update a page |
| `DELETE` | `/admin/api/pages/{id}` | Delete a page |
| `GET` | `/admin/api/media` | List media library items |
| `POST` | `/admin/api/media` | Upload a file (`multipart/form-data`, field `file`) |
| `DELETE` | `/admin/api/media/{id}` | Delete a media item |
| `GET` | `/admin/api/categories` | List categories |
| `GET` | `/admin/api/tags` | List tags |
| `GET` | `/admin/api/settings` | Read site settings (sensitive keys excluded) |

Write endpoints (`POST`/`PUT`) accept `application/json`. Creating or updating a published post triggers the same static rebuild as the admin UI (post HTML, index, feed).

CORS is restricted to the origin matching the configured `site_url`; falls back to `*` only when `site_url` is unset. Native app clients (iOS, Xcode) are unaffected — they do not send `Origin` headers.

---

## Custom CSS

Paste any CSS into **Settings → Custom CSS** and save. The styles are injected as a `<style>` block at the end of every public page's `<head>`, after `theme.css`, so they naturally take precedence. Leave the field empty to inject nothing.

This is intended for small overrides (fonts, colors, spacing). For larger changes, edit `theme.css` directly.

---

## Activity Log

**Admin → Logs** shows two tables:

- **Activity log** — the last 200 content and settings actions (create, update, publish, unpublish, schedule, delete, upload, settings save, password change, site rebuild, 2FA enable/disable/regen), with timestamp, action, detail, and IP address
- **Login attempts** — the last 200 login attempts with timestamp, IP, and success/failure badge; includes TOTP verification attempts (prefixed with `totp:`)

Log entries older than 90 days are pruned automatically on a ~1% probabilistic cleanup triggered on each admin page load.

---

## Static Output Structure

```
/                           → index.html              (page 1 of post index)
/page/2/                    → page/2/index.html        (paginated index)
/YYYY/MM/DD/{slug}/         → posts/YYYY/MM/DD/{slug}/index.html
/{slug}/                    → pages/{slug}/index.html  (via Nginx @page fallback)
/category/{slug}/           → category/{slug}/index.html
/tag/{slug}/                → tag/{slug}/index.html
/search/                    → search/index.html        (client-side search page)
/search.json                → search index (title, excerpt, date, URL for all published posts)
/feed.xml                   → Atom 1.0 feed (with Byline)
/feed.rss                   → RSS 2.0 feed (with Byline)
/feed.json                  → JSON Feed 1.1
/media/{filename}           → content/media/ alias
/theme.css                  → public stylesheet
/fonts/                     → DM Sans web font files
```

Stale pagination pages and unpublished post/page files are removed automatically on rebuild.

---

## Theme & Styling

The public theme is a single file, `theme.css`, edited directly — there is no preprocessor. It uses CSS custom properties for all colors:

| Variable | Light | Dark |
|----------|-------|------|
| `--color-text` | `#1a1a1a` | `#e5e7eb` |
| `--color-muted` | `#6b7280` | `#9ca3af` |
| `--color-border` | `#e5e7eb` | `#374151` |
| `--color-bg` | `#ffffff` | `#181818` |
| `--color-code-bg` | `#f3f4f6` | `#2a2a2a` |
| `--color-link` | `#2563eb` | `#60a5fa` |

Dark mode activates automatically when the system preference is `dark`. The toggle button in the header overrides this and persists the choice in `localStorage`. An inline script in `<head>` applies the stored preference before the stylesheet loads, preventing any flash of the wrong color scheme.

The site is set entirely in [DM Sans](https://fonts.google.com/specimen/DM+Sans) — UI, prose, and headings alike (self-hosted variable WOFF2, OFL license). To add custom styles without editing `theme.css`, use **Settings → Custom CSS**.

### Critical CSS

A marker comment in `theme.css` splits the stylesheet in two:

```css
/* =END CRITICAL= */
```

Everything above it is the critical subset — inlined into each page's `<head>` so the first paint needs no network round trip. Everything below arrives via a preloaded `theme.min.css`. Both files are generated; only `theme.css` is edited. Sections below the marker keep their relative order with those above, so moving the boundary never changes which rule wins, but a media query must stay after the rules it overrides.

After editing the theme, rebuild the stylesheets alone rather than the whole site:

```bash
php bin/build.php --css
```

This regenerates both files and, when the critical subset changed, patches the inlined block in every already-generated page — a sub-second operation. The admin panel does the same automatically when it notices `theme.css` is newer than `theme.min.css`, though it does not patch existing pages.

---

## Project Structure

```
clodd-cms/
├── admin/                  # Admin panel PHP pages
│   ├── assets/             # Admin CSS, JS, EasyMDE, Font Awesome, Chart.js
│   │   └── chart.min.js    # Chart.js 4.4.7 (vendored)
│   ├── partials/           # Shared nav partial
│   ├── analytics.php       # Analytics dashboard (views, top pages, devices, referrers, 404s)
│   ├── api.php             # REST API endpoint (HTTP Basic Auth)
│   └── xmlrpc.php          # WordPress/MetaWeblog XML-RPC API endpoint
├── bin/
│   ├── build.php           # CLI: full static rebuild, or --css for styles only
│   ├── setup.php           # CLI installer (password hash + DB init)
│   └── send-webmentions.php  # CLI: send outgoing webmention pings
├── content/
│   └── media/              # Uploaded files (not committed)
├── data/                   # SQLite database (not committed)
├── docker/                 # Docker-specific Nginx config, PHP ini, entrypoint
├── fonts/                  # DM Sans WOFF2 files + OG image fonts (fonts/og/)
├── src/                    # PHP source classes (namespace CMS\)
│   ├── ActivityLog.php     # Admin activity logger
│   ├── Auth.php            # Login, session, CSRF, rate limiting, TOTP 2FA
│   ├── Bluesky.php         # Bluesky AT Protocol API client
│   ├── Builder.php         # Static site build engine (incl. Mondrian gallery rendering)
│   ├── Byline.php          # Byline 1.0 element builder shared by Atom and RSS feeds
│   ├── Database.php        # PDO/SQLite wrapper + schema migrations
│   ├── Feed.php            # Atom 1.0 feed generator
│   ├── Helpers.php
│   ├── HighlightFencedCodeRenderer.php  # Syntax highlighting for fenced code blocks
│   ├── ImageRenderer.php   # Lazy loading, WebP <picture>, CLS-safe dimensions
│   ├── JsonFeed.php        # JSON Feed 1.1 generator
│   ├── Mastodon.php        # Mastodon API client
│   ├── Media.php
│   ├── OgImage.php         # GD + FreeType OG image generator (skipped for asides)
│   ├── Page.php
│   ├── Post.php            # Post model (incl. post_kind: standard | aside)
│   ├── RssFeed.php         # RSS 2.0 feed generator (root + per-term archives)
│   ├── Syndication.php     # Creates, updates and removes the Mastodon/Bluesky copies
│   ├── Webmention.php      # Outgoing webmention discovery and sending
│   └── XmlRpc.php
├── templates/              # Public HTML templates
│   ├── 404.php             # 404 Not Found error page
│   ├── base.php
│   ├── index.php
│   ├── page.php
│   ├── post.php
│   ├── search.php
│   └── taxonomy.php        # Category and tag archive pages
├── storage/                # Runtime logs (not committed; create on server)
├── config.php              # Credentials + paths (not committed)
├── composer.json
├── docker-compose.yml
├── Dockerfile
├── favicon.svg             # SVG favicon (blue rounded square matching theme)
├── nginx.conf.example      # Production Nginx template
├── theme.css               # Public stylesheet
├── track.php               # Analytics beacon endpoint (public, POST only)
└── INSTALL.md              # Full VPS deployment guide
```

---

## License

MIT
