<?php

declare(strict_types=1);

namespace CMS;

/**
 * SSRF-resistant outbound HTTP.
 *
 * Every request this CMS makes to a URL it did not choose itself — IndieAuth
 * client discovery, webmention discovery and delivery, media re-hosting — goes
 * through here. The guarantees are:
 *
 *  - only http:// and https:// are ever spoken, on the request and on redirects;
 *  - the host must resolve exclusively to public addresses (loopback, RFC1918,
 *    link-local, multicast, IPv6 ULA and reserved blocks are all refused);
 *  - redirects are followed manually so every hop is re-validated, and each
 *    request pins the DNS answer that was validated via CURLOPT_RESOLVE, so a
 *    rebinding race cannot swap the target between check and connect.
 *
 * Callers get back the status, headers and body of the final hop, or null when
 * any hop fails validation or transport.
 */
class SafeHttp
{
    /** Default per-request timeout in seconds. */
    public const DEFAULT_TIMEOUT = 5;

    /** Default cap on redirect hops. */
    public const DEFAULT_MAX_HOPS = 4;

    /**
     * Perform a validated request, following redirects manually.
     *
     * $curlOpts is merged over the safe defaults; the options that enforce the
     * guarantees above (protocols, redirect handling, DNS pinning) are applied
     * afterwards and cannot be overridden.
     *
     * @param array<int,mixed> $curlOpts
     * @return array{status:int, headers:string, body:string, url:string, content_type:string}|null
     */
    public static function request(
        string $url,
        array $curlOpts = [],
        int $maxHops = self::DEFAULT_MAX_HOPS,
        int $timeout = self::DEFAULT_TIMEOUT
    ): ?array {
        for ($hop = 0; $hop < $maxHops; $hop++) {
            $target = self::validateUrl($url);
            if ($target === null) {
                return null;
            }

            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }

            curl_setopt_array($ch, $curlOpts + [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => $timeout,
                CURLOPT_HEADER         => true,
                CURLOPT_USERAGENT      => self::userAgent(),
            ]);

            // Non-negotiable: these win over anything the caller passed.
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RESOLVE        => self::resolveOption($target),
            ]);

            $resp = curl_exec($ch);
            if (!is_string($resp)) {
                curl_close($ch);
                return null;
            }

            $status      = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $headerSize  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $contentType = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
            curl_close($ch);

            $headers = substr($resp, 0, $headerSize);
            $body    = substr($resp, $headerSize);

            if ($status >= 300 && $status < 400) {
                $next = self::redirectTarget($headers, $target);
                if ($next === null) {
                    return null;
                }
                $url = $next;
                continue;
            }

            return [
                'status'       => $status,
                'headers'      => $headers,
                'body'         => $body,
                'url'          => $url,
                'content_type' => $contentType,
            ];
        }

        return null;
    }

    /**
     * Stream a validated GET to an open file handle, following redirects
     * manually. Used for media downloads, where the body must never be held in
     * memory. Returns the final status code, or null on validation/transport
     * failure.
     *
     * @param resource $fp        open, writable stream
     * @param array<int,mixed> $curlOpts
     */
    public static function download(
        string $url,
        $fp,
        array $curlOpts = [],
        int $maxHops = self::DEFAULT_MAX_HOPS,
        int $timeout = 30
    ): ?int {
        for ($hop = 0; $hop < $maxHops; $hop++) {
            $target = self::validateUrl($url);
            if ($target === null) {
                return null;
            }

            // Header-only probe first: a redirect must be resolved before any
            // bytes reach $fp, otherwise a 302 body would be written to the file.
            $probe = self::request($url, [CURLOPT_NOBODY => true], 1, $timeout);
            if ($probe === null) {
                return null;
            }
            if ($probe['status'] >= 300 && $probe['status'] < 400) {
                $next = self::redirectTarget($probe['headers'], $target);
                if ($next === null) {
                    return null;
                }
                $url = $next;
                continue;
            }

            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }

            curl_setopt_array($ch, $curlOpts + [
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT      => self::userAgent(),
            ]);

            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fp,
                CURLOPT_HEADER         => false,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RESOLVE        => self::resolveOption($target),
            ]);

            $ok     = curl_exec($ch);
            $errNo  = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($ok === false || $errNo !== 0) {
                return null;
            }

            return $status;
        }

        return null;
    }

    // ── Validation ────────────────────────────────────────────────────────────

    /**
     * Validate a URL for outbound use and resolve it to a pinnable target.
     * Returns null when the scheme is not http(s) or the host does not resolve
     * exclusively to public addresses.
     *
     * @return array{host:string, port:int, ip:string, scheme:string}|null
     */
    public static function validateUrl(string $url): ?array
    {
        $parts  = parse_url($url);
        if ($parts === false) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $ip = self::resolvePublicIp($host);
        if ($ip === null) {
            return null;
        }

        return [
            'host'      => $host,
            'port'      => (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)),
            'ip'        => $ip,
            'scheme'    => $scheme,
            // An IP-literal host has no DNS answer to pin, so CURLOPT_RESOLVE
            // would add nothing (and its bracket handling for IPv6 varies).
            'is_literal' => filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false,
        ];
    }

    /**
     * The CURLOPT_RESOLVE entries pinning a validated target, or [] when the
     * host is already an IP literal.
     *
     * @param array{host:string, port:int, ip:string, scheme:string, is_literal:bool} $target
     * @return string[]
     */
    private static function resolveOption(array $target): array
    {
        return $target['is_literal']
            ? []
            : ["{$target['host']}:{$target['port']}:{$target['ip']}"];
    }

    /**
     * Resolve a hostname and return one validated public IP to pin the
     * connection to, or null when the host is a literal/resolved loopback,
     * private, link-local, multicast, or reserved address (SSRF guard).
     * Every DNS answer must be public — one bad record rejects the host.
     */
    public static function resolvePublicIp(string $host): ?string
    {
        // parse_url() keeps the brackets on an IPv6 literal host; strip them so
        // the literal is checked as an address rather than falling through to a
        // DNS lookup that would reject public IPv6 targets too.
        $literal = (str_starts_with($host, '[') && str_ends_with($host, ']'))
            ? substr($host, 1, -1)
            : $host;

        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($literal) ? $literal : null;
        }
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return null;
        }

        // Two blocking lookups per call, and bin/send-webmentions.php resolves
        // the same handful of hosts over and over across hundreds of posts.
        // Cached per process only: a long-lived cache would defeat the point of
        // re-resolving on each redirect hop, but within one run the answer is
        // the same and the result is still checked before use.
        if (array_key_exists($host, self::$dnsCache)) {
            return self::$dnsCache[$host];
        }

        $ips = [];
        foreach ((array) @dns_get_record($host, DNS_A) as $r) {
            if (!empty($r['ip'])) $ips[] = (string) $r['ip'];
        }
        foreach ((array) @dns_get_record($host, DNS_AAAA) as $r) {
            if (!empty($r['ipv6'])) $ips[] = (string) $r['ipv6'];
        }
        if ($ips === []) {
            return self::$dnsCache[$host] = null;
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                // Cache the refusal too — a host that resolves inward should not
                // be re-resolved on every mention that points at it.
                return self::$dnsCache[$host] = null;
            }
        }
        return self::$dnsCache[$host] = $ips[0];
    }

    /**
     * Ranges PHP's filter flags treat as public but which are not routable on
     * the open internet — each one can name a host on the same infrastructure
     * as this server.
     *
     * 100.64.0.0/10 matters most: it is carrier-grade NAT, which is also
     * Tailscale's range and the internal network of several VPS providers.
     * 64:ff9b::/96 is NAT64, which embeds an IPv4 address in its low 32 bits
     * and can therefore express 127.0.0.1 as an apparently public IPv6 address.
     */
    /**
     * Per-process host => first public IP (or null when refused).
     * @var array<string, string|null>
     */
    private static array $dnsCache = [];

    private const BLOCKED_CIDRS = [
        '100.64.0.0/10',    // RFC 6598 carrier-grade NAT
        '192.0.0.0/24',     // RFC 6890 IETF protocol assignments
        '192.88.99.0/24',   // RFC 7526 6to4 anycast relay
        '198.18.0.0/15',    // RFC 2544 benchmarking
        '64:ff9b::/96',     // RFC 6052 NAT64
        '64:ff9b:1::/48',   // RFC 8215 local-use NAT64
        '::ffff:0:0/96',    // IPv4-mapped IPv6
    ];

    public static function isPublicIp(string $ip): bool
    {
        // NO_PRIV_RANGE: RFC1918 + IPv6 ULA. NO_RES_RANGE: loopback,
        // link-local, 0.0.0.0/8, 240.0.0.0/4, and IPv6 reserved blocks.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach (self::BLOCKED_CIDRS as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return false;
            }
        }

        // Multicast is not covered by either flag.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $first = (int) explode('.', $ip)[0];
            return $first < 224;
        }
        return stripos($ip, 'ff') !== 0;
    }

    /**
     * True when $ip falls inside $cidr. Compares packed binary representations
     * so the same code covers IPv4 and IPv6; mismatched families never match.
     */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);

        $ipBin     = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits      = (int) $bits;
        $wholeBytes = intdiv($bits, 8);
        $restBits   = $bits % 8;

        if ($wholeBytes > 0 && strncmp($ipBin, $subnetBin, $wholeBytes) !== 0) {
            return false;
        }
        if ($restBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $restBits)) - 1) & 0xFF;
        return (ord($ipBin[$wholeBytes]) & $mask) === (ord($subnetBin[$wholeBytes]) & $mask);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Absolute URL of the next hop from a redirect response, or null when the
     * Location header is missing. Relative values resolve against the origin
     * that produced them.
     *
     * @param array{host:string, port:int, ip:string, scheme:string} $from
     */
    private static function redirectTarget(string $headers, array $from): ?string
    {
        if (!preg_match('/^Location:\s*(\S+)/mi', $headers, $m)) {
            return null;
        }

        $next = trim($m[1]);
        if (str_starts_with($next, '/')) {
            $defaultPort = $from['scheme'] === 'https' ? 443 : 80;
            $origin      = $from['scheme'] . '://' . $from['host']
                         . ($from['port'] !== $defaultPort ? ':' . $from['port'] : '');
            $next        = $origin . $next;
        }

        return $next;
    }

    private static function userAgent(): string
    {
        return 'clodd-cms/' . (defined('CMS_VERSION') ? CMS_VERSION : 'dev')
             . ' (+https://github.com/jimmitchell/clodd-cms)';
    }
}
