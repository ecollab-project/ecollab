<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for RateLimiter::attempt()/clear().
 * Requires a real 'ecollab_test' database — see tests/README.md.
 */
final class RateLimiterTest extends TestCase
{
    private \PDO $db;
    private string $action;
    private string $identity;

    protected function setUp(): void
    {
        $this->db = \Database::getInstance();
        // lookup_key is VARCHAR(80): action + ':' + a 64-char sha256 hex digest
        // leaves only 15 characters of budget for the action string itself.
        // uniqid('a') = 'a' + 13 hex chars = 14 chars, safely within that budget
        // (the earlier 'phpunit_test_' . uniqid() was 26 chars -- 11 over budget,
        // silently truncated/mismatched, which was the actual root cause of every
        // RateLimiterTest failure below, not the SQL window-comparison logic).
        $this->action   = uniqid('a');
        $this->identity = 'test-identity-' . uniqid(); // identity length is irrelevant — always hashed to a fixed 64 chars
    }

    protected function tearDown(): void
    {
        $key = $this->action . ':' . hash('sha256', $this->identity);
        $this->db->prepare("DELETE FROM rate_limit_log WHERE lookup_key = :key")->execute([':key' => $key]);
    }

    public function testFirstAttemptIsAllowed(): void
    {
        $limiter = new \RateLimiter();
        $result  = $limiter->attempt($this->action, $this->identity, maxAttempts: 3, windowSeconds: 900);

        $this->assertTrue($result['allowed']);
        $this->assertSame(1, $result['attempts']);
        $this->assertSame(0, $result['retry_after']);
    }

    public function testAttemptsWithinLimitAreAllowedAndCounted(): void
    {
        $limiter = new \RateLimiter();

        $first  = $limiter->attempt($this->action, $this->identity, maxAttempts: 3, windowSeconds: 900);
        $second = $limiter->attempt($this->action, $this->identity, maxAttempts: 3, windowSeconds: 900);
        $third  = $limiter->attempt($this->action, $this->identity, maxAttempts: 3, windowSeconds: 900);

        $this->assertTrue($first['allowed']);
        $this->assertTrue($second['allowed']);
        $this->assertTrue($third['allowed']);
        $this->assertSame(1, $first['attempts']);
        $this->assertSame(2, $second['attempts']);
        $this->assertSame(3, $third['attempts']);
    }

    public function testAttemptOverLimitIsBlocked(): void
    {
        $limiter = new \RateLimiter();

        $limiter->attempt($this->action, $this->identity, maxAttempts: 2, windowSeconds: 900);
        $limiter->attempt($this->action, $this->identity, maxAttempts: 2, windowSeconds: 900);
        // Third attempt exceeds maxAttempts=2
        $blocked = $limiter->attempt($this->action, $this->identity, maxAttempts: 2, windowSeconds: 900);

        $this->assertFalse($blocked['allowed']);
        $this->assertSame(2, $blocked['attempts']);
        $this->assertGreaterThan(0, $blocked['retry_after']);
    }

    public function testDifferentIdentitiesHaveIndependentLimits(): void
    {
        $limiter = new \RateLimiter();
        $otherIdentity = 'other-identity-' . uniqid();

        $limiter->attempt($this->action, $this->identity, maxAttempts: 1, windowSeconds: 900);
        $blocked = $limiter->attempt($this->action, $this->identity, maxAttempts: 1, windowSeconds: 900);
        $this->assertFalse($blocked['allowed'], 'precondition: identity should now be at its limit');

        $otherResult = $limiter->attempt($this->action, $otherIdentity, maxAttempts: 1, windowSeconds: 900);
        $this->assertTrue($otherResult['allowed'], 'a different identity must not share the first identity\'s limit');

        // Cleanup for the second identity, since tearDown() only clears $this->identity
        $key = $this->action . ':' . hash('sha256', $otherIdentity);
        $this->db->prepare("DELETE FROM rate_limit_log WHERE lookup_key = :key")->execute([':key' => $key]);
    }

    public function testDifferentActionsHaveIndependentLimitsForSameIdentity(): void
    {
        $limiter = new \RateLimiter();
        $otherAction = uniqid('b'); // 14 chars, within lookup_key's 15-char action budget

        $limiter->attempt($this->action, $this->identity, maxAttempts: 1, windowSeconds: 900);
        $blocked = $limiter->attempt($this->action, $this->identity, maxAttempts: 1, windowSeconds: 900);
        $this->assertFalse($blocked['allowed'], 'precondition: this action should now be at its limit');

        $otherResult = $limiter->attempt($otherAction, $this->identity, maxAttempts: 1, windowSeconds: 900);
        $this->assertTrue($otherResult['allowed'], 'a different action must not share the first action\'s limit');

        $key = $otherAction . ':' . hash('sha256', $this->identity);
        $this->db->prepare("DELETE FROM rate_limit_log WHERE lookup_key = :key")->execute([':key' => $key]);
    }

    public function testClearResetsAttemptCount(): void
    {
        $limiter = new \RateLimiter();

        $limiter->attempt($this->action, $this->identity, maxAttempts: 1, windowSeconds: 900);
        $blocked = $limiter->attempt($this->action, $this->identity, maxAttempts: 1, windowSeconds: 900);
        $this->assertFalse($blocked['allowed'], 'precondition: should be blocked before clear()');

        $limiter->clear($this->action, $this->identity);

        $afterClear = $limiter->attempt($this->action, $this->identity, maxAttempts: 1, windowSeconds: 900);
        $this->assertTrue($afterClear['allowed'], 'clear() should reset the count so the next attempt is allowed again');
    }
}
