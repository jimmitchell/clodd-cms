<?php

declare(strict_types=1);

namespace CMS\Tests;

use CMS\MicropubAuth;
use PHPUnit\Framework\TestCase;

/**
 * The legacy Micropub shared token grants every scope, so how it is stored and
 * compared is the whole of its security. These pin the digest format and the
 * refusal cases.
 */
final class MicropubAuthTest extends TestCase
{
    public function testStoredFormIsAPrefixedDigestNotTheToken(): void
    {
        $token  = 'a-real-looking-token-value';
        $stored = MicropubAuth::hashLegacyToken($token);

        $this->assertStringStartsWith('sha256:', $stored);
        $this->assertStringNotContainsString($token, $stored);
        $this->assertSame('sha256:' . hash('sha256', $token), $stored);
    }

    public function testCorrectTokenMatchesItsDigest(): void
    {
        $token = 'a-real-looking-token-value';

        $this->assertTrue(
            MicropubAuth::legacyTokenMatches(MicropubAuth::hashLegacyToken($token), $token)
        );
    }

    public function testWrongTokenIsRefused(): void
    {
        $stored = MicropubAuth::hashLegacyToken('the-right-one');

        $this->assertFalse(MicropubAuth::legacyTokenMatches($stored, 'the-wrong-one'));
    }

    /**
     * An un-migrated row holds the token verbatim. Comparing that against a
     * presented token would still authenticate, quietly keeping the plaintext
     * credential alive, so the missing prefix has to be a refusal.
     */
    public function testPlaintextStoredValueIsRefusedEvenWhenItEqualsTheToken(): void
    {
        $token = 'not-yet-migrated';

        $this->assertFalse(MicropubAuth::legacyTokenMatches($token, $token));
    }

    /**
     * hash_equals('', '') is true. An install with no token configured must not
     * therefore accept an empty Authorization header as valid.
     */
    public function testEmptyStoredAndEmptyPresentedDoNotMatch(): void
    {
        $this->assertFalse(MicropubAuth::legacyTokenMatches('', ''));
        $this->assertFalse(MicropubAuth::legacyTokenMatches(MicropubAuth::hashLegacyToken('x'), ''));
        $this->assertFalse(MicropubAuth::legacyTokenMatches('', 'anything'));
    }

    /**
     * The two halves of a rejection must agree. `authenticate()` sends a
     * WWW-Authenticate header *and* a JSON body, and for a year the header said
     * `invalid_token` while the body said `unauthorized` — a response that
     * contradicted itself, and that no client would ever report, because both
     * carry 401 and a client reading either one alone sees something coherent.
     *
     * Asserted from the source because `error()` is `never` — it exits, so the
     * branch cannot be reached from a test. Comments are stripped first, or the
     * prose above the call would satisfy the assertion on its own.
     */
    public function testAnInvalidTokenIsRejectedAsInvalidTokenNotUnauthorized(): void
    {
        $code = self::sourceWithoutComments(__DIR__ . '/../src/MicropubAuth.php');

        $this->assertMatchesRegularExpression(
            '/error="invalid_token".*?self::error\(\s*\'invalid_token\'/s',
            $code,
            'a token that was supplied and did not verify is invalid_token (RFC 6750 §3.1), '
            . 'and the body must not disagree with the WWW-Authenticate header above it'
        );

        // The other branch is the one 'unauthorized' is actually for: Micropub
        // defines it for a request that carried no token at all.
        $this->assertStringContainsString(
            "self::error('unauthorized', 'Missing access token', 401)",
            $code,
            'a request with no token stays unauthorized'
        );
    }

    private static function sourceWithoutComments(string $path): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        return $code;
    }
}
