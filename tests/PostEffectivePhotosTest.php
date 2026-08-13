<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Database;
use CMS\Post;
use PHPUnit\Framework\TestCase;

/**
 * Post::effectivePhotos() — the photos a post has, whichever way it was written.
 *
 * Micropub stores the picture as post_photos rows; a photo post written in the
 * admin keeps it in the body. Consumers that *report* a post's photos need both
 * to look the same, which is what these pin down — along with the two limits
 * that keep the fallback from firing where it would do damage: it is only for
 * photo posts, and it never displaces real rows.
 */
final class PostEffectivePhotosTest extends TestCase
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

    private function makePost(string $content, string $postKind = 'photo', string $title = ''): Post
    {
        $post               = new Post($this->db);
        $post->title        = $title;
        $post->slug         = 'p' . bin2hex(random_bytes(4));
        $post->content      = $content;
        $post->status       = 'published';
        $post->published_at = '2026-08-01 12:00:00';
        $post->post_kind    = $postKind;
        $post->save();
        return $post;
    }

    /** @return string[] */
    private function urls(array $photos): array
    {
        return array_map(fn($p) => (string) $p['url'], $photos);
    }

    // ── Attached rows win ─────────────────────────────────────────────────────

    public function testAttachedRowsAreUsedWhenPresent(): void
    {
        $post = $this->makePost('<img src="/media/from-body.jpg" alt="body">');
        $post->savePhotos([['url' => '/media/attached.jpg', 'alt' => 'attached', 'media_id' => null]]);

        $this->assertSame(['/media/attached.jpg'], $this->urls($post->effectivePhotos()));
    }

    public function testAttachedRowsSurviveUnchanged(): void
    {
        $post = $this->makePost('no images here');
        $post->savePhotos([
            ['url' => '/media/one.jpg', 'alt' => 'one', 'media_id' => null],
            ['url' => '/media/two.jpg', 'alt' => 'two', 'media_id' => null],
        ]);

        $photos = $post->effectivePhotos();
        $this->assertSame(['/media/one.jpg', '/media/two.jpg'], $this->urls($photos));
        $this->assertSame('one', $photos[0]['alt']);
    }

    // ── Body fallback ─────────────────────────────────────────────────────────

    public function testMarkdownImageIsDerived(): void
    {
        $post   = $this->makePost('![A flower](/media/flower.jpg)');
        $photos = $post->effectivePhotos();

        $this->assertCount(1, $photos);
        $this->assertSame('/media/flower.jpg', $photos[0]['url']);
        $this->assertSame('A flower', $photos[0]['alt']);
    }

    public function testHtmlImageIsDerived(): void
    {
        // The shape 12 of the 13 affected posts on the live site use.
        $post   = $this->makePost('<img src="https://example.com/media/ginger.jpg" alt="Ginger the cat">');
        $photos = $post->effectivePhotos();

        $this->assertCount(1, $photos);
        $this->assertSame('https://example.com/media/ginger.jpg', $photos[0]['url']);
        $this->assertSame('Ginger the cat', $photos[0]['alt']);
    }

    public function testMixedFormsComeBackInDocumentOrder(): void
    {
        $post = $this->makePost(
            "<img src=\"/media/first.jpg\" alt=\"first\">\n\n![second](/media/second.jpg)\n\n<img src='/media/third.jpg'>"
        );

        $this->assertSame(
            ['/media/first.jpg', '/media/second.jpg', '/media/third.jpg'],
            $this->urls($post->effectivePhotos())
        );
    }

    public function testDerivedRowsCarryPhotoRowShape(): void
    {
        $post  = $this->makePost('![alt](/media/a.jpg)');
        $photo = $post->effectivePhotos()[0];

        // Consumers treat both sources identically, so the keys must match.
        $this->assertSame(['id', 'url', 'alt', 'sort_order', 'media_id'], array_keys($photo));
        $this->assertNull($photo['id']);
        $this->assertNull($photo['media_id']);
        $this->assertSame(0, $photo['sort_order']);
    }

    public function testEntitiesAreDecoded(): void
    {
        $post = $this->makePost('<img src="/media/a.jpg?w=1&amp;h=2" alt="Jim &amp; the cat">');
        $photo = $post->effectivePhotos()[0];

        $this->assertSame('/media/a.jpg?w=1&h=2', $photo['url']);
        $this->assertSame('Jim & the cat', $photo['alt']);
    }

    public function testMissingAltBecomesEmptyString(): void
    {
        $post = $this->makePost('<img src="/media/a.jpg">');

        $this->assertSame('', $post->effectivePhotos()[0]['alt']);
    }

    // ── Where the fallback must not fire ──────────────────────────────────────

    public function testNonPhotoPostDerivesNothing(): void
    {
        // An article with inline illustrations must not advertise them as its
        // photo property — that would change how a client reads its type.
        $article = $this->makePost('![figure](/media/chart.png)', 'standard', 'An Article');
        $aside   = $this->makePost('![figure](/media/chart.png)', 'aside');

        $this->assertSame([], $article->effectivePhotos());
        $this->assertSame([], $aside->effectivePhotos());
    }

    public function testGalleryShortcodeDerivesNothing(): void
    {
        // Known gap: a gallery body names ids, not URLs. No post on the live
        // site uses this shape, but it must degrade quietly rather than throw.
        $post = $this->makePost('[gallery ids="4,5,6"]');

        $this->assertSame([], $post->effectivePhotos());
    }

    public function testEmptyBodyDerivesNothing(): void
    {
        $this->assertSame([], $this->makePost('')->effectivePhotos());
        $this->assertSame([], $this->makePost('Just words, no picture.')->effectivePhotos());
    }

    // ── The static, used by JsonFeed::render() on raw rows ────────────────────

    public function testStaticFormMatchesTheInstanceForm(): void
    {
        $content = '<img src="/media/a.jpg" alt="a">';

        $this->assertSame(
            $this->makePost($content)->effectivePhotos(),
            Post::photosOrBodyImages([], $content, 'photo')
        );
    }

    public function testStaticFormHonoursNullPostKind(): void
    {
        $this->assertSame([], Post::photosOrBodyImages([], '![x](/media/x.jpg)', null));
    }
}
