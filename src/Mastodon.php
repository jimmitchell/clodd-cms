<?php

declare(strict_types=1);

namespace CMS;

class Mastodon
{
    /**
     * Default image cap on a Mastodon instance. Instances may configure their
     * own, but the media library here accepts files far larger than any of
     * them, so an oversize photo is re-encoded rather than refused on upload.
     */
    private const IMAGE_MAX_BYTES = 16_777_216;

    private string $instanceUrl;
    private string $token;

    public function __construct(string $instanceUrl, string $token)
    {
        $this->instanceUrl = rtrim($instanceUrl, '/');
        $this->token       = $token;
    }

    /**
     * Build and post a toot for a newly-published post.
     * Returns the canonical toot URL on success, null on failure.
     *
     * @param array<array{path:string,mime:string,alt:string}> $images
     *        Local image files to attach — see SyndicationMedia::forPost().
     */
    public function tootPost(string $title, string $excerpt, string $postUrl, array $images = []): ?string
    {
        $text = $this->buildText($title, $excerpt, $postUrl);

        $mediaIds = [];
        foreach ($images as $image) {
            $id = $this->uploadMedia($image['path'], $image['mime'], $image['alt'] ?? '');
            if ($id !== null) {
                $mediaIds[] = $id;
            }
        }

        // Every attachment failed on a post that had nothing but pictures to
        // say — an empty status would be rejected anyway.
        if ($text === '' && $mediaIds === []) {
            return null;
        }

        return $this->post($text, $mediaIds);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Compose toot text within Mastodon's 500-character limit.
     * Layout: any non-empty subset of {title, excerpt, url} joined by blank lines.
     */
    private function buildText(string $title, string $excerpt, string $url): string
    {
        $fixedParts = (int) ($title !== '') + (int) ($url !== '');
        $hasExcerpt = $excerpt !== '';
        $separators = max(0, $fixedParts + (int) $hasExcerpt - 1) * 2;
        $reserved   = mb_strlen($title) + mb_strlen($url) + $separators;
        $budget     = max(0, 500 - $reserved);

        if ($hasExcerpt && mb_strlen($excerpt) > $budget) {
            $excerpt = rtrim(mb_substr($excerpt, 0, $budget - 1)) . '…';
        }

        $parts = array_filter([$title, $excerpt, $url], static fn(string $p): bool => $p !== '');
        return implode("\n\n", $parts);
    }

    /**
     * Upload one image to the media endpoint.
     * Returns the attachment id on success, null on failure.
     */
    private function uploadMedia(string $path, string $mime, string $alt): ?string
    {
        // Files within the cap upload straight off disk, so cURL streams them
        // instead of the whole photo passing through PHP's memory.
        $name   = basename($path);
        $shrunk = null;
        if ((@filesize($path) ?: 0) > self::IMAGE_MAX_BYTES) {
            $shrunk = self::spoolShrunk($path, $mime);
            if ($shrunk === null) {
                return null;
            }
            [$path, $mime] = $shrunk;
            // Send the post's own filename, not the temp one, and keep the
            // extension honest about what the re-encode produced.
            $name = pathinfo($name, PATHINFO_FILENAME) . ($mime === 'image/jpeg' ? '.jpg' : '');
        }

        $fields = ['file' => new \CURLFile($path, $mime, $name)];
        if ($alt !== '') {
            $fields['description'] = $alt;
        }

        $response = $this->request('POST', '/api/v2/media', $fields, 30);

        if ($shrunk !== null) {
            @unlink($shrunk[0]);
        }

        if ($response === null || !in_array($response['code'], [200, 202], true)) {
            return null;
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['id'])) {
            return null;
        }
        $id = (string) $data['id'];

        // 202 means the instance is still processing the file. Attaching an
        // unprocessed attachment to a status is a 422, so wait for it.
        if ($response['code'] === 202 && !$this->awaitMedia($id)) {
            return null;
        }

        return $id;
    }

    /**
     * Re-encode an oversize image to a temp file the caller must unlink.
     * Returns [path, mime], or null when it can't be shrunk.
     *
     * @return array{0:string,1:string}|null
     */
    private static function spoolShrunk(string $path, string $mime): ?array
    {
        $fitted = SyndicationMedia::fit($path, $mime, self::IMAGE_MAX_BYTES);
        if ($fitted === null) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'cms_toot_');
        if ($tmp === false || @file_put_contents($tmp, $fitted['bytes']) === false) {
            if ($tmp !== false) {
                @unlink($tmp);
            }
            return null;
        }

        return [$tmp, $fitted['mime']];
    }

    /** Poll an attachment until the instance reports it ready. */
    private function awaitMedia(string $id, int $attempts = 5): bool
    {
        for ($i = 0; $i < $attempts; $i++) {
            sleep(1);
            $response = $this->request('GET', '/api/v1/media/' . rawurlencode($id));
            if ($response !== null && $response['code'] === 200) {
                return true;
            }
        }
        return false;
    }

    /**
     * POST the status to the Mastodon API.
     * Returns the canonical toot URL on success, null on failure.
     *
     * @param string[] $mediaIds
     */
    private function post(string $text, array $mediaIds = []): ?string
    {
        $response = $this->request('POST', '/api/v1/statuses', self::statusBody($text, $mediaIds));
        if ($response === null || !in_array($response['code'], [200, 201], true)) {
            return null;
        }

        $data = json_decode($response['body'], true);
        return (is_array($data) && !empty($data['url'])) ? $data['url'] : null;
    }

    /**
     * Form-encode the status POST.
     *
     * Attachments go as repeated `media_ids[]` keys, not the numbered
     * `media_ids[0]`, `media_ids[1]` http_build_query() emits for an array:
     * Rails reads the numbered form as a hash, and strong parameters drops it.
     * The status then posts with no pictures at all, or is refused outright
     * when the photos were the whole post and the text is empty.
     *
     * @param string[] $mediaIds
     */
    private static function statusBody(string $text, array $mediaIds): string
    {
        $body = http_build_query(['status' => $text, 'visibility' => 'public']);
        foreach ($mediaIds as $id) {
            $body .= '&' . rawurlencode('media_ids[]') . '=' . rawurlencode($id);
        }
        return $body;
    }

    /**
     * Send an authenticated request to the instance.
     *
     * The hostname is resolved immediately before connecting and pinned via
     * CURLOPT_RESOLVE to prevent DNS rebinding attacks (attacker returns a
     * public IP at validation time, a private IP at request time).
     *
     * @param  array<string,mixed>|string|null $body  array → multipart, string → form-encoded
     * @return array{code:int,body:string}|null
     */
    private function request(string $method, string $path, array|string|null $body = null, int $timeout = 10): ?array
    {
        $parsed = parse_url($this->instanceUrl);
        $host   = $parsed['host'] ?? '';
        $port   = $parsed['port'] ?? 443;

        $resolvedIp = gethostbyname($host);
        if (filter_var($resolvedIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        $options = [
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_RESOLVE        => ["{$host}:{$port}:{$resolvedIp}"],
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        $ch = curl_init($this->instanceUrl . $path);
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $response === false ? null : ['code' => $httpCode, 'body' => (string) $response];
    }
}
