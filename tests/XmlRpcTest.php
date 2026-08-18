<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\DateTimeValue;
use CMS\XmlRpc;
use PHPUnit\Framework\TestCase;

/**
 * XML-RPC parsing and encoding. MarsEdit rejects a whole response over a single
 * malformed value, so encoding correctness is worth pinning.
 */
final class XmlRpcTest extends TestCase
{
    // ── Parsing ───────────────────────────────────────────────────────────────

    public function testParsesMethodNameAndTypedParams(): void
    {
        $body = <<<XML
        <?xml version="1.0"?>
        <methodCall>
          <methodName>wp.getPost</methodName>
          <params>
            <param><value><int>7</int></value></param>
            <param><value><string>hello</string></value></param>
            <param><value><boolean>1</boolean></value></param>
            <param><value><double>1.5</double></value></param>
          </params>
        </methodCall>
        XML;

        $req = XmlRpc::parseRequest($body);

        $this->assertSame('wp.getPost', $req['method']);
        $this->assertSame([7, 'hello', true, 1.5], $req['params']);
    }

    public function testParsesStructsAndArrays(): void
    {
        $body = <<<XML
        <?xml version="1.0"?>
        <methodCall>
          <methodName>metaWeblog.newPost</methodName>
          <params>
            <param><value><struct>
              <member><name>title</name><value><string>T</string></value></member>
              <member><name>categories</name><value><array><data>
                <value><string>a</string></value>
                <value><string>b</string></value>
              </data></array></value></member>
            </struct></value></param>
          </params>
        </methodCall>
        XML;

        $req = XmlRpc::parseRequest($body);

        $this->assertSame(
            [['title' => 'T', 'categories' => ['a', 'b']]],
            $req['params']
        );
    }

    public function testUntypedValueIsAString(): void
    {
        $body = '<?xml version="1.0"?><methodCall><methodName>m</methodName>'
              . '<params><param><value>bare</value></param></params></methodCall>';

        $this->assertSame(['bare'], XmlRpc::parseRequest($body)['params']);
    }

    public function testMalformedXmlThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        XmlRpc::parseRequest('<methodCall><methodName>oops');
    }

    /**
     * External entities must not be resolved. Without entity substitution the
     * reference simply does not expand, which is the safe outcome.
     */
    public function testDoesNotExpandExternalEntities(): void
    {
        $body = <<<XML
        <?xml version="1.0"?>
        <!DOCTYPE x [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
        <methodCall>
          <methodName>m</methodName>
          <params><param><value><string>&xxe;</string></value></param></params>
        </methodCall>
        XML;

        try {
            $req = XmlRpc::parseRequest($body);
        } catch (\RuntimeException) {
            // Refusing the document outright is also an acceptable outcome.
            $this->assertTrue(true);
            return;
        }

        $this->assertStringNotContainsString(
            'root:',
            (string) ($req['params'][0] ?? ''),
            'file contents must never appear in a parsed value'
        );
    }

    // ── Encoding ──────────────────────────────────────────────────────────────

    public function testEncodesScalarTypes(): void
    {
        $this->assertSame('<value><int>5</int></value>', XmlRpc::encodeValue(5));
        $this->assertSame('<value><boolean>1</boolean></value>', XmlRpc::encodeValue(true));
        $this->assertSame('<value><boolean>0</boolean></value>', XmlRpc::encodeValue(false));
        $this->assertSame('<value><string></string></value>', XmlRpc::encodeValue(null));
        $this->assertSame('<value><string>hi</string></value>', XmlRpc::encodeValue('hi'));
    }

    public function testEscapesMarkupInStrings(): void
    {
        $this->assertSame(
            '<value><string>a &lt;b&gt; &amp; c</string></value>',
            XmlRpc::encodeValue('a <b> & c')
        );
    }

    public function testStripsXmlIllegalControlCharacters(): void
    {
        // These would make MarsEdit reject the entire response.
        $this->assertSame(
            '<value><string>ab</string></value>',
            XmlRpc::encodeValue("a\x00\x0B\x1Fb")
        );
    }

    public function testListEncodesAsArrayAndMapAsStruct(): void
    {
        $this->assertStringContainsString('<array><data>', XmlRpc::encodeValue(['a', 'b']));
        $this->assertStringContainsString('<struct>', XmlRpc::encodeValue(['k' => 'v']));
    }

    public function testEmptyArrayEncodesAsArrayNotStruct(): void
    {
        $this->assertSame('<value><array><data></data></array></value>', XmlRpc::encodeValue([]));
    }

    public function testDateTimeValueGetsItsOwnType(): void
    {
        $this->assertSame(
            '<value><dateTime.iso8601>20260729T12:00:00</dateTime.iso8601></value>',
            XmlRpc::encodeValue(new DateTimeValue('20260729T12:00:00'))
        );
    }

    public function testFaultEnvelopeIsWellFormed(): void
    {
        $xml = XmlRpc::encodeFault(403, 'Forbidden');

        $doc = simplexml_load_string($xml);
        $this->assertNotFalse($doc);
        $this->assertStringContainsString('Forbidden', $xml);
    }

    // ── Round trip ────────────────────────────────────────────────────────────

    public function testResponseRoundTripsThroughTheParser(): void
    {
        $payload = [
            'post_id'    => 42,
            'post_title' => 'A <title> with & entities',
            'terms'      => ['one', 'two'],
            'sticky'     => false,
        ];

        $xml = XmlRpc::encodeResponse($payload);
        $doc = simplexml_load_string($xml);

        $this->assertNotFalse($doc, 'the response must be well-formed XML');

        // Re-parse the encoded value through the parser's own struct reader.
        $parse = new \ReflectionMethod(XmlRpc::class, 'parseValue');
        $parse->setAccessible(true);
        $back = $parse->invoke(null, $doc->params->param->value);

        $this->assertSame($payload, $back);
    }

    // ── Dates ─────────────────────────────────────────────────────────────────

    public function testParseDateConvertsLocalTimeToUtc(): void
    {
        $this->assertSame(
            '2026-07-29 16:00:00',
            XmlRpc::parseDate('20260729T12:00:00', 'America/New_York')
        );
    }

    public function testParseDateHonoursAnExplicitOffset(): void
    {
        $this->assertSame(
            '2026-07-29 12:00:00',
            XmlRpc::parseDate('2026-07-29T12:00:00Z', 'America/New_York')
        );
    }

    /**
     * The shapes clients actually send.
     *
     * Three of these get their answer from PHP's DateTime constructor rather
     * than from anything here: the compact-format normalisation regex is
     * anchored at the seconds, so it declines anything with a Z or an offset
     * glued on, and the offset check only recognises the +hh:mm spelling, not
     * +hhmm. They parse correctly anyway. Pinned because that is incidental —
     * tightening either regex could break them without touching a line of
     * these tests otherwise.
     *
     * A naked timestamp is site-local on purpose: 'dateCreated' and 'post_date'
     * are defined as blog-local by metaWeblog and WordPress respectively, which
     * is the whole reason the _gmt siblings exist. Nothing in the value says
     * otherwise, so a client that puts UTC there cannot be detected.
     *
     * @dataProvider dateFormatProvider
     */
    public function testParseDateHandlesTheFormatsClientsSend(string $iso, string $expected): void
    {
        $this->assertSame($expected, XmlRpc::parseDate($iso, 'America/New_York'));
    }

    /** @return array<string, array{string, string}> */
    public static function dateFormatProvider(): array
    {
        return [
            // Naked → site-local (New York is UTC-4 in July).
            'compact'                 => ['20260729T12:00:00',        '2026-07-29 16:00:00'],
            'fully compact'           => ['20260729T120000',          '2026-07-29 16:00:00'],
            'extended'                => ['2026-07-29T12:00:00',      '2026-07-29 16:00:00'],
            'space separated'         => ['2026-07-29 12:00:00',      '2026-07-29 16:00:00'],
            // Zone indicator present → honoured, site timezone ignored.
            'compact Z'               => ['20260729T12:00:00Z',       '2026-07-29 12:00:00'],
            'fully compact Z'         => ['20260729T120000Z',         '2026-07-29 12:00:00'],
            'extended Z'              => ['2026-07-29T12:00:00Z',     '2026-07-29 12:00:00'],
            'compact offset colon'    => ['20260729T12:00:00+00:00',  '2026-07-29 12:00:00'],
            'compact offset no colon' => ['20260729T12:00:00+0000',   '2026-07-29 12:00:00'],
            'extended offset no colon' => ['2026-07-29T12:00:00+0000', '2026-07-29 12:00:00'],
        ];
    }

    public function testIsoDateFormatsForMarsEdit(): void
    {
        $this->assertSame('20260729T12:00:00', XmlRpc::isoDate('2026-07-29 12:00:00'));
    }

    // ── Struct dates ──────────────────────────────────────────────────────────
    //
    // The regression these pin: a client that sends only the _gmt field left
    // $pubAt null, so the caller fell back to now and a scheduled post went out
    // immediately. Null must stay reachable, and only when neither key is there.

    public function testStructDateReadsTheGmtFieldAsUtc(): void
    {
        $this->assertSame(
            '2026-07-29 12:00:00',
            XmlRpc::parseStructDate(
                ['date_created_gmt' => '20260729T12:00:00'],
                'dateCreated',
                'date_created_gmt',
                'America/New_York'
            )
        );
    }

    public function testStructDateFallsBackToTheLocalField(): void
    {
        $this->assertSame(
            '2026-07-29 16:00:00',
            XmlRpc::parseStructDate(
                ['dateCreated' => '20260729T12:00:00'],
                'dateCreated',
                'date_created_gmt',
                'America/New_York'
            )
        );
    }

    public function testStructDatePrefersGmtWhenBothArePresent(): void
    {
        // Both carry the same wall-clock time, so the reading is what separates
        // them: as GMT it is 12:00 UTC, as New York local it would be 16:00.
        // (An earlier version used 08:00 local, which is 12:00 UTC — the same
        // answer either way, so it passed no matter which field was read.)
        $this->assertSame(
            '2026-07-29 12:00:00',
            XmlRpc::parseStructDate(
                [
                    'dateCreated'      => '20260729T12:00:00',
                    'date_created_gmt' => '20260729T12:00:00',
                ],
                'dateCreated',
                'date_created_gmt',
                'America/New_York'
            )
        );
    }

    public function testStructDateIsNullWhenNeitherKeyIsPresent(): void
    {
        $this->assertNull(
            XmlRpc::parseStructDate(['title' => 'No date here'], 'post_date', 'post_date_gmt', 'UTC')
        );
    }

    public function testStructDateTreatsAnEmptyValueAsAbsent(): void
    {
        $this->assertNull(
            XmlRpc::parseStructDate(
                ['post_date' => '', 'post_date_gmt' => '   '],
                'post_date',
                'post_date_gmt',
                'UTC'
            )
        );
    }

    public function testStructDateFromGmtStillResolvesToTheFuture(): void
    {
        // The end-to-end shape of the bug: a GMT-only struct scheduled an hour
        // out has to stay in the future, since that comparison is the only
        // thing separating 'scheduled' from 'published' in XmlRpcServer.
        $future = gmdate('Ymd\TH:i:s', time() + 3600);

        $resolved = XmlRpc::parseStructDate(
            ['post_date_gmt' => $future],
            'post_date',
            'post_date_gmt',
            'America/New_York'
        );

        $this->assertNotNull($resolved);
        $this->assertGreaterThan(time(), strtotime($resolved . ' UTC'));
    }

    // ── Build order ───────────────────────────────────────────────────────────

    /**
     * Syndication must never run before the build, on any publish path.
     *
     * Mastodon fetches the permalink to build its preview card exactly once,
     * seconds after the status is created, and never retries a fetch that
     * failed; Bluesky needs the OG image on disk to upload as the card
     * thumbnail. A post created over XML-RPC has neither until the build runs.
     *
     * This is a structural check rather than a behavioural one because the
     * ordering has no observable effect locally — the copies only come out
     * wrong on somebody else's server, days later. It is worth having because
     * the bug has recurred by the same route twice: 1.13.3 fixed micropub.php
     * and Scheduler and missed admin/post-edit.php, then 1.21.1 fixed that and
     * missed all four call sites here. XmlRpcServer builds its own Builder and
     * Syndication, so there is no seam to observe the order through.
     */
    public function testEveryXmlRpcPublishPathBuildsBeforeItSyndicates(): void
    {
        $source = file_get_contents(__DIR__ . '/../src/XmlRpcServer.php');

        $this->assertNotFalse($source);

        // Only the call sites, not the private method that ends in the same
        // name — a leading '$this->' with no 'function' keyword before it.
        preg_match_all('/^\s*\$this->(rebuildPost|syndicatePost)\(/m', $source, $matches);
        $calls = $matches[1];

        $this->assertNotEmpty($calls, 'No publish-path calls found — has the file been restructured?');

        foreach ($calls as $i => $call) {
            if ($call !== 'syndicatePost') {
                continue;
            }

            $this->assertSame(
                'rebuildPost',
                $calls[$i - 1] ?? null,
                'syndicatePost() must be immediately preceded by rebuildPost(); '
                . 'a copy made before the page is on disk links to a 404.'
            );
        }
    }
}
