<?php

declare(strict_types=1);

namespace CMS;

/**
 * POSSE: the copies of a post that live on Mastodon and Bluesky, for the whole
 * of their lives — created on first publish, rewritten when the post is edited,
 * removed when it is deleted or unpublished.
 *
 * Composing what to send is the same work whichever door the post came in by,
 * so the admin form, the REST API and the Micropub endpoint all call through
 * here rather than each assembling a payload of their own.
 *
 * Nothing here throws or reports. Syndication runs on the publish path, where
 * the post itself must still save even if a network is down or a token has
 * expired; failures go to the error log via the two clients.
 */
final class Syndication
{
    private Database $db;
    private string $mediaDir;

    /**
     * Networks this request has just posted to. A copy made moments ago
     * already says what the post says; reading it back to prove that would be
     * a round trip to learn nothing.
     *
     * @var array<string,true>
     */
    private array $justCreated = [];

    /** @param array<string,mixed> $config */
    public function __construct(Database $db, array $config)
    {
        $this->db       = $db;
        $this->mediaDir = $config['paths']['content'] . '/media';
    }

    /**
     * Send a newly published post to every configured network it has not
     * already reached and has not opted out of.
     */
    public function publish(Post $post): void
    {
        $payload = $this->payload($post);
        if ($payload === null) {
            return;
        }

        if ($this->wants($post, 'mastodon') && $post->tooted_at === null) {
            $mastodon = $this->mastodon();
            $result   = $mastodon?->tootPost(
                $payload['context'],
                $payload['title'],
                $payload['excerpt'],
                $payload['url'],
                $payload['images']
            );
            if ($result !== null) {
                $post->markTooted($result['url'], $result['id']);
                $this->justCreated['mastodon'] = true;
            }
        }

        if ($this->wants($post, 'bluesky') && $post->bluesky_at === null) {
            $bluesky = $this->bluesky();
            $result  = $bluesky?->postToBluesky(
                $payload['context'],
                $payload['title'],
                $payload['excerpt'],
                $payload['url'],
                $payload['images']
            );
            if ($result !== null) {
                $post->markBluesky($result['url'], $result['rkey']);
                $this->justCreated['bluesky'] = true;
            }
        }
    }

    /**
     * Bring the existing copies into line with the post as it now stands.
     *
     * A post with no copies, or one whose copies predate the columns holding
     * their remote ids, is left alone: there is nothing addressable to rewrite.
     * Both clients no-op when the copy already says what the post says, so this
     * is safe to call on every save.
     */
    public function update(Post $post): void
    {
        $payload = $this->payload($post);
        if ($payload === null) {
            return;
        }

        if ($post->mastodon_status_id !== null && !isset($this->justCreated['mastodon'])) {
            $this->mastodon()?->editPost(
                $post->mastodon_status_id,
                $payload['context'],
                $payload['title'],
                $payload['excerpt'],
                $payload['url'],
                $payload['images']
            );
        }

        if ($post->bluesky_rkey !== null && !isset($this->justCreated['bluesky'])) {
            $this->bluesky()?->editPost(
                $post->bluesky_rkey,
                $payload['context'],
                $payload['title'],
                $payload['excerpt'],
                $payload['url'],
                $payload['images']
            );
        }
    }

    /**
     * Take the copies down.
     *
     * A network is forgotten only once its copy is actually gone, so a delete
     * that failed against a network that was down leaves the id in place and
     * the post page still linking to a status that is still there. A copy whose
     * remote id was never recorded is forgotten regardless: it cannot be
     * reached from here, and leaving the post linking to it after the post
     * itself is gone helps nobody.
     */
    public function remove(Post $post): void
    {
        // Most posts have no copies anywhere; deleting one should not write a
        // row of nulls over a row of nulls.
        if (
            $post->tooted_at === null && $post->mastodon_url === null
            && $post->bluesky_at === null && $post->bluesky_url === null
        ) {
            return;
        }

        $mastodonGone = true;
        if ($post->mastodon_status_id !== null) {
            $mastodon     = $this->mastodon();
            $mastodonGone = $mastodon !== null && $mastodon->deletePost($post->mastodon_status_id);
        }

        $blueskyGone = true;
        if ($post->bluesky_rkey !== null) {
            $bluesky     = $this->bluesky();
            $blueskyGone = $bluesky !== null && $bluesky->deletePost($post->bluesky_rkey);
        }

        $post->clearSyndication($mastodonGone, $blueskyGone);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * What to say about this post on another network, or null when there is
     * nothing to say.
     *
     * A note — an aside or a photo post — syndicates as its own words with no
     * link back, because a note that arrives as a trailer for itself is not
     * the note. (An aside has no title either way.) Everything else sends the
     * title and an excerpt, and links home.
     *
     * A post that replies to, likes, reposts or bookmarks something opens with
     * the same line the page and the feeds open with — a reply that arrives on
     * Mastodon with no sign of what it is replying to is not the reply.
     *
     * @return array{context:string,title:string,excerpt:string,url:string,images:array<array{path:string,mime:string,alt:string}>}|null
     */
    private function payload(Post $post): ?array
    {
        // A bare like, repost or bookmark has no words and no picture — there
        // is no post to make out of it.
        if (trim($post->content) === '' && $post->photos === []) {
            return null;
        }

        $siteUrl = rtrim($this->db->getSetting('site_url', ''), '/');

        // Syndication runs from the admin, the API, Micropub, XML-RPC and the
        // scheduler, and not all of them arrive with contexts on the object.
        $post->loadContexts();
        $context = Post::contextsText($post->contexts);

        if ($post->isNote()) {
            $url     = '';
            $excerpt = $post->noteText();
        } else {
            $url = $siteUrl . '/'
                 . Post::datePath((string) $post->published_at, $post->slug, $this->db->getSetting('timezone', ''))
                 . '/';

            $effective = $post->effectiveExcerpt();
            $excerpt   = $effective !== null ? strip_tags($effective) : Helpers::truncate($post->content, 280);
        }

        // Photos ride along with the text so a photo post looks native on both
        // networks instead of arriving as a caption with no picture.
        $images = SyndicationMedia::forPost($post, $this->mediaDir, $siteUrl);

        // A note syndicates with no title and no link back, so the text, its
        // contexts and the photos are all there is. Nothing to say — no
        // excerpt, no context and no photo — means there is no post to make;
        // don't publish a blank status.
        if ($post->isNote() && $excerpt === '' && $context === '' && $images === []) {
            error_log('[syndication] Skipping post ' . $post->id . ': note has no text or photo');
            return null;
        }

        return [
            'context' => $context,
            'title'   => $post->title,
            'excerpt' => $excerpt,
            'url'     => $url,
            'images'  => $images,
        ];
    }

    /** Whether $post should go to $network at all: configured, and not opted out. */
    private function wants(Post $post, string $network): bool
    {
        $skip = $network === 'mastodon' ? $post->mastodon_skip : $post->bluesky_skip;
        return $skip === 0 && ($network === 'mastodon' ? $this->mastodon() : $this->bluesky()) !== null;
    }

    /** A configured Mastodon client, or null when the settings are incomplete. */
    private function mastodon(): ?Mastodon
    {
        $instance = $this->db->getSetting('mastodon_instance');
        $token    = $this->db->getSetting('mastodon_token');

        return ($instance !== '' && $token !== '') ? new Mastodon($instance, $token) : null;
    }

    /** A configured Bluesky client, or null when the settings are incomplete. */
    private function bluesky(): ?Bluesky
    {
        $handle      = $this->db->getSetting('bluesky_handle');
        $appPassword = $this->db->getSetting('bluesky_app_password');

        return ($handle !== '' && $appPassword !== '') ? new Bluesky($handle, $appPassword) : null;
    }
}
