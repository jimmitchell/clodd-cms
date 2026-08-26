<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Database;
use CMS\Post;

/**
 * The Micropub "Query for Post List" extension.
 *
 * Post::micropubType() and Post::findForMicropub() encode the same Post Type
 * Discovery ladder twice — once in PHP over hydrated contexts, once as SQL. The
 * point of most of these tests is that the two never disagree: if they drift,
 * a client's filtered list silently omits posts it can reach by URL.
 */
final class PostMicropubQueryTest extends TempSiteTestCase
{
    /**
     * @param array<array{kind: string, url: string}> $contexts
     */
    private function makePost(
        string $slug,
        string $title = '',
        string $status = 'published',
        ?string $publishedAt = '2026-08-01 12:00:00',
        string $postKind = 'standard',
        array $contexts = [],
        ?string $createdAt = null
    ): Post {
        $post               = new Post($this->db);
        $post->title        = $title;
        $post->slug         = $slug;
        $post->content      = 'Body of ' . $slug;
        $post->status       = $status;
        $post->published_at = $publishedAt;
        $post->post_kind    = $postKind;
        $post->save();

        if ($contexts !== []) {
            $post->saveContexts($contexts);
        }
        if ($createdAt !== null) {
            $this->db->update('posts', ['created_at' => $createdAt], 'id = :id', ['id' => $post->id]);
        }

        return Post::findById($this->db, (int) $post->id);
    }

    /** @return array<string, Post> keyed by expected micropub type */
    private function seedOneOfEachType(): array
    {
        return [
            'note'     => $this->makePost('a-note', '', 'published', '2026-08-01 07:00:00', 'aside'),
            'article'  => $this->makePost('an-article', 'An Article', 'published', '2026-08-01 06:00:00'),
            'photo'    => $this->makePost('a-photo', '', 'published', '2026-08-01 05:00:00', 'photo'),
            'reply'    => $this->makePost('a-reply', '', 'published', '2026-08-01 04:00:00', 'aside', [
                ['kind' => 'in-reply-to', 'url' => 'https://example.com/thought'],
            ]),
            'repost'   => $this->makePost('a-repost', '', 'published', '2026-08-01 03:00:00', 'aside', [
                ['kind' => 'repost-of', 'url' => 'https://example.com/post'],
            ]),
            'like'     => $this->makePost('a-like', '', 'published', '2026-08-01 02:00:00', 'aside', [
                ['kind' => 'like-of', 'url' => 'https://example.com/nice'],
            ]),
            'bookmark' => $this->makePost('a-bookmark', '', 'published', '2026-08-01 01:00:00', 'aside', [
                ['kind' => 'bookmark-of', 'url' => 'https://example.com/read-later'],
            ]),
        ];
    }

    // ── micropubType() ────────────────────────────────────────────────────────

    public function testMicropubTypeCoversEveryAdvertisedType(): void
    {
        foreach ($this->seedOneOfEachType() as $expected => $post) {
            $this->assertSame($expected, $post->micropubType(), "post {$post->slug}");
        }
    }

    public function testEveryAdvertisedTypeIsDerivable(): void
    {
        $this->assertSame(
            [],
            array_diff(Post::MICROPUB_TYPES, array_keys($this->seedOneOfEachType())),
            'q=config advertises a type no fixture can produce'
        );
    }

    public function testInteractionBeatsArticle(): void
    {
        $post = $this->makePost('titled-reply', 'A Titled Reply', 'published', '2026-08-01 12:00:00', 'standard', [
            ['kind' => 'in-reply-to', 'url' => 'https://example.com/thought'],
        ]);

        $this->assertSame('reply', $post->micropubType(), 'PTD puts reply above article');
    }

    public function testStrongerInteractionWins(): void
    {
        $post = $this->makePost('reply-and-like', '', 'published', '2026-08-01 12:00:00', 'aside', [
            ['kind' => 'like-of',     'url' => 'https://example.com/nice'],
            ['kind' => 'in-reply-to', 'url' => 'https://example.com/thought'],
        ]);

        $this->assertSame('reply', $post->micropubType());
    }

    // ── findForMicropub(): the SQL agrees with the PHP ────────────────────────

    public function testTypeFilterMatchesMicropubType(): void
    {
        $this->seedOneOfEachType();
        // Ambiguous shapes that exercise the exclusion clauses.
        $this->makePost('titled-reply', 'A Titled Reply', 'published', '2026-08-01 12:00:00', 'standard', [
            ['kind' => 'in-reply-to', 'url' => 'https://example.com/thought'],
        ]);
        $this->makePost('like-and-bookmark', '', 'published', '2026-08-01 11:00:00', 'aside', [
            ['kind' => 'like-of',     'url' => 'https://example.com/nice'],
            ['kind' => 'bookmark-of', 'url' => 'https://example.com/read-later'],
        ]);
        $this->makePost('titled-photo', 'A Titled Photo', 'published', '2026-08-01 10:00:00', 'standard');

        $all = Post::findForMicropub($this->db, null, null, 100, 0);
        $this->assertCount(10, $all);

        foreach (Post::MICROPUB_TYPES as $type) {
            $expected = array_map(
                fn(Post $p) => $p->slug,
                array_filter($all, fn(Post $p) => $p->micropubType() === $type)
            );
            $actual = array_map(
                fn(Post $p) => $p->slug,
                Post::findForMicropub($this->db, $type, null, 100, 0)
            );

            sort($expected);
            sort($actual);
            $this->assertSame($expected, $actual, "post-type={$type} disagrees with micropubType()");
        }
    }

    // ── findForMicropub(): status ─────────────────────────────────────────────

    public function testDraftStatusIncludesScheduled(): void
    {
        $this->makePost('live',      'Live',      'published');
        $this->makePost('draft',     'Draft',     'draft',     null);
        $this->makePost('scheduled', 'Scheduled', 'scheduled', '2099-01-01 00:00:00');

        $drafts = array_map(fn(Post $p) => $p->slug, Post::findForMicropub($this->db, null, 'draft', 100, 0));
        sort($drafts);

        $this->assertSame(['draft', 'scheduled'], $drafts);
    }

    public function testPublishedStatusExcludesDraftsAndScheduled(): void
    {
        $this->makePost('live',      'Live',      'published');
        $this->makePost('draft',     'Draft',     'draft',     null);
        $this->makePost('scheduled', 'Scheduled', 'scheduled', '2099-01-01 00:00:00');

        $published = array_map(fn(Post $p) => $p->slug, Post::findForMicropub($this->db, null, 'published', 100, 0));

        $this->assertSame(['live'], $published);
    }

    public function testNoStatusFilterReturnsEverythingAlive(): void
    {
        $this->makePost('live',      'Live',      'published');
        $this->makePost('draft',     'Draft',     'draft',     null);
        $this->makePost('scheduled', 'Scheduled', 'scheduled', '2099-01-01 00:00:00');

        $this->assertCount(3, Post::findForMicropub($this->db, null, null, 100, 0));
    }

    // ── findForMicropub(): soft deletes ───────────────────────────────────────

    public function testSoftDeletedPostsNeverAppear(): void
    {
        $this->makePost('kept', 'Kept', 'published');
        $gone = $this->makePost('gone', 'Gone', 'published');
        $gone->softDelete();

        $slugs = array_map(fn(Post $p) => $p->slug, Post::findForMicropub($this->db, null, null, 100, 0));

        $this->assertSame(['kept'], $slugs);

        // …and the filtered paths exclude it too, not just the unfiltered one.
        $filtered = array_map(
            fn(Post $p) => $p->slug,
            Post::findForMicropub($this->db, 'article', 'published', 100, 0)
        );
        $this->assertSame(['kept'], $filtered);
    }

    // ── findForMicropub(): ordering and paging ────────────────────────────────

    public function testUndatedDraftSortsByCreatedAtNotLast(): void
    {
        $this->makePost('older-published', 'Older', 'published', '2026-08-01 00:00:00');
        $this->makePost('fresh-draft',     'Fresh', 'draft',     null, 'standard', [], '2026-08-10 00:00:00');

        $slugs = array_map(fn(Post $p) => $p->slug, Post::findForMicropub($this->db, null, null, 100, 0));

        $this->assertSame(['fresh-draft', 'older-published'], $slugs);
    }

    public function testLimitAndOffsetPageWithoutGapsOrOverlap(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->makePost("post-{$i}", "Post {$i}", 'published', sprintf('2026-08-%02d 12:00:00', $i));
        }

        $all = array_map(fn(Post $p) => $p->slug, Post::findForMicropub($this->db, null, null, 100, 0));
        $this->assertSame(
            ['post-7', 'post-6', 'post-5', 'post-4', 'post-3', 'post-2', 'post-1'],
            $all,
            'newest first'
        );

        $paged = [];
        for ($offset = 0; $offset < 9; $offset += 3) {
            foreach (Post::findForMicropub($this->db, null, null, 3, $offset) as $post) {
                $paged[] = $post->slug;
            }
        }

        $this->assertSame($all, $paged, 'paging must reproduce the full list exactly once');
    }

    public function testPagingIsStableWhenTimestampsCollide(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->makePost("same-{$i}", "Same {$i}", 'published', '2026-08-01 12:00:00');
        }

        $first  = array_map(fn(Post $p) => $p->slug, Post::findForMicropub($this->db, null, null, 2, 0));
        $second = array_map(fn(Post $p) => $p->slug, Post::findForMicropub($this->db, null, null, 2, 2));

        $this->assertSame([], array_intersect($first, $second), 'pages must not overlap');
        $this->assertCount(4, array_unique(array_merge($first, $second)));
    }

    public function testPostsArriveHydratedForTheSourceMapper(): void
    {
        $this->makePost('a-reply', '', 'published', '2026-08-01 12:00:00', 'aside', [
            ['kind' => 'in-reply-to', 'url' => 'https://example.com/thought'],
        ]);

        $posts = Post::findForMicropub($this->db, null, null, 100, 0);

        $this->assertCount(1, $posts);
        $this->assertSame(
            [['kind' => 'in-reply-to', 'url' => 'https://example.com/thought']],
            $posts[0]->contexts,
            'contexts must be hydrated — the mf2 mapper reads them per item'
        );
    }
}
