<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Builder;
use CMS\Database;
use CMS\Post;
use PHPUnit\Framework\TestCase;

/**
 * Teardown of a post's generated output.
 *
 * A published post writes two files into its own directory — index.html and
 * the og.png from buildOgImage(). Unpublishing used to remove only the first,
 * so the image was orphaned and its directory survived forever (removeFile()
 * prunes an empty directory, and og.png kept it non-empty). These tests pin
 * the invariant that matters: nothing generated for a post outlives it.
 */
final class BuilderOutputTest extends TestCase
{
    private Database $db;
    private string $dbPath;
    private string $root;
    private Builder $builder;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'clodd_test_') . '.db';
        $this->db     = new Database($this->dbPath);

        // realpath() the temp dir: isInsideOutputDir() resolves the output root
        // but normalises the candidate path lexically, so a symlinked root (macOS
        // /var/folders -> /private/var/folders) fails the comparison.
        $this->root = realpath(sys_get_temp_dir()) . '/clodd_out_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/output', 0775, true);

        $this->builder = new Builder([
            'paths' => [
                'output'    => $this->root . '/output',
                'templates' => $this->root . '/templates',
                'content'   => $this->root . '/content',
            ],
        ], $this->db);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->root);

        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }

    // ── The regression ────────────────────────────────────────────────────────

    public function testUnpublishingRemovesTheOgImageAndPrunesTheDirectory(): void
    {
        $post = $this->makePublishedPost('leaving');
        $dir  = $this->root . '/output/posts/2026/07/29/leaving';
        $this->seedOutput($dir);

        $post->status = 'draft';
        $this->builder->buildPost($post);

        $this->assertDirectoryDoesNotExist($dir, 'unpublishing must leave nothing behind');
    }

    public function testSoftDeleteRemovesTheOgImageAndPrunesTheDirectory(): void
    {
        $post = $this->makePublishedPost('deleted');
        $dir  = $this->root . '/output/posts/2026/07/29/deleted';
        $this->seedOutput($dir);

        // A soft-deleted post stays 'published' — deleted_at is the signal.
        $post->deleted_at = '2026-07-30 09:00:00';
        $this->builder->buildPost($post);

        $this->assertDirectoryDoesNotExist($dir);
    }

    /**
     * The directory is only pruned once it is genuinely empty, so an og.png
     * left by an older build must not block cleanup of a later one.
     */
    public function testAnOrphanedOgImageFromAnEarlierBuildIsStillCollected(): void
    {
        $post = $this->makePublishedPost('orphan');
        $dir  = $this->root . '/output/posts/2026/07/29/orphan';
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/og.png', 'PNG');   // no index.html

        $post->status = 'draft';
        $this->builder->buildPost($post);

        $this->assertDirectoryDoesNotExist($dir);
    }

    // ── removePostOutput as a unit ────────────────────────────────────────────

    public function testRemovePostOutputClearsBothGeneratedFiles(): void
    {
        $dir = $this->root . '/output/posts/2026/07/29/direct';
        $this->seedOutput($dir);

        $this->builder->removePostOutput($dir);

        $this->assertFileDoesNotExist($dir . '/index.html');
        $this->assertFileDoesNotExist($dir . '/og.png');
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testRemovePostOutputLeavesUnrelatedSiblingsAlone(): void
    {
        $dir = $this->root . '/output/posts/2026/07/29/keeps-extras';
        $this->seedOutput($dir);
        file_put_contents($dir . '/notes.txt', 'hand-placed');

        $this->builder->removePostOutput($dir);

        $this->assertFileExists($dir . '/notes.txt');
        $this->assertDirectoryExists($dir, 'a non-empty directory must survive');
    }

    /**
     * removePostOutput() is public and takes a path, so the containment guard
     * inside removeFile() is the thing standing between a caller-supplied
     * directory and the rest of the filesystem.
     */
    public function testRemovePostOutputRefusesToEscapeTheOutputRoot(): void
    {
        $outside = $this->root . '/outside';
        mkdir($outside, 0775, true);
        file_put_contents($outside . '/index.html', 'not ours');
        file_put_contents($outside . '/og.png', 'not ours');

        $this->builder->removePostOutput($this->root . '/output/posts/../../outside');

        $this->assertFileExists($outside . '/index.html');
        $this->assertFileExists($outside . '/og.png');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Write the pair of files a published build produces. */
    private function seedOutput(string $dir): void
    {
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/index.html', '<html></html>');
        file_put_contents($dir . '/og.png', 'PNG');
    }

    private function makePublishedPost(string $slug): Post
    {
        $post = new Post($this->db);
        $post->title        = 'T';
        $post->slug         = $slug;
        $post->content      = 'x';
        $post->status       = 'published';
        $post->published_at = '2026-07-29 12:00:00';
        $post->save();

        return $post;
    }

    private function rmTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->rmTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
