<?php

declare(strict_types=1);

namespace CMS;

class Bluesky
{
    private const API_BASE = 'https://bsky.social/xrpc';

    /** Ceiling a PDS puts on an image blob. Larger files are re-encoded to fit. */
    private const BLOB_MAX_BYTES = 976_560;

    private string $handle;
    private string $appPassword;

    public function __construct(string $handle, string $appPassword)
    {
        $this->handle      = $handle;
        $this->appPassword = $appPassword;
    }

    /**
     * Build and post to Bluesky for a newly-published post.
     * Returns the canonical bsky.app post URL on success, null on failure.
     *
     * @param array<array{path:string,mime:string,alt:string}> $images
     *        Local image files to attach — see SyndicationMedia::forPost().
     */
    public function postToBluesky(string $title, string $excerpt, string $url, array $images = []): ?string
    {
        $session = $this->createSession();
        if ($session === false) {
            return null;
        }

        $text   = $this->buildText($title, $excerpt, $url);
        $facets = $this->buildFacets($text, $url);
        $embed  = $this->buildImageEmbed($session['jwt'], $images);

        // Every attachment failed on a post that had nothing but pictures to
        // say — an empty record would say nothing at all.
        if ($text === '' && $embed === null) {
            return null;
        }

        return $this->createPost($session['did'], $session['jwt'], $text, $facets, $embed);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Authenticate and return ['did' => ..., 'jwt' => ...], or false on failure.
     *
     * @return array{did:string,jwt:string}|false
     */
    private function createSession(): array|false
    {
        $ch = curl_init(self::API_BASE . '/com.atproto.server.createSession');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['identifier' => $this->handle, 'password' => $this->appPassword]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return false;
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data) || empty($data['did']) || empty($data['accessJwt'])) {
            return false;
        }

        return ['did' => $data['did'], 'jwt' => $data['accessJwt']];
    }

    /**
     * Compose post text within Bluesky's 300-grapheme limit.
     * Layout: any non-empty subset of {title, excerpt, url} joined by blank lines.
     */
    private function buildText(string $title, string $excerpt, string $url): string
    {
        $fixedParts = (int) ($title !== '') + (int) ($url !== '');
        $hasExcerpt = $excerpt !== '';
        $separators = max(0, $fixedParts + (int) $hasExcerpt - 1) * 2;
        $reserved   = mb_strlen($title) + mb_strlen($url) + $separators;
        $budget     = max(0, 300 - $reserved);

        if ($hasExcerpt && mb_strlen($excerpt) > $budget) {
            $excerpt = rtrim(mb_substr($excerpt, 0, $budget - 1)) . '…';
        }

        $parts = array_filter([$title, $excerpt, $url], static fn(string $p): bool => $p !== '');
        return implode("\n\n", $parts);
    }

    /**
     * Build AT Protocol facets array to make the URL a clickable link.
     * Byte offsets (not character offsets) are required by the protocol.
     */
    private function buildFacets(string $text, string $url): array
    {
        if ($url === '') {
            return [];
        }
        $byteStart = strpos($text, $url);
        if ($byteStart === false) {
            return [];
        }
        $byteEnd = $byteStart + strlen($url);

        return [
            [
                'index' => [
                    '$type'     => 'app.bsky.richtext.facet#byteSlice',
                    'byteStart' => $byteStart,
                    'byteEnd'   => $byteEnd,
                ],
                'features' => [
                    [
                        '$type' => 'app.bsky.richtext.facet#link',
                        'uri'   => $url,
                    ],
                ],
            ],
        ];
    }

    /**
     * Upload each image and assemble the images embed for the record.
     * Returns null when there is nothing to attach.
     *
     * @param array<array{path:string,mime:string,alt:string}> $images
     */
    private function buildImageEmbed(string $jwt, array $images): ?array
    {
        $items = [];
        foreach ($images as $image) {
            $encoded = SyndicationMedia::fit($image['path'], $image['mime'], self::BLOB_MAX_BYTES);
            if ($encoded === null) {
                continue;
            }
            $blob = $this->uploadBlob($jwt, $encoded['bytes'], $encoded['mime']);
            if ($blob === null) {
                continue;
            }

            // Alt text is required by the lexicon; empty string is valid.
            $item = ['alt' => (string) ($image['alt'] ?? ''), 'image' => $blob];

            // Measure the bytes that were actually uploaded — fit() may have
            // shrunk them — so the client reserves the right shape on load.
            $dimensions = @getimagesizefromstring($encoded['bytes']);
            if (is_array($dimensions) && $dimensions[0] > 0 && $dimensions[1] > 0) {
                $item['aspectRatio'] = ['width' => $dimensions[0], 'height' => $dimensions[1]];
            }

            $items[] = $item;
        }

        return $items === [] ? null : ['$type' => 'app.bsky.embed.images', 'images' => $items];
    }

    /**
     * Upload raw image bytes to the PDS.
     * Returns the blob reference to embed in a record, null on failure.
     */
    private function uploadBlob(string $jwt, string $bytes, string $mime): ?array
    {
        $ch = curl_init(self::API_BASE . '/com.atproto.repo.uploadBlob');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $bytes,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $jwt,
                'Content-Type: ' . $mime,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return null;
        }

        $data = json_decode((string) $response, true);
        return (is_array($data) && isset($data['blob']) && is_array($data['blob'])) ? $data['blob'] : null;
    }

    /**
     * POST the record to the Bluesky API.
     * Returns the canonical bsky.app post URL on success, null on failure.
     */
    private function createPost(string $did, string $jwt, string $text, array $facets, ?array $embed = null): ?string
    {
        $record = [
            '$type'     => 'app.bsky.feed.post',
            'text'      => $text,
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        if (!empty($facets)) {
            $record['facets'] = $facets;
        }

        if ($embed !== null) {
            $record['embed'] = $embed;
        }

        $body = json_encode([
            'repo'       => $did,
            'collection' => 'app.bsky.feed.post',
            'record'     => $record,
        ]);

        $ch = curl_init(self::API_BASE . '/com.atproto.repo.createRecord');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $jwt,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            return null;
        }

        // AT URI format: at://did:plc:.../app.bsky.feed.post/{rkey}
        // Construct the canonical bsky.app URL from the handle and rkey.
        $data = json_decode((string) $response, true);
        if (!is_array($data) || empty($data['uri'])) {
            return null;
        }

        $rkey = basename($data['uri']);
        return 'https://bsky.app/profile/' . $this->handle . '/post/' . $rkey;
    }
}
