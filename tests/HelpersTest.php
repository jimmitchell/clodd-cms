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

    // ── Remote ids parsed out of syndication URLs ─────────────────────────────

    /**
     * These are what an edit or a delete addresses the remote copy by, both for
     * posts syndicated before the ids were stored and for a toot URL typed into
     * the post form by hand.
     */
    public function testMastodonStatusIdReadsTheTrailingId(): void
    {
        $this->assertSame('113456789', Helpers::mastodonStatusId('https://mastodon.social/@jim/113456789'));
        $this->assertSame('113456789', Helpers::mastodonStatusId('https://mastodon.social/@jim/113456789/'));
        $this->assertSame('113456789', Helpers::mastodonStatusId('https://example.social/users/jim/statuses/113456789?x=1'));
    }

    public function testMastodonStatusIdRejectsAnythingElse(): void
    {
        $this->assertNull(Helpers::mastodonStatusId(''));
        $this->assertNull(Helpers::mastodonStatusId('https://mastodon.social/@jim'));
        $this->assertNull(Helpers::mastodonStatusId('not a url'));
    }

    public function testBlueskyRkeyReadsTheTrailingRecordKey(): void
    {
        $this->assertSame('3kv7qabcd2s', Helpers::blueskyRkey('https://bsky.app/profile/jim.example/post/3kv7qabcd2s'));
        $this->assertSame('3kv7qabcd2s', Helpers::blueskyRkey('https://bsky.app/profile/jim.example/post/3kv7qabcd2s/'));
    }

    public function testBlueskyRkeyRejectsAnythingElse(): void
    {
        $this->assertNull(Helpers::blueskyRkey(''));
        $this->assertNull(Helpers::blueskyRkey('https://bsky.app/profile/jim.example'));
        // Record keys are lowercase base32; an uppercase segment is a path, not a key.
        $this->assertNull(Helpers::blueskyRkey('https://bsky.app/profile/jim.example/post/ABC'));
    }

    // ── safeUrl ───────────────────────────────────────────────────────────────

    public function testSafeUrlKeepsAbsoluteHttpAndSiteRootedPaths(): void
    {
        $this->assertSame('https://example.com/x.jpg', Helpers::safeUrl('https://example.com/x.jpg'));
        $this->assertSame('http://example.com/x.jpg', Helpers::safeUrl('http://example.com/x.jpg'));
        // Scheme matching is case-insensitive; the value is returned as written.
        $this->assertSame('HTTPS://example.com/x', Helpers::safeUrl('HTTPS://example.com/x'));
        $this->assertSame('/media/x.jpg', Helpers::safeUrl('/media/x.jpg'));
        $this->assertSame('/media/x.jpg', Helpers::safeUrl('  /media/x.jpg  '));
    }

    /**
     * The reason this helper exists: a photo URL reaches an href on the public
     * post page, where a javascript: value is a working XSS.
     */
    public function testSafeUrlRejectsScriptBearingSchemes(): void
    {
        $this->assertSame('', Helpers::safeUrl('javascript:alert(1)'));
        $this->assertSame('', Helpers::safeUrl('JaVaScRiPt:alert(1)'));
        $this->assertSame('', Helpers::safeUrl('data:text/html;base64,PHNjcmlwdD4='));
        $this->assertSame('', Helpers::safeUrl('vbscript:msgbox(1)'));
        $this->assertSame('', Helpers::safeUrl('file:///etc/passwd'));
    }

    /**
     * "//evil.example" is protocol-relative: a browser resolves it to a foreign
     * origin, so it must not pass as a path rooted on this site.
     */
    public function testSafeUrlRejectsProtocolRelativeUrls(): void
    {
        $this->assertSame('', Helpers::safeUrl('//evil.example/x.jpg'));
        $this->assertSame('', Helpers::safeUrl('///evil.example/x.jpg'));
    }

    public function testSafeUrlRejectsEmptyAndRelativeValues(): void
    {
        $this->assertSame('', Helpers::safeUrl(''));
        $this->assertSame('', Helpers::safeUrl(null));
        $this->assertSame('', Helpers::safeUrl('   '));
        // A bare relative path has no leading slash, so it is not site-rooted.
        $this->assertSame('', Helpers::safeUrl('media/x.jpg'));
    }

    /**
     * Link-tag variants are the query suffixes other sites append to links
     * pointing here, and they end up inside a URL this site then fetches.
     */
    public function testLinkTagVariantsCleansTheSettingIntoAList(): void
    {
        $this->assertSame(
            ['ref=blog.example.com', 'utm_source=news'],
            Helpers::linkTagVariants("ref=blog.example.com\nutm_source=news")
        );

        // Copied out of an address bar, a parameter arrives with its separator.
        $this->assertSame(['ref=a.example'], Helpers::linkTagVariants('?ref=a.example'));
        $this->assertSame(['ref=a.example'], Helpers::linkTagVariants('&ref=a.example'));

        // Blank lines, padding, CRLF from a textarea, and repeats.
        $this->assertSame(
            ['ref=a.example'],
            Helpers::linkTagVariants("\r\n  ref=a.example  \r\n\r\n?ref=a.example\n")
        );

        $this->assertSame([], Helpers::linkTagVariants(''));
        $this->assertSame([], Helpers::linkTagVariants("   \n  \n"));
    }

    /**
     * Anything that is not a plain name=value pair is dropped rather than passed
     * along. The consumer encodes it, so this is not the only guard — but a
     * suffix carrying a fragment, a space or another URL is a mistake, and
     * silently querying a mangled target would just hide the mistake.
     */
    public function testLinkTagVariantsDropsAnythingUnlikeAQueryPair(): void
    {
        foreach ([
            'ref',                              // no value at all
            'ref=a b',                          // space
            'ref=a#frag',                       // fragment
            'ref=a&utm=b',                      // two pairs on one line
            'https://evil.example/?ref=a',      // a whole URL
            '=novalue',                         // no name
            'ref=<script>',
        ] as $bad) {
            $this->assertSame([], Helpers::linkTagVariants($bad), $bad . ' should be dropped');
        }

        // An empty value is legitimate: "?ref=" is a real, if odd, parameter.
        $this->assertSame(['ref='], Helpers::linkTagVariants('ref='));
    }
}
