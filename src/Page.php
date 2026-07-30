<?php

declare(strict_types=1);

namespace CMS;

class Page
{
    public ?int    $id           = null;
    public string  $title        = '';
    public string  $slug         = '';
    public string  $content      = '';
    public int     $nav_order    = 0;
    public string  $status       = 'draft';
    public string  $created_at   = '';
    public string  $updated_at   = '';
    public ?string $built_at     = null;
    public ?string $content_hash = null;
    public ?int    $parent_id    = null;

    /** @var self[] In-memory only; populated by Builder::refreshContext for nav rendering. */
    public array $children = [];

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ── Finders ───────────────────────────────────────────────────────────────

    /**
     * Return all pages, optionally filtered by status.
     * Ordered by nav_order ASC (0 sorts last), then title ASC.
     *
     * @return self[]
     */
    public static function findAll(Database $db, ?string $status = null): array
    {
        $order  = "ORDER BY CASE WHEN nav_order = 0 THEN 1 ELSE 0 END ASC, nav_order ASC, title ASC";
        $sql    = "SELECT * FROM pages" . ($status !== null ? " WHERE status = :status" : "") . " $order";
        $params = $status !== null ? ['status' => $status] : [];
        return array_map(fn($row) => self::fromRow($db, $row), $db->select($sql, $params));
    }

    public static function findById(Database $db, int $id): ?self
    {
        $row = $db->selectOne("SELECT * FROM pages WHERE id = :id", ['id' => $id]);
        return $row ? self::fromRow($db, $row) : null;
    }

    public static function findBySlug(Database $db, string $slug): ?self
    {
        $row = $db->selectOne("SELECT * FROM pages WHERE slug = :slug", ['slug' => $slug]);
        return $row ? self::fromRow($db, $row) : null;
    }

    /**
     * Slugify $base and return a page slug that is not already taken,
     * appending -2, -3, … on collision. Mirrors Post::resolveUniqueSlug().
     *
     * Always slugifies: page slugs become filesystem paths under /pages/, so
     * unsanitised input here is a path-traversal vector.
     *
     * Pass the id of the page being saved as $excludeId so it doesn't collide
     * with itself.
     */
    public static function resolveUniqueSlug(Database $db, string $base, ?int $excludeId = null): string
    {
        // Check before slugifying: Helpers::slugify() renders empty input as the
        // literal 'untitled', which admin validation rejects as a slug.
        $base = trim($base) === '' ? 'page' : Helpers::slugify($base);

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

    /**
     * Children of $parentId, optionally filtered by status. Same ordering as findAll.
     *
     * @return self[]
     */
    public static function findChildren(Database $db, int $parentId, ?string $status = null): array
    {
        $order  = "ORDER BY CASE WHEN nav_order = 0 THEN 1 ELSE 0 END ASC, nav_order ASC, title ASC";
        $sql    = "SELECT * FROM pages WHERE parent_id = :pid"
                . ($status !== null ? " AND status = :status" : "")
                . " $order";
        $params = ['pid' => $parentId];
        if ($status !== null) {
            $params['status'] = $status;
        }
        return array_map(fn($row) => self::fromRow($db, $row), $db->select($sql, $params));
    }

    public static function hasChildren(Database $db, int $id): bool
    {
        $row = $db->selectOne("SELECT 1 FROM pages WHERE parent_id = :pid LIMIT 1", ['pid' => $id]);
        return $row !== null;
    }

    // ── Persistence ───────────────────────────────────────────────────────────

    public function save(): bool
    {
        $data = [
            'title'     => $this->title,
            'slug'      => $this->slug,
            'content'   => $this->content,
            'nav_order' => $this->nav_order,
            'status'    => $this->status,
            'parent_id' => $this->parent_id,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($this->id === null) {
            $this->id = $this->db->insert('pages', $data);
            return $this->id > 0;
        }

        $affected = $this->db->update('pages', $data, 'id = :id', ['id' => $this->id]);
        return $affected >= 0;
    }

    public function delete(): bool
    {
        if ($this->id === null) {
            return false;
        }
        return $this->db->delete('pages', 'id = :id', ['id' => $this->id]) > 0;
    }

    // ── Build helpers ─────────────────────────────────────────────────────────

    public function markBuilt(string $contentHash): void
    {
        $now              = date('Y-m-d H:i:s');
        $this->built_at   = $now;
        $this->content_hash = $contentHash;

        $this->db->update(
            'pages',
            ['built_at' => $now, 'content_hash' => $contentHash],
            'id = :id',
            ['id' => $this->id]
        );
    }

    public function needsRebuild(): bool
    {
        if ($this->built_at === null || $this->content_hash === null) {
            return true;
        }
        return strtotime($this->updated_at) > strtotime($this->built_at);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function fromRow(Database $db, array $row): self
    {
        $page               = new self($db);
        $page->id           = (int) $row['id'];
        $page->title        = $row['title'];
        $page->slug         = $row['slug'];
        $page->content      = $row['content'];
        $page->nav_order    = (int) ($row['nav_order'] ?? 0);
        $page->status       = $row['status'];
        $page->created_at   = $row['created_at'] ?? '';
        $page->updated_at   = $row['updated_at'] ?? '';
        $page->built_at     = $row['built_at'] ?? null;
        $page->content_hash = $row['content_hash'] ?? null;
        $page->parent_id    = isset($row['parent_id']) && $row['parent_id'] !== null
            ? (int) $row['parent_id']
            : null;
        return $page;
    }
}
