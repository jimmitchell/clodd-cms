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
    /** @var array{paths: array{output: string, templates: string, content: string}} */
    private array $config;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'clodd_test_') . '.db';
        $this->db     = new Database($this->dbPath);

        // realpath() the temp dir: isInsideOutputDir() resolves the output root
        // but normalises the candidate path lexically, so a symlinked root (macOS
        // /var/folders -> /private/var/folders) fails the comparison.
        $this->root = realpath(sys_get_temp_dir()) . '/clodd_out_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/output', 0775, true);

        $this->config = [
            'paths' => [
                'output'    => $this->root . '/output',
                'templates' => $this->root . '/templates',
                'content'   => $this->root . '/content',
            ],
        ];

        $this->builder = new Builder($this->config, $this->db);
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

    // ── Moving a post: the directory it vacates ───────────────────────────────

    public function testPostOutputDirIsNullWithoutAPublicationDate(): void
    {
        $this->assertNull($this->builder->postOutputDir(null, 'some-slug'));
        $this->assertNull($this->builder->postOutputDir('2026-07-29 12:00:00', ''));
    }

    public function testRenamingAPublishedPostClearsTheOldDirectory(): void
    {
        $post   = $this->makePublishedPost('before');
        $oldDir = $this->builder->postOutputDir($post->published_at, $post->slug);
        $this->seedOutput($oldDir);

        $post->slug = 'after';
        $this->builder->removeVacatedPostOutput($oldDir, $post);

        $this->assertDirectoryDoesNotExist($oldDir, 'the old URL must stop serving');
    }

    public function testChangingThePublicationDateClearsTheOldDirectory(): void
    {
        $post   = $this->makePublishedPost('moved');
        $oldDir = $this->builder->postOutputDir($post->published_at, $post->slug);
        $this->seedOutput($oldDir);

        $post->published_at = '2026-08-15 12:00:00';
        $this->builder->removeVacatedPostOutput($oldDir, $post);

        $this->assertDirectoryDoesNotExist($oldDir);
    }

    /**
     * buildPost() derives the directory to clean from the post's *current*
     * values, so an unpublish that also renames would otherwise clean a path
     * that was never written and leave the live one behind.
     */
    public function testUnpublishingAndRenamingInOneSaveStillClearsTheOldDirectory(): void
    {
        $post   = $this->makePublishedPost('both-at-once');
        $oldDir = $this->builder->postOutputDir($post->published_at, $post->slug);
        $this->seedOutput($oldDir);

        $post->slug   = 'renamed';
        $post->status = 'draft';
        $this->builder->removeVacatedPostOutput($oldDir, $post);

        $this->assertDirectoryDoesNotExist($oldDir);
    }

    /**
     * Re-scheduling a post that is already live is an unpublish in disguise:
     * the date moves into the future *and* the post leaves the site. The
     * directory it vacates has to go, and the future-dated one it now points
     * at must not be written — publishing it early is the failure mode.
     */
    public function testReSchedulingALivePostTakesItOffTheSite(): void
    {
        $post   = $this->makePublishedPost('back-in-the-oven');
        $oldDir = $this->builder->postOutputDir($post->published_at, $post->slug);
        $this->seedOutput($oldDir);

        $post->published_at = '2027-01-01 09:00:00';
        $post->status       = 'scheduled';
        $newDir             = $this->builder->postOutputDir($post->published_at, $post->slug);

        $this->builder->removeVacatedPostOutput($oldDir, $post);
        $this->builder->buildPost($post);

        $this->assertDirectoryDoesNotExist($oldDir, 'the live URL must stop serving');
        $this->assertDirectoryDoesNotExist($newDir, 'a scheduled post must not be written');
    }

    public function testAPostThatHasNotMovedKeepsItsOutput(): void
    {
        $post   = $this->makePublishedPost('staying');
        $oldDir = $this->builder->postOutputDir($post->published_at, $post->slug);
        $this->seedOutput($oldDir);

        // Saved with no change to slug or date — an edit to the body alone.
        $this->builder->removeVacatedPostOutput($oldDir, $post);

        $this->assertFileExists($oldDir . '/index.html', 'an unmoved post must not lose its output');
        $this->assertFileExists($oldDir . '/og.png');
    }

    /**
     * A create path has no previous location and must not attempt a removal at
     * all. Asserting on the filesystem alone would not catch a regression here:
     * removeFile()'s containment check quietly absorbs a bad path, so the only
     * evidence would be spurious "Refusing to delete" lines in the error log on
     * every new post. Spy on the call instead.
     */
    public function testANewPostAttemptsNoRemovalAtAll(): void
    {
        $spy = new class($this->config, $this->db) extends \CMS\Builder {
            /** @var list<string> */
            public array $removed = [];

            public function removePostOutput(string $dir): void
            {
                $this->removed[] = $dir;
            }
        };

        $post = $this->makePublishedPost('brand-new');
        $spy->removeVacatedPostOutput(null, $post);

        $this->assertSame([], $spy->removed);
    }

    // ── The build-skip guard ──────────────────────────────────────────────────

    /**
     * The write is skipped when the rendered hash matches the stored one, so a
     * post whose file has gone missing since that hash was recorded must not be
     * left unwritten. Unpublishing removes the file but not the hash, and the
     * publish date is pre-filled in the editor, so re-publishing unchanged
     * renders byte-identical HTML — the post would be listed everywhere with no
     * page behind it.
     */
    public function testRePublishingUnchangedContentWritesTheFileBackOut(): void
    {
        $this->writeTemplate('post.php', '<h1><?= $post->title ?></h1>');

        $post = $this->makePublishedPost('round-trip');
        $dir  = $this->builder->postOutputDir($post->published_at, $post->slug);

        $this->builder->buildPost($post);
        $this->assertFileExists($dir . '/index.html', 'precondition: the first build writes');
        $this->assertNotNull($post->content_hash, 'precondition: the hash is recorded');

        $post->status = 'draft';
        $this->builder->buildPost($post);
        $this->assertFileDoesNotExist($dir . '/index.html', 'precondition: unpublishing removes it');

        // Same content, same date — the render is byte-identical to the stored hash.
        $post->status = 'published';
        $this->builder->buildPost($post);

        $this->assertFileExists($dir . '/index.html', 'a missing file must be rebuilt');
    }

    public function testRePublishingAnUnchangedPageWritesTheFileBackOut(): void
    {
        $this->writeTemplate('page.php', '<h1><?= $page->title ?></h1>');

        $page = new \CMS\Page($this->db);
        $page->title   = 'About';
        $page->slug    = 'about';
        $page->content = 'x';
        $page->status  = 'published';
        $page->save();

        $path = $this->root . '/output/pages/about/index.html';

        $this->builder->buildPage($page);
        $this->assertFileExists($path, 'precondition: the first build writes');

        $page->status = 'draft';
        $this->builder->buildPage($page);
        $this->assertFileDoesNotExist($path, 'precondition: unpublishing removes it');

        $page->status = 'published';
        $this->builder->buildPage($page);

        $this->assertFileExists($path, 'a missing file must be rebuilt');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Put a minimal template in place so render() has something to include. */
    private function writeTemplate(string $name, string $body): void
    {
        if (!is_dir($this->root . '/templates')) {
            mkdir($this->root . '/templates', 0775, true);
        }
        file_put_contents($this->root . '/templates/' . $name, $body);
    }


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
