<?php

declare(strict_types=1);

namespace CMS;

/**
 * Scheduled posts going live.
 *
 * Cron is what actually publishes: bin/publish-scheduled.php calls run() every
 * minute. The web entry points call tick() instead, which is a safety net for a
 * cron that has stopped — it does nothing while the heartbeat is fresh, and
 * when it does act it hands the response back first so no visitor waits on a
 * build. A post must go live the same way whichever caller gets there, and
 * none of them may promote a post and leave the rest of the work to somebody
 * else: Post::promoteScheduled() flips the row exactly once, so a caller that
 * drops the ids leaves a post that is published, unbuilt, and invisible to the
 * next promotion query.
 */
final class Scheduler
{
    /** Settings key holding the ISO timestamp of the last completed run. */
    public const HEARTBEAT_KEY = 'scheduler_ran_at';

    /**
     * How long a heartbeat is considered fresh. Cron runs every minute; this
     * is the point at which tick() stops trusting it and does the work itself.
     */
    public const HEARTBEAT_FRESH_SECONDS = 180;

    /**
     * How stale the heartbeat must be before the dashboard says so. Longer than
     * the freshness window, so a single missed minute is not an alarm.
     */
    public const HEARTBEAT_STALE_SECONDS = 900;

    public function __construct(
        private Database $db,
        private Builder $builder,
        private Syndication $syndication,
        private ?ActivityLog $activityLog = null,
    ) {}

    /**
     * Do the work only if nobody has recently: a no-op while the heartbeat is
     * fresh, and otherwise a full run with the response already flushed.
     *
     * This is what the web entry points call. It exists so a dead cron does not
     * mean posts silently stop publishing — not as the normal path, which is
     * why it costs one settings read on the overwhelming majority of requests.
     * The dashboard warns when the heartbeat goes stale (heartbeatIsStale()),
     * because a fallback nobody notices is a fallback nobody fixes.
     */
    public function tick(): array
    {
        if ($this->heartbeatAgeSeconds() < self::HEARTBEAT_FRESH_SECONDS) {
            return [];
        }

        // Nothing due: record that we looked and leave. Writing the heartbeat
        // here too keeps a site with no cron from re-checking on every single
        // request once the beat has aged out.
        if (!$this->anythingDue()) {
            $this->writeHeartbeat();
            return [];
        }

        // Hand the response back before building. Without this the visitor who
        // happened to arrive first pays for four buildPost() calls, a shared
        // rebuild and up to three outbound syndication requests.
        if (function_exists('fastcgi_finish_request')) {
            @ignore_user_abort(true);
            @set_time_limit(0);
            fastcgi_finish_request();
        }

        return $this->run();
    }

    /**
     * Publish every scheduled post whose time has passed: build it, its
     * neighbours and the shared pages, and send it to the networks.
     *
     * Guarded by a non-blocking lock: a run that is already under way — a slow
     * syndication call, say — must not have a second stacked behind it, because
     * they would both be building the same files. A caller that loses the lock
     * gets [] and does nothing, which is correct: whoever holds it is doing the
     * work.
     *
     * @return list<int> The ids promoted, for callers that log or report them.
     */
    public function run(): array
    {
        $path   = $this->db->dataDir() . '/scheduler.lock';
        $handle = @fopen($path, 'c');

        // No lock file to be had — an unwritable data directory, say. Publish
        // anyway: the lock prevents runs stacking up, it is not what makes
        // promotion correct. promoteScheduled() is atomic on its own.
        if ($handle === false) {
            return $this->promoteAndBuild();
        }

        // Non-blocking: if a run is already going, there is nothing useful for
        // this caller to do but leave. Blocking would queue a second full build
        // behind a slow syndication call, which is the pile-up being avoided.
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return [];
        }

        try {
            return $this->promoteAndBuild();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return list<int> */
    private function promoteAndBuild(): array
    {
        $promotedIds = Post::promoteScheduled($this->db);

        $publisher = new PostPublisher($this->db, $this->builder, $this->syndication, $this->activityLog);

        foreach ($promotedIds as $id) {
            $post = Post::findById($this->db, $id);
            if ($post === null) {
                continue;
            }

            // A scheduled post has never been on disk, so there is no old
            // position to vacate — nothing to snapshot, only somewhere to
            // arrive. deferShared because the shared pages are the same pages
            // for every post in this loop; they are rebuilt once below.
            $publisher->rebuildAfterSave($post, $publisher->snapshotOfNothing(), deferShared: true);
            $publisher->syndicateAfterBuild($post);

            // Scheduled publishes were the one way a post could go live without
            // leaving a trace in Settings → Logs.
            $this->activityLog?->log('publish', 'post', $post->id, $post->title);
        }

        if ($promotedIds !== []) {
            $this->builder->rebuildSharedResources();
        }

        // Written whether or not anything was promoted: the heartbeat answers
        // "is the runner alive", not "did it publish".
        $this->writeHeartbeat();

        return $promotedIds;
    }

    // ── Heartbeat ─────────────────────────────────────────────────────────────

    /** Whether any post is due to go live right now. */
    public function anythingDue(): bool
    {
        return $this->db->selectOne(
            "SELECT 1 FROM posts
              WHERE status = 'scheduled'
                AND deleted_at IS NULL
                AND published_at <= datetime('now')
              LIMIT 1"
        ) !== null;
    }

    private function writeHeartbeat(): void
    {
        $this->db->upsertSetting(self::HEARTBEAT_KEY, gmdate('Y-m-d H:i:s'));
    }

    /**
     * Seconds since the last completed run, or PHP_INT_MAX when it has never
     * run — which must read as "stale", not "fresh", or a site that has never
     * had a cron would never fall back.
     */
    public function heartbeatAgeSeconds(): int
    {
        $last = $this->db->getSetting(self::HEARTBEAT_KEY, '');
        if ($last === '') {
            return PHP_INT_MAX;
        }

        $ts = strtotime($last . ' UTC');

        return $ts === false ? PHP_INT_MAX : max(0, time() - $ts);
    }

    /**
     * Whether the operator should be told the runner looks dead: the heartbeat
     * has aged out *and* there is something waiting on it. A stale heartbeat on
     * a site with nothing scheduled is not worth a warning.
     */
    public function heartbeatIsStale(): bool
    {
        return $this->heartbeatAgeSeconds() > self::HEARTBEAT_STALE_SECONDS
            && $this->db->selectOne(
                "SELECT 1 FROM posts WHERE status = 'scheduled' AND deleted_at IS NULL LIMIT 1"
            ) !== null;
    }

}
