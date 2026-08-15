<?php

declare(strict_types=1);

namespace CMS;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

class Database
{
    private PDO $pdo;

    /**
     * Per-instance cache for settings rows.
     *
     * Deliberately not static: a static cache is shared by every Database in the
     * process, so a write through one instance is invisible to another. That is
     * harmless under PHP-FPM (one request, one instance) but wrong for
     * bin/build.php and anything long-running.
     *
     * Misses are not cached either — storing the caller's $default would pin it
     * for the rest of the request even after the row is created.
     *
     * @var array<string,string>
     */
    private array $settingsCache = [];

    /** Whether transaction() currently holds an open BEGIN on this connection. */
    private bool $inTransaction = false;

    // Increment this whenever the schema changes.
    private const SCHEMA_VERSION = 28;

    public function __construct(private string $dbPath)
    {
        try {
            $this->pdo = new PDO(
                'sqlite:' . $dbPath,
                null,
                null,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
            $this->pdo->exec('PRAGMA journal_mode=WAL');
            $this->pdo->exec('PRAGMA foreign_keys=ON');
            // Without this, a writer that meets a held lock (a build running
            // while the analytics beacon writes, say) raises SQLITE_BUSY
            // immediately instead of waiting for it to clear.
            $this->pdo->exec('PRAGMA busy_timeout=5000');
        } catch (PDOException $e) {
            throw new RuntimeException('Cannot open database: ' . $e->getMessage());
        }

        $this->restrictFilePermissions($dbPath);
        $this->migrate();
    }

    /**
     * Take group and other off the database file and its WAL sidecars.
     *
     * The file is created with 0644 under the usual umask, and it holds the TOTP
     * secret in plaintext along with the Mastodon, Bluesky and Pixelfed tokens.
     * config.php has been 0600 since bin/setup.php wrote it and data/analytics_salt
     * since track.php created it — the database was simply missed, and the only
     * thing standing in for it was an nginx deny rule, which is a web-layer
     * control over a filesystem-layer secret.
     *
     * The WAL and shared-memory files carry the same committed rows, so they are
     * chmod'd too; they may not exist yet, hence the file_exists() guard.
     *
     * Best-effort by design. A deploy where PHP does not own the file — the
     * database written by www-data and a CLI run as somebody else — makes chmod
     * fail, and that must not stop the CMS from opening a database it can
     * otherwise read. @ suppresses the warning that would otherwise reach output
     * on a public endpoint.
     */
    private function restrictFilePermissions(string $dbPath): void
    {
        foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $path) {
            if (!file_exists($path)) {
                continue;
            }
            // Skip when already tight, so the common case costs one stat and no
            // syscall, and a deliberately stricter mode (0400) is left alone.
            $mode = @fileperms($path);
            if ($mode !== false && ($mode & 0o077) === 0) {
                continue;
            }
            @chmod($path, 0o600);
        }
    }

    /**
     * The directory holding the database, which is also where its siblings live
     * — the analytics salt, the scheduler lock. Saves passing $config around
     * just to rederive a path this object already knows.
     */
    public function dataDir(): string
    {
        return dirname($this->dbPath);
    }

    // ── Query helpers ─────────────────────────────────────────────────────────

    /**
     * Run a SELECT and return all rows.
     *
     * @param array<mixed> $params
     * @return array<array<string,mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        $stmt = $this->run($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Run a SELECT and return a single row (or null).
     *
     * @param array<mixed> $params
     * @return array<string,mixed>|null
     */
    public function selectOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->run($sql, $params);
        $row  = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Execute an INSERT and return the last insert ID.
     *
     * @param array<string,mixed> $data  column => value
     */
    public function insert(string $table, array $data): int
    {
        $cols        = array_keys($data);
        $placeholders = implode(', ', array_map(fn($c) => ':' . $c, $cols));
        $colList     = implode(', ', $cols);

        $this->run(
            "INSERT INTO {$table} ({$colList}) VALUES ({$placeholders})",
            $data
        );

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Execute an UPDATE. $where is a raw SQL snippet (e.g. "id = :id").
     * Merge $data and $whereParams so all bindings go into one execute().
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $whereParams
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = implode(', ', array_map(fn($c) => "{$c} = :{$c}", array_keys($data)));

        $stmt = $this->run(
            "UPDATE {$table} SET {$sets} WHERE {$where}",
            array_merge($data, $whereParams)
        );

        return $stmt->rowCount();
    }

    /**
     * Execute a DELETE. $where is a raw SQL snippet.
     *
     * @param array<string,mixed> $params
     */
    public function delete(string $table, string $where, array $params = []): int
    {
        $stmt = $this->run("DELETE FROM {$table} WHERE {$where}", $params);
        return $stmt->rowCount();
    }

    /**
     * Execute arbitrary SQL (useful for CREATE TABLE, PRAGMA, etc.).
     *
     * @param array<mixed> $params
     */
    public function exec(string $sql, array $params = []): PDOStatement
    {
        return $this->run($sql, $params);
    }

    /**
     * Run $fn inside an immediate transaction, returning whatever it returns.
     *
     * `BEGIN IMMEDIATE` rather than PDO::beginTransaction(), which issues a
     * deferred `BEGIN`: under a deferred transaction SQLite takes no write lock
     * until the first write, so two connections can both read, both decide to
     * act on what they read, and only then collide — one of them getting
     * SQLITE_BUSY at commit having already made its decision. Taking the write
     * lock up front makes read-then-write sequences serialise properly, which
     * is exactly what Post::promoteScheduled() needs.
     *
     * The busy_timeout set in the constructor applies here, so a caller that
     * meets a held lock waits rather than failing instantly.
     *
     * Nested calls join the transaction already running instead of raising —
     * a second BEGIN is an error in SQLite — so the outermost caller owns the
     * commit.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        // Tracked here rather than through PDO::inTransaction(), which only
        // knows about transactions PDO itself began — it reports false after a
        // raw BEGIN, so relying on it made this guard a no-op and nesting threw
        // "cannot start a transaction within a transaction".
        if ($this->inTransaction) {
            return $fn();
        }

        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->inTransaction = true;

        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            $this->inTransaction = false;
            throw $e;
        }

        $this->pdo->exec('COMMIT');
        $this->inTransaction = false;

        return $result;
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /** @param array<mixed> $params */
    private function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    // ── Schema / migrations ───────────────────────────────────────────────────

    private function migrate(): void
    {
        // Ensure settings table exists first (stores schema_version).
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS settings (
                key        TEXT PRIMARY KEY,
                value      TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        SQL);

        $row     = $this->selectOne("SELECT value FROM settings WHERE key = 'schema_version'");
        $current = $row ? (int) $row['value'] : 0;

        for ($v = 1; $v <= self::SCHEMA_VERSION; $v++) {
            if ($current < $v) {
                $this->{'applySchemaV' . $v}();
            }
        }

        if ($current < self::SCHEMA_VERSION) {
            $this->upsertSetting('schema_version', (string) self::SCHEMA_VERSION);
        }
    }

    private function applySchemaV1(): void
    {
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS posts (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                title        TEXT    NOT NULL,
                slug         TEXT    UNIQUE NOT NULL,
                content      TEXT    NOT NULL,
                excerpt      TEXT,
                status       TEXT    NOT NULL DEFAULT 'draft',
                published_at DATETIME,
                created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
                built_at     DATETIME,
                content_hash TEXT
            )
        SQL);

        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS pages (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                title        TEXT    NOT NULL,
                slug         TEXT    UNIQUE NOT NULL,
                content      TEXT    NOT NULL,
                nav_order    INTEGER DEFAULT 0,
                status       TEXT    NOT NULL DEFAULT 'draft',
                created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
                built_at     DATETIME,
                content_hash TEXT
            )
        SQL);

        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS media (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                filename      TEXT    NOT NULL,
                original_name TEXT    NOT NULL,
                mime_type     TEXT    NOT NULL,
                size          INTEGER NOT NULL,
                uploaded_at   DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        SQL);

        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS login_attempts (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                ip           TEXT    NOT NULL,
                attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                success      INTEGER DEFAULT 0
            )
        SQL);

        // Seed default settings if they don't exist yet.
        $defaults = [
            'site_title'       => 'My CMS',
            'site_description' => '',
            'site_url'         => '',
            'posts_per_page'   => '10',
            'feed_post_count'  => '20',
            'footer_text'      => '',
        ];
        $stmt = $this->pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (:key, :value)');
        foreach ($defaults as $key => $value) {
            $stmt->execute([':key' => $key, ':value' => $value]);
        }
    }

    private function applySchemaV2(): void
    {
        // Add tooted_at column to track Mastodon syndication (NULL = not yet tooted).
        $this->pdo->exec("ALTER TABLE posts ADD COLUMN tooted_at DATETIME");
    }

    private function applySchemaV3(): void
    {
        // 0 = send to Mastodon (default), 1 = user opted out for this post.
        $this->pdo->exec("ALTER TABLE posts ADD COLUMN mastodon_skip INTEGER NOT NULL DEFAULT 0");
    }

    private function applySchemaV4(): void
    {
        // Index makes the rate-limiting COUNT(*) query efficient as the table grows.
        $this->pdo->exec(
            "CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_time
             ON login_attempts(ip, attempted_at)"
        );
    }

    private function applySchemaV5(): void
    {
        // Hash of (site_title | post_title) used when the OG image was last generated.
        // NULL means no OG image has been generated yet.
        $this->pdo->exec("ALTER TABLE posts ADD COLUMN og_image_hash TEXT");
    }

    private function applySchemaV6(): void
    {
        // Track Bluesky crossposting — mirrors tooted_at / mastodon_skip.
        $this->pdo->exec("ALTER TABLE posts ADD COLUMN bluesky_at   DATETIME");
        $this->pdo->exec("ALTER TABLE posts ADD COLUMN bluesky_skip INTEGER NOT NULL DEFAULT 0");
    }

    private function applySchemaV7(): void
    {
        // Store the canonical URL of the remote post after syndication,
        // so it can be linked from the public post page.
        $this->pdo->exec("ALTER TABLE posts ADD COLUMN mastodon_url TEXT");
        $this->pdo->exec("ALTER TABLE posts ADD COLUMN bluesky_url  TEXT");
    }

    private function applySchemaV8(): void
    {
        // Track when outgoing webmentions were last sent for a post.
        // NULL = never sent; re-send when updated_at > webmentions_sent_at.
        $this->pdo->exec("ALTER TABLE posts ADD COLUMN webmentions_sent_at DATETIME");
    }

    private function applySchemaV9(): void
    {
        // Categories (hierarchical taxonomy) and tags (flat taxonomy) with junction tables.
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS categories (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT    NOT NULL,
                slug        TEXT    UNIQUE NOT NULL,
                description TEXT    NOT NULL DEFAULT '',
                created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        SQL);

        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS tags (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT NOT NULL,
                slug       TEXT UNIQUE NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        SQL);

        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS post_categories (
                post_id     INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
                category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
                PRIMARY KEY (post_id, category_id)
            )
        SQL);

        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS post_tags (
                post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
                tag_id  INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
                PRIMARY KEY (post_id, tag_id)
            )
        SQL);
    }

    private function applySchemaV10(): void
    {
        // Admin activity log — tracks content and settings changes.
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS activity_log (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                action      TEXT    NOT NULL,
                object_type TEXT    NOT NULL,
                object_id   INTEGER,
                detail      TEXT    NOT NULL DEFAULT '',
                ip          TEXT    NOT NULL DEFAULT '',
                created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        SQL);
    }

    private function applySchemaV11(): void
    {
        // Hashed TOTP backup/recovery codes for 2FA.
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS totp_backup_codes (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                code_hash  TEXT    NOT NULL,
                used_at    DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        SQL);
    }

    private function applySchemaV12(): void
    {
        // Passkeys (WebAuthn credentials) for passwordless login.
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS passkeys (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                credential_id TEXT    NOT NULL UNIQUE,
                public_key    TEXT    NOT NULL,
                sign_count    INTEGER NOT NULL DEFAULT 0,
                name          TEXT    NOT NULL DEFAULT 'Passkey',
                created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_used_at  DATETIME
            )
        SQL);
    }

    private function applySchemaV13(): void
    {
        // Page views for self-hosted analytics.
        $this->pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS page_views (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                url         TEXT    NOT NULL,
                referrer    TEXT,
                device_type TEXT    NOT NULL DEFAULT 'desktop',
                is_404      INTEGER NOT NULL DEFAULT 0,
                ip_hash     TEXT,
                timestamp   INTEGER NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_pv_timestamp ON page_views(timestamp);
            CREATE INDEX IF NOT EXISTS idx_pv_url       ON page_views(url);
        SQL);
    }

    private function applySchemaV14(): void
    {
        // Indexes for common query patterns missing from the original schema.
        // posts.slug and category/tag slugs are already indexed via their UNIQUE constraints.
        $this->run(
            "CREATE INDEX IF NOT EXISTS idx_posts_published_at ON posts(published_at)"
        );
        $this->run(
            "CREATE INDEX IF NOT EXISTS idx_posts_status ON posts(status)"
        );
        // Junction tables have composite PKs (post_id, *_id); add explicit
        // single-column indexes so WHERE post_id = ? scans are index-only.
        $this->run(
            "CREATE INDEX IF NOT EXISTS idx_post_categories_post_id ON post_categories(post_id)"
        );
        $this->run(
            "CREATE INDEX IF NOT EXISTS idx_post_tags_post_id ON post_tags(post_id)"
        );
    }

    private function applySchemaV15(): void
    {
        // Retired: newsletter_subscribers table. Feature was removed before any
        // prod deploy. Slot kept so SCHEMA_VERSION stays monotonic.
    }

    private function applySchemaV16(): void
    {
        // parent_id: optional self-reference for one-level nav nesting.
        // No FK clause: SQLite ALTER TABLE can't add one, and parent-with-children
        // deletion is blocked in the admin layer where the user-facing error matters.
        $this->run("ALTER TABLE pages ADD COLUMN parent_id INTEGER");
        $this->run("CREATE INDEX IF NOT EXISTS idx_pages_parent_id ON pages(parent_id)");
    }

    private function applySchemaV17(): void
    {
        // post_kind: WordPress-style post format. Currently 'standard' or 'aside'.
        // Aside = titleless note: rendered with body+date on lists, no h1 on single,
        // slug stores the autoincrement id, feed entries omit <title>.
        $this->run("ALTER TABLE posts ADD COLUMN post_kind TEXT NOT NULL DEFAULT 'standard'");
        $this->run("CREATE INDEX IF NOT EXISTS idx_posts_post_kind ON posts(post_kind)");
    }

    private function applySchemaV18(): void
    {
        // import_guid: WXR <guid> from imported posts; used to dedup re-imports.
        $this->run("ALTER TABLE posts ADD COLUMN import_guid TEXT");
        $this->run("CREATE INDEX IF NOT EXISTS idx_posts_import_guid ON posts(import_guid)");
    }

    private function applySchemaV19(): void
    {
        // source_url: external URL a media item was downloaded from. Lets the
        // image-rehosting tool dedup across runs (same URL referenced from many
        // posts is fetched once) and supports safe re-runs after partial failures.
        $this->run("ALTER TABLE media ADD COLUMN source_url TEXT");
        $this->run("CREATE INDEX IF NOT EXISTS idx_media_source_url ON media(source_url)");
    }

    private function applySchemaV20(): void
    {
        // deleted_at: soft-delete marker for Micropub delete/undelete. NULL = live.
        // Micropub delete sets it; undelete clears it; admin can purge permanently.
        $this->run("ALTER TABLE posts ADD COLUMN deleted_at DATETIME");
        $this->run("CREATE INDEX IF NOT EXISTS idx_posts_deleted_at ON posts(deleted_at)");
    }

    private function applySchemaV21(): void
    {
        // post_photos: first-class Micropub photo property (u-photo), one row per
        // photo with optional alt text. media_id links uploads to the media table;
        // NULL for external URLs stored as-is.
        $this->run(<<<SQL
            CREATE TABLE IF NOT EXISTS post_photos (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id    INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
                url        TEXT    NOT NULL,
                alt        TEXT    NOT NULL DEFAULT '',
                sort_order INTEGER NOT NULL DEFAULT 0,
                media_id   INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        SQL);
        $this->run("CREATE INDEX IF NOT EXISTS idx_post_photos_post_id ON post_photos(post_id)");
    }

    private function applySchemaV22(): void
    {
        // IndieAuth server storage. Only sha256 hashes of codes/tokens are stored;
        // the plaintext is shown to the client once and never persisted.
        $this->run(<<<SQL
            CREATE TABLE IF NOT EXISTS indieauth_codes (
                id                    INTEGER PRIMARY KEY AUTOINCREMENT,
                code_hash             TEXT    NOT NULL UNIQUE,
                client_id             TEXT    NOT NULL,
                client_name           TEXT    NOT NULL DEFAULT '',
                redirect_uri          TEXT    NOT NULL,
                me                    TEXT    NOT NULL,
                scope                 TEXT    NOT NULL DEFAULT '',
                code_challenge        TEXT    NOT NULL DEFAULT '',
                code_challenge_method TEXT    NOT NULL DEFAULT '',
                created_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
                expires_at            DATETIME NOT NULL,
                used_at               DATETIME
            )
        SQL);
        $this->run(<<<SQL
            CREATE TABLE IF NOT EXISTS indieauth_tokens (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                token_hash   TEXT    NOT NULL UNIQUE,
                client_id    TEXT    NOT NULL,
                client_name  TEXT    NOT NULL DEFAULT '',
                me           TEXT    NOT NULL,
                scope        TEXT    NOT NULL,
                created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
                last_used_at DATETIME,
                expires_at   DATETIME,
                revoked_at   DATETIME
            )
        SQL);
        $this->run("CREATE INDEX IF NOT EXISTS idx_indieauth_tokens_revoked ON indieauth_tokens(revoked_at)");
    }

    private function applySchemaV23(): void
    {
        // post_contexts: Micropub interaction targets (in-reply-to / like-of /
        // repost-of / bookmark-of), one row per target URL.
        $this->run(<<<SQL
            CREATE TABLE IF NOT EXISTS post_contexts (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id    INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
                kind       TEXT    NOT NULL,
                url        TEXT    NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        SQL);
        $this->run("CREATE INDEX IF NOT EXISTS idx_post_contexts_post_id ON post_contexts(post_id)");
    }

    private function applySchemaV24(): void
    {
        // login_attempts.scope: which authentication surface recorded the
        // attempt. Previously every surface (admin form, TOTP step, passkey,
        // Micropub bearer, REST Basic auth, XML-RPC) counted into one row keyed
        // only on IP, so a misconfigured Micropub client could lock the human
        // out of /admin/. Each surface now rate-limits independently.
        //
        // Existing rows default to 'admin', which is the conservative reading:
        // they keep gating the admin login exactly as they did before.
        $this->run("ALTER TABLE login_attempts ADD COLUMN scope TEXT NOT NULL DEFAULT 'admin'");
        $this->run(
            "CREATE INDEX IF NOT EXISTS idx_login_attempts_scope_ip_time
             ON login_attempts(scope, ip, attempted_at)"
        );

        // Fold the old string-prefix convention into the new column so there is
        // one mechanism rather than two. 'user:' rows are dropped outright: the
        // username-keyed counter let anyone lock the account out from every IP
        // at once, and is no longer consulted.
        $this->run("UPDATE login_attempts SET scope = 'totp', ip = substr(ip, 6) WHERE ip LIKE 'totp:%'");
        $this->run("DELETE FROM login_attempts WHERE ip LIKE 'user:%'");
    }

    private function applySchemaV25(): void
    {
        // The handles the Mastodon and Bluesky APIs address a syndicated copy
        // by, so an edit or a delete here can reach it. The stored URLs are not
        // enough: they are hand-editable in the post form, and their shape
        // belongs to whichever instance published them.
        $this->run("ALTER TABLE posts ADD COLUMN mastodon_status_id TEXT");
        $this->run("ALTER TABLE posts ADD COLUMN bluesky_rkey       TEXT");

        // Posts syndicated before the columns existed still have their URLs, and
        // both ids are the last path segment of one. Anything that doesn't look
        // like an id is left NULL — an unrecognised URL means the copy is simply
        // not reachable from here, which is what a NULL already says.
        $rows = $this->select(
            "SELECT id, mastodon_url, bluesky_url FROM posts
              WHERE mastodon_url IS NOT NULL OR bluesky_url IS NOT NULL"
        );
        foreach ($rows as $row) {
            $this->run(
                "UPDATE posts SET mastodon_status_id = :sid, bluesky_rkey = :rkey WHERE id = :id",
                [
                    'sid'  => Helpers::mastodonStatusId((string) ($row['mastodon_url'] ?? '')),
                    'rkey' => Helpers::blueskyRkey((string) ($row['bluesky_url'] ?? '')),
                    'id'   => $row['id'],
                ]
            );
        }
    }

    private function applySchemaV26(): void
    {
        // idx_posts_published_at and idx_posts_status are separate indexes and
        // SQLite can only use one per table reference, so every query filtering
        // on status and ordering by date fell back to sorting the whole table:
        //
        //   SEARCH posts USING INDEX idx_posts_status (status=?)
        //   USE TEMP B-TREE FOR ORDER BY
        //
        // Post::findPrev()/findNext() run twice per post build, so a full
        // rebuild paid that sort ~1,400 times. The composite covers both.
        $this->run("CREATE INDEX IF NOT EXISTS idx_posts_status_pub ON posts(status, published_at)");

        // The junction tables were only indexed by post_id, which serves
        // hydration but not Post::findByCategory()/findByTag() — those look up
        // by term and had to scan. Ordering the columns term-first makes the
        // index cover the lookup and the rowid join together.
        $this->run("CREATE INDEX IF NOT EXISTS idx_post_categories_cat ON post_categories(category_id, post_id)");
        $this->run("CREATE INDEX IF NOT EXISTS idx_post_tags_tag       ON post_tags(tag_id, post_id)");

        // The analytics HMAC salt used to be minted by whichever unauthenticated
        // beacon request arrived first, via INSERT OR REPLACE — two concurrent
        // first requests could each generate one, and the loser's already-stored
        // ip_hash values became unlinkable. Seeding it here makes it exist
        // before any beacon can run. Existing installs keep the salt they have.
        $row = $this->selectOne("SELECT value FROM settings WHERE key = 'analytics_salt'");
        if ($row === null || (string) $row['value'] === '') {
            $this->upsertSetting('analytics_salt', bin2hex(random_bytes(32)));
        }
    }

    private function applySchemaV27(): void
    {
        // The legacy Micropub shared token was stored verbatim while IndieAuth
        // tokens were already hashed. Rewrite it in place so existing clients
        // keep working — they present the same token, only the stored form
        // changes — without the plaintext surviving in the settings table.
        $row    = $this->selectOne("SELECT value FROM settings WHERE key = 'micropub_token'");
        $stored = (string) ($row['value'] ?? '');

        if ($stored !== '' && !str_starts_with($stored, MicropubAuth::LEGACY_TOKEN_PREFIX)) {
            $this->upsertSetting('micropub_token', MicropubAuth::hashLegacyToken($stored));
        }
    }

    private function applySchemaV28(): void
    {
        // Track Pixelfed crossposting — mirrors the Mastodon columns exactly,
        // because Pixelfed speaks the Mastodon API and a copy there is
        // addressed the same way: a status id to edit or delete by, and a
        // canonical URL for the post page to link to.
        $this->pdo->exec("ALTER TABLE posts ADD COLUMN pixelfed_at        DATETIME");
        $this->pdo->exec("ALTER TABLE posts ADD COLUMN pixelfed_url       TEXT");
        $this->pdo->exec("ALTER TABLE posts ADD COLUMN pixelfed_status_id TEXT");
        $this->pdo->exec("ALTER TABLE posts ADD COLUMN pixelfed_skip      INTEGER NOT NULL DEFAULT 0");
    }

    /** Insert or update a single settings row. */
    public function upsertSetting(string $key, string $value): void
    {
        $this->run(
            "INSERT INTO settings (key, value, updated_at)
             VALUES (:key, :value, CURRENT_TIMESTAMP)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
            ['key' => $key, 'value' => $value]
        );
        $this->settingsCache[$key] = $value;
    }

    /** Retrieve a single setting value (or $default if not found). */
    public function getSetting(string $key, string $default = ''): string
    {
        if (array_key_exists($key, $this->settingsCache)) {
            return $this->settingsCache[$key];
        }

        $row = $this->selectOne("SELECT value FROM settings WHERE key = :key", ['key' => $key]);
        if ($row === null) {
            // Absent row: return the caller's default without caching it, so a
            // later upsert of this key is picked up rather than shadowed.
            return $default;
        }

        return $this->settingsCache[$key] = (string) $row['value'];
    }

    /** Retrieve all settings as key => value array. */
    public function getAllSettings(): array
    {
        $rows = array_column($this->select("SELECT key, value FROM settings"), 'value', 'key');
        $this->settingsCache = array_merge($this->settingsCache, $rows);
        return $rows;
    }
}
