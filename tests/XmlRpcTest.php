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

    public function testIsoDateFormatsForMarsEdit(): void
    {
        $this->assertSame('20260729T12:00:00', XmlRpc::isoDate('2026-07-29 12:00:00'));
    }
}
