#!/usr/bin/env php
<?php

/**
 * Publish scheduled posts whose time has come, and check that the last edit
 * sent to Bluesky actually surfaced there.
 *
 * The second job rides along here rather than in a cron of its own because it
 * wants exactly what this one already has: a minute-by-minute tick, and a
 * Syndication instance. Bluesky's AppView does not re-index an edited record,
 * so an edit that putRecord accepted may never reach a reader; the check runs a
 * few minutes after the write and flags the post when it did not. See
 * Syndication::verifyBluesky().
 *
 * Promotion used to happen only when somebody knocked: admin/bootstrap.php,
 * admin/api.php and micropub.php each ran the scheduler inline, so a post set
 * for 09:00 went live whenever the next request happened to arrive — and that
 * visitor paid for four buildPost() calls, a shared rebuild and up to three
 * outbound syndication requests. Cron is what makes "09:00" mean 09:00.
 *
 * The web fallback still exists (Scheduler::tick()) for when this stops
 * running, and the dashboard warns when the heartbeat goes stale.
 *
 * Usage:
 *   php bin/publish-scheduled.php [--dry-run] [--quiet]
 *
 * Options:
 *   --dry-run   Report what is due without promoting, building, syndicating or
 *               verifying.
 *   --quiet     Print nothing unless something was published, a Bluesky copy
 *               came back stale, or something went wrong.
 *
 * Add to cron (every minute):
 *   * * * * * /usr/bin/php /var/www/cms/bin/publish-scheduled.php --quiet >> /var/www/cms/storage/scheduler.log 2>&1
 *
 * Run it as the same user as PHP-FPM (www-data) so the WAL files and the HTML
 * it writes stay owned by the account that serves the site. A run as anyone
 * else leaves files the next web-triggered rebuild cannot overwrite.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

define('CMS_ROOT', dirname(__DIR__));
define('CMS_VERSION', trim(file_get_contents(CMS_ROOT . '/VERSION')));

require CMS_ROOT . '/vendor/autoload.php';

use CMS\ActivityLog;
use CMS\Builder;
use CMS\Database;
use CMS\Post;
use CMS\Scheduler;
use CMS\Syndication;

if (!class_exists(Database::class)) {
    fwrite(STDERR, "Error: autoloader not found. Run 'composer install' first.\n");
    exit(1);
}

$args   = $argv ?? [];
$dryRun = in_array('--dry-run', $args, true);
$quiet  = in_array('--quiet', $args, true);

/** Print unless --quiet is asking for silence on an uneventful run. */
$say = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        echo $line, "\n";
    }
};

try {
    $config = require CMS_ROOT . '/config.php';
    $db     = new Database($config['paths']['data'] . '/cms.db');
} catch (\Throwable $e) {
    fwrite(STDERR, '[scheduler] ' . $e->getMessage() . "\n");
    exit(1);
}

$syndication = new Syndication($db, $config);

$scheduler = new Scheduler(
    $db,
    new Builder($config, $db),
    $syndication,
    new ActivityLog($db)
);

if ($dryRun) {
    $due = $db->select(
        "SELECT id, title, slug, published_at
           FROM posts
          WHERE status = 'scheduled'
            AND deleted_at IS NULL
            AND published_at <= datetime('now')
          ORDER BY published_at"
    );

    if ($due === []) {
        $say('[scheduler] nothing due.');
        exit(0);
    }

    echo '[scheduler] ', count($due), " post(s) due (dry run, nothing changed):\n";
    foreach ($due as $row) {
        echo '  #', $row['id'], '  ', $row['published_at'], '  ', $row['slug'], "\n";
    }
    exit(0);
}

try {
    $promoted = $scheduler->run();
} catch (\Throwable $e) {
    // Loud on failure even under --quiet: a cron that fails silently is the
    // thing this script exists to stop.
    fwrite(STDERR, '[scheduler] ' . $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}

// Published something, so speak up even under --quiet.
foreach ($promoted as $id) {
    $post = Post::findById($db, $id);
    echo '[scheduler] published #', $id, '  ', $post?->slug ?? '(gone)', "\n";
}

if ($promoted === []) {
    $say('[scheduler] nothing due.');
}

// Bluesky's half of the job, and the reason this script does two things: an
// edit written to the repo a few minutes ago either reached the AppView or did
// not, and there is no way to know but to look. Wrapped separately so a network
// that is down cannot make a cron run that published correctly exit non-zero.
try {
    $stale = $syndication->verifyBluesky();
} catch (\Throwable $e) {
    fwrite(STDERR, '[bluesky] verification failed: ' . $e::class . ': ' . $e->getMessage() . "\n");
    exit(0);
}

// Worth saying even under --quiet: it is the only thing that will say it here.
foreach ($stale as $id) {
    $post = Post::findById($db, $id);
    echo '[bluesky] #', $id, '  ', $post?->slug ?? '(gone)',
         " — bsky.app is still showing the version before the last edit\n";
}

exit(0);
