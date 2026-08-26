<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\Database;
use CMS\IndieAuth;

/**
 * IndieAuth code and token handling: PKCE verification, single-use codes, and
 * the binding of a code to the client_id / redirect_uri it was issued for.
 */
final class IndieAuthTest extends TempSiteTestCase
{
    private IndieAuth $indie;

    private const CLIENT   = 'https://app.example/';
    private const REDIRECT = 'https://app.example/callback';
    private const ME       = 'https://me.example/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->indie  = new IndieAuth($this->db);
    }


    // ── PKCE ──────────────────────────────────────────────────────────────────

    public function testVerifyPkceAcceptsMatchingS256Pair(): void
    {
        $verifier  = 'a-verifier-of-reasonable-length-1234567890';
        $challenge = self::challengeFor($verifier);

        $this->assertTrue(IndieAuth::verifyPkce($verifier, $challenge, 'S256'));
    }

    public function testVerifyPkceRejectsWrongVerifier(): void
    {
        $challenge = self::challengeFor('the-real-verifier');

        $this->assertFalse(IndieAuth::verifyPkce('a-different-verifier', $challenge, 'S256'));
    }

    public function testVerifyPkceRejectsPlainMethod(): void
    {
        // "plain" would let anyone who intercepts the code supply the verifier.
        $this->assertFalse(IndieAuth::verifyPkce('v', 'v', 'plain'));
        $this->assertFalse(IndieAuth::verifyPkce('v', 'v', ''));
    }

    public function testVerifyPkceIsCaseInsensitiveOnMethodName(): void
    {
        $verifier = 'abc123';
        $this->assertTrue(IndieAuth::verifyPkce($verifier, self::challengeFor($verifier), 's256'));
    }

    // ── Code redemption ───────────────────────────────────────────────────────

    public function testCodeIsSingleUse(): void
    {
        $verifier = 'verifier-value-here';
        $code     = $this->createCode(self::challengeFor($verifier));

        $first = $this->indie->redeemCode($code, self::CLIENT, self::REDIRECT, $verifier);
        $this->assertNotNull($first, 'first redemption should succeed');

        $second = $this->indie->redeemCode($code, self::CLIENT, self::REDIRECT, $verifier);
        $this->assertNull($second, 'a replayed code must lose');
    }

    public function testCodeIsBoundToClientId(): void
    {
        $verifier = 'verifier-value-here';
        $code     = $this->createCode(self::challengeFor($verifier));

        $this->assertNull(
            $this->indie->redeemCode($code, 'https://attacker.example/', self::REDIRECT, $verifier)
        );
    }

    public function testCodeIsBoundToRedirectUri(): void
    {
        $verifier = 'verifier-value-here';
        $code     = $this->createCode(self::challengeFor($verifier));

        $this->assertNull(
            $this->indie->redeemCode($code, self::CLIENT, 'https://attacker.example/cb', $verifier)
        );
    }

    public function testWrongVerifierIsRejected(): void
    {
        $code = $this->createCode(self::challengeFor('the-real-verifier'));

        $this->assertNull(
            $this->indie->redeemCode($code, self::CLIENT, self::REDIRECT, 'wrong-verifier')
        );
    }

    public function testChallengedCodeRequiresAVerifier(): void
    {
        $code = $this->createCode(self::challengeFor('some-verifier'));

        $this->assertNull(
            $this->indie->redeemCode($code, self::CLIENT, self::REDIRECT, ''),
            'a code issued with a challenge must not redeem without a verifier'
        );
    }

    public function testChallengelessCodeRejectsAVerifier(): void
    {
        // Downgrade guard: authorization and token requests disagreeing about
        // whether PKCE is in play means something has been tampered with.
        $code = $this->createCode('');

        $this->assertNull(
            $this->indie->redeemCode($code, self::CLIENT, self::REDIRECT, 'unexpected-verifier')
        );
        $this->assertNotNull(
            $this->indie->redeemCode($code, self::CLIENT, self::REDIRECT, ''),
            'the same code should still redeem when no verifier is supplied'
        );
    }

    public function testUnknownCodeIsRejected(): void
    {
        $this->assertNull($this->indie->redeemCode('never-issued', self::CLIENT, self::REDIRECT, ''));
        $this->assertNull($this->indie->redeemCode('', self::CLIENT, self::REDIRECT, ''));
    }

    // ── Tokens ────────────────────────────────────────────────────────────────

    public function testIssuedTokenVerifiesAndCarriesItsScope(): void
    {
        $token = $this->indie->issueToken(self::CLIENT, 'App', self::ME, 'create update');

        $row = $this->indie->verifyToken($token);
        $this->assertNotNull($row);
        $this->assertSame('create update', $row['scope']);
        $this->assertSame(self::ME, $row['me']);
    }

    public function testRevokedTokenStopsVerifying(): void
    {
        $token = $this->indie->issueToken(self::CLIENT, 'App', self::ME, 'create');

        $this->assertTrue($this->indie->revokeToken($token));
        $this->assertNull($this->indie->verifyToken($token));
    }

    public function testTokenPlaintextIsNotStored(): void
    {
        $token = $this->indie->issueToken(self::CLIENT, 'App', self::ME, 'create');

        $hit = $this->db->selectOne(
            "SELECT COUNT(*) AS n FROM indieauth_tokens WHERE token_hash = :t",
            ['t' => $token]
        );
        $this->assertSame(0, (int) $hit['n'], 'only the sha256 hash should be persisted');
    }

    // ── Scope filtering ───────────────────────────────────────────────────────

    public function testFilterScopesDropsUnknownValues(): void
    {
        $this->assertSame(
            ['create', 'delete'],
            IndieAuth::filterScopes('create delete admin root')
        );
        $this->assertSame([], IndieAuth::filterScopes(''));
        $this->assertSame([], IndieAuth::filterScopes('   '));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createCode(string $challenge): string
    {
        return $this->indie->createCode(
            self::CLIENT,
            'App',
            self::REDIRECT,
            self::ME,
            'create',
            $challenge,
            $challenge === '' ? '' : 'S256'
        );
    }

    private static function challengeFor(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
