<?php

declare(strict_types=1);

namespace CMS;

class Webmention
{
    /**
     * Extract all external HTTP(S) links from an HTML string.
     * Filters out same-site URLs and non-HTTP(S) schemes.
     *
     * @return string[]
     */
    public static function extractUrls(string $html, string $siteUrl): array
    {
        if ($html === '') {
            return [];
        }

        $siteBase = rtrim($siteUrl, '/');

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query('//a[@href]');

        $urls = [];
        foreach ($nodes as $node) {
            $href = trim($node->getAttribute('href'));

            // Only HTTP(S) URLs.
            if (!preg_match('/^https?:\/\//i', $href)) {
                continue;
            }

            // Skip same-site links.
            if ($siteBase !== '' && str_starts_with($href, $siteBase)) {
                continue;
            }

            $urls[] = $href;
        }

        return array_values(array_unique($urls));
    }

    /**
     * Discover the webmention endpoint for a target URL.
     * First checks HTTP Link response headers, then parses the HTML body.
     * Returns the absolute endpoint URL, or null if none found.
     */
    public static function discoverEndpoint(string $targetUrl, int $timeout = 5): ?string
    {
        // Step 1: HEAD request — check Link response headers.
        $headers = self::fetchHeaders($targetUrl, $timeout);
        if ($headers !== null) {
            $endpoint = self::parseLinkHeader($headers, 'webmention');
            if ($endpoint !== null) {
                return self::resolveUrl($endpoint, $targetUrl);
            }
        }

        // Step 2: GET request — parse HTML for <link> or <a> with rel="webmention".
        $html = self::fetchBody($targetUrl, $timeout);
        if ($html === null) {
            return null;
        }

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);

        // <link rel="webmention" href="..."> or <link rel="...webmention..." href="...">
        $nodes = $xpath->query(
            '//*[contains(concat(" ", normalize-space(@rel), " "), " webmention ")][@href]'
        );

        foreach ($nodes as $node) {
            $href = trim($node->getAttribute('href'));
            if ($href !== '') {
                return self::resolveUrl($href, $targetUrl);
            }
        }

        return null;
    }

    /**
     * Send a webmention ping from $source to $target via $endpoint.
     * Returns true on any 2xx HTTP response.
     */
    public static function sendPing(string $source, string $target, string $endpoint, int $timeout = 5): bool
    {
        // The endpoint comes from the *target's* Link header or rel=webmention,
        // so it is fully attacker-controlled. SafeHttp is what stops a hostile
        // page from pointing this POST at an internal service.
        $resp = SafeHttp::request(
            $endpoint,
            [
                CURLOPT_POST       => true,
                CURLOPT_POSTFIELDS => http_build_query(['source' => $source, 'target' => $target]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ],
            5,
            $timeout
        );

        return $resp !== null && $resp['status'] >= 200 && $resp['status'] < 300;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /** Perform a HEAD request and return the raw response headers string, or null on error. */
    private static function fetchHeaders(string $url, int $timeout): ?string
    {
        $resp = SafeHttp::request($url, [CURLOPT_NOBODY => true], 5, $timeout);

        return $resp['headers'] ?? null;
    }

    /** Perform a GET request and return the response body, or null on error. */
    private static function fetchBody(string $url, int $timeout): ?string
    {
        $resp = SafeHttp::request($url, [CURLOPT_MAXFILESIZE => 2_097_152], 5, $timeout);

        return $resp['body'] ?? null;
    }

    /**
     * Parse a raw HTTP header blob for a Link header with the given rel type.
     * Returns the URL value, or null if not found.
     */
    private static function parseLinkHeader(string $headers, string $rel): ?string
    {
        // Match all "Link: <url>; rel=..." lines (case-insensitive).
        preg_match_all('/^Link:\s*(.+)$/im', $headers, $matches);

        foreach ($matches[1] as $value) {
            // Split on comma for multiple links in one header.
            foreach (explode(',', $value) as $part) {
                $part = trim($part);
                // Extract URL from <...>.
                if (!preg_match('/<([^>]*)>/', $part, $urlMatch)) {
                    continue;
                }
                // Check rel attribute.
                if (preg_match('/\brel\s*=\s*["\']?([^"\';\s,]+)["\']?/i', $part, $relMatch)) {
                    $rels = preg_split('/\s+/', strtolower(trim($relMatch[1])));
                    if (in_array(strtolower($rel), $rels, true)) {
                        return $urlMatch[1];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Resolve a possibly-relative $url against a $base URL.
     * Handles //-relative, /-absolute, and path-relative forms.
     */
    private static function resolveUrl(string $url, string $base): string
    {
        // Already absolute.
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        $parts = parse_url($base);
        $scheme = ($parts['scheme'] ?? 'https') . '://';
        $host   = $parts['host'] ?? '';
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';

        // Protocol-relative.
        if (str_starts_with($url, '//')) {
            return $scheme . ltrim($url, '/');
        }

        // Root-relative.
        if (str_starts_with($url, '/')) {
            return $scheme . $host . $port . $url;
        }

        // Path-relative: resolve against the base path directory.
        $basePath = isset($parts['path']) ? dirname($parts['path']) : '/';
        return $scheme . $host . $port . rtrim($basePath, '/') . '/' . $url;
    }
}
