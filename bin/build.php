#!/usr/bin/env php
<?php

/**
 * Force a full static site rebuild from the command line.
 *
 * Clears all stored content hashes so every post, page, taxonomy archive,
 * index, and feed is regenerated unconditionally — useful after template
 * or theme changes that the hash-skip logic would otherwise miss.
 *
 * Usage:
 *   php bin/build.php
 */

define('CMS_ROOT', dirname(__DIR__));
define('CMS_VERSION', trim(file_get_contents(CMS_ROOT . '/VERSION')));

require CMS_ROOT . '/vendor/autoload.php';

use CMS\Builder;
use CMS\Database;

if (!class_exists(Database::class)) {
    fwrite(STDERR, "Error: autoloader not found. Run 'composer install' first.\n");
    exit(1);
}

$config = require CMS_ROOT . '/config.php';
$db     = new Database($config['paths']['data'] . '/cms.db');

// Force every file to regenerate by wiping stored hashes.
$db->exec("UPDATE posts SET content_hash = NULL");
$db->exec("UPDATE pages SET content_hash = NULL");

$builder = new Builder($config, $db);

echo "Building site...\n";
$builder->buildAll();
echo "Done.\n";
