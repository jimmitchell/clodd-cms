<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Builder;
use CMS\Database;
use CMS\Post;
use CMS\PostPublisher;
use PHPUnit\Framework\TestCase;

/**
 * The one place that knows what publishing a post entails.
 *
 * Every assertion here was a bug at one entry point and correct at another
 * before 1.37.0 — see the class docblock on PostPublisher. They are pinned
 * against the publisher rather than against any one caller precisely so that
 * adding a sixth entry point cannot reintroduce them.
 */
final class PostPublisherTest extends TestCase
{
    private Database $db;
    private string $dbPath;
    private string $root;
    private PostPublisher $publisher;
    private Builder $builder;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'clodd_test_') . '.db';
        $this->db     = new Database($this->dbPath);

        $this->root = realpath(sys_get_temp_dir()) . '/clodd_out_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/output', 0775, true);
        mkdir($this->root . '/templates', 0775, true);
        file_put_contents($this->root . '/templates/post.php', '<h1><?= $post->title ?></h1>');

        $config = ['paths' => [
            'output'    => $this->root . '/output',
            'templates' => $this->root . '/templates',
            'content'   => $this->root . '/content',
        ]];

        $this->builder = new class ($config, $this->db) extends Builder {
            /** @var string[] */ public array $built = [];
            /** @var int[] */    public array $categoryArchives = [];
            /** @var int[] */    public array $tagArchives = [];
            public int $sharedRebuilds = 0;
            /** @var string[] */ public array $feeds = [];

            public function buildPost(Post $post): void
            {
                $this->built[] = $post->slug;
                parent::buildPost($post);
            }

            public function buildCategoryArchive(int $categoryId): void { $this->categoryArchives[] = $categoryId; }
            public function buildTagArchive(int $tagId): void           { $this->tagArchives[] = $tagId; }
            public function rebuildSharedResources(): void              { $this->sharedRebuilds++; }
            public function buildFeed(): void                           { $this->feeds[] = 'atom'; }
            public function buildJsonFeed(): void                       { $this->feeds[] = 'json'; }
            public function buildRssFeed(): void                        { $this->feeds[] = 'rss'; }
        };

        $this->publisher = new PostPublisher($this->db, $this->builder);
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

    // ── Neighbours ────────────────────────────────────────────────────────────

    public function testMovingAPostRebuildsBothTheOldAndNewNeighbours(): void
    {
        $first  = $this->publishedPost('first',  '2026-01-01 09:00:00');
        $middle = $this->publishedPost('middle', '2026-01-02 09:00:00');
        $last   = $this->publishedPost('last',   '2026-01-03 09:00:00');

        $before = $this->publisher->snapshot($middle);
        $middle->published_at = '2026-06-01 09:00:00';
        $middle->save();

        $this->reset();
        $this->publisher->rebuildAfterSave($middle, $before);

        $this->assertContains($first->slug, $this->builder->built,
            'The post before the old position keeps a "next" link to a post that moved away.');
        $this->assertContains($last->slug, $this->builder->built);
    }

    public function testAnEditThatChangesNeitherTitleNorUrlLeavesNeighboursAlone(): void
    {
        $this->publishedPost('first', '2026-01-01 09:00:00');
        $middle = $this->publishedPost('middle', '2026-01-02 09:00:00');
        $this->publishedPost('last', '2026-01-03 09:00:00');

        $before = $this->publisher->snapshot($middle);
        $middle->content = 'A typo, fixed.';
        $middle->save();

        $this->reset();
        $this->publisher->rebuildAfterSave($middle, $before);

        $this->assertSame(['middle'], $this->builder->built,
            'Neighbours render only this post title and URL, so a body edit cannot have disturbed them.');
    }

    public function testNeighboursAreBuiltOnceWhenTheOldAndNewPairsOverlap(): void
    {
        $this->publishedPost('first', '2026-01-01 09:00:00');
        $middle = $this->publishedPost('middle', '2026-01-02 09:00:00');
        $this->publishedPost('last', '2026-01-03 09:00:00');

        $before = $this->publisher->snapshot($middle);
        $middle->title = 'Retitled in place';
        $middle->save();

        $this->reset();
        $this->publisher->rebuildAfterSave($middle, $before);

        foreach (array_count_values($this->builder->built) as $slug => $n) {
            $this->assertSame(1, $n, "Built {$slug} {$n} times.");
        }
    }

    // ── Terms ─────────────────────────────────────────────────────────────────

    public function testTheArchiveAPostLeavesIsRebuilt(): void
    {
        $old = (int) $this->db->insert('categories', ['name' => 'Leaving', 'slug' => 'leaving', 'description' => '']);
        $new = (int) $this->db->insert('categories', ['name' => 'Arriving', 'slug' => 'arriving', 'description' => '']);

        $post = $this->publishedPost('moved', '2026-01-02 09:00:00');
        $post->saveTerms([$old], []);

        $before = $this->publisher->snapshot($post);
        $this->assertSame([$old], $before['catIds'], 'The snapshot must be taken before saveTerms() refreshes the in-memory terms.');

        $post->saveTerms([$new], []);

        $this->reset();
        $this->publisher->rebuildAfterSave($post, $before);

        $this->assertContains($old, $this->builder->categoryArchives,
            'The vacated archive still shows the post card until it is rebuilt.');
    }

    public function testTagsGetTheSameTreatmentAsCategories(): void
    {
        $old = (int) $this->db->insert('tags', ['name' => 'old', 'slug' => 'old']);
        $post = $this->publishedPost('retagged', '2026-01-02 09:00:00');
        $post->saveTerms([], [$old]);

        $before = $this->publisher->snapshot($post);
        $post->saveTerms([], []);

        $this->reset();
        $this->publisher->rebuildAfterSave($post, $before);

        $this->assertContains($old, $this->builder->tagArchives);
    }

    // ── Feeds ─────────────────────────────────────────────────────────────────

    /**
     * The index can be skipped on a body-only edit; the feeds cannot, because
     * all three carry the whole body. Building two of them left feed.rss on the
     * pre-edit text, and looking right because the other two were.
     */
    public function testABodyOnlyEditSkipsTheIndexButRebuildsAllThreeFeeds(): void
    {
        $post = $this->publishedPost('titled', '2026-01-02 09:00:00');

        $before = $this->publisher->snapshot($post);
        $post->content = 'Rewritten body.';
        $post->save();

        $this->reset();
        $this->publisher->rebuildAfterSave($post, $before);

        $this->assertSame(0, $this->builder->sharedRebuilds, 'Nothing the index displays changed.');
        $this->assertEqualsCanonicalizing(['atom', 'json', 'rss'], $this->builder->feeds,
            'Every feed carries the whole body, so a body edit changes all three.');
    }

    public function testATitleChangeRebuildsTheSharedPages(): void
    {
        $post = $this->publishedPost('titled', '2026-01-02 09:00:00');

        $before = $this->publisher->snapshot($post);
        $post->title = 'A different headline';
        $post->save();

        $this->reset();
        $this->publisher->rebuildAfterSave($post, $before);

        $this->assertSame(1, $this->builder->sharedRebuilds);
    }

    /** A note's whole body is rendered into the home and archive cards. */
    public function testEditingANoteAlwaysRebuildsTheSharedPages(): void
    {
        $note = $this->publishedPost('a-note', '2026-01-02 09:00:00');
        $note->title     = '';
        $note->post_kind = 'aside';
        $note->save();

        $before = $this->publisher->snapshot($note);
        $note->content = 'Reworded note.';
        $note->save();

        $this->reset();
        $this->publisher->rebuildAfterSave($note, $before);

        $this->assertSame(1, $this->builder->sharedRebuilds,
            'The home page renders a note in full, so its body edit has to propagate.');
    }

    /** Adding a category changes the card set on the index. */
    public function testChangingTermsRebuildsTheSharedPages(): void
    {
        $cat  = (int) $this->db->insert('categories', ['name' => 'New', 'slug' => 'new', 'description' => '']);
        $post = $this->publishedPost('titled', '2026-01-02 09:00:00');

        $before = $this->publisher->snapshot($post);
        $post->saveTerms([$cat], []);

        $this->reset();
        $this->publisher->rebuildAfterSave($post, $before);

        $this->assertSame(1, $this->builder->sharedRebuilds);
    }

    // ── Drafts ────────────────────────────────────────────────────────────────

    public function testADraftThatWasNeverPublicTouchesNothingShared(): void
    {
        $post          = new Post($this->db);
        $post->title   = 'Draft';
        $post->slug    = 'draft';
        $post->content = 'Not yet.';
        $post->status  = 'draft';
        $post->save();

        $before = $this->publisher->snapshot($post);
        $this->reset();
        $this->publisher->rebuildAfterSave($post, $before);

        $this->assertSame(0, $this->builder->sharedRebuilds);
        $this->assertSame([], $this->builder->feeds);
    }

    /** Unpublishing is not "nothing changed" — the post has to leave the shared pages. */
    public function testUnpublishingRebuildsTheSharedPages(): void
    {
        $post   = $this->publishedPost('going-away', '2026-01-02 09:00:00');
        $before = $this->publisher->snapshot($post);

        $post->status = 'draft';
        $post->save();

        $this->reset();
        $this->publisher->rebuildAfterSave($post, $before);

        $this->assertSame(1, $this->builder->sharedRebuilds);
    }

    // ── Syndication ordering ──────────────────────────────────────────────────

    /**
     * Mastodon fetches the permalink once, seconds after the status is created,
     * and never retries. Publishing before the page is on disk loses the card
     * permanently — on a status that otherwise looks perfect.
     */
    public function testTheSyndicationBlockIsDocumentedAsRunningAfterTheBuild(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/src/PostPublisher.php');

        $syndicate = strpos($src, 'function syndicateAfterBuild');
        $rebuild   = strpos($src, 'function rebuildAfterSave');

        $this->assertNotFalse($syndicate);
        $this->assertNotFalse($rebuild);
        $this->assertLessThan($syndicate, $rebuild,
            'rebuildAfterSave() is declared first so the ordering is legible in the file, not only in the docblock.');
    }

    /** With no Syndication wired in there is nothing to publish and nothing to raise. */
    public function testSyndicationIsOptional(): void
    {
        $post = $this->publishedPost('unsyndicated', '2026-01-02 09:00:00');

        $this->reset();
        $this->publisher->syndicateAfterBuild($post);
        $this->publisher->resyndicateAfterBuild($post, $this->publisher->snapshot($post));

        $this->assertSame([], $this->builder->built);
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /**
     * The copies have to come down *before* the row goes, because the row is
     * where their ids live. admin/api.php had this inverted — it built the post
     * before deleting it rather than after — which is the sort of ordering that
     * survives review precisely because nothing observable goes wrong until the
     * day it does.
     *
     * Structural, because Syndication is final and the ordering leaves no trace
     * to assert on afterwards: by the time deletePost() returns, either the ids
     * were read or they were lost, and the row looks the same either way.
     */
    public function testTheCopiesAreRemovedBeforeTheRowIsDeleted(): void
    {
        $src  = (string) file_get_contents(dirname(__DIR__) . '/src/PostPublisher.php');
        $body = substr($src, strpos($src, 'function deletePost('));

        $remove = strpos($body, 'syndication?->remove(');
        $delete = strpos($body, '$post->delete()');

        $this->assertNotFalse($remove, 'deletePost() must take the syndicated copies down.');
        $this->assertNotFalse($delete);
        $this->assertLessThan($delete, $remove,
            'The row carries the Mastodon, Bluesky and Pixelfed ids — remove() has nothing to work from once it is gone.');
    }

    public function testASoftDeleteKeepsTheRowSoUndeleteCanFindIt(): void
    {
        $post = $this->publishedPost('recoverable', '2026-01-02 09:00:00');
        $id   = $post->id;

        $this->publisher->deletePost($post, soft: true);

        $found = Post::findById($this->db, (int) $id);
        $this->assertNotNull($found, 'A soft delete must leave the row for action=undelete.');
        $this->assertNotNull($found->deleted_at);
    }

    public function testAHardDeleteRemovesTheRow(): void
    {
        $post = $this->publishedPost('final', '2026-01-02 09:00:00');
        $id   = $post->id;

        $this->publisher->deletePost($post);

        $this->assertNull(Post::findById($this->db, (int) $id));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function reset(): void
    {
        $this->builder->built            = [];
        $this->builder->categoryArchives = [];
        $this->builder->tagArchives      = [];
        $this->builder->sharedRebuilds   = 0;
        $this->builder->feeds            = [];
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
