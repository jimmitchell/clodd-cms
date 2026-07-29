<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\SafeHttp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SafeHttp is the SSRF boundary for every outbound request the CMS makes to a
 * URL it did not choose. These tests pin the address and scheme policy; the
 * redirect loop is covered by asserting each hop is validated the same way.
 */
final class SafeHttpTest extends TestCase
{
    // ── Address policy ────────────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function nonPublicIps(): array
    {
        return [
            'IPv4 loopback'        => ['127.0.0.1'],
            'IPv4 loopback range'  => ['127.99.4.2'],
            'link-local / metadata' => ['169.254.169.254'],
            'RFC1918 10/8'         => ['10.0.0.5'],
            'RFC1918 172.16/12'    => ['172.16.0.1'],
            'RFC1918 192.168/16'   => ['192.168.1.1'],
            'this-network 0/8'     => ['0.0.0.0'],
            'multicast low'        => ['224.0.0.1'],
            'multicast high'       => ['239.255.255.250'],
            'reserved 240/4'       => ['240.0.0.1'],
            'IPv6 loopback'        => ['::1'],
            'IPv6 ULA'             => ['fd00::1'],
            'IPv6 link-local'      => ['fe80::1'],
            'IPv6 multicast'       => ['ff02::1'],
        ];
    }

    #[DataProvider('nonPublicIps')]
    public function testRejectsNonPublicAddresses(string $ip): void
    {
        $this->assertFalse(
            SafeHttp::isPublicIp($ip),
            "{$ip} must not be treated as a public address"
        );
    }

    /** @return array<string, array{string}> */
    public static function publicIps(): array
    {
        return [
            'cloudflare v4' => ['1.1.1.1'],
            'google v4'     => ['8.8.8.8'],
            'example.com'   => ['93.184.216.34'],
            'just below multicast' => ['223.255.255.255'],
            'cloudflare v6' => ['2606:4700:4700::1111'],
        ];
    }

    #[DataProvider('publicIps')]
    public function testAcceptsPublicAddresses(string $ip): void
    {
        $this->assertTrue(
            SafeHttp::isPublicIp($ip),
            "{$ip} is public and must not be blocked"
        );
    }

    // ── URL policy ────────────────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function blockedUrls(): array
    {
        return [
            'loopback with port'   => ['http://127.0.0.1:8080/admin/'],
            'localhost by name'    => ['http://localhost/x'],
            'cloud metadata'       => ['http://169.254.169.254/latest/meta-data/'],
            'private v4'           => ['http://10.0.0.5/'],
            'bracketed IPv6 loopback' => ['http://[::1]/'],
            'bracketed IPv6 ULA'   => ['http://[fd00::1]/'],
            '.internal suffix'     => ['http://redis.internal/'],
            '.local suffix'        => ['http://box.local/'],
            'file scheme'          => ['file:///etc/passwd'],
            'gopher scheme'        => ['gopher://evil/'],
            'ftp scheme'           => ['ftp://evil/'],
            'no scheme'            => ['//evil.example/'],
            'empty'                => [''],
        ];
    }

    #[DataProvider('blockedUrls')]
    public function testValidateUrlRejects(string $url): void
    {
        $this->assertNull(
            SafeHttp::validateUrl($url),
            "{$url} must be refused before any connection is attempted"
        );
    }

    public function testValidateUrlAcceptsPublicIpLiteral(): void
    {
        $target = SafeHttp::validateUrl('http://1.1.1.1:8080/path');

        $this->assertNotNull($target);
        $this->assertSame('1.1.1.1', $target['ip']);
        $this->assertSame(8080, $target['port']);
        $this->assertSame('http', $target['scheme']);
        $this->assertTrue($target['is_literal'], 'an IP-literal host needs no DNS pinning');
    }

    public function testValidateUrlAcceptsPublicIpv6Literal(): void
    {
        // Brackets must be stripped before the address check, or a public IPv6
        // literal falls through to a DNS lookup and is wrongly refused.
        $target = SafeHttp::validateUrl('http://[2606:4700:4700::1111]/');

        $this->assertNotNull($target);
        $this->assertSame('2606:4700:4700::1111', $target['ip']);
        $this->assertSame(80, $target['port']);
    }

    public function testDefaultPortsPerScheme(): void
    {
        $this->assertSame(80, SafeHttp::validateUrl('http://1.1.1.1/')['port']);
        $this->assertSame(443, SafeHttp::validateUrl('https://1.1.1.1/')['port']);
    }

    // ── Redirect handling ─────────────────────────────────────────────────────

    /**
     * A redirect target is resolved but never trusted: the loop re-validates it
     * on the next hop, which is what stops the classic "public host 302s to
     * 127.0.0.1" bypass.
     */
    public function testRedirectTargetsAreThemselvesValidated(): void
    {
        $redirectTarget = new \ReflectionMethod(SafeHttp::class, 'redirectTarget');
        $redirectTarget->setAccessible(true);

        $from = [
            'host' => 'example.com', 'port' => 80, 'ip' => '93.184.216.34',
            'scheme' => 'http', 'is_literal' => false,
        ];

        foreach (['http://127.0.0.1:9/internal', 'http://169.254.169.254/meta'] as $hostile) {
            $next = $redirectTarget->invoke(
                null,
                "HTTP/1.1 302 Found\r\nLocation: {$hostile}\r\n\r\n",
                $from
            );

            $this->assertSame($hostile, $next, 'Location should be extracted verbatim');
            $this->assertNull(
                SafeHttp::validateUrl($next),
                'the extracted hop must be refused when re-validated'
            );
        }
    }

    public function testRelativeRedirectResolvesAgainstOrigin(): void
    {
        $redirectTarget = new \ReflectionMethod(SafeHttp::class, 'redirectTarget');
        $redirectTarget->setAccessible(true);

        $next = $redirectTarget->invoke(
            null,
            "HTTP/1.1 301 Moved\r\nLocation: /elsewhere\r\n\r\n",
            ['host' => 'example.com', 'port' => 80, 'ip' => '93.184.216.34',
             'scheme' => 'http', 'is_literal' => false]
        );

        $this->assertSame('http://example.com/elsewhere', $next);
    }

    public function testNonDefaultPortIsKeptWhenResolvingRelativeRedirect(): void
    {
        $redirectTarget = new \ReflectionMethod(SafeHttp::class, 'redirectTarget');
        $redirectTarget->setAccessible(true);

        $next = $redirectTarget->invoke(
            null,
            "HTTP/1.1 301 Moved\r\nLocation: /x\r\n\r\n",
            ['host' => 'example.com', 'port' => 8443, 'ip' => '93.184.216.34',
             'scheme' => 'https', 'is_literal' => false]
        );

        $this->assertSame('https://example.com:8443/x', $next);
    }

    public function testMissingLocationHeaderEndsTheChain(): void
    {
        $redirectTarget = new \ReflectionMethod(SafeHttp::class, 'redirectTarget');
        $redirectTarget->setAccessible(true);

        $next = $redirectTarget->invoke(
            null,
            "HTTP/1.1 302 Found\r\nContent-Type: text/html\r\n\r\n",
            ['host' => 'example.com', 'port' => 80, 'ip' => '93.184.216.34',
             'scheme' => 'http', 'is_literal' => false]
        );

        $this->assertNull($next);
    }

    /** A blocked URL must fail without opening a connection. */
    public function testRequestReturnsNullForBlockedUrl(): void
    {
        $this->assertNull(SafeHttp::request('http://127.0.0.1:1/'));
        $this->assertNull(SafeHttp::request('file:///etc/passwd'));
    }
}
