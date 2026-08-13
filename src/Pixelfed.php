<?php

declare(strict_types=1);

namespace CMS;

/**
 * The copy of a photo post that lives on Pixelfed.
 *
 * Pixelfed implements the Mastodon API for everything syndication needs —
 * `POST /api/v2/media`, `POST /api/v1/statuses`, `PUT /api/v1/statuses/{id}`,
 * `DELETE /api/v1/statuses/{id}` — so this is the Mastodon client with four
 * differences, each of which is a real difference in the server:
 *
 *  - **A status must carry a picture.** `statusCreate` aborts with 403 "Empty
 *    statuses are not allowed" when no `media_ids` are present. Syndication
 *    only sends photo posts here, but an upload that failed would turn one into
 *    a caption on its own, so the guard is repeated at the last moment.
 *  - **There is no `/api/v1/statuses/{id}/source`.** The route does not exist,
 *    so the base class's read-back 404s and every save would send an edit —
 *    into a server that allows a status to be edited **ten times, ever**. The
 *    caption is recovered from the status's own HTML instead.
 *  - **Captions and images are sized differently**: pixelfed.social advertises
 *    2000 characters and a ~15MB image cap.
 *  - **Scopes are coarse.** Pixelfed checks `tokenCan('write')` for both media
 *    and statuses, so there is no Mastodon-style write:media/write:statuses
 *    split to explain in a log line.
 */
final class Pixelfed extends Mastodon
{
    /**
     * pixelfed.social's `configuration.statuses.max_characters`. Note that a
     * self-hosted instance's default is 500 (`MAX_CAPTION_LENGTH`) — a caption
     * over an instance's own limit comes back as a 422 naming the field, which
     * the refusal log carries verbatim.
     */
    protected const TEXT_LIMIT = 2000;

    /** pixelfed.social's `configuration.media_attachments.image_size_limit`. */
    protected const IMAGE_MAX_BYTES = 15_360_000;

    protected const LOG_PREFIX = 'pixelfed';

    /**
     * Post a photo post, or nothing at all.
     *
     * The base class posts as long as it has words *or* pictures. Here the
     * pictures are the point — and the server refuses a status without them —
     * so an upload that failed means there is no post to make. Returning null
     * leaves pixelfed_at unset, so the next save tries again rather than
     * recording a copy that was never created.
     *
     * @param array<array{path:string,mime:string,alt:string}> $images
     * @return array{url:string,id:string}|null
     */
    public function tootPost(string $context, string $title, string $excerpt, string $postUrl, array $images = []): ?array
    {
        if ($images === []) {
            self::log('skipping a post with no pictures: Pixelfed does not accept a status without media');
            return null;
        }

        $mediaIds = $this->uploadAll($images);
        if ($mediaIds === []) {
            self::log('every attachment failed to upload; not posting a caption on its own');
            return null;
        }

        return $this->post($this->buildText($context, $title, $excerpt, $postUrl), $mediaIds);
    }

    /**
     * The caption as Pixelfed currently renders it.
     *
     * Read out of the status object the caller already fetched, because the
     * `/source` endpoint Mastodon answers this with is not routed here. What
     * comes back is the caption *rendered* — links and hashtags wrapped in
     * anchors, line breaks as markup — so it is only good enough for the
     * loose comparison in sameText(), which is what it is for.
     *
     * @param array<string,mixed> $status
     */
    protected function sourceText(string $statusId, array $status = []): ?string
    {
        if (!isset($status['content']) || !is_string($status['content'])) {
            return null;
        }

        // Markup that separates words has to become whitespace before the tags
        // go, or "one<br>two" reads back as "onetwo" and every save looks like
        // a change.
        $text = preg_replace('#<br\s*/?>|</p>|</div>#i', ' ', $status['content']) ?? $status['content'];

        return html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Whether the caption now composed is the caption already up there.
     *
     * Compared with whitespace collapsed on both sides, since one side has been
     * through Pixelfed's renderer and back. The cost of that looseness is an
     * edit that only changes line breaks going unsent; the cost of being strict
     * is spending one of ten lifetime edits on every save that changed nothing.
     */
    protected function sameText(string $text, ?string $remote): bool
    {
        return $remote !== null && self::collapse($text) === self::collapse($remote);
    }

    /** One-line, single-spaced, for comparison only. */
    private static function collapse(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
