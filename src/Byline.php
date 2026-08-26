<?php

declare(strict_types=1);

namespace CMS;

/**
 * Byline-spec (https://bylinespec.org/1.0) XML fragments.
 *
 * Single source of truth for the channel-level <byline:person>+<byline:org>
 * block and the per-item <byline:author ref="..."/> reference. Used by both
 * the Atom feed (Feed.php) and the RSS 2.0 feed (RssFeed.php) so the two
 * cannot drift.
 *
 * Callers must declare xmlns:byline="https://bylinespec.org/1.0" on the
 * root element.
 */
final class Byline
{
    /** Stable identifier for the site author. */
    private static function personId(string $siteUrl): string
    {
        $base = rtrim($siteUrl, '/');
        return ($base !== '' ? $base : 'urn:byline:author') . '/#author';
    }

    /** Stable identifier for the site as an org. */
    private static function orgId(string $siteUrl): string
    {
        $base = rtrim($siteUrl, '/');
        return ($base !== '' ? $base : 'urn:byline:org') . '/#org';
    }

    /**
     * Channel-level XML: <byline:person> followed by <byline:org type="personal">.
     * Returns an empty string when no author_name is configured (nothing to say).
     * Output is indented with 2-space prefix per line for embedding in feeds.
     */
    public static function channelXml(array $settings, string $indent = '  '): string
    {
        $name = trim((string) ($settings['author_name'] ?? ''));
        if ($name === '') {
            return '';
        }

        $siteUrl   = rtrim((string) ($settings['site_url'] ?? ''), '/');
        $siteTitle = trim((string) ($settings['site_title'] ?? ''));
        $bio       = trim((string) ($settings['author_bio'] ?? ''));
        $avatar    = trim((string) ($settings['author_avatar_url'] ?? ''));

        $personId = self::personId($siteUrl);
        $orgId    = self::orgId($siteUrl);

        $lines = [];
        $lines[] = $indent . '<byline:person id="' . self::x($personId) . '">';
        $lines[] = $indent . '  <byline:name>' . self::x($name) . '</byline:name>';
        if ($bio !== '') {
            $lines[] = $indent . '  <byline:context>' . self::x($bio) . '</byline:context>';
        }
        if ($siteUrl !== '') {
            $lines[] = $indent . '  <byline:url>' . self::x($siteUrl) . '</byline:url>';
        }
        if ($avatar !== '') {
            $lines[] = $indent . '  <byline:avatar>' . self::x($avatar) . '</byline:avatar>';
        }
        foreach (self::profiles($settings) as [$rel, $href]) {
            $lines[] = $indent . '  <byline:profile href="' . self::x($href) . '" rel="' . self::x($rel) . '"/>';
        }
        $lines[] = $indent . '</byline:person>';

        if ($siteTitle !== '') {
            $lines[] = $indent . '<byline:org id="' . self::x($orgId) . '">';
            $lines[] = $indent . '  <byline:name>' . self::x($siteTitle) . '</byline:name>';
            if ($siteUrl !== '') {
                $lines[] = $indent . '  <byline:url>' . self::x($siteUrl) . '</byline:url>';
            }
            $lines[] = $indent . '  <byline:type>personal</byline:type>';
            $lines[] = $indent . '</byline:org>';
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Per-item XML: <byline:author ref="..."/>.
     * Returns an empty string when no author_name is configured.
     */
    public static function authorRefXml(array $settings, string $indent = '    '): string
    {
        if (trim((string) ($settings['author_name'] ?? '')) === '') {
            return '';
        }
        $siteUrl = rtrim((string) ($settings['site_url'] ?? ''), '/');
        return $indent . '<byline:author ref="' . self::x(self::personId($siteUrl)) . '"/>' . "\n";
    }

    /**
     * Build the list of [rel, href] pairs from configured social settings.
     * Mastodon URL is derived from the @user@instance handle.
     *
     * @return array<int, array{0:string,1:string}>
     */
    private static function profiles(array $settings): array
    {
        $out = [];

        $masto = Helpers::mastodonProfileUrl((string) ($settings['mastodon_handle'] ?? ''));
        if ($masto !== null) {
            $out[] = ['mastodon', $masto];
        }

        $bsky = trim((string) ($settings['bluesky_url'] ?? ''));
        if ($bsky !== '') {
            $out[] = ['bluesky', $bsky];
        }

        $gh = trim((string) ($settings['github_url'] ?? ''));
        if ($gh !== '') {
            $out[] = ['github', $gh];
        }

        return $out;
    }

    private static function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
