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
$syndication = new \CMS\Syndication($db, $config);

// Retention pruning lives in bin/prune.php, run from cron. It used to happen
// here on ~1% of admin requests, which meant the public endpoints that fill
// these tables (the analytics beacon, the passkey and Micropub auth surfaces)
// never triggered it, and a quiet month left the eventual DELETE to land in
// the middle of a page load. See INSTALL.md for the cron entry.

// Regenerate theme.min.css if it is missing or theme.css has been modified.
$_themeCss    = CMS_ROOT . '/theme.css';
$_themeMinCss = CMS_ROOT . '/theme.min.css';
if (file_exists($_themeCss) && (!file_exists($_themeMinCss) || filemtime($_themeCss) > filemtime($_themeMinCss))) {
    $builder->buildCss();
}
unset($_themeCss, $_themeMinCss);

// Promote any scheduled posts whose publish time has passed: build them and
// send them to the networks. Shared with api.php and micropub.php, either of
// which may be the request that arrives first.
$scheduler = new \CMS\Scheduler($db, $builder, $syndication);
$scheduler->run();
