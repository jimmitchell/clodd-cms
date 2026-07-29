<?php

declare(strict_types=1);

/**
 * Admin bootstrap — loaded by every admin PHP file.
 *
 * Sets up the autoloader, reads config, creates DB + Auth instances,
 * and starts the session.
 */

// A PHP notice or fatal rendered into the page leaks absolute filesystem paths
// and sometimes query fragments. Errors still reach the server log; they just
// never reach the browser. Individual JSON endpoints set this too, because they
// are reached without this bootstrap.
ini_set('display_errors', '0');

define('CMS_ROOT', dirname(__DIR__));
define('CMS_VERSION', trim(file_get_contents(CMS_ROOT . '/VERSION')));

require CMS_ROOT . '/vendor/autoload.php';

$config = require CMS_ROOT . '/config.php';

$db   = new \CMS\Database($config['paths']['data'] . '/cms.db');
$auth = new \CMS\Auth($config, $db);

$auth->startSession();

$builder     = new \CMS\Builder($config, $db);
$activityLog = new \CMS\ActivityLog($db);

// Prune stale login attempts (~1% of requests). Keeps the table small so the
// rate-limiting query stays fast; entries older than 24 hours are never needed.
if (random_int(1, 100) === 1) {
    $db->exec("DELETE FROM login_attempts WHERE attempted_at < datetime('now', '-24 hours')");
    $db->exec("DELETE FROM activity_log WHERE created_at < datetime('now', '-90 days')");
    $db->exec("DELETE FROM page_views WHERE timestamp < strftime('%s', datetime('now', '-90 days'))");
}

// Regenerate theme.min.css if it is missing or theme.css has been modified.
$_themeCss    = CMS_ROOT . '/theme.css';
$_themeMinCss = CMS_ROOT . '/theme.min.css';
if (file_exists($_themeCss) && (!file_exists($_themeMinCss) || filemtime($_themeCss) > filemtime($_themeMinCss))) {
    $builder->buildCss();
}
unset($_themeCss, $_themeMinCss);

// Promote any scheduled posts whose publish time has passed and rebuild them.
$promotedIds = \CMS\Post::promoteScheduled($db);
foreach ($promotedIds as $pid) {
    $promoted = \CMS\Post::findById($db, $pid);
    if ($promoted) {
        // buildPost() rebuilds taxonomy archives for the post's own terms.
        $builder->buildPost($promoted);
        $prev = \CMS\Post::findPrev($db, $promoted);
        if ($prev) $builder->buildPost($prev);
        $next = \CMS\Post::findNext($db, $promoted);
        if ($next) $builder->buildPost($next);
    }
}
if (!empty($promotedIds)) {
    $builder->rebuildSharedResources();
}
