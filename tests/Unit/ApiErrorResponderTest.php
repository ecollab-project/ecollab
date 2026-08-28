<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/security/ApiErrorResponder.php';

final class ApiErrorResponderTest extends TestCase
{
    public function testProductionMessageDoesNotExposeExceptionDetails(): void
    {
        if (defined('APP_DEBUG')) {
            $this->markTestSkipped('APP_DEBUG is already defined by the test bootstrap.');
        }

        define('APP_DEBUG', false);
        $exception = new \RuntimeException('SQLSTATE[42S02]: secret_table does not exist');

        $this->assertSame(
            'Internal server error.',
            \ApiErrorResponder::publicMessage($exception, 'Internal server error.')
        );
    }
}
