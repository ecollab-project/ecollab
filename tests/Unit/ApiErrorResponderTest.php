<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/security/ApiErrorResponder.php';

final class ApiErrorResponderTest extends TestCase
{
    public function testProductionMessageDoesNotExposeExceptionDetails(): void
    {
        if (!defined('APP_DEBUG')) {
            define('APP_DEBUG', false);
        }

        $this->assertFalse(
            APP_DEBUG,
            'ApiErrorResponder security tests must execute with APP_DEBUG=false; skipping would create false confidence.'
        );

        $exception = new \RuntimeException('SQLSTATE[42S02]: secret_table does not exist');

        $this->assertSame(
            'Internal server error.',
            \ApiErrorResponder::publicMessage($exception, 'Internal server error.')
        );
    }

    public function testGenericFallbackIsReturnedForArbitraryExceptionDetails(): void
    {
        if (!defined('APP_DEBUG')) {
            define('APP_DEBUG', false);
        }

        $this->assertFalse(APP_DEBUG);

        $exception = new \RuntimeException('PDO password=super-secret host=db.internal trace=private');

        $this->assertSame(
            'Request could not be completed.',
            \ApiErrorResponder::publicMessage($exception, 'Request could not be completed.')
        );
    }
}
