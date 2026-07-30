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

        // SafeHttp enforces the SSRF guard: http(s) only, public addresses only,
        // every redirect hop re-validated against a pinned DNS answer.
        $resp = SafeHttp::request($clientId, [CURLOPT_MAXFILESIZE => 1_048_576], 4, $timeout);
        if ($resp === null) {
            return $info;
        }

        return self::parseClientResponse($resp['headers'], $resp['body'], $resp['content_type']);
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
        // An h-app's p-name is the name the client actually chose to present,
        // so it wins over <title> (which on a CMS-rendered page carries the
        // site name too). Falls back to <title> when absent or empty.
        if ($appName = self::parseHAppName($body)) {
            $info['name'] = $appName;
        }

        $info['redirect_uris'] = array_values(array_unique($info['redirect_uris']));
        return $info;
    }

    /**
     * Extract the display name from an h-app on the client_id page
     * (microformats2). Best-effort and regex-based like the rest of this
     * parser; returns '' when there is no usable name.
     */
    private static function parseHAppName(string $body): string
    {
        $class = fn(string $name): string => '\bclass=["\'][^"\']*\b' . $name . '\b[^"\']*["\']';

        if (!preg_match('/<([a-z0-9]+)\b[^>]*' . $class('h-app') . '[^>]*>/is', $body, $open, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $tag   = strtolower($open[1][0]);
        $start = (int) $open[0][1] + strlen($open[0][0]);

        // The name may sit on the h-app element itself (<a class="h-app p-name">).
        $scope = preg_match('/' . $class('p-name') . '/i', $open[0][0])
            ? substr($body, $start)
            : null;

        if ($scope === null) {
            // Otherwise look inside it. Take the element's own content when the
            // closing tag is findable, else fall back to the rest of the body.
            $scope = preg_match('/<\/' . preg_quote($tag, '/') . '\s*>/is', $body, $close, PREG_OFFSET_CAPTURE, $start)
                ? substr($body, $start, (int) $close[0][1] - $start)
                : substr($body, $start);

            if (!preg_match('/<([a-z0-9]+)\b[^>]*' . $class('p-name') . '[^>]*>(.*?)<\/\1\s*>/is', $scope, $m)) {
                return '';
            }
            $scope = $m[2];
        } elseif (preg_match('/^(.*?)<\/' . preg_quote($tag, '/') . '\s*>/is', $scope, $m)) {
            $scope = $m[1];
        }

        return trim(html_entity_decode(strip_tags($scope), ENT_QUOTES, 'UTF-8'));
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
