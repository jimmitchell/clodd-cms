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
    public ?string $mastodon_status_id = null;
    public int     $mastodon_skip = 0;
    public ?string $bluesky_at   = null;
    public ?string $bluesky_url  = null;
    public ?string $bluesky_rkey = null;
    public int     $bluesky_skip  = 0;
    /** When to ask bsky.app whether the last edit surfaced; null once asked. */
    public ?string $bluesky_verify_at = null;
    /** 1 once that answer came back no — see Bluesky::isVisible(). */
    public int     $bluesky_stale = 0;
    public ?string $pixelfed_at   = null;
    public ?string $pixelfed_url  = null;
    public ?string $pixelfed_status_id = null;
    public int     $pixelfed_skip = 0;
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

    /**
     * True unless this object was loaded via fromRow() and hasn't yet gone
     * through hydrateManyTerms() — see ensureHydrated(). A freshly constructed
     * Post is accurate by construction (save()/saveTerms()/savePhotos()/
     * saveContexts() each keep their own slice current), so it starts true.
     */
    public bool $termsHydrated = true;

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
    public static function findAll(Database $db, ?string $status = null, ?int $limit = null, int $offset = 0): array
    {
        $sql    = "SELECT * FROM posts WHERE deleted_at IS NULL" . ($status !== null ? " AND status = :status" : "") . " ORDER BY published_at DESC, created_at DESC";
        $params = $status !== null ? ['status' => $status] : [];

        // LIMIT/OFFSET are interpolated rather than bound: PDO's SQLite driver
        // binds them as strings by default, which SQLite rejects in this
        // position. Both are cast to int by the signature, so there is nothing
        // here an argument could inject.
        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset);
        }

        $posts = array_map(fn($row) => self::fromRow($db, $row), $db->select($sql, $params));
        self::hydrateManyTerms($db, $posts);
        return $posts;
    }

    /** Count of non-deleted posts, optionally filtered by status. */
    public static function countAll(Database $db, ?string $status = null): int
    {
        $row = $db->selectOne(
            "SELECT COUNT(*) AS c FROM posts WHERE deleted_at IS NULL"
                . ($status !== null ? " AND status = :status" : ""),
            $status !== null ? ['status' => $status] : []
        );

        return (int) ($row['c'] ?? 0);
    }

    // ── Micropub post list (q=source with no url) ─────────────────────────────

    /**
     * The Post Type Discovery names this server can list and filter by, in the
     * order q=config advertises them.
     */
    public const MICROPUB_TYPES = ['note', 'article', 'photo', 'reply', 'repost', 'like', 'bookmark'];

    /** Micropub post-status values a client may send or receive. */
    public const MICROPUB_STATUSES = ['published', 'draft'];

    /**
     * The Post Type Discovery name for this post.
     *
     * PTD precedence: an interaction wins over article/note, so a titled reply
     * is a `reply`. Reads the hydrated contexts, so it costs no query.
     *
     * One deviation from strict PTD: a *titled* post carrying photos is
     * `article` here rather than `photo`, because `post_kind` is what the site
     * actually renders from and Micropub only assigns 'photo' to titleless
     * posts.
     *
     * findForMicropub() filters with SQL that mirrors this ladder exactly —
     * change one and you must change the other, or the list will hide posts a
     * client can see elsewhere.
     */
    public function micropubType(): string
    {
        $kinds = array_map(fn($c) => (string) ($c['kind'] ?? ''), $this->contexts);

        foreach (['in-reply-to' => 'reply', 'repost-of' => 'repost', 'like-of' => 'like', 'bookmark-of' => 'bookmark'] as $kind => $type) {
            if (in_array($kind, $kinds, true)) {
                return $type;
            }
        }

        if ($this->post_kind === 'photo') {
            return 'photo';
        }

        return $this->title !== '' ? 'article' : 'note';
    }

    /**
     * SQL predicate selecting posts of one Post Type Discovery type, against a
     * `posts` row aliased `p`. Mirrors micropubType() — see the note there.
     */
    private static function micropubTypePredicate(string $type): string
    {
        $has    = fn(string $kind) => "EXISTS (SELECT 1 FROM post_contexts pc WHERE pc.post_id = p.id AND pc.kind = '{$kind}')";
        $anyCtx = "EXISTS (SELECT 1 FROM post_contexts pc WHERE pc.post_id = p.id)";

        // Each interaction excludes the ones above it, matching the ladder's
        // first-match-wins order.
        return match ($type) {
            'reply'    => $has('in-reply-to'),
            'repost'   => 'NOT ' . $has('in-reply-to') . ' AND ' . $has('repost-of'),
            'like'     => 'NOT ' . $has('in-reply-to') . ' AND NOT ' . $has('repost-of') . ' AND ' . $has('like-of'),
            'bookmark' => 'NOT ' . $has('in-reply-to') . ' AND NOT ' . $has('repost-of')
                          . ' AND NOT ' . $has('like-of') . ' AND ' . $has('bookmark-of'),
            'photo'    => 'NOT ' . $anyCtx . " AND p.post_kind = 'photo'",
            'article'  => 'NOT ' . $anyCtx . " AND p.post_kind <> 'photo' AND p.title <> ''",
            'note'     => 'NOT ' . $anyCtx . " AND p.post_kind <> 'photo' AND p.title = ''",
            default    => '1 = 1',
        };
    }

    /**
     * Posts for the Micropub "Query for Post List" extension — newest first,
     * optionally filtered by Post Type Discovery type and by post-status.
     *
     * `$postStatus` is the Micropub value, not the column: 'draft' covers both
     * our 'draft' and 'scheduled' rows, because that is what the source
     * representation reports for a post that has not gone live yet. Filtering
     * and reporting have to agree or a client's draft list loses its scheduled
     * posts.
     *
     * @return self[]
     */
    public static function findForMicropub(
        Database $db,
        ?string $postType = null,
        ?string $postStatus = null,
        int $limit = 20,
        int $offset = 0
    ): array {
        $where  = ['p.deleted_at IS NULL'];
        $params = [];

        if ($postStatus === 'published') {
            $where[] = "p.status = 'published'";
        } elseif ($postStatus === 'draft') {
            $where[] = "p.status IN ('draft', 'scheduled')";
        }

        if ($postType !== null && in_array($postType, self::MICROPUB_TYPES, true)) {
            $where[] = '(' . self::micropubTypePredicate($postType) . ')';
        }

        // Undated drafts have a NULL published_at, which SQLite sorts last under
        // DESC — the opposite of useful for a list whose point is showing recent
        // work, so fall back to created_at. The id tiebreak keeps offset paging
        // stable when two posts share a timestamp.
        $sql = 'SELECT p.* FROM posts p WHERE ' . implode(' AND ', $where)
             . ' ORDER BY COALESCE(p.published_at, p.created_at) DESC, p.id DESC';

        // LIMIT/OFFSET are interpolated rather than bound: PDO's SQLite driver
        // binds them as strings by default, which SQLite rejects in this
        // position. Both are cast to int by the signature, so there is nothing
        // here an argument could inject.
        $sql .= ' LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset);

        $posts = array_map(fn($row) => self::fromRow($db, $row), $db->select($sql, $params));
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


    // ── Related posts ─────────────────────────────────────────────────────────

    /**
     * The SQL naming the posts eligible to appear as a related post, and the
     * expression scoring how close each one is to post `:self`.
     *
     * Eligibility mirrors micropubTypePredicate('article') — a title and not a
     * photo — with `aside` excluded by name as well, so a titleless kind that
     * somehow acquires a title cannot slip into the list. Both sides of the
     * feature use it: findRelated() takes the top few, findRelatedNeighbours()
     * takes all of them.
     *
     * A shared category counts double. Tags are barely used on this site (12
     * post_tags rows against 117 post_categories), so tag overlap is the weaker
     * signal and should not outrank a genuine category match.
     *
     * The two overlap subqueries are grouped by post rather than joined
     * directly because a post sharing three categories must score 6, not appear
     * three times. Both are covered by idx_post_categories_cat and
     * idx_post_tags_tag (schema V26).
     *
     * `:self` appears three times, so callers bind three separately-named
     * parameters — PDO's SQLite driver is on native prepares here and reusing
     * one name is the kind of thing that works until it doesn't.
     */
    private const RELATED_SCORE = '(2 * COALESCE(sc.n, 0) + COALESCE(st.n, 0))';

    private const RELATED_FROM = "
          FROM posts p
          LEFT JOIN (SELECT post_id, COUNT(*) AS n FROM post_categories
                      WHERE category_id IN (SELECT category_id FROM post_categories WHERE post_id = :cid)
                      GROUP BY post_id) sc ON sc.post_id = p.id
          LEFT JOIN (SELECT post_id, COUNT(*) AS n FROM post_tags
                      WHERE tag_id IN (SELECT tag_id FROM post_tags WHERE post_id = :tid)
                      GROUP BY post_id) st ON st.post_id = p.id
         WHERE p.id <> :self
           AND p.status = 'published'
           AND p.deleted_at IS NULL
           AND p.title <> ''
           AND p.post_kind NOT IN ('aside', 'photo')
           AND (2 * COALESCE(sc.n, 0) + COALESCE(st.n, 0)) > 0";

    /**
     * Titled published posts sharing a category or tag with $post, best match
     * first, ties broken by recency.
     *
     * Returns [] for a post with no id — an unsaved post has no junction rows,
     * so the query could only ever match on nothing.
     *
     * @return self[]
     */
    public static function findRelated(Database $db, self $post, int $limit = 3): array
    {
        if ($post->id === null) {
            return [];
        }
        // LIMIT is interpolated, not bound: PDO's SQLite driver binds integers
        // as strings, which SQLite then refuses. Same reasoning as findAll().
        $limit = max(0, $limit);
        if ($limit === 0) {
            return [];
        }
        $rows = $db->select(
            'SELECT p.*, ' . self::RELATED_SCORE . ' AS related_score'
            . self::RELATED_FROM
            . ' ORDER BY related_score DESC, p.published_at DESC'
            . ' LIMIT ' . $limit,
            ['cid' => $post->id, 'tid' => $post->id, 'self' => $post->id]
        );
        $posts = array_map(fn($row) => self::fromRow($db, $row), $rows);
        // Hydrated: templates/partials/related-posts.php reads $post->photos for
        // the thumbnail. Four queries for the whole batch, not per post.
        self::hydrateManyTerms($db, $posts);
        return $posts;
    }

    /**
     * Every titled published post whose own related list could change when
     * $post changes — that is, everything sharing a term with it.
     *
     * This is findRelated() without the limit. A post entering or leaving the
     * site alters the ranking for all of them, not just the three it would
     * itself display, so the rebuild pass in Builder has to cover the lot.
     *
     * Deliberately unhydrated: the caller only re-renders these, and
     * Builder::buildPost() hydrates whatever it renders.
     *
     * @return self[]
     */
    public static function findRelatedNeighbours(Database $db, self $post): array
    {
        if ($post->id === null) {
            return [];
        }
        $rows = $db->select(
            'SELECT p.*' . self::RELATED_FROM,
            ['cid' => $post->id, 'tid' => $post->id, 'self' => $post->id]
        );
        return array_map(fn($row) => self::fromRow($db, $row), $rows);
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
            'pixelfed_skip' => $this->pixelfed_skip,
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

    // ── Effective photos (attached rows, or the body's images) ────────────────

    /**
     * Pull src/alt pairs out of a post body, in document order.
     *
     * Bodies are Markdown with HTML allowed, so both an image written as
     * ![alt](/media/photo.jpg) and one written as an <img> tag count. A
     * [gallery ids="…"] shortcode names no URLs and yields nothing.
     *
     * @return array<array{url:string,alt:string}>
     */
    public static function imagesInBody(string $body): array
    {
        if ($body === '') {
            return [];
        }

        $pattern = '/!\[(?P<mdalt>[^\]]*)\]\(\s*<?(?P<mdsrc>[^)\s>]+)>?[^)]*\)'
                 . '|<img\b(?P<tag>[^>]*)>/is';
        if (!preg_match_all($pattern, $body, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $images = [];
        foreach ($matches as $match) {
            if (($match['mdsrc'] ?? '') !== '') {
                $url = $match['mdsrc'];
                $alt = $match['mdalt'];
            } else {
                $tag = $match['tag'] ?? '';
                if (!preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/is', $tag, $src)) {
                    continue;
                }
                $url = $src[2];
                $alt = preg_match('/\balt\s*=\s*(["\'])(.*?)\1/is', $tag, $altMatch) ? $altMatch[2] : '';
            }

            $images[] = [
                'url' => html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'alt' => html_entity_decode($alt, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ];
        }

        return $images;
    }

    /**
     * The photos a post *has*, whichever way it was written.
     *
     * Micropub stores the picture as post_photos rows (the u-photo property).
     * A photo post written in the admin keeps it in the body instead — that is
     * the documented convention in the editor, image in the content and caption
     * in the excerpt — so the body's images are the fallback.
     *
     * Only `photo` posts fall back: an article with inline illustrations must
     * not start advertising them as its photo property, which would change how
     * a client reads its type.
     *
     * **This is for consumers that report a post's photos as data** — the
     * Micropub source representation, JSON Feed's item.image, syndication
     * attachments. Anything that renders photos *next to* the body must keep
     * reading `$post->photos` directly, or a derived image is drawn twice: once
     * in the photos block and once in the content it was parsed out of.
     *
     * Static, and taking raw values, because JsonFeed::render() works from
     * database rows rather than Post objects.
     *
     * @param array<array<string,mixed>> $photos
     * @return array<array<string,mixed>>
     */
    public static function photosOrBodyImages(array $photos, string $content, ?string $postKind): array
    {
        if ($photos !== []) {
            return $photos;
        }
        if ($postKind !== 'photo') {
            return [];
        }

        $derived = [];
        foreach (self::imagesInBody($content) as $i => $image) {
            $derived[] = [
                'id'         => null,
                'url'        => $image['url'],
                'alt'        => $image['alt'],
                'sort_order' => $i,
                'media_id'   => null,
            ];
        }
        return $derived;
    }

    /** photosOrBodyImages() for this post. */
    public function effectivePhotos(): array
    {
        return self::photosOrBodyImages($this->photos, $this->content, $this->post_kind);
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

        $this->loadContexts();
    }

    /**
     * Re-read this post's contexts from the database.
     *
     * Contexts are hydrated on some paths and not others, and syndication runs
     * from every one of them — so the code that decides what to send reloads
     * rather than trusting whatever happens to be on the object.
     */
    public function loadContexts(): void
    {
        if ($this->id === null) {
            return;
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
     * The symbol, verb and mf2 class each context kind is announced with.
     *
     * One table for the page, the feeds and the copies on Mastodon and Bluesky:
     * a reply that says "In reply to" here and nothing at all there is exactly
     * the drift this is meant to prevent.
     */
    private const CONTEXT_LABELS = [
        'repost-of'   => ['♺', 'Reposted', 'u-repost-of'],
        'like-of'     => ['♥', 'Liked', 'u-like-of'],
        'in-reply-to' => ['↩', 'In reply to', 'u-in-reply-to'],
        'bookmark-of' => ['🔖', 'Bookmarked', 'u-bookmark-of'],
    ];

    /**
     * Render context lines ("↩ In reply to <a>…</a>") with mf2 u-* classes.
     * Shared by the feed generators and templates.
     *
     * @param array<array<string,string>> $contexts
     */
    public static function contextsHtml(array $contexts): string
    {
        $html = '';
        foreach (self::sortedContexts($contexts) as [$symbol, $verb, $class, $url]) {
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

    /**
     * The same context lines as plain text ("↩ In reply to https://…"), one per
     * line, for the copies that go to Mastodon and Bluesky.
     *
     * The URL is written out in full rather than trimmed the way the page trims
     * it: Mastodon linkifies whatever it finds in the status text, so a
     * shortened URL would arrive as unclickable words.
     *
     * @param array<array<string,string>> $contexts
     */
    public static function contextsText(array $contexts): string
    {
        $lines = [];
        foreach (self::sortedContexts($contexts) as [$symbol, $verb, , $url]) {
            $lines[] = $symbol . ' ' . $verb . ' ' . $url;
        }
        return implode("\n", $lines);
    }

    /**
     * Contexts worth rendering, in display order (repost > like > reply >
     * bookmark), each resolved to the symbol, verb, class and URL to show.
     *
     * @param  array<array<string,string>> $contexts
     * @return array<array{0:string,1:string,2:string,3:string}>
     */
    private static function sortedContexts(array $contexts): array
    {
        usort($contexts, fn($a, $b) =>
            array_search($a['kind'] ?? '', self::CONTEXT_KINDS, true)
            <=> array_search($b['kind'] ?? '', self::CONTEXT_KINDS, true));

        $resolved = [];
        foreach ($contexts as $context) {
            $kind = (string) ($context['kind'] ?? '');
            $url  = trim((string) ($context['url'] ?? ''));
            if ($url === '' || !isset(self::CONTEXT_LABELS[$kind])) {
                continue;
            }
            [$symbol, $verb, $class] = self::CONTEXT_LABELS[$kind];
            $resolved[] = [$symbol, $verb, $class, $url];
        }
        return $resolved;
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
     * Optionally stores the canonical bsky.app URL and the record key a later
     * edit or delete addresses the record by.
     */
    public function markBluesky(string $url = '', string $rkey = ''): void
    {
        $now              = date('Y-m-d H:i:s');
        $this->bluesky_at = $now;
        $cols = ['bluesky_at' => $now];

        if ($url !== '') {
            $this->bluesky_url = $url;
            $cols['bluesky_url'] = $url;
        }
        if ($rkey !== '') {
            $this->bluesky_rkey = $rkey;
            $cols['bluesky_rkey'] = $rkey;
        }

        $this->db->update('posts', $cols, 'id = :id', ['id' => $this->id]);
    }

    /**
     * Record that the Bluesky record was rewritten and nobody has yet checked
     * whether bsky.app is showing the new words.
     *
     * The delay is what makes the answer mean something: the AppView takes a
     * few seconds to index an ordinary write, so asking immediately would call
     * every edit stale. Verification runs from cron — see
     * Syndication::verifyBluesky().
     *
     * Written with gmdate(), not date(): the due-row query compares this against
     * SQLite's datetime('now'), which is UTC whatever the site timezone says.
     */
    public function markBlueskyUnverified(): void
    {
        if ($this->id === null) {
            return;
        }

        $due                     = gmdate('Y-m-d H:i:s', time() + 300);
        $this->bluesky_verify_at = $due;
        $this->db->update('posts', ['bluesky_verify_at' => $due], 'id = :id', ['id' => $this->id]);
    }

    /**
     * Record what the check found: true when bsky.app is showing what the repo
     * holds, false when it is still serving the older record, null when the
     * question could not be answered — which leaves the previous verdict alone
     * rather than clearing a warning on no evidence.
     */
    public function markBlueskyVerified(?bool $visible): void
    {
        if ($this->id === null) {
            return;
        }

        $this->bluesky_verify_at = null;
        $cols = ['bluesky_verify_at' => null];

        if ($visible !== null) {
            $this->bluesky_stale  = $visible ? 0 : 1;
            $cols['bluesky_stale'] = $this->bluesky_stale;
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
     * Optionally stores the canonical toot URL and the status id a later edit
     * or delete addresses the toot by.
     */
    public function markTooted(string $url = '', string $statusId = ''): void
    {
        $now             = date('Y-m-d H:i:s');
        $this->tooted_at = $now;
        $cols = ['tooted_at' => $now];

        if ($url !== '') {
            $this->mastodon_url = $url;
            $cols['mastodon_url'] = $url;
        }
        if ($statusId !== '') {
            $this->mastodon_status_id = $statusId;
            $cols['mastodon_status_id'] = $statusId;
        }

        $this->db->update('posts', $cols, 'id = :id', ['id' => $this->id]);
    }

    /**
     * Record that this post was successfully posted to Pixelfed.
     *
     * The Pixelfed copy is addressed by status id exactly as the Mastodon one
     * is — Pixelfed speaks the Mastodon API — so this mirrors markTooted().
     */
    public function markPixelfed(string $url = '', string $statusId = ''): void
    {
        $now                = date('Y-m-d H:i:s');
        $this->pixelfed_at  = $now;
        $cols = ['pixelfed_at' => $now];

        if ($url !== '') {
            $this->pixelfed_url = $url;
            $cols['pixelfed_url'] = $url;
        }
        if ($statusId !== '') {
            $this->pixelfed_status_id = $statusId;
            $cols['pixelfed_status_id'] = $statusId;
        }

        $this->db->update('posts', $cols, 'id = :id', ['id' => $this->id]);
    }

    /**
     * Forget every trace of a syndicated copy, for one network or any subset.
     *
     * Called once the remote copy is gone: the post page links to
     * mastodon_url / bluesky_url / pixelfed_url, so leaving them behind would
     * publish a link to a status that no longer exists. Clearing tooted_at /
     * bluesky_at / pixelfed_at also re-arms first-publish syndication, which is
     * what an unpublish-then-republish should do.
     */
    public function clearSyndication(bool $mastodon = true, bool $bluesky = true, bool $pixelfed = true): void
    {
        if ($this->id === null) {
            return;
        }

        $cols = [];
        if ($mastodon) {
            $this->tooted_at = $this->mastodon_url = $this->mastodon_status_id = null;
            $cols += ['tooted_at' => null, 'mastodon_url' => null, 'mastodon_status_id' => null];
        }
        if ($bluesky) {
            $this->bluesky_at = $this->bluesky_url = $this->bluesky_rkey = null;
            // A copy that is gone cannot be showing the wrong words, so the
            // pending check and the warning go with it.
            $this->bluesky_verify_at = null;
            $this->bluesky_stale     = 0;
            $cols += [
                'bluesky_at'        => null,
                'bluesky_url'       => null,
                'bluesky_rkey'      => null,
                'bluesky_verify_at' => null,
                'bluesky_stale'     => 0,
            ];
        }
        if ($pixelfed) {
            $this->pixelfed_at = $this->pixelfed_url = $this->pixelfed_status_id = null;
            $cols += ['pixelfed_at' => null, 'pixelfed_url' => null, 'pixelfed_status_id' => null];
        }
        if ($cols === []) {
            return;
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

    /**
     * The path segment that addresses this post, with no leading or trailing
     * slash — the date-based permalink once there is a publish date, otherwise
     * the bare slug.
     *
     * Use this for anything a client will send back to identify the post (the
     * Micropub Location header, for instance). Both forms resolve to the same
     * post, because slugs are unique and lookups match on the final segment, so
     * a draft addressed by slug keeps working after it gains a date.
     *
     * Not the same as a *public* URL: an unpublished post has no page on disk,
     * so the returned path 404s for visitors until the post is published.
     */
    public function addressablePath(string $tz = ''): string
    {
        return $this->published_at !== null
            ? self::datePath($this->published_at, $this->slug, $tz)
            : $this->slug;
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
        // Not hydrated: the prev/next links in templates/post.php read only
        // title, slug, published_at and post_kind. Loading categories, tags,
        // photos and contexts cost four extra queries per call, twice per post,
        // for data nothing rendered.
        return self::fromRow($db, $row);
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
        // Not hydrated — see findPrev().
        return self::fromRow($db, $row);
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
        // Select and update in one immediate transaction. Apart it was a race:
        // the cron runner and a web request arriving together both read the
        // same due rows, both returned those ids, and the caller syndicates
        // whatever it is handed — so the post went out to Mastodon, Bluesky and
        // Pixelfed twice. The window is small and the consequence is public,
        // which is the worst combination to leave to luck.
        //
        // The UPDATE repeats the status predicate so it can only ever move rows
        // that are still scheduled, and the returned ids are filtered to what
        // was actually changed. Belt and braces: with BEGIN IMMEDIATE the
        // second caller cannot see the row as scheduled at all, but that keeps
        // this correct even if someone later loosens the transaction.
        return $db->transaction(static function () use ($db): array {
            $due = $db->select(
                "SELECT id FROM posts
                  WHERE status = 'scheduled'
                    AND deleted_at IS NULL
                    AND published_at <= datetime('now')"
            );

            if (empty($due)) {
                return [];
            }

            $ids          = array_map('intval', array_column($due, 'id'));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $db->exec(
                "UPDATE posts
                    SET status = 'published', updated_at = ?
                  WHERE id IN ($placeholders)
                    AND status = 'scheduled'",
                array_merge([date('Y-m-d H:i:s')], $ids)
            );

            // Every id, not just the rows the UPDATE reported changing. Holding
            // the write lock across both statements means the two always agree,
            // but if they ever did not, the safe direction is to hand the caller
            // too many ids rather than too few: a redundant id rebuilds a post
            // that was already correct, while a missing one leaves a published
            // post unbuilt and invisible to the next promotion query.
            return $ids;
        });
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function fromRow(Database $db, array $row): self
    {
        $post               = new self($db);
        // findPrev()/findNext() return objects straight from fromRow() without
        // hydrating terms — flag it so a caller that renders this object as a
        // page in its own right (not just for its title/slug) can catch that.
        $post->termsHydrated = false;
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
        $post->tooted_at          = $row['tooted_at']    ?? null;
        $post->mastodon_url       = $row['mastodon_url'] ?? null;
        $post->mastodon_status_id = $row['mastodon_status_id'] ?? null;
        $post->mastodon_skip = (int) ($row['mastodon_skip'] ?? 0);
        $post->bluesky_at    = $row['bluesky_at']   ?? null;
        $post->bluesky_url   = $row['bluesky_url']  ?? null;
        $post->bluesky_rkey  = $row['bluesky_rkey'] ?? null;
        $post->bluesky_skip  = (int) ($row['bluesky_skip']  ?? 0);
        $post->bluesky_verify_at = $row['bluesky_verify_at'] ?? null;
        $post->bluesky_stale = (int) ($row['bluesky_stale'] ?? 0);
        $post->pixelfed_at        = $row['pixelfed_at']        ?? null;
        $post->pixelfed_url       = $row['pixelfed_url']       ?? null;
        $post->pixelfed_status_id = $row['pixelfed_status_id'] ?? null;
        $post->pixelfed_skip      = (int) ($row['pixelfed_skip'] ?? 0);
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
     * A photo post written in the admin keeps its words in the excerpt — the
     * content holds only the picture, and plaintextFromMarkdown() strips
     * images, so reading the body would yield an empty string and syndicate a
     * blank status.
     *
     * Micropub has no caption property: a client sends the words as `content`
     * and the picture as `photo`, so a photo post created that way has an empty
     * excerpt and its words in the body. Falling back to the body picks those
     * up, and still returns '' for an admin photo post whose body is nothing
     * but the image.
     */
    public function noteText(): string
    {
        if ($this->isPhoto()) {
            $caption = trim((string) $this->excerpt);
            if ($caption !== '') {
                return $caption;
            }
        }

        return trim(self::plaintextFromMarkdown($this->content));
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

        foreach ($posts as $post) {
            $post->termsHydrated = true;
        }
    }

    /**
     * Load categories/tags/photos/contexts for $post if it isn't already
     * hydrated — e.g. an object returned by findPrev()/findNext(), which skip
     * hydration since their usual purpose (nav-link title/slug) doesn't need
     * it. Anything that renders $post as a page in its own right must call
     * this first, since photos/categories/tags/contexts feed the template.
     */
    public static function ensureHydrated(Database $db, self $post): void
    {
        if (!$post->termsHydrated) {
            self::hydrateManyTerms($db, [$post]);
        }
    }
}
