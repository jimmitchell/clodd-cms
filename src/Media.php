<?php

declare(strict_types=1);

namespace CMS;

use RuntimeException;

class Media
{
    /** MIME types the CMS will accept. */
    private const ALLOWED_MIME = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        'video/mp4'       => 'mp4',
        'video/webm'      => 'webm',
        'audio/mpeg'      => 'mp3',
        'audio/ogg'       => 'ogg',
    ];

    /** Default max upload size in bytes (50 MB). */
    private const DEFAULT_MAX_BYTES = 52_428_800;

    private Database $db;
    private string   $storageDir;
    private int      $maxBytes;

    public function __construct(Database $db, string $storageDir, int $maxBytes = self::DEFAULT_MAX_BYTES)
    {
        $this->db         = $db;
        $this->storageDir = rtrim($storageDir, '/\\');
        $this->maxBytes   = $maxBytes;

        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0775, true);
        }
    }

    // ── Upload ────────────────────────────────────────────────────────────────

    /**
     * Process a single file from $_FILES.
     *
     * @param  array{name:string,tmp_name:string,size:int,error:int} $file
     * @return array{id:int,filename:string,url:string}
     * @throws RuntimeException on validation failure or I/O error
     */
    public function upload(array $file): array
    {
        // 1. PHP upload error codes.
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->phpUploadError($file['error']));
        }

        // 2. Size check (server-side — php.ini limits may catch it earlier).
        if ($file['size'] > $this->maxBytes) {
            $mb = round($this->maxBytes / 1_048_576);
            throw new RuntimeException("File exceeds the {$mb} MB size limit.");
        }

        // 3. MIME type via fileinfo (not the browser-supplied type).
        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!array_key_exists($mimeType, self::ALLOWED_MIME)) {
            throw new RuntimeException("File type '{$mimeType}' is not allowed.");
        }

        // 4. Generate a safe, unique filename.
        $originalName = $file['name'];
        $safeName     = $this->safeFilename($originalName, self::ALLOWED_MIME[$mimeType]);
        $destPath     = $this->storageDir . '/' . $safeName;

        // 5. Move the uploaded file.
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new RuntimeException('Could not save the uploaded file.');
        }

        // 5a. Generate a WebP companion for JPEG/PNG uploads (non-fatal).
        $ext = self::ALLOWED_MIME[$mimeType];
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            $this->generateWebp($destPath);
        }

        // 6. Insert DB record.
        $id = $this->db->insert('media', [
            'filename'      => $safeName,
            'original_name' => $originalName,
            'mime_type'     => $mimeType,
            'size'          => $file['size'],
        ]);

        return [
            'id'       => $id,
            'filename' => $safeName,
            'url'      => '/media/' . rawurlencode($safeName),
        ];
    }

    // ── Ingest from a URL ─────────────────────────────────────────────────────

    /**
     * Download a remote image into the media library.
     *
     * Streams the response to a temp file via cURL (PHP RAM stays flat regardless
     * of image size), validates MIME against the same allowlist as upload(), then
     * reuses the existing safeFilename() + generateWebp() helpers. Dedups across
     * calls via media.source_url.
     *
     * @return array{id:int,filename:string,url:string,reused:bool}
     * @throws RuntimeException on fetch / validation / I/O failure
     */
    public function ingestFromUrl(string $url, int $timeout = 30): array
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new RuntimeException("Unsupported URL scheme: {$url}");
        }

        // Cross-run dedup: same URL referenced from many posts is fetched once.
        $existing = $this->db->selectOne(
            "SELECT id, filename FROM media WHERE source_url = :u LIMIT 1",
            ['u' => $url]
        );
        if ($existing !== null) {
            return [
                'id'       => (int) $existing['id'],
                'filename' => (string) $existing['filename'],
                'url'      => '/media/' . rawurlencode((string) $existing['filename']),
                'reused'   => true,
            ];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'cms_media_');
        if ($tmp === false) {
            throw new RuntimeException('Could not create temp file for download.');
        }

        $fp = fopen($tmp, 'wb');
        if ($fp === false) {
            @unlink($tmp);
            throw new RuntimeException('Could not open temp file for writing.');
        }

        // SafeHttp enforces the SSRF guard: http(s) only, public addresses only,
        // every redirect hop re-validated against a pinned DNS answer. Without it
        // a post's <img src> — which can arrive from a WXR import or a Micropub
        // client — would let a caller aim the server at internal services.
        $http = SafeHttp::download(
            $url,
            $fp,
            // Aborts early when the server sends Content-Length larger than the cap.
            [CURLOPT_MAXFILESIZE => $this->maxBytes],
            5,
            $timeout
        );
        fclose($fp);

        if ($http === null) {
            @unlink($tmp);
            throw new RuntimeException(
                "Fetch failed or blocked for {$url} (non-public address, disallowed scheme, or transport error)"
            );
        }
        if ($http >= 400) {
            @unlink($tmp);
            throw new RuntimeException("HTTP {$http} for {$url}");
        }

        // Post-download size check (covers servers that don't set Content-Length).
        $size = filesize($tmp) ?: 0;
        if ($size === 0) {
            @unlink($tmp);
            throw new RuntimeException("Empty response body for {$url}");
        }
        if ($size > $this->maxBytes) {
            $mb = round($this->maxBytes / 1_048_576);
            @unlink($tmp);
            throw new RuntimeException("Downloaded file exceeds {$mb} MB cap: {$url}");
        }

        // MIME via fileinfo (don't trust Content-Type header).
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!is_string($mimeType) || !array_key_exists($mimeType, self::ALLOWED_MIME)) {
            @unlink($tmp);
            throw new RuntimeException("Disallowed MIME '" . ($mimeType ?: 'unknown') . "' for {$url}");
        }

        // Derive a friendly original_name from the URL path.
        $urlPath      = parse_url($url, PHP_URL_PATH) ?: '';
        $urlBasename  = $urlPath !== '' ? basename($urlPath) : '';
        $originalName = $urlBasename !== '' ? $urlBasename : 'image.' . self::ALLOWED_MIME[$mimeType];

        $safeName = $this->safeFilename($originalName, self::ALLOWED_MIME[$mimeType]);
        $destPath = $this->storageDir . '/' . $safeName;

        // rename() is atomic when src and dest are on the same filesystem; falls
        // back to copy+unlink across filesystems (sys temp vs. project storage).
        if (!@rename($tmp, $destPath)) {
            if (!@copy($tmp, $destPath)) {
                @unlink($tmp);
                throw new RuntimeException('Could not move downloaded file into media storage.');
            }
            @unlink($tmp);
        }

        $ext = self::ALLOWED_MIME[$mimeType];
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            $this->generateWebp($destPath);
        }

        $id = $this->db->insert('media', [
            'filename'      => $safeName,
            'original_name' => $originalName,
            'mime_type'     => $mimeType,
            'size'          => $size,
            'source_url'    => $url,
        ]);

        return [
            'id'       => $id,
            'filename' => $safeName,
            'url'      => '/media/' . rawurlencode($safeName),
            'reused'   => false,
        ];
    }

    // ── Delete ────────────────────────────────────────────────────────────────

    /**
     * Delete a media item by DB id: removes the file and the DB row.
     * Returns true on success, false if the item wasn't found.
     */
    public function delete(int $id): bool
    {
        $row = $this->db->selectOne("SELECT filename FROM media WHERE id = :id", ['id' => $id]);
        if (!$row) {
            return false;
        }

        // Use basename() to prevent any path traversal in stored filenames.
        $path = $this->storageDir . '/' . basename($row['filename']);
        if (file_exists($path)) {
            unlink($path);
        }

        // Remove WebP companion if one was generated.
        $webpPath = (string) preg_replace('/\.[^.]+$/', '.webp', $path);
        if ($webpPath !== $path && file_exists($webpPath)) {
            unlink($webpPath);
        }

        $this->db->delete('media', 'id = :id', ['id' => $id]);
        return true;
    }

    // ── List ──────────────────────────────────────────────────────────────────

    /**
     * Return all media records, newest first.
     *
     * @return array<array<string,mixed>>
     */
    public function all(): array
    {
        return $this->db->select(
            "SELECT id, filename, original_name, mime_type, size, uploaded_at
               FROM media
              ORDER BY uploaded_at DESC"
        );
    }

    /**
     * Return media records for an ordered list of IDs.
     * The returned array preserves the same order as $ids.
     *
     * @param  int[]  $ids
     * @return array<array<string,mixed>>
     */
    public function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $ids          = array_map('intval', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows         = $this->db->select(
            "SELECT id, filename, original_name, mime_type FROM media WHERE id IN ({$placeholders})",
            $ids
        );

        // Index by id, then re-order to match the requested sequence.
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) $row['id']] = $row;
        }

        $result = [];
        foreach ($ids as $id) {
            if (isset($indexed[$id])) {
                $result[] = $indexed[$id];
            }
        }

        return $result;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Accepted MIME types for use in HTML accept= attributes.
     */
    public static function acceptAttribute(): string
    {
        return implode(',', array_keys(self::ALLOWED_MIME));
    }

    public static function isImage(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/');
    }

    /**
     * Return the public URL of a WebP companion file if it exists alongside $filename
     * in $mediaDir. Returns null if no sibling .webp is present.
     */
    public static function webpUrlFor(string $filename, string $mediaDir): ?string
    {
        $webpName = preg_replace('/\.[^.]+$/', '.webp', $filename);
        if ($webpName === null || $webpName === $filename) {
            return null;
        }
        $path = rtrim($mediaDir, '/\\') . '/' . $webpName;
        return file_exists($path) ? '/media/' . rawurlencode($webpName) : null;
    }

    public static function isVideo(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'video/');
    }

    /**
     * Extract distinct external <img> source URLs from HTML.
     * "External" = absolute http(s) URL whose host differs from $siteUrl's host
     * (or any non-empty absolute URL when $siteUrl is empty). Already-local
     * paths starting with "/" are skipped, as are data: URIs.
     *
     * @return string[]  unique URLs in first-seen order
     */
    public static function extractExternalImageUrls(string $html, string $siteUrl = ''): array
    {
        if ($html === '' || stripos($html, '<img') === false) {
            return [];
        }

        $siteHost = $siteUrl !== '' ? (string) (parse_url($siteUrl, PHP_URL_HOST) ?: '') : '';

        if (!preg_match_all('/<img\b[^>]*\bsrc\s*=\s*(["\'])(.*?)\1/is', $html, $matches)) {
            return [];
        }

        $urls = [];
        foreach ($matches[2] as $src) {
            $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($src === '' || $src[0] === '/' || str_starts_with($src, 'data:')) {
                continue;
            }
            $scheme = parse_url($src, PHP_URL_SCHEME);
            if ($scheme !== 'http' && $scheme !== 'https') {
                continue;
            }
            if ($siteHost !== '') {
                $host = (string) (parse_url($src, PHP_URL_HOST) ?: '');
                if (strcasecmp($host, $siteHost) === 0) {
                    continue;
                }
            }
            $urls[$src] = true;
        }

        return array_keys($urls);
    }

    /**
     * Rewrite img-src URLs in HTML using a map of old→new.
     * Only matches inside <img src="..."> attributes (won't touch links or
     * unrelated occurrences elsewhere in the body).
     *
     * @param array<string,string> $urlMap  externalUrl → localPath
     */
    public static function rewriteImageUrls(string $html, array $urlMap): string
    {
        if ($html === '' || empty($urlMap)) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/(<img\b[^>]*\bsrc\s*=\s*(["\']))(.*?)(\2)/is',
            static function (array $m) use ($urlMap): string {
                $decoded = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $replacement = $urlMap[$decoded] ?? null;
                if ($replacement === null) {
                    // Try the raw (un-decoded) URL too.
                    $replacement = $urlMap[$m[3]] ?? null;
                }
                if ($replacement === null) {
                    return $m[0];
                }
                return $m[1] . htmlspecialchars($replacement, ENT_QUOTES, 'UTF-8') . $m[4];
            },
            $html
        );
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1_048_576) {
            return round($bytes / 1_048_576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Generate a collision-safe filename while keeping the original name readable.
     * Pattern: {sanitized_stem}_{8_hex_chars}.{canonical_ext}
     */
    private function safeFilename(string $originalName, string $canonicalExt): string
    {
        // Strip path components — never trust client input.
        $base = basename($originalName);

        // Remove the extension from the base and sanitize.
        $stem = pathinfo($base, PATHINFO_FILENAME);
        $stem = strtolower($stem);
        $stem = preg_replace('/[^a-z0-9_-]+/', '-', $stem);
        $stem = trim($stem, '-') ?: 'file';

        // Truncate long stems.
        $stem = mb_substr($stem, 0, 60);

        $unique = bin2hex(random_bytes(4)); // 8 hex chars

        $candidate = "{$stem}_{$unique}.{$canonicalExt}";

        // In the (astronomically unlikely) event of collision, append more entropy.
        $attempts = 0;
        while (file_exists($this->storageDir . '/' . $candidate)) {
            $unique    = bin2hex(random_bytes(4));
            $candidate = "{$stem}_{$unique}.{$canonicalExt}";
            if (++$attempts > 10) {
                throw new RuntimeException('Could not generate a unique filename.');
            }
        }

        return $candidate;
    }

    /**
     * Longest edge of the primary WebP companion, in pixels. Camera and phone
     * originals run to 4000px and beyond; nothing on the site is displayed
     * wider than the article measure, so serving the full original was sending
     * several megabytes to render a ~700px column.
     */
    public const WEBP_MAX_EDGE = 1600;

    /** Quality for WebP encoding. 82 is visually clean at this scale. */
    private const WEBP_QUALITY = 82;

    /**
     * Generate .webp companions alongside a JPEG or PNG source: one capped at
     * WEBP_MAX_EDGE, and a narrow variant for phones. Both are proportional, so
     * the original's aspect ratio (and therefore the width/height attributes
     * taken from it) still describes them.
     *
     * Silently skips if GD is unavailable or conversion fails — a missing
     * companion only costs bytes, and ImageTag falls back to the original.
     *
     * @return list<string> paths written
     */
    public function generateWebp(string $sourcePath): array
    {
        if (!extension_loaded('gd') || !function_exists('imagewebp')) {
            return [];
        }

        $ext   = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $image = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($sourcePath),
            'png'         => @imagecreatefrompng($sourcePath),
            default       => false,
        };

        if ($image === false) {
            return [];
        }

        try {
            $written = [];

            $primary = (string) preg_replace('/\.[^.]+$/', '.webp', $sourcePath);
            if ($this->writeWebpAtWidth($image, $ext, $primary, self::WEBP_MAX_EDGE)) {
                $written[] = $primary;
            }

            $narrow = (string) preg_replace('/\.[^.]+$/', '-' . ImageTag::NARROW_WIDTH . '.webp', $sourcePath);
            if ($this->writeWebpAtWidth($image, $ext, $narrow, ImageTag::NARROW_WIDTH)) {
                $written[] = $narrow;
            }

            return $written;
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * Write $image to $destPath, scaled so its longest edge is at most $maxEdge.
     * Images already within the bound are written at their original size rather
     * than upscaled. Returns false when nothing was written.
     *
     * @param \GdImage $image
     */
    private function writeWebpAtWidth(\GdImage $image, string $ext, string $destPath, int $maxEdge): bool
    {
        $srcW = imagesx($image);
        $srcH = imagesy($image);
        if ($srcW < 1 || $srcH < 1) {
            return false;
        }

        $longest = max($srcW, $srcH);

        // The narrow variant only earns its place when it is actually smaller;
        // for an image already under the cap it would duplicate the primary.
        if ($longest <= $maxEdge && $maxEdge !== self::WEBP_MAX_EDGE) {
            return false;
        }

        $scale = $longest > $maxEdge ? $maxEdge / $longest : 1.0;
        $dstW  = max(1, (int) round($srcW * $scale));
        $dstH  = max(1, (int) round($srcH * $scale));

        if ($scale === 1.0) {
            $target = $image;
        } else {
            $target = imagecreatetruecolor($dstW, $dstH);
            if ($target === false) {
                return false;
            }
            if ($ext === 'png') {
                imagealphablending($target, false);
                imagesavealpha($target, true);
            }
            imagecopyresampled($target, $image, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        }

        if ($ext === 'png' && $target === $image) {
            imagepalettetotruecolor($target);
            imagealphablending($target, true);
            imagesavealpha($target, true);
        }

        $ok = @imagewebp($target, $destPath, self::WEBP_QUALITY);

        if ($target !== $image) {
            imagedestroy($target);
        }

        return $ok;
    }

    private function phpUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the allowed size.',
            UPLOAD_ERR_PARTIAL   => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE   => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary directory.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
            default              => 'Unknown upload error (code ' . $code . ').',
        };
    }
}
