<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\OgImage;
use PHPUnit\Framework\TestCase;

/**
 * The OG card is drawn, never asserted from markup, so these tests read the
 * pixels back.
 *
 * The card is the one thing this CMS produces that nobody here ever looks at —
 * it renders on someone else's timeline. A card that came out wrong would be
 * invisible from the admin, the site and the feeds alike, which is why the
 * cheap structural checks (it exists, it is 1200×630) are not enough on their
 * own and the avatar tests below sample actual pixels.
 */
final class OgImageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!extension_loaded('gd') || !function_exists('imagettftext')) {
            $this->markTestSkipped('GD with FreeType is required to draw a card.');
        }

        // The card is set in whichever sans the host provides, so there is no
        // file to check for — ask OgImage whether it found one. A machine with
        // no usable font cannot draw a card, and there is nothing to assert.
        try {
            new OgImage($this->fontDir());
        } catch (\RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }

        $this->dir = sys_get_temp_dir() . '/clodd_og_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testItWritesA1200x630Png(): void
    {
        $path = $this->dir . '/card.png';
        $this->og()->generate('Jim Mitchell', 'A perfectly ordinary title', $path);

        $this->assertFileExists($path);
        $size = getimagesize($path);
        $this->assertIsArray($size);
        $this->assertSame([1200, 630], [$size[0], $size[1]]);
        $this->assertSame('image/png', $size['mime']);
    }

    /**
     * The whole card is the site's dark ground, so the corners are the one place
     * guaranteed to be untouched by type at any title length.
     */
    public function testTheGroundIsTheThemesDarkBackground(): void
    {
        $path = $this->dir . '/ground.png';
        $this->og()->generate('Jim Mitchell', 'A perfectly ordinary title', $path);

        foreach ([[2, 2], [1197, 2], [2, 627], [1197, 627]] as [$x, $y]) {
            $this->assertSame(
                '#1A1715',
                $this->pixelAt($path, $x, $y),
                "The card ground at ($x, $y) is not the theme's dark --color-bg."
            );
        }
    }

    /** The avatar is drawn, and it is drawn as a circle rather than a square. */
    public function testTheAvatarIsDrawnAsACircle(): void
    {
        $avatar = $this->solidAvatar('#C81E4A');
        $path   = $this->dir . '/avatar.png';
        $this->og()->generate('Jim Mitchell', 'A perfectly ordinary title', $path, $avatar);

        // The lockup starts at PADDING (80) and is AVATAR_EDGE (76) across, so
        // its centre is (118, 118) and its corners are the square's, not the
        // circle's — a square crop would paint them too.
        $this->assertSame('#C81E4A', $this->pixelAt($path, 118, 118), 'The avatar was not drawn at the lockup position.');
        $this->assertSame('#1A1715', $this->pixelAt($path, 82, 82), 'The avatar corner is filled, so it was drawn square rather than circular.');
        $this->assertSame('#1A1715', $this->pixelAt($path, 153, 153), 'The avatar corner is filled, so it was drawn square rather than circular.');
    }

    /**
     * An avatar shifts the site name right to sit beside it. Without that the
     * face would be drawn underneath the name.
     */
    public function testTheAvatarMovesTheSiteNameOutFromUnderIt(): void
    {
        $avatar = $this->solidAvatar('#C81E4A');

        $with    = $this->dir . '/with.png';
        $without = $this->dir . '/without.png';
        $this->og()->generate('Jim Mitchell', 'A perfectly ordinary title', $with, $avatar);
        $this->og()->generate('Jim Mitchell', 'A perfectly ordinary title', $without);

        // Column 100 is inside the avatar's circle. With no avatar the site name
        // is set there instead, so the two cards must differ down that column.
        $this->assertNotSame(
            $this->columnSignature($with, 100),
            $this->columnSignature($without, 100),
            'The site name did not move aside for the avatar.'
        );
    }

    /**
     * A card is worth more than a face. Anything GD cannot open — a path that is
     * not there, a file that is not an image — has to fall back to the plain
     * name, not throw and leave the post with no card at all.
     */
    public function testAnUnreadableAvatarFallsBackToThePlainCard(): void
    {
        $notAnImage = $this->dir . '/notanimage.txt';
        file_put_contents($notAnImage, 'certainly not a PNG');

        $plain = $this->dir . '/plain.png';
        $this->og()->generate('Jim Mitchell', 'A perfectly ordinary title', $plain);
        $expected = md5_file($plain);

        foreach (['/no/such/file.png', $notAnImage, $this->dir] as $i => $bad) {
            $path = $this->dir . "/bad$i.png";
            $this->og()->generate('Jim Mitchell', 'A perfectly ordinary title', $path, $bad);
            $this->assertSame(
                $expected,
                md5_file($path),
                "An unreadable avatar ($bad) changed the card instead of being skipped."
            );
        }
    }

    /**
     * A title long enough to fill the card must not climb into the site name.
     * The guard measures the name's rendered foot, so the avatar — which is
     * taller than the name — has to push that measurement down with it.
     */
    public function testALongTitleStaysClearOfTheAvatarLockup(): void
    {
        $avatar = $this->solidAvatar('#C81E4A');
        $path   = $this->dir . '/long.png';
        $title  = str_repeat('An extremely long title that has to be trimmed to fit ', 8);

        $this->og()->generate('Jim Mitchell', $title, $path, $avatar);

        // The lockup occupies y = 80..156. Nothing may be painted across the
        // band just below it, which is the clearance the guard reserves.
        $image = imagecreatefrompng($path);
        $this->assertNotFalse($image);

        $intruder = null;
        try {
            for ($y = 158; $y < 180 && $intruder === null; $y++) {
                for ($x = 80; $x < 1120; $x++) {
                    if ((imagecolorat($image, $x, $y) & 0xFFFFFF) !== 0x1A1715) {
                        $intruder = "($x, $y)";
                        break;
                    }
                }
            }
        } finally {
            imagedestroy($image);
        }

        $this->assertNull(
            $intruder,
            "The title reached $intruder, inside the clearance below the lockup."
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function og(): OgImage
    {
        return new OgImage($this->fontDir());
    }

    private function fontDir(): string
    {
        return dirname(__DIR__) . '/fonts/og';
    }

    /** A flat-colour PNG standing in for an avatar upload. */
    private function solidAvatar(string $hex): string
    {
        $image = imagecreatetruecolor(200, 200);
        $rgb   = (int) hexdec(ltrim($hex, '#'));
        imagefilledrectangle($image, 0, 0, 199, 199, $rgb);

        $path = $this->dir . '/avatar-source.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    private function pixelAt(string $path, int $x, int $y): string
    {
        $image = imagecreatefrompng($path);
        $this->assertNotFalse($image);

        try {
            return sprintf('#%06X', imagecolorat($image, $x, $y) & 0xFFFFFF);
        } finally {
            imagedestroy($image);
        }
    }

    /** A cheap fingerprint of one pixel column, for comparing two renders. */
    private function columnSignature(string $path, int $x): string
    {
        $image = imagecreatefrompng($path);
        $this->assertNotFalse($image);

        try {
            $column = '';
            for ($y = 0; $y < 630; $y++) {
                $column .= imagecolorat($image, $x, $y) & 0xFFFFFF;
            }
            return md5($column);
        } finally {
            imagedestroy($image);
        }
    }
}
