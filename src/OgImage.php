<?php

declare(strict_types=1);

namespace CMS;

use RuntimeException;

/**
 * Generates an Open Graph PNG image (1200×630) for a published post.
 *
 * Uses PHP GD with FreeType. Requires the gd extension compiled with
 * --with-freetype (standard in php:8.x-fpm Docker images with the extra
 * libfreetype6-dev apt package installed).
 *
 * The card is the site's dark scheme, not a palette of its own. Its three
 * colours are lifted verbatim from the dark-mode tokens in `theme.css`, and
 * the type is the same DM Sans the site loads — `fonts/og/` holds the static
 * TTF cut of it because GD cannot read the variable `.woff2` the pages use.
 * A social preview is usually the first thing anyone sees of a post, so it
 * should look like the page it opens.
 *
 * Change any of that and bump DESIGN_VERSION, or every post already on disk
 * keeps its old card: `Builder::buildOgImage()` only redraws when the hash it
 * stamps changes, and until 1.23.0 that hash knew about the text and the font
 * file but nothing about the design.
 */
class OgImage
{
    /**
     * Stamped into the Builder's OG hash so a design change invalidates the
     * images already written. Bump it whenever the drawing below changes.
     */
    public const DESIGN_VERSION = 2;

    private const WIDTH   = 1200;
    private const HEIGHT  = 630;
    private const PADDING = 80;

    // Colours (R, G, B), taken from the dark-mode block of theme.css.
    private const BG_COLOR    = [26,  23,  21];   // #1A1715 --color-bg
    private const TITLE_COLOR = [237, 230, 220];  // #EDE6DC --color-text
    private const META_COLOR  = [163, 154, 142];  // #A39A8E --color-muted

    // Type scale. On the page the site name is the smaller of the two and the
    // title carries the weight; the card exaggerates that gap because a preview
    // is read at thumbnail size in a timeline, where only the title survives.
    private const META_SIZE  = 26;
    private const TITLE_MAX  = 76;
    private const TITLE_MIN  = 32;

    /**
     * How many lines the title may take before it is set smaller. Four lines of
     * TITLE_MAX is very close to the full height below the site name, so the
     * fit loop can shrink to TITLE_MIN without the block ever climbing into it.
     */
    private const TITLE_LINES_MAX = 4;

    /** Clear air between the site name and the tallest the title may reach. */
    private const TITLE_CLEARANCE = 48;

    /**
     * Looser than the 1.1 `.post__title` uses on the page. That value is set for
     * 27px type; DM Sans carries a 1.30em ascender-to-descender, so at 76px the
     * page value runs the lines into each other.
     */
    private const TITLE_LINE_HEIGHT = 1.2;

    private string $fontRegular;
    private string $fontBold;

    public function __construct(string $fontDir)
    {
        $fontDir = rtrim($fontDir, '/\\');

        if (!extension_loaded('gd')) {
            throw new RuntimeException('GD extension is not loaded.');
        }

        if (
            file_exists($fontDir . '/DMSans-Regular.ttf') &&
            file_exists($fontDir . '/DMSans-Bold.ttf')
        ) {
            $this->fontRegular = $fontDir . '/DMSans-Regular.ttf';
            $this->fontBold    = $fontDir . '/DMSans-Bold.ttf';
        } else {
            throw new RuntimeException('DMSans-Regular.ttf and DMSans-Bold.ttf not found in ' . $fontDir);
        }
    }

    /**
     * Generate and save the OG image PNG.
     *
     * @param string $siteTitle  The site name shown in smaller text at the top.
     * @param string $postTitle  The post title, set large and hung off the foot.
     * @param string $outputPath Absolute path to write the PNG file.
     */
    public function generate(string $siteTitle, string $postTitle, string $outputPath): void
    {
        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if ($img === false) {
            throw new RuntimeException('imagecreatetruecolor() failed.');
        }

        // Background
        $bg = imagecolorallocate($img, ...self::BG_COLOR);
        imagefilledrectangle($img, 0, 0, self::WIDTH - 1, self::HEIGHT - 1, $bg);

        $titleColor = imagecolorallocate($img, ...self::TITLE_COLOR);
        $metaColor  = imagecolorallocate($img, ...self::META_COLOR);

        $pad  = self::PADDING;
        $maxW = self::WIDTH - ($pad * 2);

        // ── Site title (top left, muted, regular) ────────────────────────────
        // Cap-aligned to the padding rather than baseline-aligned, so the gap
        // above it reads as the same 80px the title keeps below.
        $metaFoot = $pad;
        if ($siteTitle !== '') {
            $metaBox  = imagettfbbox(self::META_SIZE, 0, $this->fontRegular, $siteTitle);
            $metaY    = $pad + abs($metaBox[7]);
            $metaFoot = $metaY + max(0, $metaBox[1]);
            imagettftext($img, self::META_SIZE, 0, $pad, $metaY, $metaColor, $this->fontRegular, $siteTitle);
        }

        // ── Post title (hung off the foot, bold, word-wrapped) ────────────────
        // Anchored to the bottom, not centred: a short title and a long one then
        // share a baseline, which is what makes a run of cards look like a set.
        $titleSize = $this->fitFontSize($postTitle, $this->fontBold, $maxW);
        $lines     = $this->wrapText($postTitle, $this->fontBold, $titleSize, $maxW);

        $lineH = (int) round($titleSize * self::TITLE_LINE_HEIGHT);

        // A title long enough to still overrun at TITLE_MIN would grow upwards
        // through the site name and off the top. Nothing writes one today, but
        // an over-long card is a silently broken card, so trim it to fit.
        $room     = self::HEIGHT - $pad - $metaFoot - self::TITLE_CLEARANCE;
        $maxLines = max(1, (int) ($room / $lineH));
        if (count($lines) > $maxLines) {
            $lines   = array_slice($lines, 0, $maxLines);
            $lastIdx = $maxLines - 1;
            $lines[$lastIdx] = $this->ellipsize($lines[$lastIdx], $this->fontBold, $titleSize, $maxW);
        }

        // wrapText() never breaks inside a word, so a single long one — a URL, a
        // hashtag, a German compound — comes back as one over-wide line and runs
        // off the right edge whatever size it is set at.
        $lines = array_map(
            fn (string $line): string => $this->fitLineWidth($line, $this->fontBold, $titleSize, $maxW),
            $lines
        );

        // Sit the last line's *descender* on the padding, so a title ending in
        // "g" keeps the same visual margin as one ending in "e".
        $lastBox   = imagettfbbox($titleSize, 0, $this->fontBold, end($lines));
        $descent   = max(0, $lastBox[1]);
        $lastBaseY = self::HEIGHT - $pad - $descent;

        $lineCount = count($lines);
        foreach ($lines as $i => $line) {
            $y = $lastBaseY - (($lineCount - 1 - $i) * $lineH);
            imagettftext($img, $titleSize, 0, $pad, $y, $titleColor, $this->fontBold, $line);
        }

        // ── Save ──────────────────────────────────────────────────────────────
        $dir = dirname($outputPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create output directory: ' . $dir);
        }

        if (!imagepng($img, $outputPath, 9)) {
            throw new RuntimeException('imagepng() failed writing to ' . $outputPath);
        }
        imagedestroy($img);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Find the largest font size at which $text wraps to no more than
     * TITLE_LINES_MAX lines inside $maxWidth.
     */
    private function fitFontSize(string $text, string $font, int $maxWidth): int
    {
        for ($size = self::TITLE_MAX; $size >= self::TITLE_MIN; $size--) {
            $lines = $this->wrapText($text, $font, $size, $maxWidth);
            if (count($lines) <= self::TITLE_LINES_MAX) {
                return $size;
            }
        }
        return self::TITLE_MIN;
    }

    /** Width of $text in pixels at $fontSize. */
    private function textWidth(string $text, string $font, int $fontSize): int
    {
        $bbox = imagettfbbox($fontSize, 0, $font, $text);
        return (int) abs($bbox[4] - $bbox[0]);
    }

    /** $text as-is when it fits within $maxWidth, and ellipsized when it does not. */
    private function fitLineWidth(string $text, string $font, int $fontSize, int $maxWidth): string
    {
        return $this->textWidth($text, $font, $fontSize) <= $maxWidth
            ? $text
            : $this->ellipsize($text, $font, $fontSize, $maxWidth);
    }

    /**
     * $text cut down until it and a trailing ellipsis fit within $maxWidth.
     * Always ellipsizes, so it also marks a title truncated for height.
     */
    private function ellipsize(string $text, string $font, int $fontSize, int $maxWidth): string
    {
        $body = rtrim($text);

        while ($body !== '') {
            $candidate = rtrim($body) . '…';
            if ($this->textWidth($candidate, $font, $fontSize) <= $maxWidth) {
                return $candidate;
            }
            $body = mb_substr($body, 0, -1);
        }

        return '…';
    }

    /**
     * Break $text into lines that each fit within $maxWidth at $fontSize.
     *
     * @return string[]
     */
    private function wrapText(string $text, string $font, int $fontSize, int $maxWidth): array
    {
        $words = explode(' ', $text);
        $lines = [];
        $line  = '';

        foreach ($words as $word) {
            $test = $line === '' ? $word : $line . ' ' . $word;

            if ($this->textWidth($test, $font, $fontSize) > $maxWidth && $line !== '') {
                $lines[] = $line;
                $line    = $word;
            } else {
                $line = $test;
            }
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines === [] ? [''] : $lines;
    }
}
