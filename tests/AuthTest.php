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

    // ── Schema ────────────────────────────────────────────────────────────────

    public function testEveryRecordedAttemptCarriesAScope(): void
    {
        Auth::recordFailureIn($this->db, '203.0.113.2', Auth::SCOPE_API);

        $row = $this->db->selectOne("SELECT scope FROM login_attempts LIMIT 1");
        $this->assertSame(Auth::SCOPE_API, $row['scope']);
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
