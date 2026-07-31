#!/usr/bin/env php
<?php

/**
 * Report generated post directories that no published post claims.
 *
 * Until 1.13.1 the builder had no way to clean up a location a post had moved
 * away from, so renaming or re-dating a published post left the old directory
 * on disk — still serving the old copy of the article at the old URL. That is
 * fixed going forward, but nothing sweeps for the ones already there; this
 * finds them.
 *
 * Read-only by design. It prints what it found and the commands to remove it,
 * so deleting is always a deliberate act rather than a side effect of looking.
 *
 * Usage:
 *   php bin/find-orphaned-output.php            Report orphans
 *   php bin/find-orphaned-output.php --commands Also print rm -rf lines
 */

define('CMS_ROOT', dirname(__DIR__));
define('CMS_VERSION', trim(file_get_contents(CMS_ROOT . '/VERSION')));

require CMS_ROOT . '/vendor/autoload.php';

use CMS\Database;
use CMS\Post;

if (!class_exists(Database::class)) {
    fwrite(STDERR, "Error: autoloader not found. Run 'composer install' first.\n");
    exit(1);
}

$config    = require CMS_ROOT . '/config.php';
$db        = new Database($config['paths']['data'] . '/cms.db');
$settings  = $db->getAllSettings();
$outputDir = rtrim($config['paths']['output'], '/');
$postsDir  = $outputDir . '/posts';
$timezone  = $settings['timezone'] ?? '';
$commands  = in_array('--commands', $argv ?? [], true);

if (!is_dir($postsDir)) {
    fwrite(STDERR, "No posts directory at {$postsDir}\n");
    exit(1);
}

// Every location a published post legitimately claims. A post with no date has
// no public location at all, which is why the null check matters rather than
// falling back to today the way buildPost() does for its own path.
$claimed = [];
foreach (Post::findAll($db, 'published') as $post) {
    if ($post->published_at === null || $post->slug === '') {
        continue;
    }
    $claimed[$postsDir . '/' . Post::datePath($post->published_at, $post->slug, $timezone)] = $post->id;
}

/**
 * Directories under posts/ that hold generated output, keyed by path.
 * Only leaf directories are considered — the year/month/day levels are
 * scaffolding and are reported separately when they end up empty.
 *
 * @return array{leaves: array<string, list<string>>, empties: list<string>}
 */
function scanOutput(string $root): array
{
    $leaves  = [];
    $empties = [];

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($it as $entry) {
        if (!$entry->isDir()) {
            continue;
        }
        $path    = $entry->getPathname();
        $files   = array_values(array_filter(
            scandir($path) ?: [],
            fn($e) => $e !== '.' && $e !== '..' && !is_dir($path . '/' . $e)
        ));
        $subdirs = array_values(array_filter(
            scandir($path) ?: [],
            fn($e) => $e !== '.' && $e !== '..' && is_dir($path . '/' . $e)
        ));

        if ($files === [] && $subdirs === []) {
            $empties[] = $path;
        } elseif ($files !== []) {
            $leaves[$path] = $files;
        }
    }

    return ['leaves' => $leaves, 'empties' => $empties];
}

['leaves' => $leaves, 'empties' => $empties] = scanOutput($postsDir);

$orphans = [];
$legacy  = [];
foreach ($leaves as $path => $files) {
    if (isset($claimed[$path])) {
        continue;
    }
    // posts/<slug>/ rather than posts/YYYY/MM/DD/<slug>/ predates dated URLs.
    // buildAll() migrates these itself, so flag them apart from true orphans.
    $relative = trim(substr($path, strlen($postsDir)), '/');
    if (substr_count($relative, '/') === 0) {
        $legacy[$path] = $files;
    } else {
        $orphans[$path] = $files;
    }
}

ksort($orphans);
ksort($legacy);
sort($empties);

printf("Output root:      %s\n", $outputDir);
printf("Published posts:  %d\n", count($claimed));
printf("Directories held: %d\n\n", count($leaves));

if ($orphans === [] && $legacy === [] && $empties === []) {
    echo "No orphaned output found.\n";
    exit(0);
}

// An orphan holding index.html is answering requests with a stale copy of the
// article; one holding only og.png is untidy but unreachable. Worth separating,
// because only the first kind is actually a live problem.
$serving = array_filter($orphans, fn($files) => in_array('index.html', $files, true));
$inert   = array_diff_key($orphans, $serving);

if ($serving !== []) {
    printf("── Orphaned post directories, STILL SERVING (%d) ──\n", count($serving));
    echo "Each of these answers its URL with a stale copy of the article, and is\n";
    echo "claimed by no published post — most likely a pre-rename location.\n\n";
    foreach ($serving as $path => $files) {
        printf("  %s\n      %s\n", $path, implode(', ', $files));
    }
    echo "\n";
}

if ($inert !== []) {
    printf("── Orphaned files, not served (%d) ──\n", count($inert));
    echo "No index.html, so nothing answers here. Leftovers from a removal that\n";
    echo "took the HTML but not the OG image — the bug fixed in 1.13.0.\n\n";
    foreach ($inert as $path => $files) {
        printf("  %s\n      %s\n", $path, implode(', ', $files));
    }
    echo "\n";
}

if ($legacy !== []) {
    printf("── Legacy flat directories (%d) ──\n", count($legacy));
    echo "From before dated URLs. A full `php bin/build.php` migrates these.\n\n";
    foreach ($legacy as $path => $files) {
        printf("  %s\n      %s\n", $path, implode(', ', $files));
    }
    echo "\n";
}

if ($empties !== []) {
    printf("── Empty directories (%d) ──\n", count($empties));
    echo "Harmless — nothing is served from them — but left behind by removals.\n\n";
    foreach ($empties as $path) {
        printf("  %s\n", $path);
    }
    echo "\n";
}

if ($commands) {
    echo "── Removal commands ──\n";
    echo "Review each line before running it. Nothing here has been deleted.\n\n";
    foreach (array_keys($orphans) as $path) {
        printf("rm -rf %s\n", escapeshellarg($path));
    }
    foreach ($empties as $path) {
        printf("rmdir %s\n", escapeshellarg($path));
    }
} else {
    echo "Re-run with --commands to print removal lines. Nothing has been deleted.\n";
}
