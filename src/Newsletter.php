<?php

declare(strict_types=1);

namespace CMS;

/**
 * Email a newly published article to the EmailOctopus list.
 *
 * Runs from cron (bin/send-newsletter.php), never on the publish path. A send is
 * two API calls per subscriber, which is far too slow to make a save wait on,
 * and the delay is useful anyway: an article is not picked up until it has been
 * live for GRACE_MINUTES, which is the window for fixing the typo you spot the
 * moment it goes out.
 *
 * **How an article reaches an inbox.** EmailOctopus's API cannot create or send
 * a campaign. What it can do is start an automation for one contact. So the
 * automation holds a single email written with merge tags, and for each
 * subscriber this writes the article into their contact fields and then starts
 * the automation for them. One newsletter_deliveries row per subscriber records
 * that it was done, which is what makes a run that dies halfway safe to repeat.
 *
 * **The accepted race.** Those fields live on the contact, not on the send. If
 * EmailOctopus renders a queued email *after* the next article has overwritten
 * the fields, that subscriber gets the newer article twice and never sees the
 * first. So a new article is not started until GAP_MINUTES after the last
 * delivery was queued. Publishing two articles in an hour delays the second
 * email; it does not merge them.
 *
 * **What is never emailed.** Anything published before newsletter_enabled_from
 * (stamped when the feature is first configured) — without that cutoff the
 * first cron run would email the whole archive. Anything older than
 * MAX_AGE_DAYS, so a cron that was broken for a week does not wake up and send
 * last week's posts. Notes, photos and interactions: articles only, by the same
 * test the Micropub post list uses.
 */
final class Newsletter
{
    /** How long an article must have been live before it is emailed. */
    public const GRACE_MINUTES = 10;

    /** An article older than this is never emailed, even if it was missed. */
    public const MAX_AGE_DAYS = 3;

    /** How long after the last delivery before a *new* article may start. */
    public const GAP_MINUTES = 60;

    /**
     * Pause after each subscriber. Two calls each, so 250ms is eight calls a
     * second, under the ten a second EmailOctopus refills its bucket at.
     */
    public const PACE_MICROSECONDS = 250_000;

    public function __construct(
        private Database $db,
        private EmailOctopus $client,
        private string $siteUrl,
        private string $timezone = '',
        private int $paceMicroseconds = self::PACE_MICROSECONDS,
    ) {
        $this->siteUrl = rtrim($siteUrl, '/');
    }

    /**
     * The article to work on next, or null when there is none.
     *
     * An article already part-way through wins over a new one, so a send that
     * failed for some subscribers finishes before anything else starts.
     *
     * @phpstan-impure the answer moves with the database and the clock
     */
    public function nextArticle(): ?Post
    {
        $enabledFrom = trim($this->db->getSetting('newsletter_enabled_from'));
        if ($enabledFrom === '') {
            return null;
        }

        $eligible = "p.status = 'published'
                 AND p.deleted_at IS NULL
                 AND p.newsletter_skip = 0
                 AND p.newsletter_at IS NULL
                 AND p.published_at >= :from
                 AND p.published_at <= datetime('now', :grace)
                 AND p.published_at >= datetime('now', :maxAge)
                 AND " . Post::micropubTypePredicate('article');

        $params = [
            'from'   => $enabledFrom,
            'grace'  => '-' . self::GRACE_MINUTES . ' minutes',
            'maxAge' => '-' . self::MAX_AGE_DAYS . ' days',
        ];

        $row = $this->db->selectOne(
            "SELECT p.id FROM posts p
              WHERE {$eligible}
                AND EXISTS (SELECT 1 FROM newsletter_deliveries d WHERE d.post_id = p.id)
              ORDER BY p.published_at
              LIMIT 1",
            $params
        );

        if ($row === null) {
            // Nothing half-done, so this would start a new article — which has
            // to wait out the gap after the last one. See the class docblock.
            $recent = $this->db->selectOne(
                "SELECT 1 AS hit FROM newsletter_deliveries
                  WHERE queued_at > datetime('now', :gap)
                  LIMIT 1",
                ['gap' => '-' . self::GAP_MINUTES . ' minutes']
            );
            if ($recent !== null) {
                return null;
            }

            $row = $this->db->selectOne(
                "SELECT p.id FROM posts p
                  WHERE {$eligible}
                  ORDER BY p.published_at
                  LIMIT 1",
                $params
            );
        }

        return $row !== null ? Post::findById($this->db, (int) $row['id']) : null;
    }

    /**
     * Queue $post for every subscriber who has not had it yet.
     *
     * Marks the post sent only when nobody failed. Anyone who did is retried by
     * the next run, for as long as the article stays eligible.
     *
     * @return array{subscribers:int, queued:int, skipped:int, failed:int, done:bool}|null
     *         null when the subscriber list could not be read at all
     */
    public function send(Post $post): ?array
    {
        $ids = $this->client->subscribedContactIds();
        if ($ids === null) {
            return null;
        }

        $already = array_flip(array_column(
            $this->db->select('SELECT contact_id FROM newsletter_deliveries WHERE post_id = :id', ['id' => $post->id]),
            'contact_id'
        ));

        $fields = $this->fieldsFor($post);
        $queued = $failed = $skipped = 0;

        foreach ($ids as $contactId) {
            if (isset($already[$contactId])) {
                $skipped++;
                continue;
            }

            // Fields first, then the automation: the email is rendered from
            // whatever the contact holds when EmailOctopus gets to it.
            if ($this->client->setFields($contactId, $fields) && $this->client->queueAutomation($contactId)) {
                $this->db->insert('newsletter_deliveries', ['post_id' => $post->id, 'contact_id' => $contactId]);
                $queued++;
            } else {
                $failed++;
            }

            if ($this->paceMicroseconds > 0) {
                usleep($this->paceMicroseconds);
            }
        }

        $done = $failed === 0;
        if ($done) {
            $now = date('Y-m-d H:i:s');
            $this->db->update('posts', ['newsletter_at' => $now], 'id = :id', ['id' => $post->id]);
            $post->newsletter_at = $now;
        }

        return [
            'subscribers' => count($ids),
            'queued'      => $queued,
            'skipped'     => $skipped,
            'failed'      => $failed,
            'done'        => $done,
        ];
    }

    /** How many subscribers an article has been queued for — for the editor. */
    public static function deliveryCount(Database $db, int $postId): int
    {
        $row = $db->selectOne('SELECT COUNT(*) AS n FROM newsletter_deliveries WHERE post_id = :id', ['id' => $postId]);
        return (int) ($row['n'] ?? 0);
    }

    /**
     * What the automation's email is written from, keyed by the custom field
     * tags it uses.
     *
     * @return array<string,string>
     */
    public function fieldsFor(Post $post): array
    {
        $url = $this->siteUrl . '/' . Post::datePath((string) $post->published_at, $post->slug, $this->timezone) . '/';

        $excerpt = $post->effectiveExcerpt();
        $excerpt = $excerpt !== null ? trim(strip_tags($excerpt)) : '';

        // Our own paths are made absolute; a remote URL is passed through only
        // if its scheme is one an email client should follow.
        $image    = '';
        $featured = $post->effectiveFeaturedImage();
        if ($featured !== null) {
            $safe  = Helpers::safeUrl($featured['url']);
            $image = str_starts_with($safe, '/') ? $this->siteUrl . $safe : $safe;
        }

        return [
            EmailOctopus::FIELD_TITLE   => $post->title,
            EmailOctopus::FIELD_URL     => $url,
            EmailOctopus::FIELD_EXCERPT => $excerpt,
            EmailOctopus::FIELD_IMAGE   => $image,
        ];
    }
}
