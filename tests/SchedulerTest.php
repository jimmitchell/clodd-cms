<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Builder;
use CMS\Database;
use CMS\Post;
use CMS\Scheduler;
use CMS\Syndication;
use PHPUnit\Framework\TestCase;

/**
 * Scheduled posts going live.
 *
 * Promotion is a one-way flip of the row, so whoever calls it owns everything
 * that follows: the endpoints used to call Post::promoteScheduled() and drop
 * the ids, which published posts that were never built and could never be
 * found again — the next request's promotion query no longer matched them.
 * These tests pin promotion and the build as one step.
 */
final class SchedulerTest extends TestCase
{
    private Database $db;
    private string $dbPath;
    private string $root;
    private Builder $builder;
    private Scheduler $scheduler;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'clodd_test_') . '.db';
        $this->db     = new Database($this->dbPath);

        $this->root = realpath(sys_get_temp_dir()) . '/clodd_out_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/output', 0775, true);
        mkdir($this->root . '/templates', 0775, true);
        file_put_contents($this->root . '/templates/post.php', '<h1><?= $post->title ?></h1>');

        $config = [
            'paths' => [
                'output'    => $this->root . '/output',
                'templates' => $this->root . '/templates',
                'content'   => $this->root . '/content',
            ],
        ];

        // rebuildSharedResources() wants the index, feed and sitemap templates,
        // none of which this is about. Everything else runs for real, so the
        // assertions are on files the builder genuinely wrote.
        $this->builder = new class ($config, $this->db) extends Builder {
            public int $sharedRebuilds = 0;

            public function rebuildSharedResources(): void
            {
                $this->sharedRebuilds++;
            }
        };

        // No mastodon_* or bluesky_* settings in this database, so publish() finds
        // no configured client and makes no network call.
        $this->scheduler = new Scheduler($this->db, $this->builder, new Syndication($this->db, $config));
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

    public function testADuePostIsBuiltAndNotOnlyPromoted(): void
    {
        $post = $this->makeScheduledPost('overdue', '2026-07-29 12:00:00');

        $this->scheduler->run();

        $reloaded = Post::findById($this->db, (int) $post->id);
        $this->assertSame('published', $reloaded->status);
        $this->assertFileExists(
            $this->builder->postOutputDir($post->published_at, $post->slug) . '/index.html',
            'a promoted post must reach the disk in the same request'
        );
    }

    public function testTheSharedPagesAreRebuiltSoTheHomePageListsIt(): void
    {
        $this->makeScheduledPost('overdue', '2026-07-29 12:00:00');

        $this->scheduler->run();

        $this->assertSame(1, $this->builder->sharedRebuilds);
    }

    public function testRunReportsWhatItPromoted(): void
    {
        $post = $this->makeScheduledPost('overdue', '2026-07-29 12:00:00');

        $this->assertSame([(int) $post->id], $this->scheduler->run());
    }

    // ── Posts it must not touch ───────────────────────────────────────────────

    public function testAPostScheduledForLaterIsLeftAlone(): void
    {
        $post = $this->makeScheduledPost('not-yet', '2027-01-01 09:00:00');

        $this->assertSame([], $this->scheduler->run());

        $reloaded = Post::findById($this->db, (int) $post->id);
        $this->assertSame('scheduled', $reloaded->status);
        $this->assertDirectoryDoesNotExist(
            $this->builder->postOutputDir($post->published_at, $post->slug),
            'publishing a scheduled post early is the failure mode'
        );
    }

    public function testNothingDueRebuildsNothing(): void
    {
        $this->makeScheduledPost('not-yet', '2027-01-01 09:00:00');

        $this->scheduler->run();

        $this->assertSame(0, $this->builder->sharedRebuilds, 'a quiet request must not rebuild the site');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeScheduledPost(string $slug, string $publishedAt): Post
    {
        $post               = new Post($this->db);
        $post->title        = 'T';
        $post->slug         = $slug;
        $post->content      = 'x';
        $post->status       = 'scheduled';
        $post->published_at = $publishedAt;
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
