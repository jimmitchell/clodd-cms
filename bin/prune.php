#!/usr/bin/env php
<?php

/**
 * Prune expired rows from the operational tables.
 *
 * This used to run inline in admin/bootstrap.php on ~1% of requests, which tied
 * retention to how often the owner happened to load an admin page: tables grew
 * unbounded across a quiet month, and the eventual DELETE landed in the middle
 * of somebody's page load. The public endpoints that write these rows — the
 * analytics beacon and the passkey/Micropub auth surfaces — never ran it at all.
 *
 * Usage:
 *   php bin/prune.php [--dry-run]
 *
 * Add to cron (daily at 03:00):
 *   0 3 * * * /usr/bin/php /var/www/cms/bin/prune.php >> /var/www/cms/storage/prune.log 2>&1
 *
 * Run it as the same user as PHP-FPM (www-data) so the WAL and journal files
 * stay owned by the account that serves the site.
 */

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));

require CMS_ROOT . '/vendor/autoload.php';

use CMS\Database;

if (!class_exists(Database::class)) {
    fwrite(STDERR, "Error: autoloader not found. Run 'composer install' first.\n");
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);

$config = require CMS_ROOT . '/config.php';
$db     = new Database($config['paths']['data'] . '/cms.db');

/**
 * Each entry is [label, count query, delete query]. Retention windows match the
 * values these tables were pruned with inline.
 */
$targets = [
    [
        'login_attempts',
        "SELECT COUNT(*) AS c FROM login_attempts WHERE attempted_at < datetime('now', '-24 hours')",
        "DELETE FROM login_attempts WHERE attempted_at < datetime('now', '-24 hours')",
    ],
    [
        'activity_log',
        "SELECT COUNT(*) AS c FROM activity_log WHERE created_at < datetime('now', '-90 days')",
        "DELETE FROM activity_log WHERE created_at < datetime('now', '-90 days')",
    ],
    [
        'page_views',
        "SELECT COUNT(*) AS c FROM page_views WHERE timestamp < strftime('%s', datetime('now', '-90 days'))",
        "DELETE FROM page_views WHERE timestamp < strftime('%s', datetime('now', '-90 days'))",
    ],
];

$total = 0;

foreach ($targets as [$label, $countSql, $deleteSql]) {
    $row   = $db->selectOne($countSql);
    $count = (int) ($row['c'] ?? 0);
    $total += $count;

    if ($count === 0) {
        echo "  {$label}: nothing to prune\n";
        continue;
    }

    if ($dryRun) {
        echo "  {$label}: would delete {$count} row(s)\n";
        continue;
    }

    $db->exec($deleteSql);
    echo "  {$label}: deleted {$count} row(s)\n";
}

// Reclaim the freed pages. Skipped on a dry run and when nothing was removed,
// because incremental vacuum still rewrites the freelist.
if (!$dryRun && $total > 0) {
    $db->exec('PRAGMA incremental_vacuum');
}

echo $dryRun
    ? "Dry run complete ({$total} row(s) would be removed).\n"
    : "Pruned {$total} row(s).\n";
