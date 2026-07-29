<?php

declare(strict_types=1);

namespace CMS;

use RuntimeException;

class Auth
{
    private array $config;
    private Database $db;

    public function __construct(array $config, Database $db)
    {
        $this->config = $config;
        $this->db     = $db;
    }

    // ── Session bootstrap ─────────────────────────────────────────────────────

    /**
     * Start the session using the name and cookie settings from config.
     * Must be called before any output.
     */
    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $name     = $this->config['admin']['session_name'] ?? 'cms_session';
        $lifetime = (int) ($this->config['admin']['session_lifetime'] ?? 3600);

        session_name($name);
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    // ── Authentication ────────────────────────────────────────────────────────

    /**
     * Attempt a login. Returns true on success, false on failure.
     * On failure, records the attempt; on success, regenerates session.
     */
    public function login(string $username, string $password): bool
    {
        $ip = $this->clientIp();

        // Deliberately IP-scoped only. A username-keyed counter used to gate this
        // too, which meant anyone who knew the admin username could lock the
        // account out from every IP at once with a handful of requests. Per-IP
        // lockout plus 2FA is the actual control; failed logins are still
        // recorded in the activity log for audit.
        if ($this->isLockedOut($ip, self::SCOPE_ADMIN)) {
            return false;
        }

        $expectedUser = $this->config['admin']['username'] ?? '';
        $hash         = $this->config['admin']['password_hash'] ?? '';

        $ok = ($username === $expectedUser)
            && $hash !== ''
            && password_verify($password, $hash);

        $this->recordAttempt($ip, $ok);

        if ($ok) {
            session_regenerate_id(true);
            if ($this->isTotpEnabled()) {
                $_SESSION['totp_pending']      = true;
                $_SESSION['totp_pending_user'] = $username;
                $_SESSION['totp_pending_at']   = time();
                $_SESSION['csrf_token']        = $this->generateToken();
            } else {
                $_SESSION['authenticated'] = true;
                $_SESSION['user']          = $username;
                $_SESSION['csrf_token']    = $this->generateToken();
            }
        }

        return $ok;
    }

    /** Destroy the session and redirect to login. */
    public function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
        header('Location: /admin/');
        exit;
    }

    /**
     * Require a valid session. Redirects to login if not authenticated.
     * Call this at the top of every protected admin page.
     */
    public function check(): void
    {
        if (empty($_SESSION['authenticated'])) {
            header('Location: /admin/');
            exit;
        }
    }

    /** Returns true if the current session is authenticated. */
    public function isAuthenticated(): bool
    {
        return !empty($_SESSION['authenticated']);
    }

    // ── CSRF ──────────────────────────────────────────────────────────────────

    /** Return the CSRF token for the current session (generate if absent). */
    public function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = $this->generateToken();
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify the CSRF token from a POST request.
     * Terminates with 403 if invalid.
     */
    public function verifyCsrf(string $token): void
    {
        $expected = $_SESSION['csrf_token'] ?? '';

        // hash_equals('', '') is true, so an absent session token would otherwise
        // be satisfied by an absent submitted token — which is exactly the state
        // a cross-site POST arrives in on the login form, before any token has
        // been issued. Require both sides to be present.
        if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
            http_response_code(403);
            exit('CSRF token mismatch.');
        }
    }

    // ── Rate limiting ─────────────────────────────────────────────────────────

    /**
     * Every authentication surface that shares the login_attempts table.
     * Each rate-limits independently — a Micropub client burning through bad
     * bearer tokens must not lock the human out of the admin UI.
     */
    public const SCOPE_ADMIN    = 'admin';
    public const SCOPE_TOTP     = 'totp';
    public const SCOPE_PASSKEY  = 'passkey';
    public const SCOPE_MICROPUB = 'micropub';
    public const SCOPE_API      = 'api';
    public const SCOPE_XMLRPC   = 'xmlrpc';

    /** Returns true if the IP is currently locked out for the given scope. */
    public function isLockedOut(string $ip, string $scope = self::SCOPE_ADMIN): bool
    {
        return self::isLockedOutIn($this->db, $this->config, $ip, $scope);
    }

    /**
     * Scope-aware lockout check usable without an Auth instance — the Micropub,
     * REST and XML-RPC endpoints authenticate per-request and have no session.
     */
    public static function isLockedOutIn(Database $db, array $config, string $ip, string $scope): bool
    {
        $maxAttempts    = (int) ($config['security']['max_login_attempts'] ?? 5);
        $lockoutMinutes = (int) ($config['security']['lockout_minutes'] ?? 15);

        $row = $db->selectOne(
            "SELECT COUNT(*) AS cnt
               FROM login_attempts
              WHERE ip = :ip
                AND scope = :scope
                AND success = 0
                AND attempted_at >= datetime('now', :window)",
            ['ip' => $ip, 'scope' => $scope, 'window' => "-{$lockoutMinutes} minutes"]
        );

        return ($row['cnt'] ?? 0) >= $maxAttempts;
    }

    /** Record a failed attempt against a scope. Usable without an Auth instance. */
    public static function recordFailureIn(Database $db, string $ip, string $scope): void
    {
        $db->insert('login_attempts', ['ip' => $ip, 'scope' => $scope, 'success' => 0]);
    }

    /** Return the number of seconds remaining in a lockout (0 if not locked). */
    public function lockoutSecondsRemaining(string $ip, string $scope = self::SCOPE_ADMIN): int
    {
        $maxAttempts    = (int) ($this->config['security']['max_login_attempts'] ?? 5);
        $lockoutMinutes = (int) ($this->config['security']['lockout_minutes'] ?? 15);

        $row = $this->db->selectOne(
            "SELECT attempted_at
               FROM login_attempts
              WHERE ip = :ip AND scope = :scope AND success = 0
                AND attempted_at >= datetime('now', :window)
              ORDER BY attempted_at DESC
              LIMIT 1 OFFSET :offset",
            [
                'ip'     => $ip,
                'scope'  => $scope,
                'window' => "-{$lockoutMinutes} minutes",
                'offset' => $maxAttempts - 1,
            ]
        );

        if (!$row) {
            return 0;
        }

        $lockoutEnds = strtotime($row['attempted_at']) + ($lockoutMinutes * 60);
        $remaining   = $lockoutEnds - time();
        return max(0, $remaining);
    }

    // ── TOTP 2FA ──────────────────────────────────────────────────────────────

    public function isTotpEnabled(): bool
    {
        return $this->db->getSetting('totp_enabled', '0') === '1';
    }

    public function isTotpPending(): bool
    {
        return !empty($_SESSION['totp_pending']);
    }

    public function completeTotpLogin(): void
    {
        $user = $_SESSION['totp_pending_user'] ?? '';
        unset($_SESSION['totp_pending'], $_SESSION['totp_pending_user'], $_SESSION['totp_pending_at']);
        $_SESSION['authenticated'] = true;
        $_SESSION['user']          = $user;
        $_SESSION['csrf_token']    = $this->generateToken();
    }

    public function generateTotpSecret(): string
    {
        $totp = \OTPHP\TOTP::generate();
        return $totp->getSecret();
    }

    public function verifyTotp(string $code): bool
    {
        $secret = $this->db->getSetting('totp_secret', '');
        if ($secret === '') {
            return false;
        }
        $totp = \OTPHP\TOTP::createFromSecret($secret);
        return $totp->verify($code, null, 1);
    }

    public function enableTotp(string $secret): void
    {
        $this->db->upsertSetting('totp_secret', $secret);
        $this->db->upsertSetting('totp_enabled', '1');
    }

    public function disableTotp(): void
    {
        $this->db->upsertSetting('totp_enabled', '0');
        $this->db->upsertSetting('totp_secret', '');
        $this->db->delete('totp_backup_codes', '1=1');
    }

    /**
     * Generate $count backup/recovery codes, store their bcrypt hashes,
     * and return the plaintext codes (shown once, never stored).
     *
     * @return string[]
     */
    public function generateBackupCodes(int $count = 8): array
    {
        $this->db->delete('totp_backup_codes', '1=1');
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $hex   = bin2hex(random_bytes(5));
            $plain = strtoupper(substr($hex, 0, 5) . '-' . substr($hex, 5));
            $codes[] = $plain;
            $this->db->insert('totp_backup_codes', [
                'code_hash' => password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]),
            ]);
        }
        return $codes;
    }

    public function verifyBackupCode(string $input): bool
    {
        $rows = $this->db->select("SELECT id, code_hash FROM totp_backup_codes WHERE used_at IS NULL");
        foreach ($rows as $row) {
            if (password_verify($input, $row['code_hash'])) {
                $this->db->update(
                    'totp_backup_codes',
                    ['used_at' => date('Y-m-d H:i:s')],
                    'id = :id',
                    ['id' => (int) $row['id']]
                );
                return true;
            }
        }
        return false;
    }

    public function isTotpLockedOut(string $ip): bool
    {
        return $this->isLockedOut($ip, self::SCOPE_TOTP);
    }

    public function recordTotpAttempt(string $ip, bool $success): void
    {
        $this->db->insert('login_attempts', [
            'ip'      => $ip,
            'scope'   => self::SCOPE_TOTP,
            'success' => $success ? 1 : 0,
        ]);
    }

    private function recordAttempt(string $ip, bool $success): void
    {
        $this->db->insert('login_attempts', [
            'ip'      => $ip,
            'scope'   => self::SCOPE_ADMIN,
            'success' => $success ? 1 : 0,
        ]);
    }

    // ── Passkeys (WebAuthn) ───────────────────────────────────────────────────

    /** Returns true if at least one passkey is registered. */
    public function hasPasskeys(): bool
    {
        $row = $this->db->selectOne("SELECT COUNT(*) AS cnt FROM passkeys");
        return ((int) ($row['cnt'] ?? 0)) > 0;
    }

    /** Returns all registered passkeys (id, name, created_at, last_used_at). */
    public function getPasskeys(): array
    {
        return $this->db->select(
            "SELECT id, name, created_at, last_used_at FROM passkeys ORDER BY created_at DESC"
        );
    }

    /**
     * Generate a WebAuthn registration challenge and return the JSON options
     * to pass to navigator.credentials.create() on the client.
     * Stores the challenge binary in the session.
     */
    public function passkeyRegisterOptions(): string
    {
        $wa       = $this->webAuthn();
        $username = $this->config['admin']['username'] ?? 'admin';
        $userId   = hash('sha256', $username, true); // fixed 32-byte user ID

        // Exclude already-registered credential IDs to prevent re-registration.
        $existing   = $this->db->select("SELECT credential_id FROM passkeys");
        $excludeIds = array_map(
            fn($row) => \lbuchs\WebAuthn\Binary\ByteBuffer::fromBase64Url($row['credential_id']),
            $existing
        );

        $createArgs = $wa->getCreateArgs(
            $userId,
            $username,
            $username,
            60,           // timeout (seconds)
            'required',   // residentKey — required for passkey behaviour
            'preferred',  // userVerification
            null,         // any authenticator (platform or cross-platform)
            $excludeIds
        );

        $_SESSION['webauthn_challenge'] = $wa->getChallenge()->getBinaryString();

        return (string) json_encode($createArgs);
    }

    /**
     * Verify a registration response from the browser.
     * Returns ['credential_id', 'public_key', 'sign_count'] on success.
     *
     * @throws RuntimeException on verification failure
     */
    public function passkeyRegisterComplete(string $clientDataJSON, string $attestationObject): array
    {
        $wa        = $this->webAuthn();
        $challenge = $_SESSION['webauthn_challenge'] ?? null;
        if ($challenge === null) {
            throw new RuntimeException('No passkey challenge in session.');
        }
        unset($_SESSION['webauthn_challenge']);

        $data = $wa->processCreate(
            $clientDataJSON,
            $attestationObject,
            new \lbuchs\WebAuthn\Binary\ByteBuffer($challenge),
            false, // requireUserVerification
            true,  // requireUserPresent
            false  // failIfRootMismatch (no CA pinning needed for a single-user CMS)
        );

        return [
            'credential_id' => rtrim(strtr(base64_encode($data->credentialId), '+/', '-_'), '='),
            'public_key'    => $data->credentialPublicKey,
            'sign_count'    => (int) ($data->signatureCounter ?? 0),
        ];
    }

    /**
     * Generate a WebAuthn authentication challenge and return the JSON options
     * to pass to navigator.credentials.get() on the client.
     * Stores the challenge binary in the session.
     */
    public function passkeyAuthOptions(): string
    {
        $wa           = $this->webAuthn();
        $passkeys     = $this->db->select("SELECT credential_id FROM passkeys");
        $credentialIds = array_map(
            fn($row) => \lbuchs\WebAuthn\Binary\ByteBuffer::fromBase64Url($row['credential_id']),
            $passkeys
        );

        $getArgs = $wa->getGetArgs($credentialIds, 60);

        $_SESSION['webauthn_challenge'] = $wa->getChallenge()->getBinaryString();

        return (string) json_encode($getArgs);
    }

    /**
     * Verify an authentication assertion from the browser.
     * On success, sets the session as authenticated and returns true.
     * $credentialId is the base64url credential ID returned by the browser.
     * $clientDataJSON, $authenticatorData, $signature are raw binary strings.
     */
    public function passkeyAuthVerify(
        string $credentialId,
        string $clientDataJSON,
        string $authenticatorData,
        string $signature
    ): bool {
        $wa        = $this->webAuthn();
        $challenge = $_SESSION['webauthn_challenge'] ?? null;
        if ($challenge === null) {
            return false;
        }
        unset($_SESSION['webauthn_challenge']);

        $passkey = $this->db->selectOne(
            "SELECT * FROM passkeys WHERE credential_id = :id",
            ['id' => $credentialId]
        );
        if ($passkey === null) {
            return false;
        }

        try {
            $wa->processGet(
                $clientDataJSON,
                $authenticatorData,
                $signature,
                $passkey['public_key'],
                new \lbuchs\WebAuthn\Binary\ByteBuffer($challenge),
                (int) $passkey['sign_count'],
                false, // requireUserVerification
                true   // requireUserPresent
            );
        } catch (\lbuchs\WebAuthn\WebAuthnException $e) {
            return false;
        }

        // Update sign count and last-used timestamp.
        $newCount = $wa->getSignatureCounter() ?? (int) $passkey['sign_count'];
        $this->db->update(
            'passkeys',
            ['sign_count' => $newCount, 'last_used_at' => date('Y-m-d H:i:s')],
            'id = :id',
            ['id' => (int) $passkey['id']]
        );

        // Complete the login session.
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['user']          = $this->config['admin']['username'] ?? 'admin';
        $_SESSION['csrf_token']    = $this->generateToken();

        return true;
    }

    /**
     * Build a WebAuthn instance using the site's rpId (hostname without port).
     */
    private function webAuthn(): \lbuchs\WebAuthn\WebAuthn
    {
        // Derive rpId from the canonical site_url setting rather than the
        // attacker-controllable HTTP_HOST header.
        $siteUrl = $this->db->getSetting('site_url', '');
        $rpId    = $siteUrl !== '' ? (parse_url($siteUrl, PHP_URL_HOST) ?? 'localhost') : 'localhost';

        $rpName = $this->db->getSetting('site_title', 'CMS');

        return new \lbuchs\WebAuthn\WebAuthn($rpName, $rpId, null, true);
    }

    // ── Flash messages ────────────────────────────────────────────────────────

    /**
     * Store a flash message in the session.
     * $type is one of: 'success' | 'error' | 'info'
     */
    public function flash(string $message, string $type = 'success'): void
    {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    }

    /**
     * Retrieve and clear the flash message.
     * Returns ['message' => string, 'type' => string] or null.
     *
     * @return array{message:string,type:string}|null
     */
    public function getFlash(): ?array
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $flash;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function clientIp(): string
    {
        // Trust X-Forwarded-For only if behind a known proxy; for simplicity use REMOTE_ADDR.
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
