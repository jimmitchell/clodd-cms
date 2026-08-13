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
 */
class OgImage
{
    private const WIDTH   = 1200;
    private const HEIGHT  = 630;
    private const PADDING = 80;

    // Colours (R, G, B)
    private const BG_COLOR    = [18,  18,  18];   // #121212
    private const TITLE_COLOR = [255, 255, 255];  // white
    private const META_COLOR  = [153, 153, 153];  // #999

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
     * @param string $postTitle  The post title shown prominently in the centre.
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

        $pad = self::PADDING;
        $maxW = self::WIDTH - ($pad * 2);

        // ── Site title (top area, 24 px, regular) ────────────────────────────
        if ($siteTitle !== '') {
            $metaSize = 36;
            $metaY    = $pad + $metaSize;
            imagettftext($img, $metaSize, 0, $pad, $metaY, $metaColor, $this->fontRegular, $siteTitle);
        }

        // ── Post title (centre, bold, word-wrapped) ───────────────────────────
        $titleSize = $this->fitFontSize($postTitle, $this->fontBold, $maxW, 44, 24);
        $lines     = $this->wrapText($postTitle, $this->fontBold, $titleSize, $maxW);
        $lineCount = count($lines);

        // Line height = font size × 1.3
        $lineH      = (int) round($titleSize * 1.3);
        $blockH     = $lineCount * $lineH;
        $startY     = (int) round((self::HEIGHT - $blockH) / 2) + $titleSize;

        foreach ($lines as $i => $line) {
            $y = $startY + ($i * $lineH);
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
     * Find the largest font size (between $min and $max) at which all words
     * of $text can be wrapped to fit within $maxWidth.
     */
    private function fitFontSize(string $text, string $font, int $maxWidth, int $max, int $min): int
    {
        for ($size = $max; $size >= $min; $size--) {
            $lines = $this->wrapText($text, $font, $size, $maxWidth);
            // Accept if we have at most 4 lines so it stays readable
            if (count($lines) <= 4) {
                return $size;
            }
        }
        return $min;
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
            $bbox = imagettfbbox($fontSize, 0, $font, $test);
            $w    = abs($bbox[4] - $bbox[0]);

            if ($w > $maxWidth && $line !== '') {
                $lines[] = $line;
                $line    = $word;
            } else {
                $line = $test;
            }
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }
}
