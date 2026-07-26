<?php

declare(strict_types=1);

namespace CMS;

/**
 * Resolves a post's images to files on disk so the syndicators can attach them
 * to the Mastodon and Bluesky copies, and re-encodes an image when a network's
 * blob limit is smaller than the stored original.
 *
 * Only files inside the CMS media directory are attachable — the syndicators
 * read them straight off disk, so a remote URL would mean an outbound fetch on
 * the publish path.
 */
final class SyndicationMedia
{
    /** Both networks cap a single post at four images. */
    public const MAX_ATTACHMENTS = 4;

    /**
     * Images to attach to a syndicated copy of $post, in display order.
     *
     * Photo rows (the Micropub u-photo property) come first. A photo post
     * written in the admin keeps its image in the body instead, so the body's
     * <img> tags are the fallback.
     *
     * @return array<array{path:string,mime:string,alt:string}>
     */
    public static function forPost(
        Post $post,
        string $mediaDir,
        string $siteUrl = '',
        int $limit = self::MAX_ATTACHMENTS
    ): array {
        $candidates = [];
        foreach ($post->photos as $photo) {
            $candidates[] = [
                'url' => (string) ($photo['url'] ?? ''),
                'alt' => (string) ($photo['alt'] ?? ''),
            ];
        }
        if ($candidates === [] && $post->isPhoto()) {
            $candidates = self::imagesInBody($post->content);
        }

        $finfo  = new \finfo(FILEINFO_MIME_TYPE);
        $images = [];
        foreach ($candidates as $candidate) {
            $path = self::localPath($candidate['url'], $mediaDir, $siteUrl);
            if ($path === null || isset($images[$path])) {
                continue;
            }
            $mime = $finfo->file($path);
            if (!is_string($mime) || !Media::isImage($mime)) {
                continue;
            }
            $images[$path] = ['path' => $path, 'mime' => $mime, 'alt' => $candidate['alt']];
            if (count($images) >= $limit) {
                break;
            }
        }

        return array_values($images);
    }

    /**
     * Read an image as bytes no larger than $maxBytes.
     *
     * A file already under the cap is sent untouched. Anything larger is
     * re-encoded as JPEG, shrinking the pixel dimensions until it fits.
     * Returns null when the file can't be read, or when it needs shrinking and
     * GD isn't available to do it — the caller then skips that image rather
     * than failing the whole post.
     *
     * @return array{bytes:string,mime:string}|null
     */
    public static function fit(string $path, string $mime, int $maxBytes): ?array
    {
        $size = @filesize($path);
        if ($size !== false && $size <= $maxBytes) {
            $bytes = @file_get_contents($path);
            return $bytes === false ? null : ['bytes' => $bytes, 'mime' => $mime];
        }

        if (!extension_loaded('gd')) {
            return null;
        }

        $source = @file_get_contents($path);
        if ($source === false) {
            return null;
        }
        $image = @imagecreatefromstring($source);
        if ($image === false) {
            return null;
        }

        // JPEG has no alpha channel, so transparency would encode as black.
        $image = self::flattenOnWhite($image);

        for ($attempt = 0; $attempt < 6; $attempt++) {
            foreach ([82, 65] as $quality) {
                ob_start();
                imagejpeg($image, null, $quality);
                $bytes = (string) ob_get_clean();
                if (strlen($bytes) <= $maxBytes) {
                    imagedestroy($image);
                    return ['bytes' => $bytes, 'mime' => 'image/jpeg'];
                }
            }

            $narrower = (int) round(imagesx($image) * 0.75);
            if ($narrower < 320) {
                break;
            }
            $scaled = imagescale($image, $narrower);
            if ($scaled === false) {
                break;
            }
            imagedestroy($image);
            $image = $scaled;
        }

        imagedestroy($image);
        return null;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Map a photo URL to its file in the media directory, or null when the URL
     * points somewhere else (a remote host, an inline data URI, a path outside
     * /media/) or the file is missing.
     */
    private static function localPath(string $url, string $mediaDir, string $siteUrl): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        // Absolute URLs count only when they're the site's own origin — the
        // stored form for uploads is site-relative, but Micropub clients may
        // send either.
        $host = parse_url($url, PHP_URL_HOST);
        if ($host !== null && $host !== false) {
            $siteHost = $siteUrl !== '' ? parse_url($siteUrl, PHP_URL_HOST) : null;
            if (!is_string($siteHost) || strcasecmp($host, $siteHost) !== 0) {
                return null;
            }
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, '/media/')) {
            return null;
        }

        // basename() keeps a crafted url from walking out of the media dir.
        $file = rtrim($mediaDir, '/\\') . '/' . basename(rawurldecode($path));
        return is_file($file) ? $file : null;
    }

    /**
     * Pull src/alt pairs out of a post body, in document order.
     *
     * Bodies are Markdown with HTML allowed, so both an image written as
     * ![alt](/media/photo.jpg) and one written as an <img> tag count.
     *
     * @return array<array{url:string,alt:string}>
     */
    private static function imagesInBody(string $body): array
    {
        if ($body === '') {
            return [];
        }

        $pattern = '/!\[(?P<mdalt>[^\]]*)\]\(\s*<?(?P<mdsrc>[^)\s>]+)>?[^)]*\)'
                 . '|<img\b(?P<tag>[^>]*)>/is';
        if (!preg_match_all($pattern, $body, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $images = [];
        foreach ($matches as $match) {
            if (($match['mdsrc'] ?? '') !== '') {
                $url = $match['mdsrc'];
                $alt = $match['mdalt'];
            } else {
                $tag = $match['tag'] ?? '';
                if (!preg_match('/\bsrc\s*=\s*(["\'])(.*?)\1/is', $tag, $src)) {
                    continue;
                }
                $url = $src[2];
                $alt = preg_match('/\balt\s*=\s*(["\'])(.*?)\1/is', $tag, $altMatch) ? $altMatch[2] : '';
            }

            $images[] = [
                'url' => html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'alt' => html_entity_decode($alt, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ];
        }

        return $images;
    }

    /** Composite an image onto a white background, dropping any alpha channel. */
    private static function flattenOnWhite(\GdImage $image): \GdImage
    {
        $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
        if ($canvas === false) {
            return $image;
        }
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        imagedestroy($image);
        return $canvas;
    }
}
