<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Database;
use CMS\Post;
use PHPUnit\Framework\TestCase;

/**
 * A throwaway database and output tree, per test.
 *
 * Nineteen test classes wrote this themselves. rmTree() existed five times in
 * two dialects, the temp-DB teardown loop twelve times, the $config['paths']
 * literal five times, and a makePost() factory seven times —
 * RelatedPostsTest even carried the comment "See BuilderOutputTest", which is
 * the tell that a thing wants to be in one place.
 *
 * The schema is migrated once per process and copied per test rather than being
 * rebuilt from scratch 248 times. Measured: 6.65 ms → 2.57 ms per database.
 */
abstract class TempSiteTestCase extends TestCase
{
    protected Database $db;
    protected string $dbPath;

    /** Root of the throwaway site: $root/output, $root/templates, $root/content. */
    protected string $root;

    /** @var array{paths:array{output:string,templates:string,content:string,data:string}} */
    protected array $config;

    /** Migrated once per PHPUnit process, copied per test. */
    private static ?string $schemaTemplate = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbPath = tempnam(sys_get_temp_dir(), 'clodd_test_') . '.db';
        copy(self::schemaTemplate(), $this->dbPath);
        $this->db = new Database($this->dbPath);

        // realpath(), because macOS hands out /var/folders/... which is a symlink
        // to /private/var/folders/... — and Builder refuses to write outside an
        // output root it resolved itself, so the two spellings must match.
        $this->root = realpath(sys_get_temp_dir()) . '/clodd_out_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/output', 0775, true);
        mkdir($this->root . '/templates', 0775, true);

        $this->config = ['paths' => [
            'output'    => $this->root . '/output',
            'templates' => $this->root . '/templates',
            'content'   => $this->root . '/content',
            'data'      => $this->root,
        ]];
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->root);

        // The sidecars carry committed rows and outlive an unclean close.
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }

        parent::tearDown();
    }

    /** Write a stub template. Most tests need only enough of one to render. */
    protected function stubTemplate(string $name, string $php): void
    {
        file_put_contents($this->root . '/templates/' . $name, $php);
    }

    /** Absolute path of a file in the output tree. */
    protected function outputPath(string $relative): string
    {
        return $this->root . '/output/' . ltrim($relative, '/');
    }

    /**
     * A saved, published, titled post at a given slug and time.
     *
     * Deliberately not called makePost(): four classes already have a factory
     * of that name taking the *body* first, because what they vary is the
     * content. Those are a different helper for a different question, and
     * flattening them into this one would make every call site read worse.
     */
    protected function publishedPost(
        string $slug,
        ?string $publishedAt = '2026-01-01 09:00:00',
        string $status = 'published',
        ?string $title = null,
        string $content = '',
        string $postKind = 'standard',
    ): Post {
        $post               = new Post($this->db);
        $post->title        = $title ?? ucfirst(str_replace('-', ' ', $slug));
        $post->slug         = $slug;
        $post->content      = $content !== '' ? $content : 'Body of ' . $slug . '.';
        $post->status       = $status;
        $post->published_at = $publishedAt;
        $post->post_kind    = $postKind;
        $post->save();

        return $post;
    }

    protected function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * Build the schema once, then hand out copies.
     *
     * Registered for deletion at process exit rather than in tearDown(), since
     * every later test in the run still needs it.
     */
    private static function schemaTemplate(): string
    {
        if (self::$schemaTemplate !== null) {
            return self::$schemaTemplate;
        }

        $path = tempnam(sys_get_temp_dir(), 'clodd_schema_') . '.db';
        new Database($path);   // migrate() builds the whole schema here

        register_shutdown_function(static function () use ($path): void {
            foreach ([$path, $path . '-wal', $path . '-shm'] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
        });

        return self::$schemaTemplate = $path;
    }
}
