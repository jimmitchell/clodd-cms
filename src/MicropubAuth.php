<?php

declare(strict_types=1);

namespace CMS;

/**
 * Shared bearer-token authentication for the Micropub and media endpoints.
 *
 * Two token sources are accepted:
 *  - the legacy shared token (Settings → Micropub) — grants every scope
 *  - IndieAuth tokens issued by token.php — grant the scopes approved at consent
 */
class MicropubAuth
{
    // ── JSON response emitters (shared error shape across endpoints) ──────────

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $code, string $description = '', int $status = 400): never
    {
        $payload = ['error' => $code];
        if ($description !== '') {
            $payload['error_description'] = $description;
        }
        self::json($payload, $status);
    }

    // ── Token extraction ──────────────────────────────────────────────────────

    /**
     * Extract the bearer token from the Authorization header or the form body.
     * Errors 400 when both are supplied (RFC 6750 §2: one method per request).
     */
    public static function extractBearerToken(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header  = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        $headerToken = '';
        if ($header !== '' && preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
            $headerToken = trim($m[1]);
        }

        $bodyToken = (!empty($_POST['access_token']) && is_string($_POST['access_token']))
            ? $_POST['access_token']
            : '';

        if ($headerToken !== '' && $bodyToken !== '') {
            self::error('invalid_request', 'access token supplied in both Authorization header and body');
        }

        return $headerToken !== '' ? $headerToken : $bodyToken;
    }

    // ── Authentication + scope enforcement ────────────────────────────────────

    /**
     * Authenticate the request and return the authorization context.
     * Responds 401 / 429 directly on failure.
     *
     * @return array{scopes: string[], client_id: ?string, me: string}
     */
    public static function authenticate(Database $db, array $config): array
    {
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $max = (int) ($config['security']['max_login_attempts'] ?? 5);
        $win = (int) ($config['security']['lockout_minutes'] ?? 15);

        $row = $db->selectOne(
            "SELECT COUNT(*) AS cnt
               FROM login_attempts
              WHERE ip      = :ip
                AND success = 0
                AND attempted_at >= datetime('now', :w)",
            ['ip' => $ip, 'w' => "-{$win} minutes"]
        );

        if (($row['cnt'] ?? 0) >= $max) {
            self::error('rate_limited', 'Too many failed attempts. Try again later.', 429);
        }

        $token = self::extractBearerToken();
        if ($token === '') {
            header('WWW-Authenticate: Bearer realm="Micropub"');
            self::error('unauthorized', 'Missing access token', 401);
        }

        $siteUrl = rtrim($db->getSetting('site_url', ''), '/');
        $me      = $siteUrl !== '' ? $siteUrl . '/' : '';

        // Legacy shared token: full scopes.
        $stored = $db->getSetting('micropub_token', '');
        if ($stored !== '' && hash_equals($stored, $token)) {
            return ['scopes' => IndieAuth::SCOPES, 'client_id' => null, 'me' => $me];
        }

        // IndieAuth token: the scopes granted at consent.
        $tokenRow = (new IndieAuth($db))->verifyToken($token);
        if ($tokenRow) {
            return [
                'scopes'    => array_values(array_filter(explode(' ', (string) $tokenRow['scope']), fn($s) => $s !== '')),
                'client_id' => (string) $tokenRow['client_id'],
                'me'        => (string) $tokenRow['me'],
            ];
        }

        $db->insert('login_attempts', ['ip' => $ip, 'success' => 0]);
        header('WWW-Authenticate: Bearer realm="Micropub", error="invalid_token"');
        self::error('unauthorized', 'Invalid access token', 401);
    }

    /**
     * Require at least one of the given scopes; responds 403 insufficient_scope
     * otherwise.
     *
     * @param array{scopes: string[]} $authz
     */
    public static function requireScope(array $authz, string ...$anyOf): void
    {
        foreach ($anyOf as $scope) {
            if (in_array($scope, $authz['scopes'], true)) {
                return;
            }
        }
        self::error('insufficient_scope', 'token lacks required scope: ' . implode(' or ', $anyOf), 403);
    }
}
