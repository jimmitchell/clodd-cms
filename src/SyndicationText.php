<?php

declare(strict_types=1);

namespace CMS;

/**
 * The words of a syndicated copy, laid out the same way whichever network it
 * is bound for.
 *
 * Mastodon and Bluesky each held their own copy of this, differing only in the
 * character limit — which is how a reply came to carry its "In reply to" line
 * on the site and in the feeds but on neither network. One layout, two limits.
 */
final class SyndicationText
{
    /**
     * Compose a status from any non-empty subset of {context, title, excerpt,
     * url, links}, joined by blank lines and trimmed to $limit characters.
     *
     * Only the excerpt gives ground. The context lines say what the post is
     * replying to, the title names it and the URL is the way back to it, so a
     * copy that dropped any of them would be worse than a shorter one.
     *
     * $links is the trailer a note carries: the URLs its own links point at,
     * which flattening the body to plain text would otherwise drop (see
     * Post::linkUrls()). They go last, one per line, and are fixed for the same
     * reason the rest are — a note trimmed to fit its link is still the note,
     * and one that kept every word but lost the thing it was pointing at is
     * not. Empty for a titled post, which links home instead.
     *
     * @param string[] $links
     */
    public static function compose(string $context, string $title, string $excerpt, string $url, int $limit, array $links = []): string
    {
        $trailer = implode("\n", array_filter($links, static fn(string $l): bool => $l !== ''));

        $fixed      = array_filter([$context, $title, $url, $trailer], static fn(string $p): bool => $p !== '');
        $hasExcerpt = $excerpt !== '';
        $separators = max(0, count($fixed) + (int) $hasExcerpt - 1) * 2;
        $reserved   = array_sum(array_map('mb_strlen', $fixed)) + $separators;
        $budget     = $limit - $reserved;

        if ($hasExcerpt && mb_strlen($excerpt) > $budget) {
            // Under two characters left there is no room for even one character
            // and the ellipsis that says more was meant — drop the excerpt and
            // send what the post is about instead of a bare '…'.
            $excerpt = $budget < 2 ? '' : rtrim(mb_substr($excerpt, 0, $budget - 1)) . '…';
        }

        $parts = array_filter([$context, $title, $excerpt, $url, $trailer], static fn(string $p): bool => $p !== '');
        return implode("\n\n", $parts);
    }

    /**
     * The URLs a block of context lines points at.
     *
     * Bluesky needs to know where every link sits in the finished text before
     * it will make one clickable. Post::contextsText() writes each URL out in
     * full and never breaks a line inside one, so matching to the next space is
     * exact.
     *
     * @return string[]
     */
    public static function urlsIn(string $context): array
    {
        preg_match_all('#https?://\S+#i', $context, $matches);

        return $matches[0];
    }
}
