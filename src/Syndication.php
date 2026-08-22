<?php

declare(strict_types=1);

namespace CMS;

/**
 * POSSE: the copies of a post that live on Mastodon, Bluesky and Pixelfed, for
 * the whole of their lives — created on first publish, rewritten when the post
 * is edited, removed when it is deleted or unpublished.
 *
 * Mastodon and Bluesky take everything. Pixelfed takes photo posts only: it is
 * a photo account, and its API refuses a status with no picture on it anyway.
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
     * Where the build writes the site. Bluesky needs the post's OG image as a
     * file to upload, not a URL to advertise — see payload().
     */
    private string $outputDir;

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
        $this->db        = $db;
        $this->mediaDir  = $config['paths']['content'] . '/media';
        $this->outputDir = rtrim((string) ($config['paths']['output'] ?? ''), '/\\');
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
                $payload['images'],
                $payload['links']
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
                $payload['images'],
                $payload['og_image'],
                $payload['links']
            );
            if ($result !== null) {
                $post->markBluesky($result['url'], $result['rkey']);
                $this->justCreated['bluesky'] = true;
            }
        }

        if ($this->wantsPixelfed($post, $payload['images']) && $post->pixelfed_at === null) {
            $pixelfed = $this->pixelfed();
            $result   = $pixelfed?->tootPost(
                $payload['context'],
                $payload['title'],
                $payload['excerpt'],
                $payload['url'],
                $payload['images'],
                $payload['links']
            );
            if ($result !== null) {
                $post->markPixelfed($result['url'], $result['id']);
                $this->justCreated['pixelfed'] = true;
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
                $payload['images'],
                $payload['links']
            );
        }

        // Bluesky's answer is worth keeping: a rewrite that landed in the repo
        // still has to be indexed before anybody sees it, and it often is not.
        // Only a write that actually happened is worth checking — an unchanged
        // record has nothing new to surface.
        if ($post->bluesky_rkey !== null && !isset($this->justCreated['bluesky'])) {
            $edit = $this->bluesky()?->editPost(
                $post->bluesky_rkey,
                $payload['context'],
                $payload['title'],
                $payload['excerpt'],
                $payload['url'],
                $payload['images'],
                $payload['links']
            );

            if ($edit === Bluesky::EDIT_WRITTEN) {
                $post->markBlueskyUnverified();
            }
        }

        // An existing Pixelfed copy is kept current whatever the post has since
        // become: the gate decides what gets *sent* there, not what gets left
        // stale once it is.
        if ($post->pixelfed_status_id !== null && !isset($this->justCreated['pixelfed'])) {
            $this->pixelfed()?->editPost(
                $post->pixelfed_status_id,
                $payload['context'],
                $payload['title'],
                $payload['excerpt'],
                $payload['url'],
                $payload['images'],
                $payload['links']
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
            && $post->pixelfed_at === null && $post->pixelfed_url === null
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

        $pixelfedGone = true;
        if ($post->pixelfed_status_id !== null) {
            $pixelfed     = $this->pixelfed();
            $pixelfedGone = $pixelfed !== null && $pixelfed->deletePost($post->pixelfed_status_id);
        }

        $post->clearSyndication($mastodonGone, $blueskyGone, $pixelfedGone);
    }

    /**
     * Ask bsky.app whether the edits written to it have actually surfaced, for
     * every copy whose check has come due.
     *
     * Runs from cron (bin/publish-scheduled.php) rather than from the save that
     * made the edit: the AppView needs a moment to index an ordinary write, so
     * an answer taken straight after the write would be no answer at all.
     *
     * Returns the posts found stale, so a caller with somewhere to print can
     * say so. A no-op when nothing is due or Bluesky is not configured.
     *
     * @return list<int> ids of posts bsky.app is showing an older version of
     */
    public function verifyBluesky(): array
    {
        $due = $this->db->select(
            "SELECT id, bluesky_rkey
               FROM posts
              WHERE bluesky_verify_at IS NOT NULL
                AND bluesky_verify_at <= datetime('now')
                AND bluesky_rkey IS NOT NULL
                AND deleted_at IS NULL
              ORDER BY bluesky_verify_at"
        );

        if ($due === []) {
            return [];
        }

        // One client for the batch, so the whole run signs in once.
        $bluesky = $this->bluesky();
        if ($bluesky === null) {
            return [];
        }

        $stale = [];
        foreach ($due as $row) {
            $post = Post::findById($this->db, (int) $row['id']);
            if ($post === null) {
                continue;
            }

            $visible = $bluesky->isVisible((string) $row['bluesky_rkey']);
            $post->markBlueskyVerified($visible);

            if ($visible === false) {
                $stale[] = (int) $row['id'];
            }
        }

        return $stale;
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
     * @return array{context:string,title:string,excerpt:string,url:string,links:string[],images:array<array{path:string,mime:string,alt:string}>}|null
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

        $ogImage = null;

        if ($post->isNote()) {
            $url = '';
            // Breaks kept: a note syndicates as its whole body, and both
            // networks render a newline as a newline. Flattening it would send
            // a different post than the one on the site.
            $excerpt = $post->noteText(true);

            // Flattening also keeps a link's words and drops its URL, and a
            // note has no permalink beside it for the reader to fall back on —
            // so the URLs go on the end. One the body already writes out in
            // full is left alone rather than said twice.
            $links = array_values(array_filter(
                $post->noteLinks(),
                static fn(string $link): bool => !str_contains($excerpt, $link)
            ));
        } else {
            $datePath = Post::datePath((string) $post->published_at, $post->slug, $this->db->getSetting('timezone', ''));
            $url      = $siteUrl . '/' . $datePath . '/';

            // Bluesky renders no preview card unless the record carries one,
            // thumbnail included, so the card image has to be uploaded as
            // bytes. Builder::buildOgImage() writes it here on every build of a
            // titled post; a note has none, and neither does any post built
            // without GD. Left null when it is missing — the card still shows,
            // without a picture.
            //
            // A featured image outranks the generated title card, matching what
            // the permalink advertises as og:image and so what Mastodon puts on
            // its own card. It is *only* ever the thumbnail: putting it in
            // $images below would make the record an image post, and images
            // outrank the external card (Bluesky::postToBluesky()), costing
            // every titled post the link card it should have.
            $featured = $post->effectiveFeaturedImage();
            $ogPath   = $this->outputDir . '/posts/' . $datePath . '/og.png';
            $ogImage  = ($featured !== null
                    ? SyndicationMedia::localPath($featured['url'], $this->mediaDir, $siteUrl)
                    : null)
                ?? (is_file($ogPath) ? $ogPath : null);

            $effective = $post->effectiveExcerpt();
            $excerpt   = $effective !== null ? strip_tags($effective) : Helpers::truncate($post->content, 280);

            // A titled post links home, and the permalink carries the body's
            // links as links. Trailing them here would say the same thing
            // twice and crowd out the excerpt to do it.
            $links = [];
        }

        // Photos ride along with the text so a photo post looks native on every
        // network instead of arriving as a caption with no picture.
        //
        // One set, resolved once, capped at the smallest limit any of the three
        // imposes (four). Pixelfed allows twenty: raising it there means
        // resolving up to the largest limit here and having each client take
        // the slice it can send.
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
            'context'  => $context,
            'title'    => $post->title,
            'excerpt'  => $excerpt,
            'url'      => $url,
            'links'    => $links,
            'images'   => $images,
            'og_image' => $ogImage,
        ];
    }

    /** Whether $post should go to $network at all: configured, and not opted out. */
    private function wants(Post $post, string $network): bool
    {
        $skip = $network === 'mastodon' ? $post->mastodon_skip : $post->bluesky_skip;
        return $skip === 0 && ($network === 'mastodon' ? $this->mastodon() : $this->bluesky()) !== null;
    }

    /**
     * Whether $post should go to Pixelfed — configured and not opted out, as
     * for the others, and additionally a photo post with a picture to show.
     *
     * `post_kind === 'photo'` and not "has photos": a *titled* post carrying
     * images is an `article` here (see Post::micropubType()), and an article
     * with a screenshot in it is not a photo post. The images have to have
     * resolved to files on disk as well — a photo post whose pictures live
     * outside the media directory has nothing to attach, and Pixelfed refuses
     * a status with no media.
     *
     * @param array<array{path:string,mime:string,alt:string}> $images
     */
    private function wantsPixelfed(Post $post, array $images): bool
    {
        return $post->pixelfed_skip === 0
            && $post->isPhoto()
            && $images !== []
            && $this->pixelfed() !== null;
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

    /** A configured Pixelfed client, or null when the settings are incomplete. */
    private function pixelfed(): ?Pixelfed
    {
        $instance = $this->db->getSetting('pixelfed_instance');
        $token    = $this->db->getSetting('pixelfed_token');

        return ($instance !== '' && $token !== '') ? new Pixelfed($instance, $token) : null;
    }
}
