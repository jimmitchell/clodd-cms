<?php

declare(strict_types=1);

namespace CMS;

/**
 * Everything a save has to do besides writing the row.
 *
 * A published post is not one file. It is the post page, the two neighbours
 * whose prev/next links name it, the archive of every term it belongs to, the
 * three feeds, the index — and then, in that order and only afterwards, the
 * copies on Mastodon, Bluesky and Pixelfed.
 *
 * That sequence was hand-written at five entry points: micropub.php,
 * admin/post-edit.php, admin/posts.php, admin/api.php, XmlRpcServer and
 * Scheduler. They did not agree. Each one held some piece of correctness the
 * others lacked, and every bug fixed in 1.36.0 was the same bug found at one
 * entry point and missed at the rest:
 *
 *   - only micropub.php rebuilt the neighbours a post *moved away from*;
 *   - only the editor rebuilt the archive a post was taken *out of*;
 *   - only the editor skipped a syndication rewrite when nothing the networks
 *     display had changed — the other three paid four HTTP round-trips on
 *     every typo-fix save;
 *   - the editor's cheap feed path built two of the three feeds.
 *
 * CLAUDE.md already records the build-then-syndicate ordering being got wrong
 * twice by exactly this route. So the rule is now: **a caller decides *whether*
 * to publish; this class decides *what that means*.** If you find yourself
 * writing `$builder->buildPost()` next to `$syndication->publish()` anywhere
 * else, that is the mistake this class exists to prevent.
 */
final class PostPublisher
{
    public function __construct(
        private Database $db,
        private Builder $builder,
        private ?Syndication $syndication = null,
        private ?ActivityLog $activityLog = null,
    ) {
    }

    /**
     * Everything about a post's current published position that the pending
     * write is about to destroy.
     *
     * **Call this before the post is mutated, and before saveTerms().** Both
     * halves matter, and both have been got wrong: Post::saveTerms() refreshes
     * $post->categories/->tags in memory, so reading them afterwards yields the
     * *new* set — which is how XmlRpcServer ended up diffing the new terms
     * against themselves under a comment calling them the old ones.
     *
     * @return array<string,mixed>
     */
    public function snapshot(Post $post): array
    {
        $wasPublished = $post->status === 'published' && $post->deleted_at === null;

        return [
            'wasPublished' => $wasPublished,
            'dir'          => $wasPublished ? $this->builder->postOutputDir($post->published_at, $post->slug) : null,
            // The same position as 'dir', addressed the way the outside world
            // holds it. Kept only while the post was public: an address nobody
            // could reach is not one to redirect.
            'path'         => $wasPublished ? $this->builder->postUrlPath($post->published_at, $post->slug) : null,
            // Unhydrated on purpose: rebuildAfterSave() only re-renders these,
            // and Builder::buildPost() hydrates whatever it renders.
            'neighbours'   => $wasPublished
                ? array_values(array_filter([Post::findPrev($this->db, $post), Post::findNext($this->db, $post)]))
                : [],
            'catIds'       => array_map('intval', array_column($post->categories, 'id')),
            'tagIds'       => array_map('intval', array_column($post->tags, 'id')),
            'title'        => $post->title,
            'slug'         => $post->slug,
            'content'      => $post->content,
            'publishedAt'  => $post->published_at,
            'excerpt'      => $post->excerpt,
            'postKind'     => $post->post_kind,
            'featuredUrl'  => $post->featured_image_url,
            'featuredAlt'  => $post->featured_image_alt,
        ];
    }

    /** The snapshot for a post that does not exist yet. Nothing to vacate. */
    public function snapshotOfNothing(): array
    {
        return [
            'wasPublished' => false, 'dir' => null, 'path' => null, 'neighbours' => [],
            'catIds' => [], 'tagIds' => [], 'title' => null, 'slug' => null,
            'content' => null, 'publishedAt' => null, 'excerpt' => null, 'postKind' => null,
            'featuredUrl' => null, 'featuredAlt' => null,
        ];
    }

    /**
     * Re-render every static file the save just invalidated.
     *
     * Safe to call for a draft: a post that has never been public has nothing
     * on disk and this returns after clearing any output it is vacating.
     *
     * $deferShared is for bulk callers — Scheduler promoting several posts in
     * one tick — which do one rebuildSharedResources() at the end rather than
     * one per post. Same reasoning as Builder's deferTaxonomy: the shared pages
     * are the same pages every time round the loop.
     *
     * @param array<string,mixed> $before from snapshot()
     */
    public function rebuildAfterSave(Post $post, array $before, bool $deferShared = false): void
    {
        // Stale output at the old date-path, when a slug or published date moved.
        $this->builder->removeVacatedPostOutput($before['dir'] ?? null, $post);

        // The address the post just left, kept so the old URL still redirects
        // and the webmentions filed under it are still found. Deliberately
        // beside removeVacatedPostOutput(): they are the two halves of a move —
        // one clears what we serve there, this remembers that we used to.
        // Every write path reaches this method, which is the point; doing it in
        // Builder instead would have the renderer writing rows about posts.
        $post->recordLegacyPath($before['path'] ?? null, $this->db->getSetting('timezone', ''));

        $isPublished  = $post->status === 'published' && $post->deleted_at === null;
        $wasPublished = (bool) ($before['wasPublished'] ?? false);

        if (!$isPublished && !$wasPublished) {
            // Never been public and still isn't. buildPost() still runs so a
            // draft's leftovers are cleared, but nothing shared can have changed.
            $this->builder->buildPost($post);
            return;
        }

        $this->builder->buildPost($post);

        $this->rebuildNeighbours($post, $before);
        $this->rebuildVacatedTerms($post, $before);

        if (!$deferShared) {
            $this->rebuildSharedResources($post, $before);
        }
    }

    /**
     * The pair the post sits between now, and the pair it used to sit between.
     *
     * Two pairs, not one: a post that moves in the timeline leaves the posts
     * either side of its old position still linking to where it was. Rebuilding
     * only the new pair fixes the destination and leaves the origin wrong — for
     * a year, that is exactly what the admin editor did.
     *
     * @param array<string,mixed> $before
     */
    private function rebuildNeighbours(Post $post, array $before): void
    {
        // Neighbours display this post's title and URL, and nothing else about
        // it, so an edit that changes neither cannot have disturbed them.
        $affected = !($before['wasPublished'] ?? false)
            || $post->status !== 'published'
            || $post->title        !== ($before['title'] ?? null)
            || $post->slug         !== ($before['slug'] ?? null)
            || $post->published_at !== ($before['publishedAt'] ?? null);

        if (!$affected) {
            return;
        }

        $built = [];
        $candidates = [
            ...($before['neighbours'] ?? []),
            Post::findPrev($this->db, $post),
            Post::findNext($this->db, $post),
        ];
        foreach ($candidates as $neighbour) {
            if ($neighbour && $neighbour->id !== $post->id && !isset($built[$neighbour->id])) {
                $this->builder->buildPost($neighbour);
                $built[$neighbour->id] = true;
            }
        }
    }

    /**
     * The archives the post was just taken out of.
     *
     * Builder::buildPost() rebuilds the archives of the terms the post holds
     * *now*, so the ones it no longer holds are precisely what nothing else
     * covers — and they are still showing its card.
     *
     * @param array<string,mixed> $before
     */
    private function rebuildVacatedTerms(Post $post, array $before): void
    {
        $nowCatIds = array_map('intval', array_column($post->categories, 'id'));
        $nowTagIds = array_map('intval', array_column($post->tags, 'id'));

        foreach (array_diff($before['catIds'] ?? [], $nowCatIds) as $catId) {
            $this->builder->buildCategoryArchive((int) $catId);
        }
        foreach (array_diff($before['tagIds'] ?? [], $nowTagIds) as $tagId) {
            $this->builder->buildTagArchive((int) $tagId);
        }
    }

    /**
     * Index, sitemap and feeds.
     *
     * The index and sitemap only change when something they *display* changes,
     * so an ordinary body edit can skip them. The feeds cannot be skipped: all
     * three carry the whole body, so any edit to the words changes all three.
     * Building two of them was a real bug — feed.rss served pre-edit text until
     * the next full rebuild, and looked fine because the other two were right.
     *
     * @param array<string,mixed> $before
     */
    private function rebuildSharedResources(Post $post, array $before): void
    {
        $nowCatIds = array_map('intval', array_column($post->categories, 'id'));
        $nowTagIds = array_map('intval', array_column($post->tags, 'id'));

        $metaChanged = !($before['wasPublished'] ?? false)
            || $post->status !== 'published'
            || $post->title        !== ($before['title'] ?? null)
            || $post->slug         !== ($before['slug'] ?? null)
            || $post->published_at !== ($before['publishedAt'] ?? null)
            || $post->excerpt      !== ($before['excerpt'] ?? null)
            || $post->post_kind    !== ($before['postKind'] ?? null)
            // The card thumbnail on the home page and every archive, and the
            // image the feeds carry.
            || $post->featured_image_url !== ($before['featuredUrl'] ?? null)
            || $post->featured_image_alt !== ($before['featuredAlt'] ?? null)
            // A note's whole body is rendered into the home and archive cards,
            // so any content edit propagates.
            || $post->isNote()
            || $nowCatIds !== ($before['catIds'] ?? [])
            || $nowTagIds !== ($before['tagIds'] ?? []);

        if ($metaChanged) {
            $this->builder->rebuildSharedResources();
            return;
        }

        $this->builder->buildFeed();
        $this->builder->buildJsonFeed();
        $this->builder->buildRssFeed();
    }

    /**
     * Send the post to Mastodon, Bluesky and Pixelfed — **after** the build.
     *
     * Order matters, and it is not the obvious one. Mastodon fetches the
     * permalink to build its preview card exactly once, seconds after the
     * status is created, and never retries a fetch that failed. A first publish
     * from a draft has nothing on disk until buildPost() has run, so
     * syndicating first hands Mastodon a 404 and the card is gone permanently —
     * on a status that otherwise looks perfect.
     *
     * The post page then lists the copies it made, so a syndication that
     * recorded a URL leaves the page just written a version behind. Only
     * post.php renders those URLs — the feeds and the index do not — so one
     * more buildPost() is the whole of the second pass.
     *
     * Callers decide *whether* to syndicate. This decides what it entails.
     */
    public function syndicateAfterBuild(Post $post): void
    {
        if ($this->syndication === null) {
            return;
        }

        $before = [$post->mastodon_url, $post->bluesky_url, $post->pixelfed_url];
        $this->syndication->publish($post);

        if ([$post->mastodon_url, $post->bluesky_url, $post->pixelfed_url] !== $before) {
            $this->builder->buildPost($post);
        }
    }

    /**
     * Bring the existing copies into line with the post, or take them down.
     *
     * The check is here rather than left to the three clients because they each
     * no-op only *after* fetching the remote copy to compare against it — four
     * HTTP round-trips on an ordinary typo-fix save whose answers are all
     * thrown away. It mattered most for Pixelfed, which allows a status only
     * ten edits in its lifetime.
     *
     * The payload is built from these seven fields plus photos and contexts.
     * featured_image_url is in the list even though no status prints it: it is
     * the picture Bluesky uploads as its link-card thumbnail and what the
     * permalink advertises as og:image, so changing it changes the copy.
     * post_kind is in it for a similar reason — it decides whether the copy
     * links home at all, because a note syndicates as its own words with no
     * trailer.
     *
     * @param array<string,mixed> $before from snapshot()
     */
    public function resyndicateAfterBuild(Post $post, array $before): void
    {
        if ($this->syndication === null) {
            return;
        }

        $wasPublished = (bool) ($before['wasPublished'] ?? false);
        $isPublished  = $post->status === 'published' && $post->deleted_at === null;

        // Off the public site means off the other networks too: nothing should
        // be left pointing readers at a page that has stopped existing.
        if ($wasPublished && !$isPublished) {
            $this->syndication->remove($post);
            return;
        }

        if (!$isPublished) {
            return;
        }

        $textChanged = $post->title        !== ($before['title'] ?? null)
            || $post->slug                 !== ($before['slug'] ?? null)
            || $post->published_at         !== ($before['publishedAt'] ?? null)
            || $post->excerpt              !== ($before['excerpt'] ?? null)
            || $post->post_kind            !== ($before['postKind'] ?? null)
            || $post->featured_image_url   !== ($before['featuredUrl'] ?? null)
            || $post->content              !== ($before['content'] ?? null);

        if ($textChanged) {
            $this->syndication->update($post);
        }
    }

    /**
     * Delete a post and everything that pointed at it.
     *
     * $soft keeps the row so Micropub's `action=undelete` can bring it back;
     * the admin's Delete is final. That difference is deliberate — the two
     * surfaces mean different things by the word — so it is a parameter rather
     * than something to unify away.
     *
     * The copies are removed *first*, while the row still carries their ids.
     */
    public function deletePost(Post $post, bool $soft = false): void
    {
        $before = $this->snapshot($post);

        $this->syndication?->remove($post);

        if ($soft) {
            $post->softDelete();
        } else {
            $post->delete();
        }

        // buildPost() takes the unpublished path — it removes the output and
        // rebuilds the archives of the terms the post held.
        $post->status = 'draft';
        $this->builder->buildPost($post);

        if ($before['wasPublished']) {
            $this->rebuildNeighbours($post, $before);
            $this->builder->rebuildSharedResources();
        }

        $this->activityLog?->log('delete', 'post', $post->id, $post->title);
    }

    /** Bring a soft-deleted post back onto the public site. */
    public function restorePost(Post $post): void
    {
        $post->restore();

        if ($post->status !== 'published') {
            return;
        }

        $this->builder->buildPost($post);
        // No old position to restore from — the post was off the site.
        $this->rebuildNeighbours($post, $this->snapshotOfNothing());
        $this->builder->rebuildSharedResources();
    }
}
