<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Builder;
use CMS\Database;
use CMS\Post;
use CMS\Scheduler;
use CMS\Syndication;

/**
 * Scheduled posts going live.
 *
 * Promotion is a one-way flip of the row, so whoever calls it owns everything
 * that follows: the endpoints used to call Post::promoteScheduled() and drop
 * the ids, which published posts that were never built and could never be
 * found again — the next request's promotion query no longer matched them.
 * These tests pin promotion and the build as one step.
 */
final class SchedulerTest extends TempSiteTestCase
{
    private Builder $builder;
    private Scheduler $scheduler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stubTemplate('post.php', '<h1><?= $post->title ?></h1>');

        // rebuildSharedResources() wants the index, feed and sitemap templates,
        // none of which this is about. Everything else runs for real, so the
        // assertions are on files the builder genuinely wrote.
        $this->builder = new class ($this->config, $this->db) extends Builder {
            public int $sharedRebuilds = 0;

            public function rebuildSharedResources(): void
            {
                $this->sharedRebuilds++;
            }
        };

        // No mastodon_* or bluesky_* settings in this database, so publish() finds
        // no configured client and makes no network call.
        $this->scheduler = new Scheduler($this->db, $this->builder, new Syndication($this->db, $this->config));
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

    // ── Concurrency ───────────────────────────────────────────────────────────

    /**
     * The race: promoteScheduled() read the due rows and updated them in two
     * separate statements, so a cron run and a web request arriving together
     * both saw the post as scheduled, both returned its id, and the caller
     * syndicates whatever it is handed — the post went to Mastodon, Bluesky and
     * Pixelfed twice.
     *
     * This has to be real processes. Two calls in one process cannot race: the
     * first returns before the second begins, so it passes with or without the
     * fix, which is worse than no test. Each child blocks on a barrier file so
     * they all arrive at the same moment.
     */
    public function testConcurrentProcessesPromoteAPostExactlyOnce(): void
    {
        $post = $this->makeScheduledPost('contended', '2026-07-29 12:00:00');
        $id   = (int) $post->id;

        $barrier = $this->root . '/go';
        $runner  = $this->root . '/promote.php';
        file_put_contents($runner, <<<'PHP'
            <?php
            require $argv[1];
            while (!file_exists($argv[3])) { usleep(200); }
            echo json_encode(CMS\Post::promoteScheduled(new CMS\Database($argv[2])));
            PHP);

        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $procs    = [];
        for ($i = 0; $i < 4; $i++) {
            $procs[$i] = proc_open(
                [PHP_BINARY, $runner, $autoload, $this->dbPath, $barrier],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$i]
            );
        }

        usleep(150_000);          // let every child reach the barrier
        touch($barrier);

        $claimed = [];
        foreach ($procs as $i => $proc) {
            $out = stream_get_contents($pipes[$i][1]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            proc_close($proc);
            foreach (json_decode((string) $out, true) ?: [] as $got) {
                $claimed[] = (int) $got;
            }
        }

        $this->assertSame(
            [$id],
            $claimed,
            'exactly one process may promote the post — more than one means it syndicates twice'
        );
    }

    // ── tick(): the web fallback ──────────────────────────────────────────────

    public function testTickDoesNothingWhileTheHeartbeatIsFresh(): void
    {
        $post = $this->makeScheduledPost('overdue', '2026-07-29 12:00:00');
        $this->db->upsertSetting(Scheduler::HEARTBEAT_KEY, gmdate('Y-m-d H:i:s'));

        $this->assertSame([], $this->scheduler->tick(), 'cron is alive; leave it to cron');

        $reloaded = Post::findById($this->db, (int) $post->id);
        $this->assertSame('scheduled', $reloaded->status);
    }

    public function testTickRunsWhenTheHeartbeatHasAgedOut(): void
    {
        $post = $this->makeScheduledPost('overdue', '2026-07-29 12:00:00');
        $this->db->upsertSetting(
            Scheduler::HEARTBEAT_KEY,
            gmdate('Y-m-d H:i:s', time() - (Scheduler::HEARTBEAT_FRESH_SECONDS + 60))
        );

        $this->assertSame([(int) $post->id], $this->scheduler->tick());
    }

    /** A site that has never run the cron must fall back, not sit forever. */
    public function testTickRunsWhenThereIsNoHeartbeatAtAll(): void
    {
        $post = $this->makeScheduledPost('overdue', '2026-07-29 12:00:00');

        $this->assertSame([(int) $post->id], $this->scheduler->tick());
    }

    /** Nothing due still refreshes the beat, so the check is not repeated. */
    public function testTickWritesTheHeartbeatEvenWithNothingDue(): void
    {
        $this->makeScheduledPost('not-yet', '2027-01-01 09:00:00');

        $this->assertSame([], $this->scheduler->tick());
        $this->assertLessThan(5, $this->scheduler->heartbeatAgeSeconds());
    }

    public function testRunWritesTheHeartbeatEvenWhenNothingWasPromoted(): void
    {
        $this->assertSame([], $this->scheduler->run());
        $this->assertLessThan(5, $this->scheduler->heartbeatAgeSeconds());
    }

    // ── The lock ──────────────────────────────────────────────────────────────

    /**
     * A held lock must make run() a no-op rather than queue a second full build
     * behind a slow syndication call.
     */
    public function testAHeldLockMakesRunDoNothing(): void
    {
        $post = $this->makeScheduledPost('overdue', '2026-07-29 12:00:00');

        $held = fopen($this->db->dataDir() . '/scheduler.lock', 'c');
        $this->assertTrue(flock($held, LOCK_EX | LOCK_NB));

        $this->assertSame([], $this->scheduler->run(), 'another run holds the lock');

        $reloaded = Post::findById($this->db, (int) $post->id);
        $this->assertSame('scheduled', $reloaded->status, 'and it must not have promoted');

        flock($held, LOCK_UN);
        fclose($held);

        $this->assertSame([(int) $post->id], $this->scheduler->run(), 'released, it runs');
    }

    // ── Staleness warning ─────────────────────────────────────────────────────

    public function testHeartbeatIsStaleOnlyWhenSomethingIsWaitingOnIt(): void
    {
        $old = gmdate('Y-m-d H:i:s', time() - (Scheduler::HEARTBEAT_STALE_SECONDS + 60));
        $this->db->upsertSetting(Scheduler::HEARTBEAT_KEY, $old);

        $this->assertFalse(
            $this->scheduler->heartbeatIsStale(),
            'no scheduled posts means a quiet runner is not news'
        );

        $this->makeScheduledPost('waiting', '2027-01-01 09:00:00');
        $this->db->upsertSetting(Scheduler::HEARTBEAT_KEY, $old);

        $this->assertTrue($this->scheduler->heartbeatIsStale());
    }

    // ── The transaction helper promotion relies on ────────────────────────────

    public function testTransactionCommitsAndIsVisibleToAnotherConnection(): void
    {
        $this->db->transaction(fn() => $this->db->exec(
            "INSERT INTO settings (key, value) VALUES ('t_commit', 'yes')"
        ));

        $other = new Database($this->dbPath);
        $this->assertSame('yes', $other->getSetting('t_commit', ''));
    }

    public function testTransactionRollsBackOnFailureAndRethrows(): void
    {
        try {
            $this->db->transaction(function (): void {
                $this->db->exec("INSERT INTO settings (key, value) VALUES ('t_roll', 'yes')");
                throw new \RuntimeException('boom');
            });
            $this->fail('the exception must reach the caller');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame('', $this->db->getSetting('t_roll', ''));
    }

    /** A second BEGIN is an error in SQLite, so nesting joins the outer one. */
    public function testTransactionNestsWithoutStartingASecond(): void
    {
        $this->db->transaction(function (): void {
            $this->db->transaction(fn() => $this->db->exec(
                "INSERT INTO settings (key, value) VALUES ('t_nest', 'yes')"
            ));
        });

        $this->assertSame('yes', $this->db->getSetting('t_nest', ''));
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

}
