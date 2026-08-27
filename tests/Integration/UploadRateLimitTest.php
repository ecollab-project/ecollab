<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

final class UploadRateLimitTest extends TestCase
{
    private \PDO $db;
    private string $identity;

    protected function setUp(): void
    {
        $this->db = \Database::getInstance();
        $this->identity = 'm2-upload-test-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        $key = 'upload:' . hash('sha256', $this->identity);
        $this->db->prepare('DELETE FROM rate_limit_log WHERE lookup_key = :key')
            ->execute([':key' => $key]);
    }

    public function testUploadRateLimitAllowsTenAttemptsAndBlocksTheEleventh(): void
    {
        $limiter = new \RateLimiter();

        for ($i = 1; $i <= 10; $i++) {
            $result = $limiter->attempt('upload', $this->identity, 10, 600);
            $this->assertTrue($result['allowed'], "attempt {$i} should be allowed");
        }

        $blocked = $limiter->attempt('upload', $this->identity, 10, 600);

        $this->assertFalse($blocked['allowed']);
        $this->assertSame(10, $blocked['attempts']);
        $this->assertGreaterThan(0, $blocked['retry_after']);
    }
}
