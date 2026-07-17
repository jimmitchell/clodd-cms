<?php

declare(strict_types=1);

namespace CMS;

/**
 * Self-hosted IndieAuth server storage: authorization codes, bearer tokens,
 * and PKCE verification (S256 only; codes issued without a challenge, for
 * legacy clients, must be redeemed without a verifier).
 *
 * Codes and tokens are stored as sha256 hashes; the plaintext is returned to
 * the caller once and never persisted. Codes are single-use with a 10-minute
 * TTL. Tokens do not expire by default — revocation (admin UI or the token
 * endpoint) is the kill switch.
 */
class IndieAuth
{
    public const SCOPES = ['profile', 'create', 'update', 'delete', 'media'];

    private const CODE_TTL_SECONDS = 600;

    public function __construct(private Database $db)
    {
    }

    // ── Authorization codes ───────────────────────────────────────────────────

    /**
     * Store a new authorization code and return its plaintext.
     */
    public function createCode(
        string $clientId,
        string $clientName,
        string $redirectUri,
        string $me,
        string $scope,
        string $codeChallenge,
        string $codeChallengeMethod
    ): string {
        $code = self::newSecret();
        $this->db->insert('indieauth_codes', [
            'code_hash'             => hash('sha256', $code),
            'client_id'             => $clientId,
            'client_name'           => $clientName,
            'redirect_uri'          => $redirectUri,
            'me'                    => $me,
            'scope'                 => $scope,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
            // gmdate, not date: expiry is compared against SQLite's UTC
            // datetime('now'), so a non-UTC PHP timezone must not skew it.
            'expires_at'            => gmdate('Y-m-d H:i:s', time() + self::CODE_TTL_SECONDS),
        ]);
        return $code;
    }

    /**
     * Redeem an authorization code. Returns the code row (client_id, me, scope)
     * or null when the code is unknown, expired, already used, or fails the
     * client_id / redirect_uri / PKCE checks. Single-use is enforced atomically,
     * so a replayed code loses the race.
     */
    public function redeemCode(string $code, string $clientId, string $redirectUri, string $codeVerifier): ?array
    {
        if ($code === '') {
            return null;
        }
        $row = $this->db->selectOne(
            "SELECT * FROM indieauth_codes
              WHERE code_hash = :h AND expires_at > datetime('now')",
            ['h' => hash('sha256', $code)]
        );
        if (!$row) {
            return null;
        }
        if (
            !hash_equals((string) $row['client_id'], $clientId)
            || !hash_equals((string) $row['redirect_uri'], $redirectUri)
        ) {
            return null;
        }
        if ($row['code_challenge'] === '') {
            // Downgrade guard: a verifier for a challenge-less code means the
            // authorization and token requests disagree about PKCE — reject.
            if ($codeVerifier !== '') {
                return null;
            }
        } elseif ($codeVerifier === '' || !self::verifyPkce($codeVerifier, (string) $row['code_challenge'], (string) $row['code_challenge_method'])) {
            return null;
        }

        $claimed = $this->db->update(
            'indieauth_codes',
            ['used_at' => gmdate('Y-m-d H:i:s')],
            'id = :id AND used_at IS NULL',
            ['id' => $row['id']]
        );
        return $claimed > 0 ? $row : null;
    }

    /** Remove expired and already-redeemed codes. */
    public function purgeExpiredCodes(): void
    {
        $this->db->delete('indieauth_codes', "expires_at <= datetime('now') OR used_at IS NOT NULL");
    }

    // ── Access tokens ─────────────────────────────────────────────────────────

    /**
     * Issue a bearer token and return its plaintext.
     */
    public function issueToken(string $clientId, string $clientName, string $me, string $scope): string
    {
        $token = self::newSecret();
        $this->db->insert('indieauth_tokens', [
            'token_hash'  => hash('sha256', $token),
            'client_id'   => $clientId,
            'client_name' => $clientName,
            'me'          => $me,
            'scope'       => $scope,
        ]);
        return $token;
    }

    /**
     * Return the active token row for a plaintext bearer token (and bump
     * last_used_at), or null when unknown, revoked, or expired.
     */
    public function verifyToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $row = $this->db->selectOne(
            "SELECT * FROM indieauth_tokens
              WHERE token_hash = :h
                AND revoked_at IS NULL
                AND (expires_at IS NULL OR expires_at > datetime('now'))",
            ['h' => hash('sha256', $token)]
        );
        if (!$row) {
            return null;
        }
        $this->db->update('indieauth_tokens', ['last_used_at' => gmdate('Y-m-d H:i:s')], 'id = :id', ['id' => $row['id']]);
        return $row;
    }

    public function revokeToken(string $token): bool
    {
        return $this->db->update(
            'indieauth_tokens',
            ['revoked_at' => gmdate('Y-m-d H:i:s')],
            'token_hash = :h AND revoked_at IS NULL',
            ['h' => hash('sha256', $token)]
        ) > 0;
    }

    public function revokeTokenById(int $id): bool
    {
        return $this->db->update(
            'indieauth_tokens',
            ['revoked_at' => gmdate('Y-m-d H:i:s')],
            'id = :id AND revoked_at IS NULL',
            ['id' => $id]
        ) > 0;
    }

    /**
     * Active tokens for the admin UI, newest first.
     *
     * @return array<array<string,mixed>>
     */
    public function listTokens(): array
    {
        return $this->db->select(
            "SELECT id, client_id, client_name, me, scope, created_at, last_used_at
               FROM indieauth_tokens
              WHERE revoked_at IS NULL
                AND (expires_at IS NULL OR expires_at > datetime('now'))
              ORDER BY created_at DESC"
        );
    }

    // ── Client discovery ──────────────────────────────────────────────────────

    /**
     * Fetch client metadata for the consent screen: display name and
     * registered redirect URIs (JSON client metadata `client_name` /
     * `redirect_uris`, HTML `rel=redirect_uri` links / <title>, and
     * `Link: rel="redirect_uri"` headers). Best-effort — returns empty
     * values on any failure.
     *
     * @return array{name: string, redirect_uris: string[]}
     */
    public static function fetchClientInfo(string $clientId, int $timeout = 5): array
    {
        $info = ['name' => '', 'redirect_uris' => []];
        $url  = $clientId;

        // SSRF guard: redirects are followed manually so every hop is
        // re-validated, and each request pins the DNS answer we validated via
        // CURLOPT_RESOLVE so a rebinding race cannot swap the target.
        for ($hop = 0; $hop < 4; $hop++) {
            $parts  = parse_url($url);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host   = strtolower((string) ($parts['host'] ?? ''));
            if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
                return $info;
            }
            $ip = self::resolvePublicIp($host);
            if ($ip === null) {
                return $info;
            }
            $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_HEADER         => true,
                CURLOPT_MAXFILESIZE    => 1_048_576,
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_RESOLVE        => ["{$host}:{$port}:{$ip}"],
                CURLOPT_USERAGENT      => 'Clodd-CMS IndieAuth (+https://github.com/jimmitchell/clodd-cms)',
            ]);
            $resp = curl_exec($ch);
            if (!is_string($resp)) {
                curl_close($ch);
                return $info;
            }
            $status      = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $headerSize  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $contentType = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
            curl_close($ch);

            $headers = substr($resp, 0, $headerSize);
            $body    = substr($resp, $headerSize);

            if ($status >= 300 && $status < 400) {
                if (!preg_match('/^Location:\s*(\S+)/mi', $headers, $m)) {
                    return $info;
                }
                $next = trim($m[1]);
                // Resolve a relative redirect against the current URL's origin.
                if (str_starts_with($next, '/')) {
                    $origin = $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
                    $next   = $origin . $next;
                }
                $url = $next;
                continue;
            }

            return self::parseClientResponse($headers, $body, $contentType);
        }

        return $info;
    }

    /** @return array{name: string, redirect_uris: string[]} */
    private static function parseClientResponse(string $headers, string $body, string $contentType): array
    {
        $info = ['name' => '', 'redirect_uris' => []];

        if (preg_match_all('/^Link:\s*<([^>]+)>\s*;\s*rel="?redirect_uri"?/mi', $headers, $m)) {
            $info['redirect_uris'] = array_merge($info['redirect_uris'], $m[1]);
        }

        if (str_contains($contentType, 'application/json')) {
            $json = json_decode($body, true);
            if (is_array($json)) {
                $info['name'] = is_string($json['client_name'] ?? null) ? trim($json['client_name']) : '';
                foreach ((array) ($json['redirect_uris'] ?? []) as $uri) {
                    if (is_string($uri)) {
                        $info['redirect_uris'][] = $uri;
                    }
                }
            }
            return $info;
        }

        if (preg_match_all('/<(?:link|a)\b[^>]*rel=["\']?redirect_uri["\']?[^>]*href=["\']([^"\']+)["\']/i', $body, $m)) {
            $info['redirect_uris'] = array_merge($info['redirect_uris'], $m[1]);
        }
        if (preg_match_all('/<(?:link|a)\b[^>]*href=["\']([^"\']+)["\'][^>]*rel=["\']?redirect_uri["\']?/i', $body, $m)) {
            $info['redirect_uris'] = array_merge($info['redirect_uris'], $m[1]);
        }
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $m)) {
            $info['name'] = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
        }

        $info['redirect_uris'] = array_values(array_unique($info['redirect_uris']));
        return $info;
    }

    /**
     * Resolve a hostname and return one validated public IP to pin the
     * connection to, or null when the host is a literal/resolved loopback,
     * private, link-local, multicast, or reserved address (SSRF guard).
     * Every DNS answer must be public — one bad record rejects the host.
     */
    private static function resolvePublicIp(string $host): ?string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($host) ? $host : null;
        }
        if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return null;
        }

        $ips = [];
        foreach ((array) @dns_get_record($host, DNS_A) as $r) {
            if (!empty($r['ip'])) $ips[] = (string) $r['ip'];
        }
        foreach ((array) @dns_get_record($host, DNS_AAAA) as $r) {
            if (!empty($r['ipv6'])) $ips[] = (string) $r['ipv6'];
        }
        if ($ips === []) {
            return null;
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return null;
            }
        }
        return $ips[0];
    }

    private static function isPublicIp(string $ip): bool
    {
        // NO_PRIV_RANGE: RFC1918 + IPv6 ULA. NO_RES_RANGE: loopback,
        // link-local, 0.0.0.0/8, 240.0.0.0/4, and IPv6 reserved blocks.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        // Multicast is not covered by either flag.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $first = (int) explode('.', $ip)[0];
            return $first < 224;
        }
        return stripos($ip, 'ff') !== 0;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function verifyPkce(string $verifier, string $challenge, string $method): bool
    {
        if (strtoupper($method) !== 'S256') {
            return false;
        }
        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return hash_equals($challenge, $computed);
    }

    /**
     * Reduce a space-separated scope string to the known scopes, in order.
     *
     * @return string[]
     */
    public static function filterScopes(string $scope): array
    {
        $requested = array_filter(explode(' ', trim($scope)), fn($s) => $s !== '');
        return array_values(array_intersect($requested, self::SCOPES));
    }

    private static function newSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
