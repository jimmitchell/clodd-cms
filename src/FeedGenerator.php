<?php

declare(strict_types=1);

namespace CMS;

/**
 * Renders every feed the site publishes — Atom, JSON Feed and RSS, for the
 * site as a whole and for each category and tag.
 *
 * Renders only — Builder writes the results, so each file goes through
 * writeFile()'s output-root guard and unchanged-content check like everything
 * else the build produces.
 *
 * The three format classes are constructed once here rather than per call:
 * they hold the settings and the shared FeedMarkdown converter and nothing
 * else, so one set serves the site feeds and every term. Builder constructs a
 * new FeedGenerator whenever it reloads settings, which is what keeps them
 * current.
 */
class FeedGenerator
{
    /**
     * The filename each format is published under, at the site root and in
     * every term directory. Builder's stale-term cleanup reads this too, so a
     * fourth format cannot be written without also being removed.
     */
    public const FILES = [
        'atom' => 'feed.xml',
        'json' => 'feed.json',
        'rss'  => 'feed.rss',
    ];

    private Feed     $atom;
    private JsonFeed $json;
    private RssFeed  $rss;

    public function __construct(Database $db, array $settings)
    {
        $this->atom = new Feed($db, $settings);
        $this->json = new JsonFeed($db, $settings);
        $this->rss  = new RssFeed($db, $settings);
    }

    /** The site's Atom 1.0 feed. */
    public function atom(): string
    {
        return $this->atom->render();
    }

    /** The site's JSON Feed 1.1. */
    public function json(): string
    {
        return $this->json->render();
    }

    /** The site's RSS 2.0 feed, with Byline elements. */
    public function rss(): string
    {
        return $this->rss->render();
    }

    /**
     * All three feeds for one category or tag, keyed by filename.
     *
     * @param array<string,mixed> $term
     * @param Post[]              $posts
     * @return array<string,string>
     */
    public function forTerm(string $type, array $term, array $posts): array
    {
        return [
            self::FILES['atom'] => $this->atom->renderForTerm($type, $term, $posts),
            self::FILES['json'] => $this->json->renderForTerm($type, $term, $posts),
            self::FILES['rss']  => $this->rss->renderForTerm($type, $term, $posts),
        ];
    }
}
