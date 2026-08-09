#!/usr/bin/env php
<?php

/**
 * Generate WebP companions for images already in content/media/.
 *
 * Media::generateWebp() only ever ran on new uploads, so anything imported from
 * WordPress or copied in by hand never got one — and the companions that do
 * exist predate downscaling, so they are full-resolution re-encodes. This walks
 * the media directory and brings every JPEG/PNG up to the current output.
 *
 * Originals are never modified, moved, or deleted: the .webp files sit beside
 * them and ImageTag falls back to the original whenever one is absent.
 *
 * Usage:
 *   php bin/optimise-media.php [--dry-run] [--force] [--limit=N]
 *
 * Options:
 *   --dry-run   Report what would be written; touch nothing.
 *   --force     Rebuild companions that already exist (use after changing the
 *               max edge or quality). Without it, only missing or oversized
 *               companions are written.
 *   --limit=N   Stop after N source images. Useful for a first pass.
 */

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));

require CMS_ROOT . '/vendor/autoload.php';

use CMS\Database;
use CMS\ImageTag;
use CMS\Media;

if (!class_exists(Database::class)) {
    fwrite(STDERR, "Error: autoloader not found. Run 'composer install' first.\n");
    exit(1);
}

if (!extension_loaded('gd') || !function_exists('imagewebp')) {
    fwrite(STDERR, "Error: PHP's GD extension with WebP support is required.\n");
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);
$force  = in_array('--force', $argv, true);

$limit = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(0, (int) substr($arg, 8));
    }
}

$config   = require CMS_ROOT . '/config.php';
$mediaDir = rtrim($config['paths']['content'], '/\\') . '/media';

if (!is_dir($mediaDir)) {
    fwrite(STDERR, "Error: media directory not found at {$mediaDir}\n");
    exit(1);
}

$db    = new Database($config['paths']['data'] . '/cms.db');
$media = new Media($db, $mediaDir);

/** Every JPEG/PNG in the media directory, excluding generated companions. */
$sources = [];
foreach (scandir($mediaDir) ?: [] as $entry) {
    $path = $mediaDir . '/' . $entry;
    if (!is_file($path)) {
        continue;
    }
    if (!in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true)) {
        continue;
    }
    $sources[] = $path;
}

sort($sources);

if ($sources === []) {
    echo "No JPEG or PNG files found in {$mediaDir}.\n";
    exit(0);
}

echo ($dryRun ? 'Dry run: ' : '') . count($sources) . " source image(s) in {$mediaDir}\n";
echo 'Target: longest edge ' . Media::WEBP_MAX_EDGE . 'px, narrow variant ' . ImageTag::NARROW_WIDTH . "px\n\n";

$processed = 0;
$skipped   = 0;
$failed    = 0;
$bytesIn   = 0;
$bytesOut  = 0;

foreach ($sources as $path) {
    if ($limit > 0 && $processed >= $limit) {
        break;
    }

    $name    = basename($path);
    $primary = (string) preg_replace('/\.[^.]+$/', '.webp', $path);

    // An existing companion is up to date when it is within the size cap. That
    // catches the full-resolution ones written before downscaling existed.
    if (!$force && is_file($primary)) {
        $size = @getimagesize($primary);
        if ($size !== false && max($size[0], $size[1]) <= Media::WEBP_MAX_EDGE) {
            $skipped++;
            continue;
        }
    }

    $sourceBytes = (int) filesize($path);

    if ($dryRun) {
        printf("  would convert  %-44s %6.1f KB\n", $name, $sourceBytes / 1024);
        $processed++;
        $bytesIn += $sourceBytes;
        continue;
    }

    $written = $media->generateWebp($path);

    if ($written === []) {
        printf("  FAILED         %-44s\n", $name);
        $failed++;
        continue;
    }

    $outBytes = 0;
    foreach ($written as $file) {
        $outBytes += (int) filesize($file);
    }

    printf(
        "  converted      %-44s %6.1f KB -> %6.1f KB (%d file%s)\n",
        $name,
        $sourceBytes / 1024,
        $outBytes / 1024,
        count($written),
        count($written) === 1 ? '' : 's'
    );

    $processed++;
    $bytesIn  += $sourceBytes;
    $bytesOut += $outBytes;
}

echo "\n";
echo "Processed: {$processed}\n";
echo "Skipped (already current): {$skipped}\n";
if ($failed > 0) {
    echo "Failed: {$failed}\n";
}

if (!$dryRun && $processed > 0) {
    printf(
        "Originals %.1f MB -> companions %.1f MB\n",
        $bytesIn / 1_048_576,
        $bytesOut / 1_048_576
    );
    echo "\nOriginals are untouched. Rebuild the site so pages pick the companions up:\n";
    echo "  php bin/build.php\n";
}
