<?php

declare(strict_types=1);

namespace CMS;

/**
 * Builds the markup for a single image, shared by the Markdown renderer and the
 * post templates.
 *
 * Photo posts used to emit a bare <img src> straight from post_photos: no
 * dimensions (so every photo card shifted the layout as it loaded) and no WebP
 * (so the original JPEG went over the wire at full size). The Markdown path had
 * done both properly for a long time via ImageRenderer; this is that logic
 * lifted out so there is one implementation instead of two.
 *
 * For a local /media/ file it produces:
 *   - width/height, to reserve the box before the bytes arrive
 *   - a <picture> with a WebP <source> when a sibling exists
 *   - a srcset when a narrow variant exists, so phones fetch the small one
 */
final class ImageTag
{
    /** Width of the narrow WebP variant written by Media::generateWebp(). */
    public const NARROW_WIDTH = 800;

    /**
     * getimagesize() reads from disk, and the same file is rendered many times
     * per build — once per post, again per feed, again per archive page. Keyed
     * by absolute path; a build is short enough that staleness is not a concern.
     *
     * @var array<string, array{0:int,1:int}|null>
     */
    private static array $dimensions = [];

    /**
     * @param array<string,string|int> $attrs extra attributes for the <img>
     */
    public static function render(string $mediaDir, string $url, string $alt, array $attrs = []): string
    {
        $img = array_merge([
            'src'      => $url,
            'alt'      => $alt,
            'loading'  => 'lazy',
            'decoding' => 'async',
        ], $attrs);

        // 'sizes' belongs on the <source>, not the <img>.
        $sizes = (string) ($img['sizes'] ?? '');
        unset($img['sizes']);

        if (!str_starts_with($url, '/media/')) {
            return self::img($img);
        }

        $filename = basename(rawurldecode($url));
        $path     = rtrim($mediaDir, '/\\') . '/' . $filename;

        if (!is_file($path)) {
            return self::img($img);
        }

        $size = self::dimensions($path);
        if ($size !== null) {
            $img['width']  = $size[0];
            $img['height'] = $size[1];
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            return self::img($img);
        }

        $webpPath = preg_replace('/\.[^.]+$/', '.webp', $path);
        $webpUrl  = preg_replace('/\.[^.]+$/', '.webp', $url);
        if ($webpPath === null || $webpUrl === null || !is_file($webpPath)) {
            return self::img($img);
        }

        return self::picture($webpPath, $webpUrl, $img, $sizes);
    }

    /** <picture> with a WebP <source>, plus a narrow variant when one exists. */
    private static function picture(string $webpPath, string $webpUrl, array $img, string $sizes): string
    {
        $narrowPath = preg_replace('/\.webp$/', '-' . self::NARROW_WIDTH . '.webp', $webpPath);
        $narrowUrl  = preg_replace('/\.webp$/', '-' . self::NARROW_WIDTH . '.webp', $webpUrl);

        $srcset   = [];
        $webpSize = self::dimensions($webpPath);

        if ($narrowPath !== null && $narrowUrl !== null && is_file($narrowPath)) {
            $srcset[] = self::esc($narrowUrl) . ' ' . self::NARROW_WIDTH . 'w';
        }
        if ($webpSize !== null) {
            $srcset[] = self::esc($webpUrl) . ' ' . $webpSize[0] . 'w';
        }

        // With a single candidate there is nothing to choose between, so emit a
        // plain srcset and skip the sizes hint entirely.
        if (count($srcset) < 2) {
            $source = '<source srcset="' . self::esc($webpUrl) . '" type="image/webp">';
        } else {
            $source = '<source srcset="' . implode(', ', $srcset) . '"'
                . ($sizes !== '' ? ' sizes="' . self::esc($sizes) . '"' : '')
                . ' type="image/webp">';
        }

        return '<picture>' . $source . self::img($img) . '</picture>';
    }

    /** @return array{0:int,1:int}|null */
    private static function dimensions(string $path): ?array
    {
        if (array_key_exists($path, self::$dimensions)) {
            return self::$dimensions[$path];
        }

        $size = @getimagesize($path);

        return self::$dimensions[$path] = $size === false ? null : [$size[0], $size[1]];
    }

    /** @param array<string,string|int> $attrs */
    private static function img(array $attrs): string
    {
        $html = '<img';
        foreach ($attrs as $name => $value) {
            $html .= ' ' . $name . '="' . self::esc((string) $value) . '"';
        }
        return $html . '>';
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
