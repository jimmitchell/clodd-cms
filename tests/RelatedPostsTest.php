<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Builder;
use CMS\Database;
use CMS\Post;

/**
 * The Related posts block: what it selects, and when it is rebuilt.
 *
 * Two things here are worth more than the rest. The scoring order, because a
 * weighting nobody checks quietly becomes "newest first" the moment the SQL is
 * touched. And the neighbour rebuild, because a related block makes a post's
 * output depend on *other* posts — which is precisely what buildPost()'s
 * content_hash short-circuit assumes never happens.
 */
final class RelatedPostsTest extends TempSiteTestCase
{
    private Builder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new Builder($this->config, $this->db);
    }


    // ── Selection ─────────────────────────────────────────────────────────────

    public function testAPostSharingNoTermsHasNoRelatedPosts(): void
    {
        $subject = $this->post('subject', 'Subject');
        $subject->saveTerms([$this->category('php')], []);
        $this->post('stranger', 'Stranger')->saveTerms([$this->category('gardening')], []);

        $this->assertSame([], Post::findRelated($this->db, $subject));
    }

    public function testASharedCategoryOutranksASharedTag(): void
    {
        $php     = $this->category('php');
        $sqlite  = $this->tag('sqlite');

        $subject = $this->post('subject', 'Subject');
        $subject->saveTerms([$php], [$sqlite]);

        // Deliberately published newest-first in the order that would win on
        // recency alone, so a regression to date-ordering fails this test.
        $this->post('tag-only', 'Tag only', '2026-07-03 12:00:00')->saveTerms([], [$sqlite]);
        $this->post('cat-only', 'Category only', '2026-07-02 12:00:00')->saveTerms([$php], []);
        $this->post('both', 'Both', '2026-07-01 12:00:00')->saveTerms([$php], [$sqlite]);

        $titles = array_map(fn(Post $p) => $p->title, Post::findRelated($this->db, $subject));

        $this->assertSame(['Both', 'Category only', 'Tag only'], $titles);
    }

    public function testTiesAreBrokenByRecency(): void
    {
        $php     = $this->category('php');
        $subject = $this->post('subject', 'Subject');
        $subject->saveTerms([$php], []);

        $this->post('older', 'Older', '2026-07-01 12:00:00')->saveTerms([$php], []);
        $this->post('newer', 'Newer', '2026-07-05 12:00:00')->saveTerms([$php], []);

        $titles = array_map(fn(Post $p) => $p->title, Post::findRelated($this->db, $subject));

        $this->assertSame(['Newer', 'Older'], $titles);
    }

    public function testTheLimitIsHonoured(): void
    {
        $php     = $this->category('php');
        $subject = $this->post('subject', 'Subject');
        $subject->saveTerms([$php], []);

        foreach (range(1, 5) as $n) {
            $this->post("other-{$n}", "Other {$n}")->saveTerms([$php], []);
        }

        $this->assertCount(3, Post::findRelated($this->db, $subject, 3));
    }

    public function testAPostIsNeverRelatedToItself(): void
    {
        $php     = $this->category('php');
        $subject = $this->post('subject', 'Subject');
        $subject->saveTerms([$php], []);

        $this->assertSame([], Post::findRelated($this->db, $subject));
    }

    /**
     * Notes and photo posts have no title to label a link with, which is the
     * whole reason the feature is scoped to titled posts.
     */
    public function testNotesAndPhotoPostsAreNeverCandidates(): void
    {
        $php     = $this->category('php');
        $subject = $this->post('subject', 'Subject');
        $subject->saveTerms([$php], []);

        $aside = $this->post('an-aside', '');
        $aside->post_kind = 'aside';
        $aside->save();
        $aside->saveTerms([$php], []);

        $photo = $this->post('a-photo', 'Has a title but is a photo');
        $photo->post_kind = 'photo';
        $photo->save();
        $photo->saveTerms([$php], []);

        $this->assertSame([], Post::findRelated($this->db, $subject));
    }

    public function testDraftsAndDeletedPostsAreNeverCandidates(): void
    {
        $php     = $this->category('php');
        $subject = $this->post('subject', 'Subject');
        $subject->saveTerms([$php], []);

        $draft = $this->post('a-draft', 'A draft');
        $draft->status = 'draft';
        $draft->save();
        $draft->saveTerms([$php], []);

        // Soft delete is its own path — save() does not write deleted_at.
        $gone = $this->post('deleted', 'Deleted');
        $gone->saveTerms([$php], []);
        $gone->softDelete();

        $this->assertSame([], Post::findRelated($this->db, $subject));
    }

    /**
     * Two categories in common must score 4, not produce the post twice — the
     * reason the overlap counts are grouped subqueries rather than plain joins.
     */
    public function testMultipleSharedTermsScoreOnceAndCumulatively(): void
    {
        $php     = $this->category('php');
        $sqlite  = $this->category('sqlite');
        $subject = $this->post('subject', 'Subject');
        $subject->saveTerms([$php, $sqlite], []);

        $this->post('two', 'Two shared', '2026-07-01 12:00:00')->saveTerms([$php, $sqlite], []);
        $this->post('one', 'One shared', '2026-07-05 12:00:00')->saveTerms([$php], []);

        $related = Post::findRelated($this->db, $subject);

        $this->assertSame(['Two shared', 'One shared'], array_map(fn(Post $p) => $p->title, $related));
        $this->assertCount(2, $related, 'a post sharing two categories must appear once');
    }

    // ── Rendering ─────────────────────────────────────────────────────────────

    public function testTheBlockIsAbsentWhileTheSettingIsOff(): void
    {
        $this->writeRelatedTemplate();
        [$subject, $dir] = $this->twoRelatedPosts();

        $this->builder->buildPost($subject);

        $this->assertStringNotContainsString('RELATED:', file_get_contents($dir . '/index.html'));
    }

    public function testTheBlockRendersWhenTheSettingIsOn(): void
    {
        $this->enableRelated();
        $this->writeRelatedTemplate();
        [$subject, $dir] = $this->twoRelatedPosts();

        $this->builder->buildPost($subject);

        $this->assertStringContainsString('RELATED:Neighbour', file_get_contents($dir . '/index.html'));
    }

    /**
     * A note must not grow a related block even when it shares a category —
     * the builder gates on isNote() as well as on the candidate predicate.
     */
    public function testANoteRendersNoBlockOfItsOwn(): void
    {
        $this->enableRelated();
        $this->writeRelatedTemplate();

        $php  = $this->category('php');
        $note = $this->post('a-note', '');
        $note->post_kind = 'aside';
        $note->save();
        $note->saveTerms([$php], []);
        $this->post('titled', 'Titled')->saveTerms([$php], []);

        $this->builder->buildPost($note);
        $dir = $this->builder->postOutputDir($note->published_at, $note->slug);

        $this->assertStringNotContainsString('RELATED:', file_get_contents($dir . '/index.html'));
    }

    // ── Invalidation ──────────────────────────────────────────────────────────

    /**
     * The regression this feature is most likely to ship with.
     *
     * buildPost() skips the write when the rendered hash matches the stored
     * one, which assumes a post's output depends only on the post. It doesn't
     * any more: publishing a new post in a shared category changes what every
     * other post in it should show, with no edit of their own to trigger a
     * rebuild. Without the neighbour pass the old list is served forever.
     */
    public function testPublishingANewPostRefreshesItsNeighboursOnDisk(): void
    {
        $this->enableRelated();
        $this->writeRelatedTemplate();

        $php     = $this->category('php');
        $subject = $this->post('subject', 'Subject', '2026-07-01 12:00:00');
        $subject->saveTerms([$php], []);
        $this->builder->buildPost($subject);

        $dir = $this->builder->postOutputDir($subject->published_at, $subject->slug);
        $this->assertStringNotContainsString(
            'Latecomer',
            file_get_contents($dir . '/index.html'),
            'precondition: the later post does not exist yet'
        );

        $late = $this->post('latecomer', 'Latecomer', '2026-07-09 12:00:00');
        $late->saveTerms([$php], []);
        $this->builder->buildPost($late);

        $this->assertStringContainsString(
            'RELATED:Latecomer',
            file_get_contents($dir . '/index.html'),
            'an existing post must pick up a newly published neighbour'
        );
    }

    /**
     * The same hazard in reverse: a post leaving the site has to leave the
     * lists that pointed at it, and buildPost() returns early on that path.
     */
    public function testUnpublishingAPostRemovesItFromItsNeighboursOnDisk(): void
    {
        $this->enableRelated();
        $this->writeRelatedTemplate();

        $php     = $this->category('php');
        $subject = $this->post('subject', 'Subject', '2026-07-01 12:00:00');
        $subject->saveTerms([$php], []);
        $doomed  = $this->post('doomed', 'Doomed', '2026-07-09 12:00:00');
        $doomed->saveTerms([$php], []);

        $this->builder->buildPost($subject);
        $this->builder->buildPost($doomed);

        $dir = $this->builder->postOutputDir($subject->published_at, $subject->slug);
        $this->assertStringContainsString(
            'RELATED:Doomed',
            file_get_contents($dir . '/index.html'),
            'precondition: the doomed post is listed'
        );

        $doomed->status = 'draft';
        $doomed->save();
        $this->builder->buildPost($doomed);

        $this->assertStringNotContainsString(
            'Doomed',
            file_get_contents($dir . '/index.html'),
            'an unpublished post must drop out of every list that named it'
        );
    }

    /**
     * The neighbour pass calls buildPost(), which would call the neighbour pass
     * again. Two posts pointing at each other is the tightest possible cycle,
     * so if the guard is missing this test does not fail — it hangs.
     */
    public function testTheNeighbourRebuildDoesNotRecurse(): void
    {
        $this->enableRelated();
        $this->writeRelatedTemplate();

        $php = $this->category('php');
        $a   = $this->post('a', 'A', '2026-07-01 12:00:00');
        $b   = $this->post('b', 'B', '2026-07-02 12:00:00');
        $a->saveTerms([$php], []);
        $b->saveTerms([$php], []);

        $this->builder->buildPost($a);

        $dirA = $this->builder->postOutputDir($a->published_at, $a->slug);
        $dirB = $this->builder->postOutputDir($b->published_at, $b->slug);

        $this->assertStringContainsString('RELATED:B', file_get_contents($dirA . '/index.html'));
        $this->assertStringContainsString('RELATED:A', file_get_contents($dirB . '/index.html'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * The Builder snapshots settings at construction, so the toggle has to be
     * in place before the instance that reads it exists.
     */
    private function enableRelated(): void
    {
        $this->db->upsertSetting('show_related_posts', '1');
        $this->builder = new Builder($this->config, $this->db);
    }

    /**
     * A post template that names its related posts and nothing else.
     *
     * The taxonomy stub goes with it: every post here carries a category, so
     * buildPost() rebuilds that archive too and would otherwise warn about the
     * missing template on each call.
     */
    private function writeRelatedTemplate(): void
    {
        if (!is_dir($this->root . '/templates')) {
            mkdir($this->root . '/templates', 0775, true);
        }
        file_put_contents($this->root . '/templates/taxonomy.php', '');
        file_put_contents($this->root . '/templates/post.php', <<<'PHP'
<h1><?= $post->title ?></h1>
<?php foreach ($relatedPosts as $r): ?>RELATED:<?= $r->title ?>

<?php endforeach; ?>
PHP);
    }

    /** @return array{0: Post, 1: string} the subject and its output directory */
    private function twoRelatedPosts(): array
    {
        $php     = $this->category('php');
        $subject = $this->post('subject', 'Subject', '2026-07-01 12:00:00');
        $subject->saveTerms([$php], []);
        $this->post('neighbour', 'Neighbour', '2026-07-05 12:00:00')->saveTerms([$php], []);

        return [$subject, $this->builder->postOutputDir($subject->published_at, $subject->slug)];
    }

    private function post(string $slug, string $title, string $publishedAt = '2026-07-29 12:00:00'): Post
    {
        $post = new Post($this->db);
        $post->title        = $title;
        $post->slug         = $slug;
        $post->content      = 'x';
        $post->status       = 'published';
        $post->published_at = $publishedAt;
        $post->save();

        return $post;
    }

    private function category(string $slug): int
    {
        return $this->db->insert('categories', ['name' => ucfirst($slug), 'slug' => $slug]);
    }

    private function tag(string $slug): int
    {
        return $this->db->insert('tags', ['name' => ucfirst($slug), 'slug' => $slug]);
    }

}
