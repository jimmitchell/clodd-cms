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
     * @param array<array{kind: string, url: string}> $media
     */
    private function makePost(
        string $slug,
        string $title = '',
        string $status = 'published',
        ?string $publishedAt = '2026-08-01 12:00:00',
        string $postKind = 'standard',
        array $contexts = [],
        ?string $createdAt = null,
        ?string $rsvp = null,
        array $media = []
    ): Post {
        $post               = new Post($this->db);
        $post->title        = $title;
        $post->slug         = $slug;
        $post->content      = 'Body of ' . $slug;
        $post->status       = $status;
        $post->published_at = $publishedAt;
        $post->post_kind    = $postKind;
        $post->rsvp         = $rsvp;
        $post->save();

        if ($contexts !== []) {
            $post->saveContexts($contexts);
        }
        if ($media !== []) {
            $post->saveMedia($media);
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
            'rsvp'     => $this->makePost('an-rsvp', '', 'published', '2026-08-01 03:30:00', 'aside', [
                ['kind' => 'in-reply-to', 'url' => 'https://example.com/party'],
            ], null, 'yes'),
            'repost'   => $this->makePost('a-repost', '', 'published', '2026-08-01 03:00:00', 'aside', [
                ['kind' => 'repost-of', 'url' => 'https://example.com/post'],
            ]),
            'like'     => $this->makePost('a-like', '', 'published', '2026-08-01 02:00:00', 'aside', [
                ['kind' => 'like-of', 'url' => 'https://example.com/nice'],
            ]),
            'bookmark' => $this->makePost('a-bookmark', '', 'published', '2026-08-01 01:00:00', 'aside', [
                ['kind' => 'bookmark-of', 'url' => 'https://example.com/read-later'],
            ]),
            'video'    => $this->makePost('a-video', '', 'published', '2026-08-01 00:40:00', 'aside', [], null, null, [
                ['kind' => 'video', 'url' => '/media/2026/08/clip.mp4'],
            ]),
            'audio'    => $this->makePost('an-audio', '', 'published', '2026-08-01 00:20:00', 'aside', [], null, null, [
                ['kind' => 'audio', 'url' => '/media/2026/08/song.mp3'],
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

    public function testRsvpBeatsReply(): void
    {
        $post = $this->makePost('titled-rsvp', 'A Titled RSVP', 'published', '2026-08-01 12:00:00', 'standard', [
            ['kind' => 'in-reply-to', 'url' => 'https://example.com/party'],
        ], null, 'maybe');

        $this->assertSame('rsvp', $post->micropubType(), 'PTD puts rsvp above reply');
        $this->assertSame('maybe', $post->rsvp, 'the value survives a save and a fresh read');
    }

    /** The column holds the four spec values and nothing else, whatever writes it. */
    public function testTheRsvpColumnRefusesOtherValues(): void
    {
        $post = $this->makePost('not-an-rsvp');

        $this->expectException(\PDOException::class);
        $this->db->update('posts', ['rsvp' => 'perhaps'], 'id = :id', ['id' => $post->id]);
    }

    public function testRsvpValueNormalisesCaseAndRefusesTheRest(): void
    {
        $this->assertSame('yes', Post::rsvpValue(' Yes '));
        $this->assertSame('interested', Post::rsvpValue('INTERESTED'));
        $this->assertNull(Post::rsvpValue('perhaps'));
        $this->assertNull(Post::rsvpValue(['yes']));
        $this->assertNull(Post::rsvpValue(null));
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
        $this->makePost('titled-rsvp', 'A Titled RSVP', 'published', '2026-08-01 09:00:00', 'standard', [
            ['kind' => 'like-of',     'url' => 'https://example.com/nice'],
            ['kind' => 'in-reply-to', 'url' => 'https://example.com/party'],
        ], null, 'no');
        $this->makePost('titled-video', 'A Titled Video', 'published', '2026-08-01 08:00:00', 'standard', [], null, null, [
            ['kind' => 'video', 'url' => '/media/a.mp4'],
        ]);
        $this->makePost('photo-and-video', '', 'published', '2026-08-01 07:30:00', 'photo', [], null, null, [
            ['kind' => 'video', 'url' => '/media/b.mp4'],
        ]);
        $this->makePost('photo-and-audio', '', 'published', '2026-08-01 07:20:00', 'photo', [], null, null, [
            ['kind' => 'audio', 'url' => '/media/b.mp3'],
        ]);
        $this->makePost('titled-audio', 'A Titled Audio', 'published', '2026-08-01 07:10:00', 'standard', [], null, null, [
            ['kind' => 'audio', 'url' => '/media/c.mp3'],
        ]);
        $this->makePost('reply-with-video', '', 'published', '2026-08-01 06:30:00', 'aside', [
            ['kind' => 'in-reply-to', 'url' => 'https://example.com/thought'],
        ], null, null, [
            ['kind' => 'video', 'url' => '/media/d.mp4'],
        ]);

        $all = Post::findForMicropub($this->db, null, null, 100, 0);
        $this->assertCount(19, $all);

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

    // ── Video and audio ───────────────────────────────────────────────────────

    /** PTD has video above photo and audio below it, and neither cares about a title. */
    public function testVideoAndAudioTakeTheirPlaceOnTheLadder(): void
    {
        $video = ['kind' => 'video', 'url' => '/media/v.mp4'];
        $audio = ['kind' => 'audio', 'url' => '/media/a.mp3'];

        $this->assertSame('video', $this->makePost('pv', '', 'published', '2026-08-01 12:00:00', 'photo', [], null, null, [$video])->micropubType());
        $this->assertSame('photo', $this->makePost('pa', '', 'published', '2026-08-01 12:00:00', 'photo', [], null, null, [$audio])->micropubType());
        $this->assertSame('video', $this->makePost('tv', 'Titled', 'published', '2026-08-01 12:00:00', 'standard', [], null, null, [$video])->micropubType());
        $this->assertSame('audio', $this->makePost('ta', 'Titled', 'published', '2026-08-01 12:00:00', 'standard', [], null, null, [$audio])->micropubType());
        $this->assertSame('video', $this->makePost('va', '', 'published', '2026-08-01 12:00:00', 'aside', [], null, null, [$audio, $video])->micropubType());
        $this->assertSame('like', $this->makePost('lv', '', 'published', '2026-08-01 12:00:00', 'aside', [
            ['kind' => 'like-of', 'url' => 'https://example.com/nice'],
        ], null, null, [$video])->micropubType(), 'an interaction still outranks the media rungs');
    }

    public function testSaveMediaKeepsOrderAndSkipsWhatItCannotStore(): void
    {
        $post = $this->makePost('media-order', '', 'published', '2026-08-01 12:00:00', 'aside', [], null, null, [
            ['kind' => 'audio', 'url' => '/media/1.mp3'],
            ['kind' => 'photo', 'url' => '/media/not-here.jpg'],
            ['kind' => 'video', 'url' => '  '],
            ['kind' => 'video', 'url' => '/media/2.mp4'],
        ]);

        $this->assertSame(
            [['audio', '/media/1.mp3'], ['video', '/media/2.mp4']],
            array_map(fn($m) => [$m['kind'], $m['url']], $post->media),
            'hydrated by the finder, in order, with nothing it cannot store'
        );
        $this->assertSame(['/media/2.mp4'], $post->mediaUrls('video'));
        $this->assertSame([], $post->photos, 'a video never lands in post_photos');

        $post->saveMedia([]);
        $this->assertSame([], Post::findById($this->db, (int) $post->id)->media);
    }

    public function testThePostMediaTableRefusesOtherKinds(): void
    {
        $post = $this->makePost('bad-kind');
        $this->expectException(\Throwable::class);
        $this->db->insert('post_media', ['post_id' => $post->id, 'kind' => 'photo', 'url' => '/media/x.jpg']);
    }

    public function testMediaGoesWithItsPost(): void
    {
        $post = $this->makePost('cascade', '', 'published', '2026-08-01 12:00:00', 'aside', [], null, null, [
            ['kind' => 'video', 'url' => '/media/gone.mp4'],
        ]);
        $this->db->delete('posts', 'id = :id', ['id' => $post->id]);

        $this->assertSame([], $this->db->select('SELECT id FROM post_media'));
    }

    public function testMediaHtmlDrawsPlayersAndRefusesUnsafeUrls(): void
    {
        $html = Post::mediaHtml([
            ['kind' => 'video', 'url' => '/media/clip.mp4'],
            ['kind' => 'audio', 'url' => 'https://cdn.example.com/a "b".mp3'],
            ['kind' => 'video', 'url' => 'javascript:alert(1)'],
            ['kind' => 'photo', 'url' => '/media/p.jpg'],
        ], 'https://example.org');

        $this->assertStringContainsString('<video class="u-video" controls preload="metadata" src="https://example.org/media/clip.mp4">', $html);
        $this->assertStringContainsString('<audio class="u-audio" controls preload="metadata" src="https://cdn.example.com/a &quot;b&quot;.mp3">', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('p.jpg', $html);
        $this->assertSame(2, substr_count($html, '<figure'));
    }

    public function testAMediaPostIsAdvertisedReadAndReportedBack(): void
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents(__DIR__ . '/../micropub.php')) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        $types = substr($code, (int) strpos($code, 'function mp_post_types'));
        $types = substr($types, 0, (int) strpos($types, "\nfunction "));
        $this->assertStringContainsString("'type' => 'video'", $types);
        $this->assertStringContainsString("'type' => 'audio'", $types);

        $source = substr($code, (int) strpos($code, 'function mp_post_source_properties'));
        $source = substr($source, 0, (int) strpos($source, "\nfunction "));
        $this->assertStringContainsString('$post->mediaUrls($mediaKind)', $source, 'q=source must report video and audio, or a round-trip strips them');

        $this->assertStringContainsString('$post->saveMedia($mediaRows)', $code, 'create stores them');
        $this->assertStringContainsString('$post->saveMedia($newMedia)', $code, 'update stores them');
        $this->assertSame(
            2,
            substr_count($code, 'mp_parse_media_values($prop, $vals, $updSiteUrl)'),
            'an update reads them on replace and on add'
        );
    }

    public function testMediaReachesThePageTheCardsAndEveryFeed(): void
    {
        $root = __DIR__ . '/..';
        $this->assertStringContainsString('Post::mediaHtml($post->media)', (string) file_get_contents($root . '/templates/post.php'));
        $this->assertStringContainsString('Post::mediaHtml($post->media)', (string) file_get_contents($root . '/templates/partials/post-card.php'));
        foreach (['Feed', 'RssFeed', 'JsonFeed'] as $feed) {
            $src = (string) file_get_contents($root . "/src/{$feed}.php");
            $this->assertSame(2, substr_count($src, 'Post::mediaHtml('), "{$feed}: the raw-row path and the hydrated one");
        }
    }

    // ── findForMicropub(): status ─────────────────────────────────────────────

    public function testDraftStatusIsOnlyDrafts(): void
    {
        $this->makePost('live',      'Live',      'published');
        $this->makePost('draft',     'Draft',     'draft',     null);
        $this->makePost('scheduled', 'Scheduled', 'scheduled', '2099-01-01 00:00:00');

        $drafts = array_map(fn(Post $p) => $p->slug, Post::findForMicropub($this->db, null, 'draft', 100, 0));

        $this->assertSame(['draft'], $drafts);
    }

    /** Scheduled posts report as published, so they filter as published too. */
    public function testPublishedStatusIncludesScheduled(): void
    {
        $this->makePost('live',      'Live',      'published');
        $this->makePost('draft',     'Draft',     'draft',     null);
        $this->makePost('scheduled', 'Scheduled', 'scheduled', '2099-01-01 00:00:00');

        $published = array_map(fn(Post $p) => $p->slug, Post::findForMicropub($this->db, null, 'published', 100, 0));
        sort($published);

        $this->assertSame(['live', 'scheduled'], $published);
    }

    /** Each item's reported status puts it in the list that filter returns. */
    public function testEachStatusFilterAgreesWithWhatItsItemsReport(): void
    {
        $this->makePost('live',      'Live',      'published');
        $this->makePost('draft',     'Draft',     'draft',     null);
        $this->makePost('scheduled', 'Scheduled', 'scheduled', '2099-01-01 00:00:00');

        foreach (Post::MICROPUB_STATUSES as $status) {
            foreach (Post::findForMicropub($this->db, null, $status, 100, 0) as $post) {
                $this->assertSame($status, $post->micropubStatus(), "{$post->slug} is listed under {$status}");
            }
        }
    }

    // ── micropubStatus() and markPublished() ──────────────────────────────────

    public function testOnlyADraftReportsAsADraft(): void
    {
        foreach (['draft' => 'draft', 'scheduled' => 'published', 'published' => 'published'] as $column => $reported) {
            $post = new Post($this->db);
            $post->status = $column;
            $this->assertSame($reported, $post->micropubStatus(), "a {$column} post");
        }
    }

    /**
     * The round trip obsidian-micropub makes: a scheduled post, reported as
     * published, sent back as published. It must stay scheduled.
     */
    public function testMarkingAFutureDatedPostPublishedSchedulesIt(): void
    {
        $now  = strtotime('2026-11-21 12:00:00');
        $post = new Post($this->db);
        $post->status       = 'scheduled';
        $post->published_at = '2026-12-01 09:00:00';

        $post->markPublished($now);

        $this->assertSame('scheduled', $post->status);
        $this->assertSame('2026-12-01 09:00:00', $post->published_at);
    }

    public function testMarkingAPastDatedDraftPublishedPublishesIt(): void
    {
        $now  = strtotime('2026-11-21 12:00:00');
        $post = new Post($this->db);
        $post->status       = 'draft';
        $post->published_at = '2026-11-01 09:00:00';

        $post->markPublished($now);

        $this->assertSame('published', $post->status);
        $this->assertSame('2026-11-01 09:00:00', $post->published_at);
    }

    public function testMarkingAnUndatedDraftPublishedDatesItNow(): void
    {
        $now  = strtotime('2026-11-21 12:00:00');
        $post = new Post($this->db);
        $post->status = 'draft';

        $post->markPublished($now);

        $this->assertSame('published', $post->status);
        $this->assertSame(date('Y-m-d H:i:s', $now), $post->published_at);
    }

    /**
     * micropub.php reports and updates the status through those two methods,
     * rather than a rule of its own that could drift from them. Source-level
     * for the reason given at testATitlelessPostReportsNoNameProperty().
     */
    public function testMicropubReportsAndUpdatesTheStatusThroughPost(): void
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents(__DIR__ . '/../micropub.php')) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        $source = substr($code, (int) strpos($code, 'function mp_post_source_properties'));
        $source = substr($source, 0, (int) strpos($source, "\nfunction "));
        $this->assertStringContainsString("'post-status' => [\$post->micropubStatus()]", $source);

        $this->assertStringContainsString('$post->markPublished(time())', $code);
        $this->assertStringNotContainsString(
            "\$post->status = 'published';",
            $code,
            'an update setting the status by hand would skip the scheduling rule'
        );
    }

    /**
     * PHP turns a response carrying a Location header into a 302 unless its
     * status is already 201 or a 3xx, so a 202 has to be passed to header()
     * along with the Location, not set beforehand.
     */
    public function testAScheduledCreateAnswers202NotARedirect(): void
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents(__DIR__ . '/../micropub.php')) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        $this->assertStringContainsString(
            "header('Location: ' . \$location, true, \$status === 'scheduled' ? 202 : 201);",
            $code
        );
        $this->assertDoesNotMatchRegularExpression(
            '/http_response_code\([^)]*202/',
            $code,
            'a 202 set before the Location header goes out as a 302'
        );
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

    /**
     * Micropub's single `category` namespace spans two tables here, and a name
     * arriving over Micropub becomes a *tag* unless a category already claims
     * that slug. So the common case is a post whose terms are all tags and
     * whose `categories` is empty.
     *
     * micropub.php's `add` and `delete` update handlers each read
     * `$post->categories` alone. They saw nothing, then wrote their result back
     * over the whole set: adding one term destroyed the rest, and deleting one
     * named term cleared all of them. Both are silent data loss on a post the
     * client thought it was editing conservatively.
     */
    public function testMicropubTermNamesSpansBothCategoriesAndTags(): void
    {
        $post = $this->makePost('terms-subject', 'Terms Subject');
        $post->categories = [['id' => 1, 'name' => 'Essays', 'slug' => 'essays', 'description' => '']];
        $post->tags       = [['id' => 7, 'name' => 'alpha', 'slug' => 'alpha'],
                             ['id' => 8, 'name' => 'beta',  'slug' => 'beta']];

        $this->assertSame(
            ['Essays', 'alpha', 'beta'],
            $post->micropubTermNames(),
            'a post\'s Micropub terms are its categories and its tags, categories first'
        );
    }

    public function testMicropubTermNamesSeesTagsWhenNoCategoryIsSet(): void
    {
        $post = $this->makePost('tags-only', 'Tags Only');
        $post->categories = [];
        $post->tags       = [['id' => 7, 'name' => 'alpha', 'slug' => 'alpha']];

        // The exact shape the update handlers used to miss: reading
        // ->categories here returns [], and writing that back wipes the post.
        $this->assertSame(['alpha'], $post->micropubTermNames());
        $this->assertNotSame([], $post->micropubTermNames(), 'this is the read that used to come back empty');
    }

    /**
     * The method above is only worth anything if the update handlers use it.
     * Source-level because those handlers live in micropub.php, and including
     * that file executes the endpoint.
     */
    public function testTheUpdateHandlersReadTermsThroughThatMethod(): void
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents(__DIR__ . '/../micropub.php')) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        $this->assertStringNotContainsString(
            "array_map(fn(\$c) => (string) \$c['name'], \$post->categories)",
            $code,
            'reading ->categories alone misses tags, which is where Micropub terms usually live'
        );

        $this->assertSame(
            3,
            substr_count($code, '$post->micropubTermNames()'),
            'q=source plus the add and delete handlers — all three read terms the same way'
        );
    }

    public function testMicropubTermNamesDropsBlankNames(): void
    {
        $post = $this->makePost('blank-terms', 'Blank Terms');
        $post->categories = [['id' => 1, 'name' => '', 'slug' => '', 'description' => '']];
        $post->tags       = [['id' => 7, 'name' => 'kept', 'slug' => 'kept']];

        $this->assertSame(['kept'], $post->micropubTermNames());
    }

    /**
     * A titleless post must report no `name` property at all.
     *
     * `templates/post.php` omits `p-name` for a note, so emitting one from
     * `q=source` made the two descriptions of the same post contradict each
     * other — and the endpoint then refuses the value it just handed out, with
     * `400 name cannot be empty`. The guard has to use the same `title !== ''`
     * predicate as `Post::micropubType()`, or a post can be a note to one and
     * an article to the other.
     *
     * Asserted from source: `mp_post_source_properties()` is a function inside
     * micropub.php, and including that file executes the endpoint. Comments are
     * stripped first, or the prose above the guard would satisfy it.
     */
    public function testATitlelessPostReportsNoNameProperty(): void
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents(__DIR__ . '/../micropub.php')) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        $body = substr($code, (int) strpos($code, 'function mp_post_source_properties'));
        $body = substr($body, 0, (int) strpos($body, "\nfunction "));

        // The literal the function opens with — everything up to its first `];`.
        $start   = (int) strpos($body, '$props = [');
        $literal = substr($body, $start, (int) strpos($body, '];', $start) - $start);

        $this->assertStringNotContainsString(
            "'name'",
            $literal,
            'name must not be in the unconditional literal — a note would advertise an empty one'
        );

        $this->assertMatchesRegularExpression(
            "/\\\$post->title\s*!==\s*''/",
            $body,
            "the guard must be title !== '', matching Post::micropubType()"
        );

        // The other half: the predicate this mirrors is still the one in Post.
        $this->assertStringContainsString(
            "return \$this->title !== '' ? 'article' : 'note';",
            (string) file_get_contents(__DIR__ . '/../src/Post.php'),
            'Post::micropubType() decides note vs article on the same test'
        );
    }

    /**
     * micropub.php carries the rsvp through every path a client can see it by:
     * q=source reports it, q=config advertises it, and both create and update
     * refuse an answer with no event. Source-level for the reason above.
     */
    public function testMicropubCarriesTheRsvpEndToEnd(): void
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents(__DIR__ . '/../micropub.php')) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        $source = substr($code, (int) strpos($code, 'function mp_post_source_properties'));
        $source = substr($source, 0, (int) strpos($source, "\nfunction "));
        $this->assertStringContainsString("\$props['rsvp']", $source, 'q=source must report rsvp, or a round-trip strips it');

        $types = substr($code, (int) strpos($code, 'function mp_post_types'));
        $types = substr($types, 0, (int) strpos($types, "\nfunction "));
        $this->assertStringContainsString("'type' => 'rsvp'", $types);

        $this->assertSame(
            3,
            substr_count($code, 'mp_require_rsvp_target('),
            'the definition plus one call each on create and update'
        );
    }
}
