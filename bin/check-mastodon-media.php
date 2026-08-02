#!/usr/bin/env php
<?php

/**
 * Attempt a Mastodon media upload for one post and report exactly what the
 * instance said.
 *
 * Syndication runs on the publish path, where a failure cannot be shown to
 * anybody and must not take the publish down with it, so an image that never
 * arrives leaves no trace beyond a copy with no picture. This walks the same
 * code the publish path walks — SyndicationMedia::forPost() to resolve the
 * files, Mastodon::uploadMedia() to send one — and prints the HTTP status and
 * body behind the failure.
 *
 * It uploads, but it does not post: an attachment nobody attaches to a status
 * is unlisted, and the instance discards it within the hour. Nothing appears
 * on the timeline and no post record is touched.
 *
 * Usage:
 *   php bin/check-mastodon-media.php        The most recent photo post
 *   php bin/check-mastodon-media.php 812    That post id
 */

define('CMS_ROOT', dirname(__DIR__));
define('CMS_VERSION', trim(file_get_contents(CMS_ROOT . '/VERSION')));

// The failure detail is written with error_log(), which the publish path wants
// in the FPM log. Here it is the whole point of running, so send it to stderr
// rather than wherever the CLI php.ini would have filed it.
ini_set('error_log', '');

require CMS_ROOT . '/vendor/autoload.php';

use CMS\Database;
use CMS\Mastodon;
use CMS\Post;
use CMS\SyndicationMedia;

if (!class_exists(Database::class)) {
    fwrite(STDERR, "Error: autoloader not found. Run 'composer install' first.\n");
    exit(1);
}

$config = require CMS_ROOT . '/config.php';
$db     = new Database($config['paths']['data'] . '/cms.db');

$instance = $db->getSetting('mastodon_instance');
$token    = $db->getSetting('mastodon_token');
if ($instance === '' || $token === '') {
    fwrite(STDERR, "Error: no Mastodon instance or token configured in settings.\n");
    exit(1);
}

// ── The post ────────────────────────────────────────────────────────────────

$argId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($argId > 0) {
    $post = Post::findById($db, $argId);
} else {
    $row  = $db->selectOne(
        "SELECT id FROM posts WHERE post_kind = 'photo' AND deleted_at IS NULL ORDER BY published_at DESC LIMIT 1"
    );
    $post = $row ? Post::findById($db, (int) $row['id']) : null;
}

if ($post === null) {
    fwrite(STDERR, $argId > 0 ? "Error: no post with id {$argId}.\n" : "Error: no photo posts found.\n");
    exit(1);
}

echo "Post {$post->id}: {$post->slug} ({$post->post_kind}, {$post->status})\n";
echo 'Instance: ' . $instance . "\n\n";

// ── The images the publish path would have sent ─────────────────────────────

$images = SyndicationMedia::forPost(
    $post,
    $config['paths']['content'] . '/media',
    rtrim($db->getSetting('site_url', ''), '/')
);

if ($images === []) {
    echo "No attachable images resolved for this post.\n";
    echo "Only files inside content/media are attachable — check the photo rows and the body.\n";
    exit(0);
}

echo count($images) . " image(s) resolved:\n";
foreach ($images as $i => $image) {
    $bytes = (@filesize($image['path']) ?: 0);
    printf(
        "  [%d] %s\n      %s, %s (%d bytes)%s\n",
        $i,
        $image['path'],
        $image['mime'],
        number_format($bytes / 1048576, 2) . ' MiB',
        $bytes,
        $image['alt'] !== '' ? "\n      alt: " . $image['alt'] : ''
    );
}

// ── Send the first one ──────────────────────────────────────────────────────

echo "\nUploading image [0] to {$instance} …\n";
echo "Any [mastodon] lines below are the failure detail.\n\n";

$mastodon = new Mastodon($instance, $token);
$upload   = new ReflectionMethod(Mastodon::class, 'uploadMedia');
$id       = $upload->invoke($mastodon, $images[0]['path'], $images[0]['mime'], $images[0]['alt']);

echo "\n";
if ($id === null) {
    echo "FAILED — the instance did not give back an attachment id.\n";
    exit(1);
}

echo "OK — attachment id {$id}.\n";
echo "The upload works, so a missing picture is in how the status POST carries the ids.\n";
