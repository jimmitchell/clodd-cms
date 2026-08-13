<?php

declare(strict_types=1);

namespace CMS;

/**
 * Scheduled posts going live.
 *
 * There is no cron here: a scheduled post becomes published when a request
 * arrives after its time, and every entry point that can be that request runs
 * this — the admin UI, the REST API and the Micropub endpoint. Which one gets
 * there first is a matter of who knocks, so a post must go live the same way
 * whichever it is, and none of them may promote a post and leave the rest of
 * the work to somebody else: Post::promoteScheduled() flips the row exactly
 * once, so a caller that drops the ids leaves a post that is published,
 * unbuilt, and invisible to the next request's promotion query.
 */
final class Scheduler
{
    public function __construct(
        private Database $db,
        private Builder $builder,
        private Syndication $syndication,
    ) {}

    /**
     * Publish every scheduled post whose time has passed: build it, its
     * neighbours and the shared pages, and send it to the networks.
     *
     * @return list<int> The ids promoted, for callers that log or report them.
     */
    public function run(): array
    {
        $promotedIds = Post::promoteScheduled($this->db);

        foreach ($promotedIds as $id) {
            $post = Post::findById($this->db, $id);
            if ($post === null) {
                continue;
            }

            // buildPost() rebuilds taxonomy archives for the post's own terms.
            $this->builder->buildPost($post);
            $prev = Post::findPrev($this->db, $post);
            if ($prev) $this->builder->buildPost($prev);
            $next = Post::findNext($this->db, $post);
            if ($next) $this->builder->buildPost($next);

            // Syndicate after the build, not before: both networks fetch the
            // permalink to make a preview card, and the page has only just
            // reached the disk. The post page shows the copies it made, so a
            // syndication that recorded a URL leaves the page a version behind
            // — rebuild it. Only post.php renders these URLs, so the shared
            // pages below need no second pass.
            $syndicationBefore = [$post->mastodon_url, $post->bluesky_url, $post->pixelfed_url];
            $this->syndication->publish($post);

            if ([$post->mastodon_url, $post->bluesky_url, $post->pixelfed_url] !== $syndicationBefore) {
                $this->builder->buildPost($post);
            }
        }

        if ($promotedIds !== []) {
            $this->builder->rebuildSharedResources();
        }

        return $promotedIds;
    }
}
