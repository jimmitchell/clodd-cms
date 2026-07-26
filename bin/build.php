#!/usr/bin/env php
<?php

/**
 * Rebuild the static site from the command line.
 *
 * By default this clears all stored content hashes so every post, page,
 * taxonomy archive, index, and feed is regenerated unconditionally — useful
 * after template or theme changes that the hash-skip logic would otherwise miss.
 *
 * Usage:
 *   php bin/build.php            Full rebuild
 *   php bin/build.php --css      Styles only (see --help)
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

$usage = <<<TXT
Usage: php bin/build.php [options]

Options:
  --css, --styles  Rebuild theme.min.css and theme.critical.css only, then
                   patch the inlined critical CSS in already-generated pages.
                   Skips re-rendering every post and page.
  -h, --help       Show this message.

TXT;

$cssOnly = false;

foreach (array_slice($argv, 1) as $arg) {
    switch ($arg) {
        case '--css':
        case '--styles':
            $cssOnly = true;
            break;
        case '-h':
        case '--help':
            echo $usage;
            exit(0);
        default:
            fwrite(STDERR, "Unknown option: {$arg}\n\n{$usage}");
            exit(1);
    }
}

$config = require CMS_ROOT . '/config.php';
$db     = new Database($config['paths']['data'] . '/cms.db');

$builder = new Builder($config, $db);

if ($cssOnly) {
    echo "Rebuilding styles...\n";
    $result = $builder->rebuildCss();

    echo "Wrote theme.min.css and theme.critical.css.\n";

    if (!$result['changed']) {
        echo "Critical CSS unchanged — no pages to patch.\n";
    } elseif ($result['updated'] > 0 || $result['current'] > 0) {
        $inlined = $result['updated'] + $result['current'];
        echo "Critical CSS changed — patched {$result['updated']} of {$inlined} inlining page(s).\n";
    } else {
        echo "Critical CSS changed — no pages inline it, so nothing to patch.\n";
    }

    echo "Done.\n";
    exit(0);
}

// Force every file to regenerate by wiping stored hashes.
$db->exec("UPDATE posts SET content_hash = NULL");
$db->exec("UPDATE pages SET content_hash = NULL");

echo "Building site...\n";
$builder->buildAll();
echo "Done.\n";
