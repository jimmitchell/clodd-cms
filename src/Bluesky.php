<?php

declare(strict_types=1);

namespace CMS;

class Bluesky
{
    /**
     * The repo lives on the PDS; what readers see is assembled by a separate
     * service, the AppView. Both hosts are named here rather than taken from
     * anything a post or a request carried in, which is why this class talks to
     * them with curl directly instead of going through CMS\SafeHttp — that
     * invariant covers URLs we did not choose.
     */
    private const API_BASE     = 'https://bsky.social/xrpc';
    private const APPVIEW_BASE = 'https://public.api.bsky.app/xrpc';

    /** The collection every post this CMS writes lives in. */
    private const COLLECTION = 'app.bsky.feed.post';

    /** Ceiling a PDS puts on an image blob. Larger files are re-encoded to fit. */
    private const BLOB_MAX_BYTES = 976_560;

    /** Characters the app.bsky.feed.post lexicon accepts in one record. */
    private const TEXT_LIMIT = 300;

    /** What editPost() did — see its docblock. */
    public const EDIT_WRITTEN   = 'written';
    public const EDIT_UNCHANGED = 'unchanged';
    public const EDIT_FAILED    = 'failed';

    /**
     * A failure worth one more go: the record changed between being read and
     * being written. Internal to the edit path — callers only ever see the
     * three above.
     */
    private const EDIT_STALE_SWAP = 'stale-swap';

    private string $handle;
    private string $appPassword;

    /**
     * The session this instance has already opened, if any. An edit followed by
     * a visibility check would otherwise sign in twice to learn the same did.
     *
     * @var array{did:string,jwt:string}|null
     */
    private ?array $session = null;

    public function __construct(string $handle, string $appPassword)
    {
        $this->handle      = $handle;
        $this->appPassword = $appPassword;
    }

    /**
     * Build and post to Bluesky for a newly-published post.
     * Returns ['url' => canonical bsky.app URL, 'rkey' => record key], null on failure.
     *
     * @param  string $context
     *         The reply/like/repost/bookmark lines this post opens with, as
     *         plain text — see Post::contextsText().
     * @param  array<array{path:string,mime:string,alt:string}> $images
     *         Local image files to attach — see SyndicationMedia::forPost().
     * @return array{url:string,rkey:string}|null
     */
    public function postToBluesky(string $context, string $title, string $excerpt, string $url, array $images = []): ?array
    {
        $session = $this->createSession();
        if ($session === false) {
            return null;
        }

        $text   = $this->buildText($context, $title, $excerpt, $url);
        $facets = $this->buildFacets($text, $url, ...SyndicationText::urlsIn($context));
        $embed  = $this->buildImageEmbed($session['jwt'], $images);

        // Every attachment failed on a post that had nothing but pictures to
        // say — an empty record would say nothing at all.
        if ($text === '' && $embed === null) {
            return null;
        }

        return $this->createPost($session['did'], $session['jwt'], $text, $facets, $embed);
    }

    /**
     * Rewrite an existing record to match the post as it now stands.
     *
     * AT Protocol has no edit operation: putRecord replaces what is at the
     * record key. Doing that rather than deleting and re-posting is what keeps
     * the bsky.app URL, and the likes and replies hanging off it, alive.
     *
     * Returns EDIT_WRITTEN when the rewrite landed, EDIT_UNCHANGED when the
     * record already said this, EDIT_FAILED otherwise. The first two both leave
     * the repo correct; only the first has anything for isVisible() to check,
     * which is why they are told apart.
     *
     * @param  array<array{path:string,mime:string,alt:string}> $images
     * @return self::EDIT_*
     */
    public function editPost(
        string $rkey,
        string $context,
        string $title,
        string $excerpt,
        string $url,
        array $images = []
    ): string {
        $session = $this->createSession();
        if ($session === false) {
            self::log("could not sign in to edit record {$rkey}");
            return self::EDIT_FAILED;
        }

        $result = $this->rewriteRecord($session, $rkey, $context, $title, $excerpt, $url, $images);

        // A swapRecord that no longer matches means the record moved under us
        // between the read and the write — usually something the PDS or the
        // Bluesky app added of its own accord rather than a rival edit. Read it
        // again and write on top of what is actually there, which keeps the
        // guard doing its job instead of dropping it. Once only: a second
        // collision is a fight worth losing.
        if ($result === self::EDIT_STALE_SWAP) {
            self::log("retrying {$rkey} against its current cid after an InvalidSwap");
            $result = $this->rewriteRecord($session, $rkey, $context, $title, $excerpt, $url, $images);
        }

        return $result === self::EDIT_STALE_SWAP ? self::EDIT_FAILED : $result;
    }

    /**
     * Whether bsky.app is showing what the repo holds.
     *
     * Returns true when the AppView's cid matches the PDS's, false when it
     * differs — an edit that was written but has not surfaced — and null when
     * either side could not be read, which is evidence of nothing.
     *
     * Bluesky has no edit feature of its own, and its AppView does not re-index
     * a record that putRecord replaced: the repo is rewritten, the view is not.
     * So a successful edit is not a visible edit, and the only way to know which
     * one happened is to ask the view.
     */
    public function isVisible(string $rkey): ?bool
    {
        $session = $this->createSession();
        if ($session === false) {
            self::log("could not sign in to verify record {$rkey}");
            return null;
        }

        $existing = $this->fetchRecord($session['did'], $rkey);
        if ($existing === null) {
            return null;
        }

        $uri   = 'at://' . $session['did'] . '/' . self::COLLECTION . '/' . $rkey;
        $query = http_build_query(['uris' => $uri]);

        $response = $this->request(
            'GET',
            'app.bsky.feed.getPosts?' . $query,
            null,
            null,
            'application/json',
            10,
            self::APPVIEW_BASE
        );

        if ($response === null || $response['code'] !== 200) {
            $code = $response['code'] ?? 'no response';
            self::log("could not read {$rkey} from the AppView (HTTP {$code})");
            return null;
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || !is_array($data['posts'] ?? null)) {
            self::log("the AppView returned nothing usable for {$rkey}: " . self::snippet($response['body']));
            return null;
        }

        // No post at all: the view has never indexed this record, or has
        // dropped it. Either way readers are not seeing what the repo holds.
        if ($data['posts'] === []) {
            self::log("the AppView has no record for {$rkey} — bsky.app is showing nothing for it");
            return false;
        }

        $seen = (string) ($data['posts'][0]['cid'] ?? '');
        if ($seen === $existing['cid']) {
            return true;
        }

        self::log(
            "{$rkey}: the repo holds cid {$existing['cid']}, but the AppView still serves {$seen}"
            . ' — bsky.app will show the older text'
        );
        return false;
    }

    /**
     * Delete a record. Returns true once it is gone from the repo — including
     * when it was already gone, since the caller's goal is met either way and a
     * missing record means somebody deleted it in the app.
     */
    public function deletePost(string $rkey): bool
    {
        $session = $this->createSession();
        if ($session === false) {
            self::log("could not sign in to delete record {$rkey}");
            return false;
        }

        $response = $this->request('POST', 'com.atproto.repo.deleteRecord', $session['jwt'], [
            'repo'       => $session['did'],
            'collection' => self::COLLECTION,
            'rkey'       => $rkey,
        ]);

        if ($response === null) {
            self::log("deleteRecord got no response for {$rkey}");
            return false;
        }
        if ($response['code'] === 200) {
            return true;
        }
        // Older PDS builds answer a delete of something already deleted with an
        // error rather than the modern no-op.
        if (str_contains($response['body'], 'RecordNotFound') || $response['code'] === 404) {
            return true;
        }

        self::log("deleteRecord refused for {$rkey} (HTTP {$response['code']}): " . self::snippet($response['body']));
        return false;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Read the record as it stands, compose what it should say, and write that
     * back over it.
     *
     * Everything is re-read on each call, so running it a second time after a
     * lost swapRecord race composes against the record that is actually there
     * rather than replaying a stale one.
     *
     * @param  array{did:string,jwt:string}                      $session
     * @param  array<array{path:string,mime:string,alt:string}>  $images
     * @return self::EDIT_*|self::EDIT_STALE_SWAP
     */
    private function rewriteRecord(
        array $session,
        string $rkey,
        string $context,
        string $title,
        string $excerpt,
        string $url,
        array $images
    ): string {
        $existing = $this->fetchRecord($session['did'], $rkey);
        if ($existing === null) {
            return self::EDIT_FAILED;
        }
        $record = $existing['record'];

        $text   = $this->buildText($context, $title, $excerpt, $url);
        $facets = $this->buildFacets($text, $url, ...SyndicationText::urlsIn($context));

        // Blobs already on the PDS are reused, so fixing a typo on a photo post
        // rewrites the words without pushing the pictures over the wire again.
        // A changed number of images means the post's photos really did change,
        // and only then are they uploaded afresh.
        $embed = is_array($record['embed'] ?? null) ? $record['embed'] : null;
        if (count($images) !== count($embed['images'] ?? [])) {
            $embed = $this->buildImageEmbed($session['jwt'], $images);
        }

        if ($text === '' && $embed === null) {
            self::log("skipping edit of record {$rkey}: the post has no text and no images left");
            return self::EDIT_FAILED;
        }

        $updated = ['$type' => self::COLLECTION, 'text' => $text];
        // The original timestamp stays: it is what orders the record in every
        // reader's timeline, and an edit is not a re-post.
        $updated['createdAt'] = (string) ($record['createdAt'] ?? gmdate('Y-m-d\TH:i:s\Z'));
        if ($facets !== []) {
            $updated['facets'] = $facets;
        }
        if ($embed !== null) {
            $updated['embed'] = $embed;
        }

        if (self::sameRecord($record, $updated)) {
            return self::EDIT_UNCHANGED;
        }

        $response = $this->request('POST', 'com.atproto.repo.putRecord', $session['jwt'], [
            'repo'       => $session['did'],
            'collection' => self::COLLECTION,
            'rkey'       => $rkey,
            'record'     => $updated,
            // Refuse the write if the record changed since it was read, rather
            // than overwriting an edit made in the Bluesky app.
            'swapRecord' => $existing['cid'],
        ]);

        if ($response === null || $response['code'] !== 200) {
            $code = $response['code'] ?? 'no response';
            $body = $response['body'] ?? '';
            self::log("putRecord refused for {$rkey} (HTTP {$code}): " . self::snippet($body));

            return str_contains($body, 'InvalidSwap') ? self::EDIT_STALE_SWAP : self::EDIT_FAILED;
        }

        return self::EDIT_WRITTEN;
    }

    /**
     * Authenticate and return ['did' => ..., 'jwt' => ...], or false on failure.
     *
     * Held for the life of the instance: an edit and the visibility check that
     * follows it want the same did, and Bluesky counts sign-ins.
     *
     * @return array{did:string,jwt:string}|false
     */
    private function createSession(): array|false
    {
        if ($this->session !== null) {
            return $this->session;
        }

        $response = $this->request('POST', 'com.atproto.server.createSession', null, [
            'identifier' => $this->handle,
            'password'   => $this->appPassword,
        ]);

        if ($response === null || $response['code'] !== 200) {
            return false;
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['did']) || empty($data['accessJwt'])) {
            return false;
        }

        $this->session = ['did' => (string) $data['did'], 'jwt' => (string) $data['accessJwt']];
        return $this->session;
    }

    /**
     * Read a record as it currently stands in the repo.
     * Returns the record body and its cid, or null when it can't be read.
     *
     * @return array{record:array<string,mixed>,cid:string}|null
     */
    private function fetchRecord(string $did, string $rkey): ?array
    {
        $query = http_build_query([
            'repo'       => $did,
            'collection' => self::COLLECTION,
            'rkey'       => $rkey,
        ]);

        $response = $this->request('GET', 'com.atproto.repo.getRecord?' . $query, null);
        if ($response === null || $response['code'] !== 200) {
            $code = $response['code'] ?? 'no response';
            self::log("could not read record {$rkey} (HTTP {$code})");
            return null;
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data) || !is_array($data['value'] ?? null) || empty($data['cid'])) {
            self::log("getRecord returned nothing usable for {$rkey}: " . self::snippet($response['body']));
            return null;
        }

        return ['record' => $data['value'], 'cid' => (string) $data['cid']];
    }

    /**
     * Whether a rewrite would leave the record saying exactly what it says now.
     *
     * Compared on the fields this CMS writes, so a key the PDS or the Bluesky
     * app added of its own accord — langs, a label — is not read as a change
     * and does not provoke a pointless write.
     *
     * @param array<string,mixed> $current
     * @param array<string,mixed> $updated
     */
    private static function sameRecord(array $current, array $updated): bool
    {
        foreach (['text', 'createdAt', 'facets', 'embed'] as $field) {
            if (($current[$field] ?? null) != ($updated[$field] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Compose post text within Bluesky's 300-grapheme limit.
     * Layout: any non-empty subset of {context, title, excerpt, url} joined by
     * blank lines.
     */
    private function buildText(string $context, string $title, string $excerpt, string $url): string
    {
        return SyndicationText::compose($context, $title, $excerpt, $url, self::TEXT_LIMIT);
    }

    /**
     * Build the AT Protocol facets that make each URL in the text clickable —
     * the post's own URL, and the ones the reply/like/repost/bookmark lines
     * point at. Byte offsets (not character offsets) are what the protocol
     * wants.
     *
     * The URLs are named rather than found by scanning the text, because the
     * excerpt is truncated to fit and a URL cut short by that would otherwise
     * be linkified into a dead one.
     *
     * @return array<array<string,mixed>>
     */
    private function buildFacets(string $text, string ...$urls): array
    {
        $urls = array_values(array_unique(array_filter(
            $urls,
            static fn(string $u): bool => $u !== ''
        )));

        // Longest first: a URL that is the opening of another — a site root and
        // a post beneath it — must not claim the longer one's bytes.
        usort($urls, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        $facets = [];
        $taken  = [];
        foreach ($urls as $url) {
            $from = 0;
            while (($start = strpos($text, $url, $from)) !== false) {
                $end = $start + strlen($url);
                foreach ($taken as [$takenStart, $takenEnd]) {
                    if ($start < $takenEnd && $end > $takenStart) {
                        $from = $start + 1;
                        continue 2;
                    }
                }

                $taken[]  = [$start, $end];
                $facets[] = [
                    'index' => [
                        '$type'     => 'app.bsky.richtext.facet#byteSlice',
                        'byteStart' => $start,
                        'byteEnd'   => $end,
                    ],
                    'features' => [
                        [
                            '$type' => 'app.bsky.richtext.facet#link',
                            'uri'   => $url,
                        ],
                    ],
                ];
                break;
            }
        }

        // In the order they appear, so that rewriting a record whose links have
        // not moved compares equal and sends no pointless write.
        usort($facets, static fn(array $a, array $b): int => $a['index']['byteStart'] <=> $b['index']['byteStart']);

        return $facets;
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
        $response = $this->request('POST', 'com.atproto.repo.uploadBlob', $jwt, $bytes, $mime, 30);
        if ($response === null || $response['code'] !== 200) {
            $code = $response['code'] ?? 'no response';
            self::log("uploadBlob refused a {$mime} of " . strlen($bytes) . " bytes (HTTP {$code})");
            return null;
        }

        $data = json_decode($response['body'], true);
        return (is_array($data) && isset($data['blob']) && is_array($data['blob'])) ? $data['blob'] : null;
    }

    /**
     * POST the record to the Bluesky API.
     * Returns ['url' => ..., 'rkey' => ...] on success, null on failure.
     *
     * @return array{url:string,rkey:string}|null
     */
    private function createPost(string $did, string $jwt, string $text, array $facets, ?array $embed = null): ?array
    {
        $record = [
            '$type'     => self::COLLECTION,
            'text'      => $text,
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        if (!empty($facets)) {
            $record['facets'] = $facets;
        }

        if ($embed !== null) {
            $record['embed'] = $embed;
        }

        $response = $this->request('POST', 'com.atproto.repo.createRecord', $jwt, [
            'repo'       => $did,
            'collection' => self::COLLECTION,
            'record'     => $record,
        ]);

        if ($response === null || $response['code'] !== 200) {
            $code = $response['code'] ?? 'no response';
            self::log("createRecord refused (HTTP {$code}): " . self::snippet($response['body'] ?? ''));
            return null;
        }

        // AT URI format: at://did:plc:.../app.bsky.feed.post/{rkey}
        // Construct the canonical bsky.app URL from the handle and rkey.
        $data = json_decode($response['body'], true);
        if (!is_array($data) || empty($data['uri'])) {
            self::log('createRecord returned no uri: ' . self::snippet($response['body']));
            return null;
        }

        $rkey = basename($data['uri']);
        return [
            'url'  => 'https://bsky.app/profile/' . $this->handle . '/post/' . $rkey,
            'rkey' => $rkey,
        ];
    }

    /**
     * Send one XRPC request.
     *
     * @param  array<string,mixed>|string|null $body  array → JSON, string → raw bytes
     * @param  string                          $base  which service to ask — the
     *         repo lives on API_BASE, what readers see on APPVIEW_BASE
     * @return array{code:int,body:string}|null
     */
    private function request(
        string $method,
        string $endpoint,
        ?string $jwt = null,
        array|string|null $body = null,
        string $contentType = 'application/json',
        int $timeout = 10,
        string $base = self::API_BASE
    ): ?array {
        $headers = [];
        if ($jwt !== null) {
            $headers[] = 'Authorization: Bearer ' . $jwt;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = is_array($body) ? (string) json_encode($body) : (string) $body;
            $headers[]                   = 'Content-Type: ' . $contentType;
        }

        $options[CURLOPT_HTTPHEADER] = $headers;

        $ch = curl_init($base . '/' . $endpoint);
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            self::log("{$method} {$endpoint} failed: " . ($error !== '' ? $error : 'unknown cURL error'));
            return null;
        }

        return ['code' => $httpCode, 'body' => (string) $response];
    }

    /**
     * Syndication runs alongside publishing, where there is nobody to show an
     * error to and the post itself must still succeed. Every failure here is
     * therefore swallowed — so each one says what happened on the way past,
     * because otherwise a copy that never arrived leaves nothing to read.
     */
    private static function log(string $message): void
    {
        error_log('[bluesky] ' . $message);
    }

    /** A response body cut to something a log line can carry. */
    private static function snippet(string $body, int $max = 300): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? '');
        return mb_strlen($body) > $max ? mb_substr($body, 0, $max) . '…' : $body;
    }
}
