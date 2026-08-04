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
        // Unique action name per test run so parallel/repeated runs never
        // collide on leftover rows from a previous run.
        $this->action   = 'phpunit_test_' . uniqid();
        $this->identity = 'test-identity-' . uniqid();
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
        $otherAction = 'phpunit_other_action_' . uniqid();

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
