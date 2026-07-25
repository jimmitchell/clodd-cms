<?php

declare(strict_types=1);

namespace CMS;

class Post
{
    public ?int    $id           = null;
    public string  $title        = '';
    public string  $slug         = '';
    public string  $content      = '';
    public ?string $excerpt      = null;
    public string  $status       = 'draft';
    public ?string $published_at = null;
    public string  $created_at   = '';
    public string  $updated_at   = '';
    public ?string $built_at     = null;
    public ?string $content_hash = null;
    public ?string $tooted_at    = null;
    public ?string $mastodon_url = null;
    public int     $mastodon_skip = 0;
    public ?string $bluesky_at   = null;
    public ?string $bluesky_url  = null;
    public int     $bluesky_skip  = 0;
    public ?string $og_image_hash      = null;
    public ?string $webmentions_sent_at = null;
    public string  $post_kind    = 'standard';
    public ?string $deleted_at   = null;

    /** @var array<array<string,mixed>>  [['id'=>int,'name'=>string,'slug'=>string,'description'=>string], ...] */
    public array $categories = [];

    /** @var array<array<string,mixed>>  [['id'=>int,'name'=>string,'slug'=>string], ...] */
    public array $tags = [];

    /** @var array<array<string,mixed>>  [['id'=>int,'url'=>string,'alt'=>string,'sort_order'=>int,'media_id'=>?int], ...] */
    public array $photos = [];
    public array $contexts = [];

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ── Finders ───────────────────────────────────────────────────────────────

    /**
     * Return all posts, optionally filtered by status.
     * Ordered by published_at DESC, then created_at DESC.
     *
     * @return self[]
     */
    public static function findAll(Database $db, ?string $status = null): array
    {
        $sql    = "SELECT * FROM posts WHERE deleted_at IS NULL" . ($status !== null ? " AND status = :status" : "") . " ORDER BY published_at DESC, created_at DESC";
        $params = $status !== null ? ['status' => $status] : [];
        $posts  = array_map(fn($row) => self::fromRow($db, $row), $db->select($sql, $params));
        self::hydrateManyTerms($db, $posts);
        return $posts;
    }

    /**
     * All soft-deleted posts, most recently deleted first.
     *
     * @return self[]
     */
    public static function findDeleted(Database $db): array
    {
        $posts = array_map(
            fn($row) => self::fromRow($db, $row),
            $db->select("SELECT * FROM posts WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC")
        );
        self::hydrateManyTerms($db, $posts);
        return $posts;
    }

    public static function findById(Database $db, int $id): ?self
    {
        $row = $db->selectOne("SELECT * FROM posts WHERE id = :id", ['id' => $id]);
        if (!$row) {
            return null;
        }
        $post = self::fromRow($db, $row);
        self::hydrateManyTerms($db, [$post]);
        return $post;
    }

    public static function findBySlug(Database $db, string $slug): ?self
    {
        $row = $db->selectOne("SELECT * FROM posts WHERE slug = :slug", ['slug' => $slug]);
        if (!$row) {
            return null;
        }
        $post = self::fromRow($db, $row);
        self::hydrateManyTerms($db, [$post]);
        return $post;
    }

    /**
     * Return all published posts in a given category, newest first.
     *
     * @return self[]
     */
    public static function findByCategory(Database $db, int $categoryId): array
    {
        $rows = $db->select(
            "SELECT p.* FROM posts p
              JOIN post_categories pc ON pc.post_id = p.id
             WHERE pc.category_id = :cid AND p.status = 'published' AND p.deleted_at IS NULL
             ORDER BY p.published_at DESC",
            ['cid' => $categoryId]
        );
        $posts = array_map(fn($row) => self::fromRow($db, $row), $rows);
        self::hydrateManyTerms($db, $posts);
        return $posts;
    }

    /**
     * Return all published posts with a given tag, newest first.
     *
     * @return self[]
     */
    public static function findByTag(Database $db, int $tagId): array
    {
        $rows = $db->select(
            "SELECT p.* FROM posts p
              JOIN post_tags pt ON pt.post_id = p.id
             WHERE pt.tag_id = :tid AND p.status = 'published' AND p.deleted_at IS NULL
             ORDER BY p.published_at DESC",
            ['tid' => $tagId]
        );
        $posts = array_map(fn($row) => self::fromRow($db, $row), $rows);
        self::hydrateManyTerms($db, $posts);
        return $posts;
    }

    // ── Persistence ───────────────────────────────────────────────────────────

    /**
     * Insert (new) or update (existing) the post. Returns true on success.
     */
    public function save(): bool
    {
        $data = [
            'title'         => $this->title,
            'slug'          => $this->slug,
            'content'       => $this->content,
            'excerpt'       => $this->excerpt,
            'status'        => $this->status,
            'published_at'  => $this->published_at,
            'mastodon_skip' => $this->mastodon_skip,
            'bluesky_skip'  => $this->bluesky_skip,
            'post_kind'     => $this->post_kind,
            'updated_at'    => date('Y-m-d H:i:s'),
        ];

        if ($this->id === null) {
            $this->id = $this->db->insert('posts', $data);
            return $this->id > 0;
        }

        $affected = $this->db->update('posts', $data, 'id = :id', ['id' => $this->id]);
        return $affected >= 0; // 0 rows affected is still "ok" (no changes)
    }

    public function delete(): bool
    {
        if ($this->id === null) {
            return false;
        }
        $affected = $this->db->delete('posts', 'id = :id', ['id' => $this->id]);
        return $affected > 0;
    }

    /**
     * Soft-delete: mark the post deleted without removing the row, so a
     * Micropub undelete can restore it.
     */
    public function softDelete(): bool
    {
        if ($this->id === null) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        $this->deleted_at = $now;
        return $this->db->update('posts', ['deleted_at' => $now, 'updated_at' => $now], 'id = :id', ['id' => $this->id]) >= 0;
    }

    /**
     * Undo a soft delete.
     */
    public function restore(): bool
    {
        if ($this->id === null) {
            return false;
        }
        $this->deleted_at = null;
        return $this->db->update('posts', ['deleted_at' => null, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $this->id]) >= 0;
    }

    /**
     * Replace all category and tag associations for this post.
     * Silently ignores IDs that do not exist in the respective tables.
     *
     * @param int[] $categoryIds
     * @param int[] $tagIds
     */
    public function saveTerms(array $categoryIds, array $tagIds): void
    {
        if ($this->id === null) {
            return;
        }

        // Replace category associations.
        $this->db->exec("DELETE FROM post_categories WHERE post_id = :post_id", [':post_id' => $this->id]);
        foreach (array_unique($categoryIds) as $cid) {
            $cid = (int) $cid;
            if ($cid > 0) {
                $this->db->exec(
                    "INSERT OR IGNORE INTO post_categories (post_id, category_id) VALUES (:post_id, :category_id)",
                    [':post_id' => $this->id, ':category_id' => $cid]
                );
            }
        }

        // Replace tag associations.
        $this->db->exec("DELETE FROM post_tags WHERE post_id = :post_id", [':post_id' => $this->id]);
        foreach (array_unique($tagIds) as $tid) {
            $tid = (int) $tid;
            if ($tid > 0) {
                $this->db->exec(
                    "INSERT OR IGNORE INTO post_tags (post_id, tag_id) VALUES (:post_id, :tag_id)",
                    [':post_id' => $this->id, ':tag_id' => $tid]
                );
            }
        }

        // Refresh the in-memory arrays.
        $this->categories = $this->db->select(
            "SELECT c.id, c.name, c.slug, c.description
               FROM categories c
               JOIN post_categories pc ON pc.category_id = c.id
              WHERE pc.post_id = :post_id
              ORDER BY c.name",
            [':post_id' => $this->id]
        );
        $this->tags = $this->db->select(
            "SELECT t.id, t.name, t.slug
               FROM tags t
               JOIN post_tags pt ON pt.tag_id = t.id
              WHERE pt.post_id = :post_id
              ORDER BY t.name",
            [':post_id' => $this->id]
        );
    }

    /**
     * Replace all photo rows for this post (Micropub u-photo property).
     * Each entry: ['url' => string, 'alt' => string, 'media_id' => ?int].
     * Order in the array becomes sort_order.
     *
     * @param array<array<string,mixed>> $photos
     */
    public function savePhotos(array $photos): void
    {
        if ($this->id === null) {
            return;
        }

        $this->db->delete('post_photos', 'post_id = :post_id', ['post_id' => $this->id]);
        foreach (array_values($photos) as $i => $photo) {
            $url = trim((string) ($photo['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $this->db->insert('post_photos', [
                'post_id'    => $this->id,
                'url'        => $url,
                'alt'        => (string) ($photo['alt'] ?? ''),
                'sort_order' => $i,
                'media_id'   => $photo['media_id'] ?? null,
            ]);
        }

        // Refresh the in-memory array.
        $this->photos = $this->db->select(
            "SELECT id, url, alt, sort_order, media_id
               FROM post_photos
              WHERE post_id = :post_id
              ORDER BY sort_order, id",
            ['post_id' => $this->id]
        );
    }

    /**
     * Photos for a set of post ids, keyed by post id — for the feed generators,
     * which select raw rows instead of Post objects.
     *
     * @param int[] $ids
     * @return array<int, array<array<string,mixed>>>
     */
    public static function photosForPostIds(Database $db, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $map = [];
        $rows = $db->select(
            "SELECT post_id, url, alt
               FROM post_photos
              WHERE post_id IN ($placeholders)
              ORDER BY post_id, sort_order, id",
            $ids
        );
        foreach ($rows as $row) {
            $map[(int) $row['post_id']][] = [
                'url' => (string) $row['url'],
                'alt' => (string) ($row['alt'] ?? ''),
            ];
        }
        return $map;
    }

    /**
     * Render photos as h-entry figures (u-photo). Used by the feed generators;
     * templates render their own markup.
     *
     * @param array<array<string,mixed>> $photos
     */
    public static function photosHtml(array $photos, string $siteUrl = ''): string
    {
        $html = '';
        foreach ($photos as $photo) {
            $url = (string) $photo['url'];
            if ($siteUrl !== '' && str_starts_with($url, '/')) {
                $url = rtrim($siteUrl, '/') . $url;
            }
            $alt   = htmlspecialchars((string) ($photo['alt'] ?? ''), ENT_QUOTES, 'UTF-8');
            $src   = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $html .= '<figure><img class="u-photo" src="' . $src . '" alt="' . $alt . '"></figure>' . "\n";
        }
        return $html;
    }

    /** Micropub context kinds in display order (strongest interaction first). */
    public const CONTEXT_KINDS = ['repost-of', 'like-of', 'in-reply-to', 'bookmark-of'];

    /**
     * Replace all context rows for this post (Micropub in-reply-to / like-of /
     * repost-of / bookmark-of). Each entry: ['kind' => string, 'url' => string].
     *
     * @param array<array<string,string>> $contexts
     */
    public function saveContexts(array $contexts): void
    {
        if ($this->id === null) {
            return;
        }

        $this->db->delete('post_contexts', 'post_id = :post_id', ['post_id' => $this->id]);
        foreach ($contexts as $context) {
            $kind = (string) ($context['kind'] ?? '');
            $url  = trim((string) ($context['url'] ?? ''));
            if ($url === '' || !in_array($kind, self::CONTEXT_KINDS, true)) {
                continue;
            }
            $this->db->insert('post_contexts', [
                'post_id' => $this->id,
                'kind'    => $kind,
                'url'     => $url,
            ]);
        }

        $this->contexts = array_map(
            fn(array $row) => ['kind' => (string) $row['kind'], 'url' => (string) $row['url']],
            $this->db->select(
                "SELECT kind, url FROM post_contexts WHERE post_id = :post_id ORDER BY id",
                ['post_id' => $this->id]
            )
        );
    }

    /**
     * Contexts for a set of post ids, keyed by post id — for the feed generators,
     * which select raw rows instead of Post objects.
     *
     * @param int[] $ids
     * @return array<int, array<array<string,string>>>
     */
    public static function contextsForPostIds(Database $db, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $map = [];
        $rows = $db->select(
            "SELECT post_id, kind, url
               FROM post_contexts
              WHERE post_id IN ($placeholders)
              ORDER BY post_id, id",
            $ids
        );
        foreach ($rows as $row) {
            $map[(int) $row['post_id']][] = [
                'kind' => (string) $row['kind'],
                'url'  => (string) $row['url'],
            ];
        }
        return $map;
    }

    /**
     * Render context lines ("↩ In reply to <a>…</a>") with mf2 u-* classes.
     * Shared by the feed generators and templates.
     *
     * @param array<array<string,string>> $contexts
     */
    public static function contextsHtml(array $contexts): string
    {
        static $labels = [
            'repost-of'   => ['♺', 'Reposted', 'u-repost-of'],
            'like-of'     => ['♥', 'Liked', 'u-like-of'],
            'in-reply-to' => ['↩', 'In reply to', 'u-in-reply-to'],
            'bookmark-of' => ['🔖', 'Bookmarked', 'u-bookmark-of'],
        ];

        // Display order: repost > like > reply > bookmark.
        usort($contexts, fn($a, $b) =>
            array_search($a['kind'] ?? '', self::CONTEXT_KINDS, true)
            <=> array_search($b['kind'] ?? '', self::CONTEXT_KINDS, true));

        $html = '';
        foreach ($contexts as $context) {
            $kind = (string) ($context['kind'] ?? '');
            $url  = (string) ($context['url'] ?? '');
            if ($url === '' || !isset($labels[$kind])) {
                continue;
            }
            [$symbol, $verb, $class] = $labels[$kind];
            $text = preg_replace('#^https?://#', '', $url);
            if (mb_strlen($text) > 60) {
                $text = mb_substr($text, 0, 57) . '…';
            }
            $html .= '<p class="post__context"><span aria-hidden="true">' . $symbol . '</span> ' . $verb
                . ' <a class="' . $class . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
                . '" rel="nofollow">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</a></p>' . "\n";
        }
        return $html;
    }

    // ── Build helpers ─────────────────────────────────────────────────────────

    /**
     * Mark this post as built: store the hash and timestamp.
     */
    public function markBuilt(string $contentHash): void
    {
        $now              = date('Y-m-d H:i:s');
        $this->built_at   = $now;
        $this->content_hash = $contentHash;

        $this->db->update(
            'posts',
            ['built_at' => $now, 'content_hash' => $contentHash],
            'id = :id',
            ['id' => $this->id]
        );
    }

    /**
     * Record that an OG image was generated with the given hash.
     */
    public function markOgBuilt(string $hash): void
    {
        $this->og_image_hash = $hash;

        $this->db->update(
            'posts',
            ['og_image_hash' => $hash],
            'id = :id',
            ['id' => $this->id]
        );
    }

    /**
     * Record that this post was successfully posted to Bluesky.
     * Optionally stores the canonical bsky.app URL.
     */
    public function markBluesky(string $url = ''): void
    {
        $now              = date('Y-m-d H:i:s');
        $this->bluesky_at = $now;
        $cols = ['bluesky_at' => $now];

        if ($url !== '') {
            $this->bluesky_url = $url;
            $cols['bluesky_url'] = $url;
        }

        $this->db->update('posts', $cols, 'id = :id', ['id' => $this->id]);
    }

    /**
     * Record that outgoing webmentions were sent for this post.
     */
    public function markWebmentionsSent(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->webmentions_sent_at = $now;
        $this->db->update('posts', ['webmentions_sent_at' => $now], 'id = :id', ['id' => $this->id]);
    }

    /**
     * Record that this post was successfully tooted to Mastodon.
     * Optionally stores the canonical toot URL.
     */
    public function markTooted(string $url = ''): void
    {
        $now             = date('Y-m-d H:i:s');
        $this->tooted_at = $now;
        $cols = ['tooted_at' => $now];

        if ($url !== '') {
            $this->mastodon_url = $url;
            $cols['mastodon_url'] = $url;
        }

        $this->db->update('posts', $cols, 'id = :id', ['id' => $this->id]);
    }

    /**
     * Returns true if the rendered HTML would differ from the stored hash.
     * The actual hash comparison is done by Builder; this is a quick shortcut
     * based on updated_at vs built_at.
     */
    public function needsRebuild(): bool
    {
        if ($this->built_at === null || $this->content_hash === null) {
            return true;
        }
        return strtotime($this->updated_at) > strtotime($this->built_at);
    }

    // ── Excerpt resolution ────────────────────────────────────────────────────

    /**
     * Returns the effective excerpt for display:
     *  1. Explicit excerpt (stored, user-entered plain text) — returned as-is.
     *  2. Text before <!--more--> in the post content — Markdown stripped.
     *  3. Auto-generated: first 200 characters of plain-text content with ellipsis.
     */
    public function effectiveExcerpt(): ?string
    {
        if ($this->excerpt !== null && $this->excerpt !== '') {
            return $this->excerpt;
        }

        $pos = strpos($this->content, '<!--more-->');
        if ($pos !== false) {
            $plain = self::plaintextFromMarkdown(substr($this->content, 0, $pos));
            return $plain !== '' ? $plain : null;
        }

        // Auto-generate from the full content.
        $plain = self::plaintextFromMarkdown($this->content);
        if ($plain === '') {
            return null;
        }
        if (mb_strlen($plain) <= 200) {
            return $plain;
        }
        $truncated = mb_substr($plain, 0, 200);
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }
        return rtrim($truncated) . '…';
    }

    /**
     * Strip common Markdown syntax and HTML tags, returning normalized plain text.
     */
    public static function plaintextFromMarkdown(string $md): string
    {
        $text = strip_tags($md);
        $text = preg_replace('/^#{1,6}\h+/m', '', $text);              // headings
        $text = preg_replace('/(\*{1,3}|_{1,3})(.*?)\1/s', '$2', $text); // bold/italic
        $text = preg_replace('/~~(.+?)~~/s', '$1', $text);             // strikethrough
        $text = preg_replace('/`+[^`]*`+/', '', $text);                // inline code
        $text = preg_replace('/!\[[^\]]*\]\([^\)]*\)/', '', $text);    // images
        $text = preg_replace('/\[([^\]]+)\]\([^\)]*\)/', '$1', $text); // links
        $text = preg_replace('/\[([^\]]+)\]\[[^\]]*\]/', '$1', $text); // ref links
        $text = preg_replace('/^>\h*/m', '', $text);                   // blockquotes
        $text = preg_replace('/^[-*_]{3,}\h*$/m', '', $text);         // hr
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    // ── Slug helpers ──────────────────────────────────────────────────────────

    /**
     * Derive a slug from the opening words of a post body — used for asides,
     * which have no title to slugify.
     *
     * Returns '' when the body yields nothing usable (a bare like-of, or a
     * photo-only post). Callers treat that as "fall back to the post id".
     * Note this cannot defer to Helpers::slugify() for the empty check: that
     * returns the literal 'untitled' rather than an empty string.
     */
    public static function slugFromContent(string $content, int $words = 5, int $maxChars = 60): string
    {
        $plain = self::plaintextFromMarkdown($content);
        if ($plain === '') {
            return '';
        }

        $opening = implode(' ', array_slice(preg_split('/\s+/', $plain), 0, $words));
        $slug    = Helpers::slugify($opening);
        if ($slug === 'untitled') {
            return '';
        }

        // Cap the length so a body opening with a URL or long compound token
        // doesn't produce an unwieldy slug. Trim back to a hyphen boundary so
        // the last word isn't chopped mid-way.
        if (strlen($slug) > $maxChars) {
            $slug     = substr($slug, 0, $maxChars);
            $lastDash = strrpos($slug, '-');
            if ($lastDash !== false && $lastDash > 0) {
                $slug = substr($slug, 0, $lastDash);
            }
            $slug = trim($slug, '-');
        }

        return $slug;
    }

    /**
     * Slugify $base and make it unique, appending a numeric suffix on collision.
     * Pass the id of the post being saved as $excludeId so it doesn't collide
     * with itself.
     *
     * Digit-only slugs get a "-post" suffix: asides created before slugs were
     * derived from content use the bare post id, and a new post must not land
     * on one of those legacy URLs.
     */
    public static function resolveUniqueSlug(Database $db, string $base, ?int $excludeId = null): string
    {
        // Check before slugifying: Helpers::slugify() renders empty input as the
        // literal 'untitled', which admin validation rejects as a slug.
        $base = trim($base) === '' ? 'post' : Helpers::slugify($base);

        // A legacy aside keeps its own numeric slug when re-saved — applying the
        // digit guard here would rewrite "234" to "234-post" and break a live URL.
        if ($excludeId !== null) {
            $current = $db->selectOne("SELECT slug FROM posts WHERE id = :id", ['id' => $excludeId]);
            if ($current && $current['slug'] === $base) {
                return $base;
            }
        }

        if (ctype_digit($base)) {
            $base .= '-post';
        }

        $candidate = $base;
        $suffix    = 2;
        while (true) {
            $existing = self::findBySlug($db, $candidate);
            if ($existing === null || $existing->id === $excludeId) {
                return $candidate;
            }
            $candidate = $base . '-' . $suffix++;
        }
    }

    // ── URL helpers ───────────────────────────────────────────────────────────

    /**
     * Returns the date + slug path segment used in public URLs and file paths.
     * e.g. "2026/03/01/my-post-slug"  (no leading or trailing slash)
     */
    public static function datePath(string $published_at, string $slug, string $tz = ''): string
    {
        if ($tz !== '') {
            $dt = new \DateTime($published_at, new \DateTimeZone('UTC'));
            $dt->setTimezone(new \DateTimeZone($tz));
            return $dt->format('Y/m/d') . '/' . $slug;
        }
        $ts = strtotime($published_at);
        if ($ts === false) {
            throw new \InvalidArgumentException("Invalid published_at date: {$published_at}");
        }
        return date('Y/m/d', $ts) . '/' . $slug;
    }

    // ── Adjacent post navigation ──────────────────────────────────────────────

    /**
     * The nearest published post older than this one (for "← Previous" links).
     */
    public static function findPrev(Database $db, self $post): ?self
    {
        if ($post->published_at === null) {
            return null;
        }
        $row = $db->selectOne(
            "SELECT * FROM posts
              WHERE status = 'published'
                AND deleted_at IS NULL
                AND published_at < :pub
              ORDER BY published_at DESC
              LIMIT 1",
            ['pub' => $post->published_at]
        );
        if (!$row) {
            return null;
        }
        $prev = self::fromRow($db, $row);
        self::hydrateManyTerms($db, [$prev]);
        return $prev;
    }

    /**
     * The nearest published post newer than this one (for "Next →" links).
     */
    public static function findNext(Database $db, self $post): ?self
    {
        if ($post->published_at === null) {
            return null;
        }
        $row = $db->selectOne(
            "SELECT * FROM posts
              WHERE status = 'published'
                AND deleted_at IS NULL
                AND published_at > :pub
              ORDER BY published_at ASC
              LIMIT 1",
            ['pub' => $post->published_at]
        );
        if (!$row) {
            return null;
        }
        $next = self::fromRow($db, $row);
        self::hydrateManyTerms($db, [$next]);
        return $next;
    }

    // ── Scheduled post promotion ──────────────────────────────────────────────

    /**
     * Flip any due scheduled posts to 'published'.
     * Returns the IDs of promoted posts (for the caller to rebuild).
     *
     * @return int[]
     */
    public static function promoteScheduled(Database $db): array
    {
        $due = $db->select(
            "SELECT id FROM posts
              WHERE status = 'scheduled'
                AND deleted_at IS NULL
                AND published_at <= datetime('now')"
        );

        if (empty($due)) {
            return [];
        }

        $ids          = array_column($due, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->exec(
            "UPDATE posts SET status = 'published', updated_at = ? WHERE id IN ($placeholders)",
            array_merge([date('Y-m-d H:i:s')], $ids)
        );

        return $ids;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function fromRow(Database $db, array $row): self
    {
        $post               = new self($db);
        $post->id           = (int) $row['id'];
        $post->title        = $row['title'];
        $post->slug         = $row['slug'];
        $post->content      = $row['content'];
        $post->excerpt      = $row['excerpt'] ?? null;
        $post->status       = $row['status'];
        $post->published_at = $row['published_at'] ?? null;
        $post->created_at   = $row['created_at'] ?? '';
        $post->updated_at   = $row['updated_at'] ?? '';
        $post->built_at     = $row['built_at'] ?? null;
        $post->content_hash = $row['content_hash'] ?? null;
        $post->tooted_at     = $row['tooted_at']    ?? null;
        $post->mastodon_url  = $row['mastodon_url'] ?? null;
        $post->mastodon_skip = (int) ($row['mastodon_skip'] ?? 0);
        $post->bluesky_at    = $row['bluesky_at']   ?? null;
        $post->bluesky_url   = $row['bluesky_url']  ?? null;
        $post->bluesky_skip  = (int) ($row['bluesky_skip']  ?? 0);
        $post->og_image_hash       = $row['og_image_hash']       ?? null;
        $post->webmentions_sent_at = $row['webmentions_sent_at'] ?? null;
        $post->post_kind           = $row['post_kind']           ?? 'standard';
        $post->deleted_at          = $row['deleted_at']          ?? null;

        return $post;
    }

    public function isAside(): bool
    {
        return $this->post_kind === 'aside';
    }

    public function isPhoto(): bool
    {
        return $this->post_kind === 'photo';
    }

    /**
     * Body-first kinds: asides and photo posts. These render their full content
     * on list cards instead of an excerpt, omit the h1 on their permalink, and
     * syndicate as native notes (no title, no link back).
     */
    public function isNote(): bool
    {
        return self::isNoteKind($this->post_kind);
    }

    /**
     * isNote() for callers holding a raw `post_kind` value rather than a Post —
     * the feed generators select rows directly for speed. Keeping the definition
     * here means adding a kind can't silently miss one of them.
     */
    public static function isNoteKind(?string $kind): bool
    {
        return in_array($kind ?? 'standard', ['aside', 'photo'], true);
    }

    /**
     * The plain-text words of a note, for syndication and the search index.
     *
     * A photo post keeps its words in the excerpt — the content holds only the
     * picture, and plaintextFromMarkdown() strips images, so reading the body
     * would yield an empty string and syndicate a blank status.
     */
    public function noteText(): string
    {
        return $this->isPhoto()
            ? trim((string) $this->excerpt)
            : trim(self::plaintextFromMarkdown($this->content));
    }

    /**
     * A photo post's caption as an HTML paragraph, for appending to feed content
     * and the permalink body. Empty string for any other kind, or no excerpt.
     *
     * Takes raw values rather than a Post because the feed generators select
     * rows directly.
     */
    public static function photoCaptionHtml(?string $kind, ?string $excerpt, string $class = ''): string
    {
        if ($kind !== 'photo') {
            return '';
        }
        $text = trim((string) $excerpt);
        if ($text === '') {
            return '';
        }

        return '<p' . ($class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' : '') . '>'
            . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</p>';
    }

    /**
     * Batch-load categories and tags for an array of Post objects.
     * Executes exactly 2 queries regardless of how many posts are passed.
     *
     * @param self[] $posts
     */
    private static function hydrateManyTerms(Database $db, array $posts): void
    {
        if (empty($posts)) {
            return;
        }

        $ids          = array_map(fn($p) => $p->id, $posts);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $byId         = [];
        foreach ($posts as $post) {
            $byId[$post->id] = $post;
            $post->categories = [];
            $post->tags       = [];
        }

        $catRows = $db->select(
            "SELECT pc.post_id, c.id, c.name, c.slug, c.description
               FROM categories c
               JOIN post_categories pc ON pc.category_id = c.id
              WHERE pc.post_id IN ($placeholders)
              ORDER BY c.name",
            $ids
        );
        foreach ($catRows as $row) {
            $pid = (int) $row['post_id'];
            if (isset($byId[$pid])) {
                $byId[$pid]->categories[] = [
                    'id'          => $row['id'],
                    'name'        => $row['name'],
                    'slug'        => $row['slug'],
                    'description' => $row['description'],
                ];
            }
        }

        $tagRows = $db->select(
            "SELECT pt.post_id, t.id, t.name, t.slug
               FROM tags t
               JOIN post_tags pt ON pt.tag_id = t.id
              WHERE pt.post_id IN ($placeholders)
              ORDER BY t.name",
            $ids
        );
        foreach ($tagRows as $row) {
            $pid = (int) $row['post_id'];
            if (isset($byId[$pid])) {
                $byId[$pid]->tags[] = [
                    'id'   => $row['id'],
                    'name' => $row['name'],
                    'slug' => $row['slug'],
                ];
            }
        }

        // Photos ride along with terms so every finder hydrates them too.
        foreach ($posts as $post) {
            $post->photos = [];
        }
        $photoRows = $db->select(
            "SELECT post_id, id, url, alt, sort_order, media_id
               FROM post_photos
              WHERE post_id IN ($placeholders)
              ORDER BY post_id, sort_order, id",
            $ids
        );
        foreach ($photoRows as $row) {
            $pid = (int) $row['post_id'];
            if (isset($byId[$pid])) {
                $byId[$pid]->photos[] = [
                    'id'         => (int) $row['id'],
                    'url'        => (string) $row['url'],
                    'alt'        => (string) ($row['alt'] ?? ''),
                    'sort_order' => (int) $row['sort_order'],
                    'media_id'   => $row['media_id'] !== null ? (int) $row['media_id'] : null,
                ];
            }
        }

        // Contexts ride along too (reply/like/repost/bookmark targets).
        foreach ($posts as $post) {
            $post->contexts = [];
        }
        $contextRows = $db->select(
            "SELECT post_id, kind, url
               FROM post_contexts
              WHERE post_id IN ($placeholders)
              ORDER BY post_id, id",
            $ids
        );
        foreach ($contextRows as $row) {
            $pid = (int) $row['post_id'];
            if (isset($byId[$pid])) {
                $byId[$pid]->contexts[] = [
                    'kind' => (string) $row['kind'],
                    'url'  => (string) $row['url'],
                ];
            }
        }
    }
}
