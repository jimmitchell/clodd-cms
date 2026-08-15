<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\ResponsiveImages;
use PHPUnit\Framework\TestCase;

/**
 * Upgrading raw <img> tags in rendered HTML.
 *
 * ImageRenderer only fires for Markdown ![](…) syntax, so an image written as
 * literal HTML — the whole imported Micro.blog archive — shipped the original
 * JPEG with no dimensions. Across the built site that was 207 MB of images
 * where 51 MB would do.
 */
final class ResponsiveImagesTest extends TestCase
{
    private string $mediaDir;

    protected function setUp(): void
    {
        $this->mediaDir = sys_get_temp_dir() . '/clodd_ri_' . bin2hex(random_bytes(6));
        mkdir($this->mediaDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->mediaDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->mediaDir);
    }

    /** Write a real image so getimagesize() and is_file() see what they expect. */
    private function makeImage(string $name, int $w = 1600, int $h = 900): void
    {
        $im = imagecreatetruecolor($w, $h);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $path = $this->mediaDir . '/' . $name;
        match ($ext) {
            'png'  => imagepng($im, $path),
            'webp' => imagewebp($im, $path),
            default => imagejpeg($im, $path),
        };
        imagedestroy($im);
    }

    // ── The point of the class ────────────────────────────────────────────────

    public function testARawImgWithAWebpSiblingBecomesAPicture(): void
    {
        $this->makeImage('shot.jpg');
        $this->makeImage('shot.webp');

        $out = ResponsiveImages::upgrade(
            '<p><img src="/media/shot.jpg" alt="A shot"></p>',
            $this->mediaDir
        );

        $this->assertStringContainsString('<picture>', $out);
        $this->assertStringContainsString('type="image/webp"', $out);
        $this->assertStringContainsString('/media/shot.webp', $out);
        // The original stays as the <img> fallback for browsers without WebP.
        $this->assertStringContainsString('src="/media/shot.jpg"', $out);
    }

    public function testDimensionsAreAddedSoTheLayoutDoesNotShift(): void
    {
        $this->makeImage('sized.jpg', 1200, 675);

        $out = ResponsiveImages::upgrade('<img src="/media/sized.jpg" alt="">', $this->mediaDir);

        $this->assertStringContainsString('width="1200"', $out);
        $this->assertStringContainsString('height="675"', $out);
    }

    public function testTheNarrowVariantBecomesASrcsetCandidate(): void
    {
        $this->makeImage('wide.jpg');
        $this->makeImage('wide.webp');
        $this->makeImage('wide-800.webp', 800, 450);

        $out = ResponsiveImages::upgrade('<img src="/media/wide.jpg" alt="">', $this->mediaDir);

        $this->assertStringContainsString('/media/wide-800.webp 800w', $out);
        $this->assertStringContainsString('/media/wide.webp 1600w', $out);
    }

    // ── What it must leave alone ──────────────────────────────────────────────

    /**
     * The Markdown path already built these. Wrapping one again would nest
     * <picture> inside <picture> and give the browser two competing sources.
     */
    public function testAnImgAlreadyInsideAPictureIsUntouched(): void
    {
        $this->makeImage('done.jpg');
        $this->makeImage('done.webp');

        $html = '<picture><source srcset="/media/done.webp" type="image/webp">'
              . '<img src="/media/done.jpg" alt="x"></picture>';

        $this->assertSame($html, ResponsiveImages::upgrade($html, $this->mediaDir));
    }

    public function testRunningTwiceChangesNothingTheSecondTime(): void
    {
        $this->makeImage('idem.jpg');
        $this->makeImage('idem.webp');

        $once  = ResponsiveImages::upgrade('<img src="/media/idem.jpg" alt="x">', $this->mediaDir);
        $twice = ResponsiveImages::upgrade($once, $this->mediaDir);

        $this->assertSame($once, $twice);
    }

    public function testARemoteImageIsUntouched(): void
    {
        $html = '<img src="https://example.com/x.jpg" alt="x">';

        $this->assertSame($html, ResponsiveImages::upgrade($html, $this->mediaDir));
    }

    public function testAnAuthorSuppliedSrcsetIsLeftAlone(): void
    {
        $this->makeImage('mine.jpg');
        $this->makeImage('mine.webp');

        $html = '<img src="/media/mine.jpg" srcset="/media/mine.jpg 1x" alt="x">';

        $this->assertSame($html, ResponsiveImages::upgrade($html, $this->mediaDir));
    }

    // ── Attributes ────────────────────────────────────────────────────────────

    public function testAuthorAttributesSurviveTheRewrite(): void
    {
        $this->makeImage('attrs.jpg');
        $this->makeImage('attrs.webp');

        $out = ResponsiveImages::upgrade(
            '<img class="wide" src="/media/attrs.jpg" alt="A &amp; B" title="Cap">',
            $this->mediaDir
        );

        $this->assertStringContainsString('class="wide"', $out);
        $this->assertStringContainsString('title="Cap"', $out);
        // Decoded on the way in and re-escaped on the way out — not &amp;amp;.
        $this->assertStringContainsString('alt="A &amp; B"', $out);
        $this->assertStringNotContainsString('&amp;amp;', $out);
    }

    /** An explicit loading="eager" is how the LCP image opts out of lazy. */
    public function testAnExplicitLoadingAttributeBeatsTheDefault(): void
    {
        $this->makeImage('lcp.jpg');
        $this->makeImage('lcp.webp');

        $out = ResponsiveImages::upgrade(
            '<img src="/media/lcp.jpg" alt="x" loading="eager" fetchpriority="high">',
            $this->mediaDir
        );

        $this->assertStringContainsString('loading="eager"', $out);
        $this->assertStringContainsString('fetchpriority="high"', $out);
        $this->assertStringNotContainsString('loading="lazy"', $out);
    }

    public function testSelfClosingSyntaxIsHandled(): void
    {
        $this->makeImage('xhtml.jpg');
        $this->makeImage('xhtml.webp');

        $out = ResponsiveImages::upgrade('<img src="/media/xhtml.jpg" alt="x" />', $this->mediaDir);

        $this->assertStringContainsString('<picture>', $out);
    }

    public function testMissingFilesAndEmptyInputDoNotBlowUp(): void
    {
        $this->assertSame('<p>none</p>', ResponsiveImages::upgrade('<p>none</p>', $this->mediaDir));
        $this->assertStringContainsString(
            'src="/media/gone.jpg"',
            ResponsiveImages::upgrade('<img src="/media/gone.jpg" alt="x">', $this->mediaDir)
        );
    }

    /** A gallery emits several images in one block; all of them count. */
    public function testEveryImageInABlockIsUpgraded(): void
    {
        foreach (['a', 'b', 'c'] as $n) {
            $this->makeImage($n . '.jpg');
            $this->makeImage($n . '.webp');
        }

        $out = ResponsiveImages::upgrade(
            '<div><img src="/media/a.jpg" alt=""><img src="/media/b.jpg" alt=""><img src="/media/c.jpg" alt=""></div>',
            $this->mediaDir
        );

        $this->assertSame(3, substr_count($out, '<picture>'));
    }
}
