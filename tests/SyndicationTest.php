<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Database;
use CMS\Mastodon;
use CMS\Post;
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
}
