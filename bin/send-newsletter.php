#!/usr/bin/env php
<?php

/**
 * Email the next newly published article to the EmailOctopus list.
 *
 * One article per run, at most. See CMS\Newsletter for what makes an article
 * eligible, and for why a second article waits an hour behind the first.
 *
 * Usage:
 *   php bin/send-newsletter.php [--dry-run] [--quiet]
 *
 * Options:
 *   --dry-run   Say which article would go out and to how many subscribers,
 *               without writing a field or queueing anyone.
 *   --quiet     Print nothing unless something was sent or went wrong.
 *
 * Add to cron (every five minutes):
 *   0-59/5 * * * * /usr/bin/php /var/www/cms/bin/send-newsletter.php --quiet >> /var/www/cms/storage/newsletter.log 2>&1
 *
 * Run it as www-data, like the scheduler: the database's WAL sidecars must stay
 * owned by the account that serves the site.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

define('CMS_ROOT', dirname(__DIR__));
define('CMS_VERSION', trim(file_get_contents(CMS_ROOT . '/VERSION')));

require CMS_ROOT . '/vendor/autoload.php';

use CMS\ActivityLog;
use CMS\Database;
use CMS\EmailOctopus;
use CMS\Newsletter;

if (!class_exists(Database::class)) {
    fwrite(STDERR, "Error: autoloader not found. Run 'composer install' first.\n");
    exit(1);
}

$args   = $argv ?? [];
$dryRun = in_array('--dry-run', $args, true);
$quiet  = in_array('--quiet', $args, true);

$say = static function (string $line) use ($quiet): void {
    if (!$quiet) {
        echo $line, "\n";
    }
};

try {
    $config = require CMS_ROOT . '/config.php';
    $db     = new Database($config['paths']['data'] . '/cms.db');
} catch (\Throwable $e) {
    fwrite(STDERR, '[newsletter] ' . $e->getMessage() . "\n");
    exit(1);
}

// A send can outlast the five minutes between runs on a large enough list.
// Two runs at once would each read the same deliveries and queue the same
// subscribers twice, so the second one simply leaves.
$lock = fopen($config['paths']['data'] . '/newsletter.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $say('[newsletter] another run is still going.');
    exit(0);
}

$client = EmailOctopus::fromSettings($db);
if ($client === null) {
    $say('[newsletter] EmailOctopus is not configured.');
    exit(0);
}

$settings   = $db->getAllSettings();
$newsletter = new Newsletter($db, $client, $settings['site_url'] ?? '', $settings['timezone'] ?? '');

$post = $newsletter->nextArticle();
if ($post === null) {
    $say('[newsletter] nothing to send.');
    exit(0);
}

if ($dryRun) {
    $ids = $client->subscribedContactIds();
    echo '[newsletter] would send #', $post->id, ' "', $post->title, '" to ',
        $ids === null ? '(could not read the list)' : count($ids), " subscriber(s).\n";
    foreach ($newsletter->fieldsFor($post) as $tag => $value) {
        echo '  ', $tag, ': ', $value, "\n";
    }
    exit(0);
}

try {
    $result = $newsletter->send($post);
} catch (\Throwable $e) {
    fwrite(STDERR, '[newsletter] ' . $e::class . ': ' . $e->getMessage() . "\n");
    exit(1);
}

if ($result === null) {
    fwrite(STDERR, '[newsletter] could not read the subscriber list; #' . $post->id . " will be retried.\n");
    exit(1);
}

echo '[newsletter] #', $post->id, ' "', $post->title, '": queued ', $result['queued'],
    ' of ', $result['subscribers'], ' (', $result['skipped'], ' already had it, ',
    $result['failed'], " failed).\n";

if ($result['done'] && $result['queued'] > 0) {
    (new ActivityLog($db))->log('newsletter', 'post', $post->id, $post->title);
}

exit($result['failed'] > 0 ? 1 : 0);
