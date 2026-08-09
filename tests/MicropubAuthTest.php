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
}
