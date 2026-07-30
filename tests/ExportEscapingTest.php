<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Helpers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CDATA escaping for the WXR export.
 *
 * A CDATA section ends at the first "]]>", so content containing that sequence
 * used to terminate it early and corrupt the export. Every payload here must
 * survive a real XML parse and come back byte-identical.
 */
final class ExportEscapingTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function payloads(): array
    {
        return [
            'plain'                 => ['plain text'],
            'cdata terminator'      => [']]>'],
            'terminator in prose'   => ['code with ]]> inside'],
            'repeated terminators'  => ['a ]]> b ]]> c'],
            'ampersand and tags'    => ['Tom & Jerry <b>bold</b>'],
            'injection attempt'     => [']]><script>alert(1)</script><![CDATA['],
            'raw html attribute'    => ['<div data-x="a ]]> b">x</div>'],
            'unicode'               => ['Café — 日本語 — emoji 🎉'],
            'newlines'              => ["line one\nline two\nline three"],
            'tabs'                  => ["col1\tcol2"],
            'empty'                 => [''],
        ];
    }

    #[DataProvider('payloads')]
    public function testCdataRoundTripsThroughARealXmlParse(string $payload): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><r><v>'
             . Helpers::cdata($payload)
             . '</v></r>';

        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();

        $this->assertNotFalse($doc, 'the document must remain well-formed');
        $this->assertSame($payload, (string) $doc->v, 'content must survive unchanged');
    }

    public function testTerminatorIsSplitAcrossTwoSections(): void
    {
        $this->assertSame(
            '<![CDATA[a ]]]]><![CDATA[> b]]>',
            Helpers::cdata('a ]]> b')
        );
    }

    public function testContentIsNotHtmlEscaped(): void
    {
        // Escaping inside CDATA is not merely redundant, it is wrong: the
        // entity would be taken literally and "&" would round-trip as "&amp;".
        $this->assertSame('<![CDATA[Tom & Jerry]]>', Helpers::cdata('Tom & Jerry'));
    }

    public function testIllegalXmlControlCharactersAreStripped(): void
    {
        // No escape exists for these in XML 1.0, so they are dropped rather than
        // left to make the document unparseable.
        $this->assertSame(
            '<![CDATA[abc]]>',
            Helpers::cdata("a\x00b\x0B\x1Fc")
        );
    }

    public function testLegalWhitespaceControlCharactersAreKept(): void
    {
        // Tab, LF and CR are valid XML 1.0 characters and carry meaning in a
        // post body, so cdata() must not strip them.
        $this->assertSame(
            "<![CDATA[a\tb\nc\rd]]>",
            Helpers::cdata("a\tb\nc\rd")
        );
    }

    /**
     * CRLF becomes LF on the way back, and that is XML, not us: parsers perform
     * line-ending normalisation (XML 1.0 §2.11) before the application sees the
     * text, and entities are not parsed inside CDATA so there is no way to
     * escape a CR. WordPress's own WXR round-trips the same way. Pinned here so
     * the behaviour is a documented property rather than a surprise.
     */
    public function testCarriageReturnsAreNormalisedByTheParserNotByUs(): void
    {
        $this->assertStringContainsString(
            "\r",
            Helpers::cdata("a\r\nb"),
            'cdata() itself preserves the CR'
        );

        $doc = simplexml_load_string(
            '<?xml version="1.0" encoding="UTF-8"?><r><v>' . Helpers::cdata("a\r\nb") . '</v></r>'
        );

        $this->assertSame("a\nb", (string) $doc->v, 'the parser normalises CRLF to LF');
    }

    public function testNullIsTreatedAsEmpty(): void
    {
        $this->assertSame('<![CDATA[]]>', Helpers::cdata(null));
    }
}
