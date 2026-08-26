<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Database;
use CMS\Post;

/**
 * A titled post's featured image.
 *
 * The stored field is the truth, but every post written before it existed keeps
 * its lead picture at the top of the body — so the helper that *reports* a
 * post's image derives one from the body as a fallback, exactly the way
 * effectivePhotos() does for photo posts. These pin down the derivation and the
 * three limits that keep it from doing damage: it only fires on a titled post,
 * only when the picture actually leads the body, and never over a stored value.
 */
final class FeaturedImageTest extends TempSiteTestCase
{
    private function makePost(string $content, string $postKind = 'standard', string $title = 'A post'): Post
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

    // ── leadingBodyImage() ───────────────────────────────────────────────────

    public function testMarkdownImageAtTheTopOfTheBodyLeads(): void
    {
        $image = Post::leadingBodyImage("![A rainbow arch](/media/df.jpg)\n\nRumor has it…");

        $this->assertNotNull($image);
        $this->assertSame('/media/df.jpg', $image['url']);
        $this->assertSame('A rainbow arch', $image['alt']);
    }

    public function testHtmlImageAtTheTopOfTheBodyLeads(): void
    {
        // How the WordPress import left them: an <img> inside a paragraph.
        $image = Post::leadingBodyImage('<p><img src="/media/df.webp" alt="Arch"></p><p>Rumor…</p>');

        $this->assertNotNull($image);
        $this->assertSame('/media/df.webp', $image['url']);
        $this->assertSame('Arch', $image['alt']);
    }

    public function testAnImageAfterProseDoesNotLead(): void
    {
        // The whole point of the restriction: an article with a screenshot in
        // the middle must not start advertising it as the post's picture.
        $this->assertNull(Post::leadingBodyImage("Some words first.\n\n![Shot](/media/s.png)"));
    }

    public function testABodyWithNoImageLeadsWithNothing(): void
    {
        $this->assertNull(Post::leadingBodyImage('Just words, all the way down.'));
    }

    // ── effectiveFeaturedImage() ─────────────────────────────────────────────

    public function testTheStoredImageWinsOverTheBody(): void
    {
        $post = $this->makePost("![In the body](/media/body.jpg)\n\nWords.");
        $post->featured_image_url = '/media/stored.jpg';
        $post->featured_image_alt = 'Stored';
        $post->save();

        $image = $post->effectiveFeaturedImage();
        $this->assertNotNull($image);
        $this->assertSame('/media/stored.jpg', $image['url']);
        $this->assertSame('Stored', $image['alt']);
    }

    public function testATitledPostDerivesItsLeadingImage(): void
    {
        $post  = $this->makePost("![Lead](/media/lead.jpg)\n\nWords.");
        $image = $post->effectiveFeaturedImage();

        $this->assertNotNull($image);
        $this->assertSame('/media/lead.jpg', $image['url']);
    }

    public function testOnlyTitledPostsDerive(): void
    {
        // A photo post leads with its own pictures and has no featured slot;
        // deriving here would make it advertise the same image twice, under two
        // different properties.
        foreach (['photo', 'aside'] as $kind) {
            $post = $this->makePost("![Lead](/media/lead.jpg)\n\nWords.", $kind, '');
            $this->assertNull($post->effectiveFeaturedImage(), "kind {$kind} should not derive");
        }
    }

    public function testANoteCannotKeepAStoredFeaturedImage(): void
    {
        // Flipping an article to a note clears the field on the object as well
        // as in the row — the build that follows the save reads the object.
        $post = $this->makePost('Words.', 'standard');
        $post->featured_image_url = '/media/stored.jpg';
        $post->featured_image_alt = 'Stored';
        $post->save();

        $post->post_kind = 'aside';
        $post->save();

        $this->assertNull($post->featured_image_url);
        $this->assertSame('', $post->featured_image_alt);

        $reloaded = Post::findById($this->db, (int) $post->id);
        $this->assertNull($reloaded->featured_image_url);
    }

    public function testTheStoredImageRoundTripsThroughTheDatabase(): void
    {
        $post = $this->makePost('Words.');
        $post->featured_image_url = '/media/lead.jpg';
        $post->featured_image_alt = 'A lead picture';
        $post->save();

        $reloaded = Post::findById($this->db, (int) $post->id);
        $this->assertSame('/media/lead.jpg', $reloaded->featured_image_url);
        $this->assertSame('A lead picture', $reloaded->featured_image_alt);
    }

    // ── normaliseImageUrl() ──────────────────────────────────────────────────

    public function testSameOriginAbsoluteUrlsAreStoredSiteRelative(): void
    {
        $this->assertSame(
            '/media/x.jpg',
            Post::normaliseImageUrl('https://example.com/media/x.jpg', 'https://example.com')
        );
    }

    public function testARemoteUrlIsKeptWhole(): void
    {
        $this->assertSame(
            'https://cdn.example.net/x.jpg',
            Post::normaliseImageUrl('https://cdn.example.net/x.jpg', 'https://example.com')
        );
    }

    public function testAJavascriptUrlIsRefused(): void
    {
        // This value reaches an href on the public page, where javascript: is a
        // working XSS — escaping alone would not stop it.
        $this->assertNull(Post::normaliseImageUrl('javascript:alert(1)', 'https://example.com'));
        $this->assertNull(Post::normaliseImageUrl('  ', 'https://example.com'));
    }

    // ── withoutLeadingImage() ────────────────────────────────────────────────

    public function testPromotingRemovesTheMarkdownImageFromTheBody(): void
    {
        $this->assertSame(
            'Rumor has it…',
            Post::withoutLeadingImage("![A rainbow arch](/media/df.jpg)\n\nRumor has it…")
        );
    }

    public function testPromotingRemovesTheWrappingParagraphToo(): void
    {
        // An empty <p> left behind would print as a gap above the first words.
        $this->assertSame(
            '<p>Rumor…</p>',
            Post::withoutLeadingImage('<p><img src="/media/df.webp" alt="Arch"></p><p>Rumor…</p>')
        );
    }

    public function testABodyWithNoLeadingImageIsReturnedUnchanged(): void
    {
        $body = "Some words first.\n\n![Shot](/media/s.png)";
        $this->assertSame($body, Post::withoutLeadingImage($body));
    }

    // ── contentForRender(): the double-draw guard ────────────────────────────

    /**
     * The picture must not appear twice when the stored featured image is also
     * the one the body opens with. Two ordinary routes get there: a Micropub
     * client round-tripping `q=source` (which reports the derived `featured`)
     * and sending it back as a stored one, and an author picking a featured
     * image in the editor that they had already pasted at the top of the post.
     */
    public function testTheBodyCopyIsDroppedWhenItIsTheFeaturedImage(): void
    {
        $body = "![Arch](/media/lead.jpg)\n\nRumor has it…";

        $this->assertSame('Rumor has it…', Post::contentForRender($body, '/media/lead.jpg'));
    }

    /** A *different* picture below a featured image is legitimate, so it stays. */
    public function testADifferentLeadingImageIsKept(): void
    {
        $body = "![Other](/media/other.jpg)\n\nWords.";

        $this->assertSame($body, Post::contentForRender($body, '/media/lead.jpg'));
    }

    public function testWithNoFeaturedImageTheBodyIsUntouched(): void
    {
        $body = "![Arch](/media/lead.jpg)\n\nWords.";

        $this->assertSame($body, Post::contentForRender($body, null));
        $this->assertSame($body, Post::contentForRender($body, ''));
    }

    public function testTheGuardAlsoCoversAnHtmlLeadingImage(): void
    {
        $body = '<p><img src="/media/lead.jpg" alt="Arch"></p><p>Rumor…</p>';

        $this->assertSame('<p>Rumor…</p>', Post::contentForRender($body, '/media/lead.jpg'));
    }

    public function testRenderableContentAppliesTheGuardForAPost(): void
    {
        $post = $this->makePost("![Arch](/media/lead.jpg)\n\nRumor has it…");
        $post->featured_image_url = '/media/lead.jpg';
        $post->save();

        $this->assertSame('Rumor has it…', $post->renderableContent());
        // The stored content is not rewritten — only what gets rendered.
        $this->assertStringContainsString('![Arch]', $post->content);
    }
}
