<?php

declare(strict_types=1);

namespace CMS;

class Helpers
{
    /**
     * Convert a string into a URL-safe slug.
     * e.g. "Hello, World! It's Alive" → "hello-world-its-alive"
     */
    public static function slugify(string $text): string
    {
        // Transliterate non-ASCII characters to ASCII equivalents (requires intl).
        if (function_exists('transliterator_transliterate')) {
            $text = transliterator_transliterate('Any-Latin; Latin-ASCII', $text) ?? $text;
        }

        $text = strtolower($text);
        $text = preg_replace('/[\'\"]+/', '', $text);         // strip quotes
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);    // non-alnum → hyphen
        $text = trim($text, '-');

        return $text !== '' ? $text : 'untitled';
    }

    /**
     * Format a datetime string for display.
     *
     * When $locale is provided and the intl extension is available, uses
     * IntlDateFormatter::FULL for a fully localised date (e.g. "vendredi
     * 28 février 2026" for fr_FR).  Falls back to PHP date() otherwise.
     */
    public static function formatDate(string $datetime, string $format = 'F j, Y', string $locale = '', string $timezone = ''): string
    {
        $ts = strtotime($datetime);
        if ($ts === false) {
            return $datetime;
        }

        $tz = $timezone !== '' ? @timezone_open($timezone) : false;

        if ($locale !== '' && class_exists('IntlDateFormatter')) {
            $fmt = new \IntlDateFormatter(
                $locale,
                \IntlDateFormatter::LONG,
                \IntlDateFormatter::NONE,
                $tz ?: null
            );
            $result = $fmt->format($ts);
            if ($result !== false) {
                return $result;
            }
        }

        if ($tz) {
            $dt = new \DateTime('@' . $ts);
            $dt->setTimezone($tz);
            return $dt->format($format);
        }

        return date($format, $ts);
    }

    /**
     * Strip HTML tags and truncate plain text to $length characters,
     * appending an ellipsis if truncated.
     */
    public static function truncate(string $html, int $length = 200): string
    {
        $text = strip_tags($html);
        $text = preg_replace('/\s+/', ' ', trim($text));

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        // Break at the last word boundary within the limit.
        $truncated = mb_substr($text, 0, $length);
        $lastSpace = mb_strrpos($truncated, ' ');
        if ($lastSpace !== false) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return rtrim($truncated) . '…';
    }

    /**
     * Estimate reading time in minutes for rendered HTML.
     * Strips tags, counts words, divides by $wpm. Returns at least 1.
     */
    public static function readingTime(string $html, int $wpm = 200): int
    {
        // Not str_word_count(): it is byte-oriented and locale-dependent, so it
        // undercounts any non-ASCII text (accented words split, CJK ignored).
        $text  = trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));
        $words = $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);

        return max(1, (int) ceil($words / max(1, $wpm)));
    }

    /**
     * Escape a string for safe HTML output.
     */
    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Wrap a value in a CDATA section, safely.
     *
     * CDATA has exactly one escape concern: the section ends at the first `]]>`,
     * so content containing that sequence — a post about CDATA, or any code
     * block with `]]>` in it — would terminate the section early and leave the
     * remainder as markup. The fix is to split across two sections at that point.
     *
     * Content inside CDATA must NOT be HTML-escaped: `&` and `<` are already
     * literal there, so escaping would round-trip `&` back as `&amp;`.
     *
     * Also strips characters that are illegal in XML 1.0 (control characters
     * other than tab/LF/CR), which no amount of escaping can represent.
     */
    public static function cdata(?string $value): string
    {
        $value = (string) $value;
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value);

        return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $value) . ']]>';
    }

    /**
     * Build a Mastodon profile URL from a handle in the form "@user@instance".
     * Returns null if the handle is empty or malformed.
     */
    public static function mastodonProfileUrl(string $handle): ?string
    {
        $stripped = ltrim($handle, '@');
        if ($stripped === '' || substr_count($stripped, '@') !== 1) {
            return null;
        }
        [$user, $instance] = explode('@', $stripped, 2);
        if ($user === '' || $instance === '') {
            return null;
        }
        return 'https://' . $instance . '/@' . $user;
    }
}
