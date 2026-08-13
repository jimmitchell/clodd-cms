<?php

declare(strict_types=1);

namespace CMS;

/**
 * A client for the Mastodon API — and, through {@see Pixelfed}, for the other
 * servers that speak it. The pieces an implementation is allowed to differ on
 * are the protected hooks below; everything else (the DNS-pinned request, the
 * `media_ids[]` encoding, re-encoding an oversize photo) is shared, because
 * each of those has been a bug once and is not worth having twice.
 */
class Mastodon
{
    /** Characters a default Mastodon instance accepts in one status. */
    protected const TEXT_LIMIT = 500;

    /**
     * Default image cap on a Mastodon instance. Instances may configure their
     * own, but the media library here accepts files far larger than any of
     * them, so an oversize photo is re-encoded rather than refused on upload.
     */
    protected const IMAGE_MAX_BYTES = 16_777_216;

    /** Names this client in the error log. */
    protected const LOG_PREFIX = 'mastodon';

    protected string $instanceUrl;
    protected string $token;

    public function __construct(string $instanceUrl, string $token)
    {
        $this->instanceUrl = rtrim($instanceUrl, '/');
        $this->token       = $token;
    }

    /**
     * Build and post a toot for a newly-published post.
     * Returns ['url' => canonical toot URL, 'id' => status id], null on failure.
     *
     * @param  string $context
     *         The reply/like/repost/bookmark lines this post opens with, as
     *         plain text — see Post::contextsText().
     * @param  array<array{path:string,mime:string,alt:string}> $images
     *         Local image files to attach — see SyndicationMedia::forPost().
     * @return array{url:string,id:string}|null
     */
    public function tootPost(string $context, string $title, string $excerpt, string $postUrl, array $images = []): ?array
    {
        $text = $this->buildText($context, $title, $excerpt, $postUrl);

        $mediaIds = $this->uploadAll($images);

        // Every attachment failed on a post that had nothing but pictures to
        // say — an empty status would be rejected anyway.
        if ($text === '' && $mediaIds === []) {
            return null;
        }

        return $this->post($text, $mediaIds);
    }

    /**
     * Rewrite an existing toot to match the post as it now stands.
     *
     * Returns true when the toot already says this, as well as when the edit
     * lands — both leave the copy correct, which is all the caller can act on.
     *
     * Pictures are only re-uploaded when their number has changed. Mastodon
     * accepts the attachment ids already on the status, so the ordinary case —
     * fixing a typo on a photo post — edits the words and leaves the photos
     * where they are instead of pushing them over the wire again.
     *
     * @param array<array{path:string,mime:string,alt:string}> $images
     */
    public function editPost(
        string $statusId,
        string $context,
        string $title,
        string $excerpt,
        string $postUrl,
        array $images = []
    ): bool {
        $text = $this->buildText($context, $title, $excerpt, $postUrl);

        $current = $this->fetchStatus($statusId);
        if ($current === null) {
            return false;
        }

        $mediaIds = $current['media_ids'];
        if (count($images) !== count($mediaIds)) {
            $mediaIds = $this->uploadAll($images);
        }

        // Nothing to say and nothing to show: an empty status is not something
        // Mastodon will accept, so leave the existing one alone.
        if ($text === '' && $mediaIds === []) {
            self::log("skipping edit of status {$statusId}: the post has no text and no attachments left");
            return false;
        }

        // An edit that changes nothing still marks the toot as edited for every
        // reader, so a save that didn't touch the syndicated text stays quiet —
        // when the text could be read back to know that.
        if ($this->sameText($text, $current['text']) && $mediaIds === $current['media_ids']) {
            return true;
        }

        $response = $this->request(
            'PUT',
            '/api/v1/statuses/' . rawurlencode($statusId),
            self::statusBody($text, $mediaIds, false)
        );
        if ($response === null) {
            self::log("status PUT got no response for {$statusId}");
            return false;
        }
        if ($response['code'] !== 200) {
            self::log("status PUT refused with HTTP {$response['code']} for {$statusId}: " . self::snippet($response['body']));
            return false;
        }

        return true;
    }

    /**
     * Delete a toot. Returns true once the status is gone from the instance —
     * including when it was already gone, since the caller's goal is met either
     * way and a 404 here means somebody deleted it by hand.
     */
    public function deletePost(string $statusId): bool
    {
        $response = $this->request('DELETE', '/api/v1/statuses/' . rawurlencode($statusId));
        if ($response === null) {
            self::log("status DELETE got no response for {$statusId}");
            return false;
        }
        if ($response['code'] === 404 || $response['code'] === 410) {
            return true;
        }
        if ($response['code'] !== 200) {
            self::log("status DELETE refused with HTTP {$response['code']} for {$statusId}: " . self::snippet($response['body']));
            return false;
        }

        return true;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Upload every image, dropping the ones the instance refused.
     *
     * @param  array<array{path:string,mime:string,alt:string}> $images
     * @return string[]
     */
    protected function uploadAll(array $images): array
    {
        $mediaIds = [];
        foreach ($images as $image) {
            $id = $this->uploadMedia($image['path'], $image['mime'], $image['alt'] ?? '');
            if ($id !== null) {
                $mediaIds[] = $id;
            }
        }
        return $mediaIds;
    }

    /**
     * Read back a status as it currently stands: the ids of the attachments
     * hanging off it, and — when the token is allowed to see it — the plain
     * text the author submitted.
     *
     * A status that cannot be read at all is not editable, and returns null.
     * The text comes back as null when it could not be read, which is not the
     * same as an empty status: it means the comparison that would have skipped
     * a pointless edit could not be made, and the edit goes ahead regardless.
     *
     * @return array{text:?string,media_ids:string[]}|null
     */
    protected function fetchStatus(string $statusId): ?array
    {
        $id = rawurlencode($statusId);

        $status = $this->request('GET', "/api/v1/statuses/{$id}");
        if ($status === null || $status['code'] !== 200) {
            $code = $status['code'] ?? 'no response';
            self::log("could not read status {$statusId} (HTTP {$code})");
            return null;
        }
        $statusData  = json_decode($status['body'], true);
        $statusData  = is_array($statusData) ? $statusData : [];
        $attachments = is_array($statusData['media_attachments'] ?? null)
            ? $statusData['media_attachments']
            : [];

        $mediaIds = [];
        foreach ($attachments as $attachment) {
            if (is_array($attachment) && isset($attachment['id'])) {
                $mediaIds[] = (string) $attachment['id'];
            }
        }

        return ['text' => $this->sourceText($statusId, $statusData), 'media_ids' => $mediaIds];
    }

    /**
     * Whether the text now composed is the text the copy already carries.
     *
     * Exact here, because /source returns what was submitted, character for
     * character. A server without that endpoint has to compare something
     * looser — see Pixelfed.
     */
    protected function sameText(string $text, ?string $remote): bool
    {
        return $remote !== null && $text === $remote;
    }

    /**
     * The plain text a status was submitted with, or null when it can't be read.
     *
     * The status itself carries its text as HTML, with URLs linkified and
     * newlines turned into markup, so there is no honest way to compare it to
     * text composed here. /source returns exactly what was submitted — but it
     * is the author's own view of their status and needs `read:statuses`,
     * which a token minted only to post does not carry. Without it there is no
     * way to tell an edit that changes something from one that changes nothing,
     * so every save sends its edit and the instance decides.
     *
     * $status is the already-fetched status object, for implementations that
     * can answer from it rather than spending a second request.
     *
     * @param array<string,mixed> $status
     */
    protected function sourceText(string $statusId, array $status = []): ?string
    {
        $response = $this->request('GET', '/api/v1/statuses/' . rawurlencode($statusId) . '/source');
        if ($response === null) {
            return null;
        }
        if ($response['code'] === 401 || $response['code'] === 403) {
            self::log(
                "cannot read the text of status {$statusId} (HTTP {$response['code']}) — add the read:statuses "
                . 'scope to the access token and an edit that changes nothing will stop being sent'
            );
            return null;
        }
        if ($response['code'] !== 200) {
            self::log("could not read the source of status {$statusId} (HTTP {$response['code']})");
            return null;
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || !isset($data['text'])) {
            self::log("status source for {$statusId} carried no text: " . self::snippet($response['body']));
            return null;
        }

        return (string) $data['text'];
    }

    /**
     * Compose toot text within Mastodon's 500-character limit.
     * Layout: any non-empty subset of {context, title, excerpt, url} joined by
     * blank lines.
     */
    protected function buildText(string $context, string $title, string $excerpt, string $url): string
    {
        return SyndicationText::compose($context, $title, $excerpt, $url, static::TEXT_LIMIT);
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
        if ((@filesize($path) ?: 0) > static::IMAGE_MAX_BYTES) {
            $shrunk = self::spoolShrunk($path, $mime, static::IMAGE_MAX_BYTES);
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

        $bytes    = (@filesize($path) ?: 0);
        $response = $this->request('POST', '/api/v2/media', $fields, 30);

        if ($shrunk !== null) {
            @unlink($shrunk[0]);
        }

        if ($response === null) {
            self::log("media upload got no response ({$name}, {$mime}, {$bytes} bytes)");
            return null;
        }
        if (!in_array($response['code'], [200, 202], true)) {
            // Uploading an attachment is write:media, a scope of its own — a
            // token holding only write:statuses toots the words and is refused
            // here, which reads as a post that simply lost its pictures.
            $hint = $response['code'] === 403
                ? ' — does the access token carry the write:media scope?'
                : '';
            self::log(
                "media upload refused with HTTP {$response['code']} ({$name}, {$mime}, {$bytes} bytes): "
                . self::snippet($response['body']) . $hint
            );
            return null;
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['id'])) {
            self::log('media upload returned no attachment id: ' . self::snippet($response['body']));
            return null;
        }
        $id = (string) $data['id'];

        // 202 means the instance is still processing the file. Attaching an
        // unprocessed attachment to a status is a 422, so wait for it.
        if ($response['code'] === 202 && !$this->awaitMedia($id)) {
            self::log("attachment {$id} was still processing after the wait; posting without it");
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
    private static function spoolShrunk(string $path, string $mime, int $maxBytes): ?array
    {
        $fitted = SyndicationMedia::fit($path, $mime, $maxBytes);
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
     * Returns ['url' => ..., 'id' => ...] on success, null on failure.
     *
     * @param  string[] $mediaIds
     * @return array{url:string,id:string}|null
     */
    protected function post(string $text, array $mediaIds = []): ?array
    {
        $response = $this->request('POST', '/api/v1/statuses', self::statusBody($text, $mediaIds));
        if ($response === null) {
            self::log('status POST got no response');
            return null;
        }
        if (!in_array($response['code'], [200, 201], true)) {
            self::log("status POST refused with HTTP {$response['code']}: " . self::snippet($response['body']));
            return null;
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['url'])) {
            self::log('status POST returned no url: ' . self::snippet($response['body']));
            return null;
        }
        if (empty($data['id'])) {
            // The toot exists and is worth recording, but nothing here will be
            // able to edit or delete it later.
            self::log('status POST returned no id; the toot cannot be edited or deleted from here');
        }

        // A status that came back with fewer attachments than were sent posted
        // without some of the pictures — the id was accepted and then dropped.
        $attached = is_array($data['media_attachments'] ?? null) ? count($data['media_attachments']) : 0;
        if ($attached < count($mediaIds)) {
            self::log('status posted with ' . $attached . ' of ' . count($mediaIds) . ' attachments');
        }

        return ['url' => (string) $data['url'], 'id' => (string) ($data['id'] ?? '')];
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
     * `visibility` is only meaningful when the status is first posted; the edit
     * endpoint cannot change it and does not accept it.
     *
     * @param string[] $mediaIds
     */
    private static function statusBody(string $text, array $mediaIds, bool $withVisibility = true): string
    {
        $params = ['status' => $text];
        if ($withVisibility) {
            $params['visibility'] = 'public';
        }
        $body = http_build_query($params);
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
    protected function request(string $method, string $path, array|string|null $body = null, int $timeout = 10): ?array
    {
        $parsed = parse_url($this->instanceUrl);
        $host   = $parsed['host'] ?? '';
        $port   = $parsed['port'] ?? 443;

        $resolvedIp = gethostbyname($host);
        if (filter_var($resolvedIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            self::log("refusing to call {$host}: it resolves to {$resolvedIp}");
            return null;
        }

        $options = [
            // Accept matters on the error path: a Laravel-backed server (which
            // is what Pixelfed is) answers a failed validation with a redirect
            // to the web UI unless the request asked for JSON, so a 422 that
            // says which field was wrong arrives as an opaque 302 instead.
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_RESOLVE        => ["{$host}:{$port}:{$resolvedIp}"],
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = $body;
        } elseif ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
                // cURL only volunteers a Content-Type for POST. An edit sent as
                // a PUT with no type arrives as an empty params hash, and
                // Mastodon answers 422 for a status that is now blank.
                if (is_string($body)) {
                    $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
                }
            }
        }

        $ch = curl_init($this->instanceUrl . $path);
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            self::log("{$method} {$path} failed: " . ($error !== '' ? $error : 'unknown cURL error'));
            return null;
        }

        return ['code' => $httpCode, 'body' => (string) $response];
    }

    /**
     * Syndication runs on the publish path, where there is nobody to show an
     * error to and the post itself must still succeed. Every failure here is
     * therefore swallowed — so each one says what happened on the way past,
     * because otherwise a copy that never arrived leaves nothing to read.
     */
    protected static function log(string $message): void
    {
        error_log('[' . static::LOG_PREFIX . '] ' . $message);
    }

    /** A response body cut to something a log line can carry. */
    protected static function snippet(string $body, int $max = 300): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? '');
        return mb_strlen($body) > $max ? mb_substr($body, 0, $max) . '…' : $body;
    }
}
