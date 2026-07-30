<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Helpers;
use PHPUnit\Framework\TestCase;

/**
 * Text helpers. The reading-time cases pin UTF-8 correctness: str_word_count()
 * is byte-oriented, so it splits accented words and miscounts CJK entirely.
 */
final class HelpersTest extends TestCase
{
    // ── readingTime ───────────────────────────────────────────────────────────

    public function testReadingTimeCountsWhitespaceSeparatedTokens(): void
    {
        $this->assertSame(1, Helpers::readingTime('<p>' . str_repeat('word ', 200) . '</p>'));
        $this->assertSame(2, Helpers::readingTime('<p>' . str_repeat('word ', 400) . '</p>'));
        $this->assertSame(3, Helpers::readingTime('<p>' . str_repeat('word ', 401) . '</p>'));
    }

    public function testReadingTimeIsAtLeastOneMinute(): void
    {
        $this->assertSame(1, Helpers::readingTime(''));
        $this->assertSame(1, Helpers::readingTime('<p></p>'));
        $this->assertSame(1, Helpers::readingTime('<p>short</p>'));
    }

    public function testReadingTimeIgnoresMarkup(): void
    {
        $bare   = Helpers::readingTime('<p>' . str_repeat('word ', 300) . '</p>');
        $nested = Helpers::readingTime(
            '<div class="x"><p><em>' . str_repeat('word ', 300) . '</em></p></div>'
        );

        $this->assertSame($bare, $nested, 'tags must not be counted as words');
    }

    /**
     * 300 accented words is 300 words. str_word_count() split them into 600,
     * which doubled the estimate.
     */
    public function testReadingTimeHandlesAccentedText(): void
    {
        $html = '<p>' . str_repeat('café déjà-vu naïve ', 100) . '</p>';

        $this->assertSame(2, Helpers::readingTime($html));
        $this->assertNotSame(
            (int) ceil(str_word_count(strip_tags($html)) / 200),
            Helpers::readingTime($html),
            'the byte-oriented count should differ — that is the bug being fixed'
        );
    }

    public function testReadingTimeHandlesCjkText(): void
    {
        // 200 space-separated tokens, whatever the script.
        $html = '<p>' . str_repeat('日本語 のテキスト ', 100) . '</p>';

        $this->assertSame(1, Helpers::readingTime($html));
    }

    public function testReadingTimeCollapsesRunsOfWhitespace(): void
    {
        $this->assertSame(
            Helpers::readingTime('<p>one two three</p>'),
            Helpers::readingTime("<p>one   \n\t two \n three</p>")
        );
    }

    // ── truncate ──────────────────────────────────────────────────────────────

    public function testTruncateLeavesShortTextAlone(): void
    {
        $this->assertSame('Short enough.', Helpers::truncate('<p>Short enough.</p>', 200));
    }

    public function testTruncateBreaksOnAWordBoundary(): void
    {
        $out = Helpers::truncate(str_repeat('alpha beta ', 50), 20);

        $this->assertStringEndsWith('…', $out);
        $this->assertLessThanOrEqual(21, mb_strlen($out));
        $this->assertStringNotContainsString('alph…', $out, 'must not chop mid-word');
    }

    // ── slugify (URL shape) ───────────────────────────────────────────────────

    public function testSlugifyOutputIsAlwaysUrlSafe(): void
    {
        foreach (['Héllo Wörld!', 'a/b\\c', '  spaces  ', '@#$%^&*', '日本語'] as $input) {
            $slug = Helpers::slugify($input);

            $this->assertMatchesRegularExpression(
                '/^[a-z0-9-]+$/',
                $slug,
                "slugify({$input}) produced an unsafe slug: {$slug}"
            );
        }
    }

    // ── mastodonProfileUrl ────────────────────────────────────────────────────

    public function testMastodonProfileUrlBuildsFromAHandle(): void
    {
        $this->assertSame(
            'https://mastodon.social/@jim',
            Helpers::mastodonProfileUrl('@jim@mastodon.social')
        );
    }

    public function testMastodonProfileUrlRejectsMalformedHandles(): void
    {
        $this->assertNull(Helpers::mastodonProfileUrl(''));
        $this->assertNull(Helpers::mastodonProfileUrl('jim'));
        $this->assertNull(Helpers::mastodonProfileUrl('@jim@a@b'));
        $this->assertNull(Helpers::mastodonProfileUrl('@jim@'));
    }
}
