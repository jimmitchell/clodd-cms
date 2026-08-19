#!/usr/bin/env php
<?php

/**
 * Promote a titled post's leading body image into its featured image field.
 *
 * Every post written before the field existed keeps its lead picture as the
 * opening paragraph of the body — that is how the WordPress import left them,
 * and how MarsEdit writes them. Those pictures already render in the right
 * place, but nothing in the database knows what they are, so they never reach
 * og:image, the card thumbnails, or the feeds' item image.
 *
 * This moves the picture: into featured_image_url/_alt, out of the body. The
 * rendered page comes out the same — the image simply moves from a <p> in the
 * content into the featured figure above it — but it is now real data.
 *
 * Only titled posts (post_kind = 'standard') with no featured image already set
 * and a body that *begins* with an image are touched. An image further down is
 * an illustration and is left alone.
 *
 * Dry run by default: this rewrites post content, so nothing is written until
 * you have read the report and passed --force.
 *
 * Usage:
 *   php bin/promote-featured-images.php [--force] [--limit=N]
 *
 * Options:
 *   --force     Actually write. Without it, nothing is modified.
 *   --limit=N   Stop after N posts. Useful for a first pass.
 *
 * On the server this must run as the user that owns the database:
 *   sudo -u www-data php bin/promote-featured-images.php
 */

declare(strict_types=1);

define('CMS_ROOT', dirname(__DIR__));

require CMS_ROOT . '/vendor/autoload.php';

use CMS\Database;
use CMS\Post;

if (!class_exists(Database::class)) {
    fwrite(STDERR, "Error: autoloader not found. Run 'composer install' first.\n");
    exit(1);
}

$force = in_array('--force', $argv, true);

$limit = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(0, (int) substr($arg, 8));
    }
}

$config = require CMS_ROOT . '/config.php';
$db     = new Database($config['paths']['data'] . '/cms.db');

$rows = $db->select(
    "SELECT id, title, slug, content, status, featured_image_url
       FROM posts
      WHERE post_kind = 'standard'
        AND deleted_at IS NULL
        AND (featured_image_url IS NULL OR featured_image_url = '')
      ORDER BY published_at DESC, id DESC"
);

echo ($force ? '' : 'Dry run — nothing will be written. ')
    . count($rows) . " titled post(s) with no featured image.\n\n";

$promoted = 0;
$skipped  = 0;

foreach ($rows as $row) {
    if ($limit > 0 && $promoted >= $limit) {
        break;
    }

    $content  = (string) $row['content'];
    $featured = Post::leadingBodyImage($content);
    if ($featured === null) {
        $skipped++;
        continue;
    }

    // The picture has to survive the trip: a body whose leading image cannot be
    // stripped cleanly would end up rendering it twice, once in the featured
    // slot and once still in the content. Leave those for a human.
    $newContent = Post::withoutLeadingImage($content);
    if ($newContent === $content) {
        fwrite(STDERR, sprintf(
            "  ! #%d %s — leading image found but not removable; skipped\n",
            (int) $row['id'],
            (string) $row['slug']
        ));
        $skipped++;
        continue;
    }

    printf(
        "  #%-6d %s\n           %s%s\n",
        (int) $row['id'],
        (string) $row['slug'],
        $featured['url'],
        $featured['alt'] === '' ? '  (no alt text)' : ''
    );

    if ($force) {
        $db->update(
            'posts',
            [
                'content'            => $newContent,
                'featured_image_url' => $featured['url'],
                'featured_image_alt' => $featured['alt'],
                // The build short-circuits on content_hash, and the rendered
                // HTML is about to change. Clearing it is what makes the next
                // build write the page again.
                'content_hash'       => null,
            ],
            'id = :id',
            ['id' => (int) $row['id']]
        );
    }

    $promoted++;
}

echo "\n";
echo ($force ? "Promoted: {$promoted}\n" : "Would promote: {$promoted}\n");
echo "Left alone (no leading image): {$skipped}\n";

if (!$force && $promoted > 0) {
    echo "\nNothing was written. Re-run with --force to apply:\n";
    echo "  php bin/promote-featured-images.php --force\n";
} elseif ($force && $promoted > 0) {
    echo "\nRebuild so the pages pick up the featured figure:\n";
    echo "  php bin/build.php\n";
}
