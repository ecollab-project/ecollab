<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Covers CSRF::token()/regenerate() fully, and CSRF::verify()'s SUCCESS path
 * only.
 *
 * NOT covered here: CSRF::verify()'s failure path. On a mismatched/missing
 * token it calls http_response_code()/header()/exit(), which terminates the
 * PHP process — safely asserting that path needs PHPUnit process isolation
 * (@runInSeparateProcess) that could not be verified against a live PHP
 * interpreter in the environment this suite was authored in. Flagged in
 * tests/README.md as a follow-up rather than claimed as covered here.
 */
final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_POST    = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = null;
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST    = [];
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    public function testTokenGeneratesA64CharacterHexString(): void
    {
        $token = \CSRF::token();
        // TOKEN_LEN = 32 bytes -> bin2hex doubles it to 64 hex characters.
        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testTokenIsStableAcrossRepeatedCallsInSameSession(): void
    {
        $first  = \CSRF::token();
        $second = \CSRF::token();
        $this->assertSame($first, $second, 'token() must not rotate the token on every call.');
    }

    public function testRegenerateProducesADifferentToken(): void
    {
        $before = \CSRF::token();
        \CSRF::regenerate();
        $after = \CSRF::token();
        $this->assertNotSame($before, $after);
    }

    public function testFieldRendersHiddenInputWithCurrentToken(): void
    {
        $token = \CSRF::token();
        $field = \CSRF::field();
        $this->assertStringContainsString('type="hidden"', $field);
        $this->assertStringContainsString('name="csrf_token"', $field);
        $this->assertStringContainsString($token, $field);
    }

    public function testVerifySucceedsSilentlyWithMatchingPostToken(): void
    {
        $token = \CSRF::token();
        $_POST['csrf_token'] = $token;

        // If verify() takes its failure branch it calls exit(), which would
        // kill the test process before this assertion runs — so simply
        // reaching this line is itself proof the success path was taken.
        \CSRF::verify();
        $this->assertTrue(true, 'verify() returned normally for a matching token.');
    }

    public function testVerifySucceedsSilentlyWithMatchingHeaderToken(): void
    {
        $token = \CSRF::token();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        \CSRF::verify();
        $this->assertTrue(true, 'verify() returned normally for a matching header token.');
    }

    public function testVerifySucceedsSilentlyWithMatchingUnderscorePostKey(): void
    {
        $token = \CSRF::token();
        $_POST['_csrf_token'] = $token;

        \CSRF::verify();
        $this->assertTrue(true, 'verify() checks the _csrf_token POST key as a fallback.');
    }
}
