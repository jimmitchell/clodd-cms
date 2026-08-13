<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Bluesky;
use CMS\Database;
use CMS\Mastodon;
use CMS\Pixelfed;
use CMS\Post;
use CMS\Syndication;
use CMS\SyndicationText;
use PHPUnit\Framework\TestCase;

/**
 * What actually reaches Mastodon and Bluesky when a post is published.
 *
 * A syndication bug is invisible locally — the copy is only wrong on somebody
 * else's server — so the two pieces that decide what gets sent are pinned here:
 * the words a note contributes, and the form encoding of the status POST.
 */
final class SyndicationTest extends TestCase
{
    private Database $db;
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'clodd_test_') . '.db';
        $this->db     = new Database($this->dbPath);
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }

    private function photoPost(string $content, ?string $excerpt): Post
    {
        $post            = new Post($this->db);
        $post->post_kind = 'photo';
        $post->content   = $content;
        $post->excerpt   = $excerpt;

        return $post;
    }

    // ── noteText ──────────────────────────────────────────────────────────────

    /**
     * Micropub has no caption property: a client sends the words as `content`
     * and the picture as `photo`. Reading only the excerpt syndicated those
     * posts as a bare photo with the caption dropped.
     */
    public function testPhotoPostFromMicropubSyndicatesItsBodyText(): void
    {
        $post = $this->photoPost('Sunset over the marina, warm enough to sit outside.', null);

        $this->assertSame('Sunset over the marina, warm enough to sit outside.', $post->noteText());
    }

    public function testPhotoPostFromTheAdminSyndicatesItsCaption(): void
    {
        $post = $this->photoPost('![A flower](/content/media/2026/08/flower.jpg)', 'A pretty flower post.');

        $this->assertSame('A pretty flower post.', $post->noteText());
    }

    /**
     * An admin photo post is a picture and nothing else, in Markdown or as raw
     * HTML. With no caption there are no words to send, and the callers skip
     * rather than publish a blank status.
     */
    public function testPhotoPostWithOnlyAPictureHasNoText(): void
    {
        $markdown = $this->photoPost('![](/content/media/2026/08/flower.jpg)', null);
        $html     = $this->photoPost('<img src="/content/media/2026/08/flower.jpg" alt="A flower">', '');

        $this->assertSame('', $markdown->noteText());
        $this->assertSame('', $html->noteText());
    }

    public function testAsideSyndicatesItsBodyText(): void
    {
        $post            = new Post($this->db);
        $post->post_kind = 'aside';
        $post->content   = 'Reading **the docs** again, for [the third time](https://example.com/).';
        $post->excerpt   = 'ignored';

        $this->assertSame('Reading the docs again, for the third time.', $post->noteText());
    }

    // ── Mastodon status body ──────────────────────────────────────────────────

    /**
     * Rails reads media_ids[0]=… as a hash, and `permit(media_ids: [])` then
     * drops it — the pictures never made it onto the status, and a photo post
     * with its words in the body was rejected outright as an empty status.
     */
    public function testStatusBodyRepeatsMediaIdsAsAnArray(): void
    {
        $statusBody = new \ReflectionMethod(Mastodon::class, 'statusBody');

        $body = $statusBody->invoke(null, 'A caption', ['111', '222']);

        parse_str($body, $parsed);
        $this->assertSame(['111', '222'], $parsed['media_ids']);
        $this->assertSame('A caption', $parsed['status']);
        $this->assertSame('public', $parsed['visibility']);
        $this->assertStringNotContainsString('media_ids%5B0%5D', $body);
    }

    public function testStatusBodyWithoutMediaIsUnchanged(): void
    {
        $statusBody = new \ReflectionMethod(Mastodon::class, 'statusBody');

        $body = $statusBody->invoke(null, 'Just words', []);

        parse_str($body, $parsed);
        $this->assertSame(['status' => 'Just words', 'visibility' => 'public'], $parsed);
    }

    /**
     * The edit endpoint cannot change a status's visibility and does not accept
     * the parameter; sending it anyway risks a 422 on every edit.
     */
    public function testEditBodyOmitsVisibility(): void
    {
        $statusBody = new \ReflectionMethod(Mastodon::class, 'statusBody');

        $body = $statusBody->invoke(null, 'Fixed a typo', ['111'], false);

        parse_str($body, $parsed);
        $this->assertSame('Fixed a typo', $parsed['status']);
        $this->assertSame(['111'], $parsed['media_ids']);
        $this->assertArrayNotHasKey('visibility', $parsed);
    }

    // ── Rewriting a Bluesky record ────────────────────────────────────────────

    /**
     * putRecord is a write to the user's repo whichever way it goes, so a save
     * that didn't touch the syndicated words must not send one.
     */
    public function testAnUnchangedBlueskyRecordIsRecognised(): void
    {
        $sameRecord = new \ReflectionMethod(Bluesky::class, 'sameRecord');

        $current = [
            '$type'     => 'app.bsky.feed.post',
            'text'      => 'A note',
            'createdAt' => '2026-08-01T10:00:00Z',
            // The Bluesky app tags records with a language of its own accord.
            'langs'     => ['en'],
        ];
        $updated = [
            '$type'     => 'app.bsky.feed.post',
            'text'      => 'A note',
            'createdAt' => '2026-08-01T10:00:00Z',
        ];

        $this->assertTrue($sameRecord->invoke(null, $current, $updated));
    }

    public function testAChangedBlueskyRecordIsRecognised(): void
    {
        $sameRecord = new \ReflectionMethod(Bluesky::class, 'sameRecord');

        $current = ['text' => 'A note', 'createdAt' => '2026-08-01T10:00:00Z'];

        $this->assertFalse($sameRecord->invoke(
            null,
            $current,
            ['text' => 'A note, corrected', 'createdAt' => '2026-08-01T10:00:00Z']
        ));
        // A picture added to the post is a change even when the words are not.
        $this->assertFalse($sameRecord->invoke(
            null,
            $current,
            ['text' => 'A note', 'createdAt' => '2026-08-01T10:00:00Z', 'embed' => ['images' => []]]
        ));
    }

    // ── Forgetting a copy that is gone ────────────────────────────────────────

    /**
     * The post page links to mastodon_url / bluesky_url, so a copy that has been
     * deleted must leave no link behind. Clearing tooted_at / bluesky_at also
     * re-arms first-publish syndication for an unpublish-then-republish.
     */
    public function testClearSyndicationForgetsBothCopies(): void
    {
        $post = $this->savedPost();
        $post->markTooted('https://mastodon.social/@jim/113456789', '113456789');
        $post->markBluesky('https://bsky.app/profile/jim.example/post/3kv7qabcd2s', '3kv7qabcd2s');

        $post->clearSyndication();

        $reloaded = Post::findById($this->db, (int) $post->id);
        foreach ([$post, $reloaded] as $subject) {
            $this->assertNull($subject->tooted_at);
            $this->assertNull($subject->mastodon_url);
            $this->assertNull($subject->mastodon_status_id);
            $this->assertNull($subject->bluesky_at);
            $this->assertNull($subject->bluesky_url);
            $this->assertNull($subject->bluesky_rkey);
        }
    }

    /**
     * A delete that failed against one network — instance down, token expired —
     * must leave that copy's id alone, because it is still out there and still
     * the only way back to it.
     */
    public function testClearSyndicationLeavesTheNetworkItWasNotToldAbout(): void
    {
        $post = $this->savedPost();
        $post->markTooted('https://mastodon.social/@jim/113456789', '113456789');
        $post->markBluesky('https://bsky.app/profile/jim.example/post/3kv7qabcd2s', '3kv7qabcd2s');

        $post->clearSyndication(mastodon: false, bluesky: true);

        $reloaded = Post::findById($this->db, (int) $post->id);
        $this->assertSame('113456789', $reloaded->mastodon_status_id);
        $this->assertSame('https://mastodon.social/@jim/113456789', $reloaded->mastodon_url);
        $this->assertNull($reloaded->bluesky_rkey);
        $this->assertNull($reloaded->bluesky_url);
    }

    // ── What gets sent ────────────────────────────────────────────────────────

    public function testAStandardPostSyndicatesItsTitleExcerptAndUrl(): void
    {
        $this->db->upsertSetting('site_url', 'https://example.com');

        $post = $this->savedPost();
        $post->title        = 'A proper post';
        $post->excerpt      = 'The short version.';
        $post->slug         = 'a-proper-post';
        $post->status       = 'published';
        $post->published_at = '2026-08-01 09:00:00';

        $payload = $this->payloadFor($post);

        $this->assertSame('A proper post', $payload['title']);
        $this->assertSame('The short version.', $payload['excerpt']);
        $this->assertSame('https://example.com/2026/08/01/a-proper-post/', $payload['url']);
    }

    /** A note is its own words: no link back, because it is not a trailer. */
    public function testANoteSyndicatesWithNoLinkBack(): void
    {
        $this->db->upsertSetting('site_url', 'https://example.com');

        $post = $this->savedPost();
        $post->post_kind    = 'aside';
        $post->content      = 'Just a thought.';
        $post->status       = 'published';
        $post->published_at = '2026-08-01 09:00:00';

        $payload = $this->payloadFor($post);

        $this->assertSame('', $payload['url']);
        $this->assertSame('Just a thought.', $payload['excerpt']);
    }

    /**
     * A bare like, repost or bookmark has no words and no picture. Publishing
     * one used to be skipped by an inline check at each call site; the rule now
     * lives in one place, and applies to updates and deletes as well.
     */
    public function testAnInteractionPostWithNoWordsSaysNothing(): void
    {
        $post          = $this->savedPost();
        $post->content = '';
        $post->status  = 'published';

        $this->assertNull($this->payloadFor($post));
    }

    // ── Contexts: replies, likes, reposts, bookmarks ──────────────────────────

    /**
     * The whole point of a reply is what it is replying to. The page and both
     * feeds open with that line; the copies on Mastodon and Bluesky carried
     * only the words, so a reply arrived there as a remark about nothing.
     */
    public function testAReplySyndicatesWhatItIsReplyingTo(): void
    {
        $post = $this->savedPost();
        $post->post_kind = 'aside';
        $post->content   = 'Quite right.';
        $post->status    = 'published';
        $post->saveContexts([['kind' => 'in-reply-to', 'url' => 'https://example.com/a-thought']]);

        $payload = $this->payloadFor($post);

        $this->assertSame('↩ In reply to https://example.com/a-thought', $payload['context']);
        $this->assertSame('Quite right.', $payload['excerpt']);
    }

    /**
     * Contexts hang off the post id, and syndication runs from paths that never
     * hydrated them — Micropub updates, the scheduler, the admin. Reading them
     * back is what stops the copy silently losing its reply line.
     */
    public function testContextsAreReadBackWhenTheyAreNotOnTheObject(): void
    {
        $this->db->upsertSetting('site_url', 'https://example.com');

        $post = $this->savedPost();
        $post->status       = 'published';
        $post->published_at = '2026-08-01 09:00:00';
        $post->save();
        $post->saveContexts([['kind' => 'like-of', 'url' => 'https://example.com/nice']]);

        $unhydrated = Post::findById($this->db, (int) $post->id);
        $unhydrated->contexts = [];

        $this->assertSame('♥ Liked https://example.com/nice', $this->payloadFor($unhydrated)['context']);
    }

    /** Display order is the page's order — repost, like, reply, bookmark. */
    public function testContextsTextKeepsTheDisplayOrderAndFullUrls(): void
    {
        $text = Post::contextsText([
            ['kind' => 'bookmark-of', 'url' => 'https://example.com/keep'],
            ['kind' => 'in-reply-to', 'url' => 'https://example.com/said'],
            ['kind' => 'repost-of',   'url' => 'https://example.com/boost'],
        ]);

        $this->assertSame(
            "♺ Reposted https://example.com/boost\n"
            . "↩ In reply to https://example.com/said\n"
            . '🔖 Bookmarked https://example.com/keep',
            $text
        );
    }

    /** An unknown kind, or a context with no target, is not a line. */
    public function testContextsTextSkipsWhatItCannotAnnounce(): void
    {
        $this->assertSame('', Post::contextsText([
            ['kind' => 'in-reply-to', 'url' => '  '],
            ['kind' => 'rsvp',        'url' => 'https://example.com/party'],
        ]));
    }

    // ── Laying out the words ──────────────────────────────────────────────────

    /** Context first, then title, excerpt and the way home — as on the page. */
    public function testComposeOpensWithTheContextLine(): void
    {
        $text = SyndicationText::compose(
            '↩ In reply to https://example.com/said',
            'A proper post',
            'The short version.',
            'https://example.com/2026/08/01/a-proper-post/',
            500
        );

        $this->assertSame(
            "↩ In reply to https://example.com/said\n\n"
            . "A proper post\n\n"
            . "The short version.\n\n"
            . 'https://example.com/2026/08/01/a-proper-post/',
            $text
        );
    }

    /**
     * Only the excerpt gives ground: a reply that dropped the line saying what
     * it replies to, to keep a few more of its own words, would be worse.
     */
    public function testComposeTrimsTheExcerptToFitAroundTheContext(): void
    {
        $context = '↩ In reply to https://example.com/said';
        $text    = SyndicationText::compose($context, '', str_repeat('word ', 100), '', 100);

        $this->assertLessThanOrEqual(100, mb_strlen($text));
        $this->assertStringStartsWith($context . "\n\n", $text);
        $this->assertStringEndsWith('…', $text);
    }

    /**
     * A long enough context and URL can leave no room at all. One character and
     * an ellipsis is not an excerpt — send the rest rather than a bare '…'.
     */
    public function testComposeDropsAnExcerptThatCannotFitAtAll(): void
    {
        $context = '↩ In reply to https://example.com/' . str_repeat('a', 60);

        $this->assertSame($context, SyndicationText::compose($context, '', 'Some words.', '', 80));
    }

    // ── Making the links clickable on Bluesky ─────────────────────────────────

    /**
     * Bluesky renders no link unless a facet points at one, by byte offset. A
     * reply now carries two URLs, and the ↩ ahead of the first is three bytes
     * where mb_strlen counts one — measure in the wrong units and the link
     * lands on the wrong text.
     */
    public function testBlueskyLinksBothTheReplyTargetAndThePostUrl(): void
    {
        $context  = '↩ In reply to https://example.com/said';
        $postUrl  = 'https://example.com/2026/08/01/a-reply/';
        $text     = SyndicationText::compose($context, '', 'Quite right.', $postUrl, 300);

        $facets = $this->facetsFor($text, $postUrl, ...SyndicationText::urlsIn($context));

        $this->assertCount(2, $facets);
        foreach ($facets as $facet) {
            $uri   = $facet['features'][0]['uri'];
            $start = $facet['index']['byteStart'];
            $this->assertSame($uri, substr($text, $start, $facet['index']['byteEnd'] - $start));
        }
        // In the order they appear: the reply target opens the post.
        $this->assertSame('https://example.com/said', $facets[0]['features'][0]['uri']);
        $this->assertSame($postUrl, $facets[1]['features'][0]['uri']);
    }

    /**
     * A bookmark of a site whose address is the opening of the post's own URL
     * would, matched shortest-first, take the longer one's bytes and leave the
     * post URL linking to somewhere else entirely.
     */
    public function testBlueskyLinksAUrlThatOpensAnotherOne(): void
    {
        $context = '🔖 Bookmarked https://example.com/';
        $postUrl = 'https://example.com/2026/08/01/a-bookmark/';
        $text    = SyndicationText::compose($context, '', 'Worth keeping.', $postUrl, 300);

        $facets = $this->facetsFor($text, $postUrl, ...SyndicationText::urlsIn($context));

        $this->assertCount(2, $facets);
        foreach ($facets as $facet) {
            $start = $facet['index']['byteStart'];
            $this->assertSame(
                $facet['features'][0]['uri'],
                substr($text, $start, $facet['index']['byteEnd'] - $start)
            );
        }
    }

    public function testAPhotoPostWithNoCaptionAndNoPictureSaysNothing(): void
    {
        $post = $this->savedPost();
        $post->post_kind = 'photo';
        // A picture that is not in the media library cannot be attached, which
        // leaves an untitled photo post with nothing at all to send.
        $post->content   = '<img src="https://elsewhere.example/flower.jpg" alt="A flower">';
        $post->status    = 'published';

        $this->assertNull($this->payloadFor($post));
    }

    // ── Pixelfed: what is allowed through ─────────────────────────────────────

    /**
     * The whole point of the Pixelfed copy: a photo post, and only a photo post.
     */
    public function testPixelfedTakesAPhotoPostWithAPicture(): void
    {
        $this->configurePixelfed();

        $post            = $this->savedPost();
        $post->post_kind = 'photo';

        $this->assertTrue($this->wantsPixelfed($post, [self::AN_IMAGE]));
    }

    /**
     * A titled post carrying images is an `article` here (Post::micropubType()),
     * and an article with a screenshot in it is not a photo post.
     */
    public function testPixelfedRefusesAnArticleEvenWhenItHasImages(): void
    {
        $this->configurePixelfed();

        $post            = $this->savedPost();
        $post->post_kind = 'standard';

        $this->assertFalse($this->wantsPixelfed($post, [self::AN_IMAGE]));
    }

    /**
     * A photo post whose pictures live outside the media library resolves to no
     * attachments, and Pixelfed answers a status with no media with a 403.
     */
    public function testPixelfedRefusesAPhotoPostWithNothingToAttach(): void
    {
        $this->configurePixelfed();

        $post            = $this->savedPost();
        $post->post_kind = 'photo';

        $this->assertFalse($this->wantsPixelfed($post, []));
    }

    public function testPixelfedRespectsTheOptOut(): void
    {
        $this->configurePixelfed();

        $post                = $this->savedPost();
        $post->post_kind     = 'photo';
        $post->pixelfed_skip = 1;

        $this->assertFalse($this->wantsPixelfed($post, [self::AN_IMAGE]));
    }

    /** No instance, no token, no copy — however photographic the post is. */
    public function testPixelfedIsSkippedWhenItIsNotConfigured(): void
    {
        $post            = $this->savedPost();
        $post->post_kind = 'photo';

        $this->assertFalse($this->wantsPixelfed($post, [self::AN_IMAGE]));
    }

    // ── Pixelfed: reading a caption back ──────────────────────────────────────

    /**
     * Pixelfed does not route /api/v1/statuses/{id}/source, so the caption has
     * to be recovered from the status's rendered HTML. Markup that separates
     * words must become whitespace on the way, or every save looks like a
     * change — and a status can only be edited ten times, ever.
     */
    public function testPixelfedReadsTheCaptionOutOfTheRenderedStatus(): void
    {
        $sourceText = new \ReflectionMethod(Pixelfed::class, 'sourceText');

        $text = $sourceText->invoke(
            new Pixelfed('https://pixelfed.social', 'token'),
            '123',
            ['content' => '<p>Low tide<br>at dawn &amp; alone</p>']
        );

        $this->assertSame('Low tide at dawn & alone', trim((string) $text));
    }

    /**
     * The recovered caption has been through a renderer, so the comparison that
     * decides whether to spend one of ten edits ignores whitespace.
     */
    public function testPixelfedRecognisesACaptionItAlreadyPosted(): void
    {
        $sameText = new \ReflectionMethod(Pixelfed::class, 'sameText');
        $pixelfed = new Pixelfed('https://pixelfed.social', 'token');

        $this->assertTrue($sameText->invoke($pixelfed, "Low tide\nat dawn", 'Low tide at dawn'));
        $this->assertFalse($sameText->invoke($pixelfed, 'Low tide at dusk', 'Low tide at dawn'));
        $this->assertFalse($sameText->invoke($pixelfed, 'Low tide at dawn', null));
    }

    /** Pixelfed rejects a status with no media, so there is no post to make. */
    public function testPixelfedDoesNotPostACaptionOnItsOwn(): void
    {
        $pixelfed = new Pixelfed('https://pixelfed.social', 'token');

        $this->assertNull($pixelfed->tootPost('', '', 'Just words', '', []));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Stands in for a resolved attachment; nothing here reads the file. */
    private const AN_IMAGE = ['path' => '/tmp/photo.jpg', 'mime' => 'image/jpeg', 'alt' => ''];

    private function configurePixelfed(): void
    {
        $this->db->upsertSetting('pixelfed_instance', 'https://pixelfed.social');
        $this->db->upsertSetting('pixelfed_token', 'a-token');
    }

    /** @param array<array{path:string,mime:string,alt:string}> $images */
    private function wantsPixelfed(Post $post, array $images): bool
    {
        $syndication = new Syndication($this->db, ['paths' => ['content' => sys_get_temp_dir()]]);
        $wants       = new \ReflectionMethod(Syndication::class, 'wantsPixelfed');

        return (bool) $wants->invoke($syndication, $post, $images);
    }

    private function savedPost(): Post
    {
        $post          = new Post($this->db);
        $post->title   = 'Test post';
        $post->slug    = 'test-post';
        $post->content = 'Some words.';
        $post->save();

        return $post;
    }

    /** @return array<array<string,mixed>> */
    private function facetsFor(string $text, string ...$urls): array
    {
        $buildFacets = new \ReflectionMethod(Bluesky::class, 'buildFacets');

        return $buildFacets->invoke(new Bluesky('jim.example', 'app-password'), $text, ...$urls);
    }

    /** @return array<string,mixed>|null */
    private function payloadFor(Post $post): ?array
    {
        $syndication = new Syndication($this->db, ['paths' => ['content' => sys_get_temp_dir()]]);
        $payload     = new \ReflectionMethod(Syndication::class, 'payload');

        return $payload->invoke($syndication, $post);
    }
}
