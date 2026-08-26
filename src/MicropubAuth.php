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
    /**
     * Marks a stored legacy token as a digest rather than the token itself.
     *
     * The legacy token grants every scope and used to sit in the settings table
     * verbatim, so anything that could read the database — a backup, a snapshot,
     * the settings API — yielded a working full-scope publishing credential.
     * IndieAuth codes and tokens were already hashed; this brings the shared
     * token in line. The prefix makes a stored value self-describing, so an
     * un-migrated plaintext row is recognisable and refused rather than silently
     * compared as if it were a digest.
     */
    public const LEGACY_TOKEN_PREFIX = 'sha256:';

    /** Storage form of a legacy shared token. */
    public static function hashLegacyToken(string $token): string
    {
        return self::LEGACY_TOKEN_PREFIX . hash('sha256', $token);
    }

    /**
     * Constant-time check of a presented token against the stored digest.
     * A plain SHA-256 is right here: the token is 256 bits of CSPRNG output,
     * so there is no guessable input for a slow KDF to protect.
     */
    public static function legacyTokenMatches(string $stored, string $presented): bool
    {
        if ($stored === '' || $presented === '' || !str_starts_with($stored, self::LEGACY_TOKEN_PREFIX)) {
            return false;
        }

        return hash_equals($stored, self::hashLegacyToken($presented));
    }

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
    private static function extractBearerToken(): string
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

        // Strict per RFC 6750 §2 and required by micropub.rocks test 805.
        // Known casualty: their test 802 sends the header alongside the body
        // token by mistake (micropub.rocks issues #103/#124) and cannot pass.
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
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Scoped to 'micropub' so a client looping on a stale bearer token
        // cannot lock the owner out of the admin login from the same IP.
        if (Auth::isLockedOutIn($db, $config, $ip, Auth::SCOPE_MICROPUB)) {
            self::error('rate_limited', 'Too many failed attempts. Try again later.', 429);
        }

        $token = self::extractBearerToken();
        if ($token === '') {
            header('WWW-Authenticate: Bearer realm="Micropub"');
            self::error('unauthorized', 'Missing access token', 401);
        }

        $siteUrl = rtrim($db->getSetting('site_url', ''), '/');
        $me      = $siteUrl !== '' ? $siteUrl . '/' : '';

        // Legacy shared token: full scopes. Stored as a digest, never verbatim.
        if (self::legacyTokenMatches($db->getSetting('micropub_token', ''), $token)) {
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

        Auth::recordFailureIn($db, $ip, Auth::SCOPE_MICROPUB);
        header('WWW-Authenticate: Bearer realm="Micropub", error="invalid_token"');
        self::error('unauthorized', 'Invalid access token', 401);
    }

    /**
     * Normalise a $_FILES entry to a single file.
     *
     * Micropub clients send the upload as either `file` or `file[]`; PHP shapes
     * those two very differently (scalar fields vs. parallel arrays). Returns
     * the first file in the canonical scalar shape, or null when nothing usable
     * was sent. The caller still checks ['error'].
     *
     * @param  array<string,mixed>|null $entry  a single $_FILES[...] entry
     * @return array{name:string,tmp_name:string,size:int,error:int}|null
     */
    public static function firstUploadedFile(?array $entry): ?array
    {
        if ($entry === null || $entry === []) {
            return null;
        }

        if (!is_array($entry['name'] ?? null)) {
            return [
                'name'     => (string) ($entry['name'] ?? ''),
                'tmp_name' => (string) ($entry['tmp_name'] ?? ''),
                'size'     => (int) ($entry['size'] ?? 0),
                'error'    => (int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE),
            ];
        }

        if ($entry['name'] === []) {
            return null;
        }

        return [
            'name'     => (string) ($entry['name'][0]     ?? ''),
            'tmp_name' => (string) ($entry['tmp_name'][0] ?? ''),
            'size'     => (int) ($entry['size'][0]  ?? 0),
            'error'    => (int) ($entry['error'][0] ?? UPLOAD_ERR_NO_FILE),
        ];
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
