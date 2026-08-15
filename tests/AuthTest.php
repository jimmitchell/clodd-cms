<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Auth;
use CMS\Database;
use PHPUnit\Framework\TestCase;

/**
 * Lockout scoping and CSRF verification.
 *
 * The scoping matters because all four authentication surfaces share one table:
 * without a scope, a client looping on a stale Micropub token locks the owner
 * out of the admin login.
 */
final class AuthTest extends TestCase
{
    private Database $db;
    private string $dbPath;

    /** @var array<string,mixed> */
    private array $config = [
        'admin'    => ['username' => 'admin', 'password_hash' => '', 'session_lifetime' => 3600],
        'security' => ['max_login_attempts' => 5, 'lockout_minutes' => 15],
    ];

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 'clodd_auth_') . '.db';
        $this->db     = new Database($this->dbPath);
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }

    // ── Lockout scoping ───────────────────────────────────────────────────────

    public function testLockoutIsScopedToOneSurface(): void
    {
        $ip = '198.51.100.7';

        for ($i = 0; $i < 5; $i++) {
            Auth::recordFailureIn($this->db, $ip, Auth::SCOPE_MICROPUB);
        }

        $this->assertTrue($this->lockedOut($ip, Auth::SCOPE_MICROPUB));

        foreach ([Auth::SCOPE_ADMIN, Auth::SCOPE_API, Auth::SCOPE_XMLRPC, Auth::SCOPE_TOTP] as $other) {
            $this->assertFalse(
                $this->lockedOut($ip, $other),
                "burning the micropub budget must not lock out {$other}"
            );
        }
    }

    public function testAdminLockoutDoesNotBlockMachineSurfaces(): void
    {
        $ip = '198.51.100.8';

        for ($i = 0; $i < 5; $i++) {
            Auth::recordFailureIn($this->db, $ip, Auth::SCOPE_ADMIN);
        }

        $this->assertTrue($this->lockedOut($ip, Auth::SCOPE_ADMIN));
        $this->assertFalse($this->lockedOut($ip, Auth::SCOPE_MICROPUB));
    }

    public function testLockoutIsPerIp(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Auth::recordFailureIn($this->db, '198.51.100.9', Auth::SCOPE_ADMIN);
        }

        $this->assertTrue($this->lockedOut('198.51.100.9', Auth::SCOPE_ADMIN));
        $this->assertFalse($this->lockedOut('203.0.113.1', Auth::SCOPE_ADMIN));
    }

    public function testThresholdIsExactlyMaxAttempts(): void
    {
        $ip = '198.51.100.10';

        for ($i = 0; $i < 4; $i++) {
            Auth::recordFailureIn($this->db, $ip, Auth::SCOPE_ADMIN);
        }
        $this->assertFalse($this->lockedOut($ip, Auth::SCOPE_ADMIN), '4 failures is under the limit');

        Auth::recordFailureIn($this->db, $ip, Auth::SCOPE_ADMIN);
        $this->assertTrue($this->lockedOut($ip, Auth::SCOPE_ADMIN), '5 failures reaches the limit');
    }

    public function testAttemptsOutsideTheWindowDoNotCount(): void
    {
        $ip = '198.51.100.11';

        for ($i = 0; $i < 10; $i++) {
            $this->db->insert('login_attempts', [
                'ip'           => $ip,
                'scope'        => Auth::SCOPE_ADMIN,
                'success'      => 0,
                'attempted_at' => gmdate('Y-m-d H:i:s', time() - 3600),  // an hour ago
            ]);
        }

        $this->assertFalse(
            $this->lockedOut($ip, Auth::SCOPE_ADMIN),
            'the 15-minute window must have expired'
        );
    }

    public function testSuccessfulAttemptsDoNotCountTowardsLockout(): void
    {
        $ip = '198.51.100.12';

        for ($i = 0; $i < 10; $i++) {
            $this->db->insert('login_attempts', [
                'ip' => $ip, 'scope' => Auth::SCOPE_ADMIN, 'success' => 1,
            ]);
        }

        $this->assertFalse($this->lockedOut($ip, Auth::SCOPE_ADMIN));
    }

    /**
     * The username-keyed counter is gone: it let anyone who knew the admin
     * username lock the account out from every IP at once.
     */
    public function testFailedLoginDoesNotRecordAUsernameKeyedRow(): void
    {
        $auth = new Auth($this->config, $this->db);
        $auth->login('admin', 'wrong-password');

        $rows = $this->db->select("SELECT ip FROM login_attempts");
        foreach ($rows as $row) {
            $this->assertStringNotContainsString(
                'user:',
                (string) $row['ip'],
                'no username-keyed lockout row should be written'
            );
        }
    }

    // ── CSRF ──────────────────────────────────────────────────────────────────

    /**
     * The bypass this guards: hash_equals('', '') is true, so before this fix a
     * POST carrying no token, from a browser holding no session, was accepted —
     * exactly the shape of a cross-site POST to the login form.
     */
    public function testEmptyTokenOnBothSidesIsRejected(): void
    {
        $auth = new Auth($this->config, $this->db);
        $_SESSION = [];

        $this->assertFalse($auth->isCsrfValid(''));
    }

    public function testTokenWithoutASessionTokenIsRejected(): void
    {
        $auth = new Auth($this->config, $this->db);
        $_SESSION = [];

        $this->assertFalse($auth->isCsrfValid('anything'));
    }

    public function testEmptySubmittedTokenIsRejected(): void
    {
        $auth = new Auth($this->config, $this->db);
        $_SESSION = ['csrf_token' => 'a-real-token'];

        $this->assertFalse($auth->isCsrfValid(''));
    }

    public function testMismatchedTokenIsRejected(): void
    {
        $auth = new Auth($this->config, $this->db);
        $_SESSION = ['csrf_token' => 'a-real-token'];

        $this->assertFalse($auth->isCsrfValid('a-different-token'));
    }

    public function testMatchingTokenIsAccepted(): void
    {
        $auth = new Auth($this->config, $this->db);
        $_SESSION = ['csrf_token' => 'a-real-token'];

        $this->assertTrue($auth->isCsrfValid('a-real-token'));
    }

    // ── Idle timeout ──────────────────────────────────────────────────────────

    /**
     * The gap this covers: the timeout used to live only inside check(), so the
     * endpoints that answer differently when signed out called isAuthenticated()
     * and skipped it. indieauth.php did that on both the consent render and the
     * approval POST — the two requests that mint `create`-scope tokens.
     */
    public function testSessionIsLiveRejectsAnIdleSession(): void
    {
        $auth = new Auth($this->config, $this->db);   // session_lifetime = 3600
        $_SESSION = ['authenticated' => true, 'last_seen' => time() - 3601];

        $this->assertFalse($auth->sessionIsLive());
        // Expiring must clear the session, not merely report it stale, so a
        // caller that ignores the return value cannot act on a dead one.
        $this->assertSame([], $_SESSION);
    }

    public function testSessionIsLiveAcceptsAnActiveSessionAndRefreshesLastSeen(): void
    {
        $auth = new Auth($this->config, $this->db);
        $_SESSION = ['authenticated' => true, 'last_seen' => time() - 60];

        $this->assertTrue($auth->sessionIsLive());
        $this->assertSame(time(), $_SESSION['last_seen']);
    }

    public function testSessionIsLiveRejectsAnUnauthenticatedSession(): void
    {
        $auth = new Auth($this->config, $this->db);
        $_SESSION = [];

        $this->assertFalse($auth->sessionIsLive());
    }

    /** A zero lifetime disables the timeout rather than expiring everything. */
    public function testSessionIsLiveTreatsAZeroLifetimeAsNoTimeout(): void
    {
        $config = $this->config;
        $config['admin']['session_lifetime'] = 0;
        $auth = new Auth($config, $this->db);
        $_SESSION = ['authenticated' => true, 'last_seen' => time() - 999999];

        $this->assertTrue($auth->sessionIsLive());
    }

    // ── Passkeys ──────────────────────────────────────────────────────────────

    /**
     * A passkey signs the owner in on its own — past the password and past TOTP,
     * because passkeyAuthVerify() never consults isTotpEnabled(). With
     * userVerification merely 'preferred', an authenticator that declines to ask
     * for a PIN or biometric reduced admin login to possession of the key alone.
     * 'required' is what keeps that path two-factor.
     */
    public function testRegistrationOptionsRequireUserVerification(): void
    {
        $auth    = new Auth($this->config, $this->db);
        $options = json_decode($auth->passkeyRegisterOptions(), true);

        $this->assertSame(
            'required',
            $options['publicKey']['authenticatorSelection']['userVerification'] ?? null
        );
    }

    public function testAuthenticationOptionsRequireUserVerification(): void
    {
        $auth    = new Auth($this->config, $this->db);
        $options = json_decode($auth->passkeyAuthOptions(), true);

        $this->assertSame('required', $options['publicKey']['userVerification'] ?? null);
    }

    // ── Database file permissions ─────────────────────────────────────────────

    /**
     * The database stores the TOTP secret in plaintext plus the Mastodon,
     * Bluesky and Pixelfed tokens. config.php and data/analytics_salt were
     * already 0600; the database was created at the umask default of 0644 with
     * only an nginx deny rule standing in for file permissions.
     */
    public function testOpeningTheDatabaseTakesOtherAccessOffTheFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'clodd_perm_') . '.db';
        touch($path);
        chmod($path, 0644);

        new Database($path);
        clearstatcache();

        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            if (!file_exists($file)) {
                continue;
            }
            $this->assertSame(
                0,
                fileperms($file) & 0o007,
                basename($file) . ' must not be readable by other'
            );
        }

        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            @unlink($file);
        }
    }

    /**
     * The regression that took prod down in 1.19.0. The database is owned
     * deploy:www-data there and PHP-FPM reaches it through the group bit, so a
     * flat chmod(0600) locked www-data out and every PHP endpoint 500'd.
     * Whether the web user arrives as owner or as group is the deployment's
     * choice; only world access is ours to remove.
     */
    public function testOpeningTheDatabaseLeavesGroupAccessAlone(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'clodd_perm_') . '.db';
        touch($path);
        chmod($path, 0660);

        new Database($path);
        clearstatcache();

        $this->assertSame(
            0o660,
            fileperms($path) & 0o777,
            'a group-readable deployment must survive opening the database'
        );

        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            @unlink($file);
        }
    }

    /** 0644 loses only the world bits, so group-based installs still work. */
    public function testWorldReadableBecomesGroupReadableNotOwnerOnly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'clodd_perm_') . '.db';
        touch($path);
        chmod($path, 0664);

        new Database($path);
        clearstatcache();

        $this->assertSame(0o660, fileperms($path) & 0o777);

        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
            @unlink($file);
        }
    }

    // ── Schema ────────────────────────────────────────────────────────────────

    public function testEveryRecordedAttemptCarriesAScope(): void
    {
        Auth::recordFailureIn($this->db, '203.0.113.2', Auth::SCOPE_API);

        $row = $this->db->selectOne("SELECT scope FROM login_attempts LIMIT 1");
        $this->assertSame(Auth::SCOPE_API, $row['scope']);
    }

    /**
     * Settings → Logs names each surface, and an unlabelled scope falls back to
     * printing the raw constant. Adding a SCOPE_* without a label is easy to
     * miss, so pin the two together.
     */
    public function testEveryScopeConstantHasALogsLabel(): void
    {
        $scopes = array_filter(
            (new \ReflectionClass(Auth::class))->getConstants(),
            fn($v, $k) => str_starts_with($k, 'SCOPE_'),
            ARRAY_FILTER_USE_BOTH
        );

        // Re-run the handler's label table in isolation: it is a plain array
        // literal, so eval-free extraction keeps this honest without including
        // a file that expects $db and a session.
        $source = file_get_contents(__DIR__ . '/../admin/partials/settings/logs.handler.php');
        preg_match('/\$scopeLabels = \[(.*?)\n\];/s', $source, $m);
        $this->assertNotEmpty($m, 'logs.handler.php must define $scopeLabels');

        foreach ($scopes as $name => $value) {
            $this->assertStringContainsString(
                'Auth::' . $name . ' ',
                $m[1],
                "Auth::{$name} has no label in the Logs tab"
            );
        }
    }

    public function testScopeDefaultsToAdminForRowsWrittenWithoutOne(): void
    {
        // Pre-v24 rows migrate to 'admin', the conservative reading.
        $this->db->insert('login_attempts', ['ip' => '203.0.113.3', 'success' => 0]);

        $row = $this->db->selectOne(
            "SELECT scope FROM login_attempts WHERE ip = '203.0.113.3'"
        );
        $this->assertSame('admin', $row['scope']);
    }

    private function lockedOut(string $ip, string $scope): bool
    {
        return Auth::isLockedOutIn($this->db, $this->config, $ip, $scope);
    }
}
