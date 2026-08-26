<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Auth;
use CMS\Builder;
use CMS\Database;
use CMS\FeedMarkdown;
use CMS\Post;
use CMS\XmlRpcServer;
use PHPUnit\Framework\TestCase;

/**
 * What a save has to rebuild besides the post itself.
 *
 * A published post is not one file. It is the post page, the two neighbours
 * whose prev/next links name it, the archive of every term it belongs to, and
 * the three feeds. Each write path assembled that list by hand, and each one
 * assembled a different list — so the bugs pinned here were all the same bug
 * wearing four hats, and all of them were invisible from the editor: the page
 * you just saved always looked right.
 */
final class BuildFanoutTest extends TestCase
{
    private Database $db;
    private string $dbPath;
    private string $root;
    private array $config;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'clodd_test_') . '.db';
        $this->db     = new Database($this->dbPath);

        $this->root = realpath(sys_get_temp_dir()) . '/clodd_out_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/output', 0775, true);
        mkdir($this->root . '/templates', 0775, true);
        file_put_contents($this->root . '/templates/post.php', '<h1><?= $post->title ?></h1>');

        $this->config = ['paths' => [
            'output'    => $this->root . '/output',
            'templates' => $this->root . '/templates',
            'content'   => $this->root . '/content',
        ]];
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->root);
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }

    // ── 1d: the deferTaxonomy guard ───────────────────────────────────────────

    /**
     * buildAll() has set both guards since the quadratic archive rebuild was
     * found; rebuildPosts() set only one, so the fix never reached the path the
     * settings screen and both importers actually call.
     *
     * Structural, because the guard's whole effect is a method returning early:
     * buildCategoryArchive() is still *called* once per post either way, and
     * counting calls from a subclass cannot see the difference. The cost it
     * avoids is real — the largest category here rebuilt its archive, its
     * pagination and its three feeds once for every post in it.
     */
    public function testRebuildPostsDefersTaxonomyTheWayBuildAllDoes(): void
    {
        $src  = (string) file_get_contents(dirname(__DIR__) . '/src/Builder.php');
        $body = $this->methodBody($src, 'rebuildPosts');

        $this->assertStringContainsString('$this->deferTaxonomy = true;', $body,
            'rebuildPosts() must defer taxonomy archives — without it a category holding N posts is rebuilt N times.');
        $this->assertStringContainsString('$this->deferRelated', $body);
        $this->assertStringContainsString('$this->deferTaxonomy = false;', $body,
            'The guard must be cleared in a finally, or every later single-post save silently stops rebuilding archives.');
    }

    /**
     * The guard is only safe because every caller covers the terms itself
     * afterwards. import-media.handler.php did not, and it rewrites post
     * bodies — so deferring without this would have traded a slow correct
     * rebuild for a fast stale one.
     */
    public function testEveryCallerOfRebuildPostsFollowsItWithATaxonomyPass(): void
    {
        $root  = dirname(__DIR__);
        $files = [];
        $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/admin'));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }

        $callers = 0;
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            if (!str_contains($src, 'rebuildPosts()')) {
                continue;
            }
            $callers++;
            $this->assertStringContainsString(
                'buildAllTaxonomyArchives()',
                $src,
                basename($file) . ' calls rebuildPosts(), which defers taxonomy archives, but never covers the terms afterwards.'
            );
        }

        $this->assertGreaterThan(0, $callers, 'Expected to find callers of rebuildPosts().');
    }

    // ── 1b: old-position neighbours ───────────────────────────────────────────

    /**
     * Move a post in the timeline and two posts go stale, not one: the pair it
     * used to sit between still link to where it was. Rebuilding only the new
     * neighbours fixes the destination and leaves the origin wrong.
     */
    public function testMovingAPostInTimeRebuildsTheNeighboursItLeftBehind(): void
    {
        $first  = $this->publishedPost('first',  '2026-01-01 09:00:00');
        $middle = $this->publishedPost('middle', '2026-01-02 09:00:00');
        $last   = $this->publishedPost('last',   '2026-01-03 09:00:00');

        [$server, $builder] = $this->server();

        $before = $this->call($server, 'xmlrpc_snapshot', [$middle, true]);

        // Past every other post, so its old neighbours are no longer adjacent.
        $middle->published_at = '2026-06-01 09:00:00';
        $middle->save();

        $builder->built = [];
        $this->call($server, 'rebuildPost', [$middle, true, $before]);

        $this->assertContains($first->slug, $builder->built,
            'The post before the old position keeps a "next" link to a post that moved away.');
        $this->assertContains($last->slug, $builder->built,
            'The post after the old position keeps a "prev" link to a post that moved away.');
    }

    /** The de-duplication is real: an edit that moves nothing builds each neighbour once. */
    public function testAnEditThatDoesNotMoveThePostBuildsEachNeighbourOnce(): void
    {
        $this->publishedPost('first',  '2026-01-01 09:00:00');
        $middle = $this->publishedPost('middle', '2026-01-02 09:00:00');
        $this->publishedPost('last',   '2026-01-03 09:00:00');

        [$server, $builder] = $this->server();
        $before = $this->call($server, 'xmlrpc_snapshot', [$middle, true]);

        $middle->title = 'Retitled, same position';
        $middle->save();

        $builder->built = [];
        $this->call($server, 'rebuildPost', [$middle, true, $before]);

        $counts = array_count_values($builder->built);
        foreach ($counts as $slug => $n) {
            $this->assertSame(1, $n, "Built {$slug} {$n} times; the old and new neighbour lists overlap and must be de-duplicated.");
        }
    }

    // ── 1c: the archive a post was removed from ───────────────────────────────

    /**
     * buildPost() rebuilds the archives of the terms the post holds *now*, so
     * the term it was just taken out of is the one nothing covers. The XML-RPC
     * path did try — but it read $post->categories for the "old" ids *after*
     * saveTerms() had already refreshed them in memory, so it was diffing the
     * new set against itself and the loop could never fire.
     */
    public function testRemovingACategoryRebuildsTheArchiveThePostLeft(): void
    {
        $oldCat = (int) $this->db->insert('categories', ['name' => 'Leaving',  'slug' => 'leaving',  'description' => '']);
        $newCat = (int) $this->db->insert('categories', ['name' => 'Arriving', 'slug' => 'arriving', 'description' => '']);

        $post = $this->publishedPost('recategorised', '2026-01-02 09:00:00');
        $post->saveTerms([$oldCat], []);

        [$server, $builder] = $this->server();
        $before = $this->call($server, 'xmlrpc_snapshot', [$post, true]);

        $this->assertSame([$oldCat], $before['catIds'], 'The snapshot must capture terms before saveTerms() refreshes them.');

        $post->saveTerms([$newCat], []);

        $builder->categoryArchives = [];
        $this->call($server, 'rebuildPost', [$post, true, $before]);

        $this->assertContains($oldCat, $builder->categoryArchives,
            'The vacated archive still shows the post card until it is rebuilt.');
        $this->assertContains($newCat, $builder->categoryArchives,
            'The archive the post joined has to gain the card.');
    }

    // ── 1a: the cheap feed path ───────────────────────────────────────────────

    /**
     * The editor's fast path — skip the index when nothing it displays changed,
     * but never skip a feed, because all three carry the whole body. It built
     * two of the three, and feed.rss served pre-edit text until the next full
     * rebuild while looking fine because the other two were right.
     *
     * 1.37.0 moved that decision into PostPublisher, where
     * PostPublisherTest::testABodyOnlyEditSkipsTheIndexButRebuildsAllThreeFeeds
     * pins it by behaviour instead of by source. What is left to assert here is
     * the reason it can be pinned in one place at all: that no write path
     * assembles the sequence by hand any more.
     */
    public function testNoWritePathBuildsAndSyndicatesByHand(): void
    {
        $root  = dirname(__DIR__);
        $paths = array_merge(
            glob($root . '/*.php') ?: [],
            glob($root . '/admin/*.php') ?: [],
            glob($root . '/src/*.php') ?: [],
        );

        foreach ($paths as $file) {
            if (basename($file) === 'PostPublisher.php') {
                continue;   // the one place that is allowed to know.
            }

            $src = (string) file_get_contents($file);
            $this->assertStringNotContainsString(
                'syndication->publish(',
                $src,
                basename($file) . ' calls Syndication::publish() directly. That belongs to '
                    . 'PostPublisher::syndicateAfterBuild(), which knows it has to run *after* the '
                    . 'build — Mastodon fetches the permalink once and never retries, so a copy '
                    . 'made before the page is on disk loses its card permanently.'
            );
        }
    }

    // ── The feed tracking pixel ───────────────────────────────────────────────

    public function testTheTrackingPixelIsEmptyWithoutACode(): void
    {
        $this->assertSame('', FeedMarkdown::trackingPixel([], '/posts/2026/01/02/x/'));
        $this->assertSame('', FeedMarkdown::trackingPixel(['tinylytics_code' => ''], '/posts/2026/01/02/x/'));
    }

    public function testTheTrackingPixelCarriesTheEntryPath(): void
    {
        $html = FeedMarkdown::trackingPixel(['tinylytics_code' => 'abc123'], '/posts/2026/01/02/x/');

        $this->assertStringContainsString('https://tinylytics.app/pixel/abc123.gif', $html);
        $this->assertStringContainsString(rawurlencode('/posts/2026/01/02/x/'), $html);
        $this->assertStringContainsString('alt=""', $html, 'A counting pixel is decorative and must not be announced.');
    }

    /**
     * It was written inline in Feed::render() and nowhere else, so readers of
     * the RSS feed, the JSON feed and every per-term Atom feed were invisible
     * while readers of the main Atom feed were counted. Partial numbers are
     * worse than no numbers, because they look like numbers.
     */
    public function testEveryFeedBuilderEmitsTheTrackingPixel(): void
    {
        foreach (['Feed.php', 'RssFeed.php', 'JsonFeed.php'] as $file) {
            $src = (string) file_get_contents(dirname(__DIR__) . '/src/' . $file);

            $this->assertSame(
                2,
                substr_count($src, 'FeedMarkdown::trackingPixel('),
                "{$file} renders entries in two places — the site feed and the per-term feed — and both must count."
            );
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array{0:XmlRpcServer,1:Builder} */
    private function server(): array
    {
        $builder = new class ($this->config, $this->db) extends Builder {
            /** @var string[] */
            public array $built = [];
            /** @var int[] */
            public array $categoryArchives = [];

            public function buildPost(Post $post): void
            {
                $this->built[] = $post->slug;
                parent::buildPost($post);
            }

            public function buildCategoryArchive(int $categoryId): void
            {
                $this->categoryArchives[] = $categoryId;
            }

            public function buildTagArchive(int $tagId): void
            {
            }

            public function rebuildSharedResources(): void
            {
            }
        };

        $server = new XmlRpcServer($this->db, new Auth($this->config, $this->db), $this->config, $builder);

        return [$server, $builder];
    }

    /** Reach a private method — the same approach XmlRpcSchedulingTest uses. */
    private function call(XmlRpcServer $server, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod(XmlRpcServer::class, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($server, $args);
    }

    private function publishedPost(string $slug, string $publishedAt): Post
    {
        $post               = new Post($this->db);
        $post->title        = ucfirst($slug);
        $post->slug         = $slug;
        $post->content      = 'Body of ' . $slug . '.';
        $post->status       = 'published';
        $post->published_at = $publishedAt;
        $post->save();

        return $post;
    }

    /** Crude source slice: the body of a method, up to the next one at the same indent. */
    private function methodBody(string $src, string $method): string
    {
        $start = strpos($src, 'function ' . $method . '(');
        $this->assertNotFalse($start, "Could not find {$method}() in the source.");
        $next = strpos($src, "\n    }\n", $start);

        return substr($src, $start, $next === false ? 2000 : $next - $start);
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
