#!/usr/bin/env php
<?php

/**
 * Give the legacy numeric asides readable slugs, keeping their old addresses.
 *
 * Until 1.11.0 a titleless note had nothing to slugify, so it took the
 * autoincrement id as its slug and published at /YYYY/MM/DD/783/. 1.11.0 started
 * deriving one from the body and deliberately left the backlog alone, because
 * re-slugging would have broken every URL already out in the world. What makes
 * it affordable now is the pair that arrived since:
 *
 *   - post_legacy_urls remembers the vacated address, so bin/build-redirects.php
 *     can 301 it and the post page can still ask webmention.io about it;
 *   - Syndication::payload() sends a note as its whole body with no permalink
 *     beside it, so the Mastodon, Bluesky and Pixelfed copies carry no link
 *     back that could go stale.
 *
 * Read-only by default. Print the whole old -> new table and read it before
 * passing --apply; a slug is a URL, and this rewrites hundreds of them at once.
 *
 * Usage:
 *   php bin/reslug-numeric-asides.php           Report what would change
 *   php bin/reslug-numeric-asides.php --apply   Write it
 */

define('CMS_ROOT', dirname(__DIR__));
define('CMS_VERSION', trim(file_get_contents(CMS_ROOT . '/VERSION')));

require CMS_ROOT . '/vendor/autoload.php';

use CMS\Builder;
use CMS\Database;
use CMS\Post;

if (!class_exists(Database::class)) {
    fwrite(STDERR, "Error: autoloader not found. Run 'composer install' first.\n");
    exit(1);
}

$config   = require CMS_ROOT . '/config.php';
$db       = new Database($config['paths']['data'] . '/cms.db');
$settings = $db->getAllSettings();
$timezone = $settings['timezone'] ?? '';
$apply    = in_array('--apply', $argv ?? [], true);

$builder = new Builder($config, $db);

/**
 * The slug a numeric aside should have had.
 *
 * import_guid holds the post's original micro.blog permalink, whose last segment
 * is the slug micro.blog derived from the same body — better truncated than a
 * fresh five-word cut, and the address these posts were actually read at for
 * years. Post::slugFromContent() is the fallback for a note written here rather
 * than imported.
 *
 * Returns '' when neither yields anything: a bare like-of or a photo-only note
 * has no words to name it, which is the case the id fallback exists for. Those
 * keep their numeric slug.
 */
function reslug_base(Post $post, string $importGuid): string
{
    $guid = trim($importGuid);
    if ($guid !== '') {
        $path    = (string) parse_url($guid, PHP_URL_PATH);
        $segment = basename(rtrim($path, '/'));
        // A guid that is not a URL, or whose last segment is the id again,
        // tells us nothing the body will not tell us better.
        if ($segment !== '' && $segment !== $post->slug && !ctype_digit($segment)) {
            return $segment;
        }
    }

    return Post::slugFromContent($post->content);
}

$planned = [];
$skipped = [];
$claimed = [];

foreach (Post::findAll($db, 'published') as $post) {
    if (!ctype_digit($post->slug) || $post->id === null || $post->published_at === null) {
        continue;
    }

    // import_guid is a column Post does not hydrate, and adding it to the object
    // would put it in reach of a write path that has no business touching it.
    $guidRow = $db->selectOne("SELECT import_guid FROM posts WHERE id = :id", ['id' => $post->id]);

    $base = reslug_base($post, (string) ($guidRow['import_guid'] ?? ''));
    if ($base === '') {
        $skipped[] = $post;
        continue;
    }

    // resolveUniqueSlug() applies the digit guard and the -2 suffixing, and its
    // $excludeId early-return does not fire here: the base is the derived slug,
    // never the numeric one the post currently holds.
    //
    // It only knows what is *in the database*, though, and nothing is written
    // until the transaction below — so two posts whose bodies open the same way
    // would both be handed the same free slug and the second UPDATE would hit
    // the UNIQUE constraint. $claimed is the other half of the check. It is not
    // hypothetical: six pairs in this backlog share a micro.blog slug.
    $new = Post::resolveUniqueSlug($db, $base, $post->id);
    for ($n = 2; isset($claimed[$new]); $n++) {
        $new = Post::resolveUniqueSlug($db, $base . '-' . $n, $post->id);
    }
    $claimed[$new] = true;

    if ($new === $post->slug) {
        continue;
    }

    $planned[] = [
        'post'    => $post,
        'oldPath' => $builder->postUrlPath($post->published_at, $post->slug),
        'oldDir'  => $builder->postOutputDir($post->published_at, $post->slug),
        'newSlug' => $new,
    ];
}

foreach ($planned as $row) {
    printf("%-28s -> %s\n", $row['oldPath'], dirname($row['oldPath']) . '/' . $row['newSlug']);
}

printf("\n%d numeric slug%s to rewrite.\n", count($planned), count($planned) === 1 ? '' : 's');

if ($skipped !== []) {
    printf("%d skipped — no words to slug from, keeping the id:\n", count($skipped));
    foreach ($skipped as $post) {
        printf("  %s\n", $builder->postUrlPath($post->published_at, $post->slug));
    }
}

if (!$apply) {
    print("\nNothing written. Re-run with --apply once the table above reads right.\n");
    exit(0);
}

if ($planned === []) {
    exit(0);
}

// One transaction for the whole set: a half-applied rename would leave some
// posts redirecting and others not, with no record of which.
$db->transaction(function () use ($db, $planned, $timezone): void {
    foreach ($planned as $row) {
        /** @var \CMS\Post $post */
        $post = $row['post'];

        $db->update('posts', ['slug' => $row['newSlug']], 'id = :id', ['id' => $post->id]);
        $post->slug = $row['newSlug'];
        $post->recordLegacyPath($row['oldPath'], $timezone);

        // Hold the outbound webmention clock still. bin/send-webmentions.php
        // re-sends when updated_at > webmentions_sent_at, and a bulk rename
        // would otherwise re-ping every site these posts link to. Only our
        // source URL moved; the targets did not, and a second ping would just
        // file a duplicate mention on someone else's page under two addresses.
        $db->update(
            'posts',
            ['webmentions_sent_at' => date('Y-m-d H:i:s')],
            'id = :id',
            ['id' => $post->id]
        );
    }
});

// Clear the vacated output. Deliberately not the per-post publish pipeline: the
// shared pages it would rebuild are the same pages every time round the loop,
// so a single bin/build.php afterwards is both cheaper and more correct.
foreach ($planned as $row) {
    $builder->removeVacatedPostOutput($row['oldDir'], $row['post']);
}

printf("\n%d slugs rewritten. Now:\n", count($planned));
print("  php bin/build.php                 (rebuild every moved post)\n");
print("  php bin/build-redirects.php       (regenerate the nginx 301s)\n");
