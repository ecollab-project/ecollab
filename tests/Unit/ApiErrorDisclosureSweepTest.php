<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ApiErrorDisclosureSweepTest extends TestCase
{
    /**
     * M3 invariant: exception messages may be logged server-side, but API and
     * service source must not directly expose Throwable::getMessage() to a
     * client response. ApiErrorResponder is the single approved response
     * boundary and lives outside these directories.
     */
    public function testApiAndServiceSourceContainsNoDirectExceptionMessageExposure(): void
    {
        $root = dirname(__DIR__, 2);
        $directories = [
            $root . '/API',
            $root . '/services',
        ];

        $violations = [];

        foreach ($directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }

                $path = $file->getPathname();
                $lines = file($path, FILE_IGNORE_NEW_LINES);
                if ($lines === false) {
                    $violations[] = $path . ': unable to read source file';
                    continue;
                }

                foreach ($lines as $lineNumber => $line) {
                    if (!str_contains($line, '->getMessage()')) {
                        continue;
                    }

                    // Server-side logging is allowed. Anything else in an API
                    // or service file must use a safe public error boundary.
                    if (str_contains($line, 'error_log(')) {
                        continue;
                    }

                    $violations[] = sprintf(
                        '%s:%d contains direct Throwable::getMessage() outside server-side logging',
                        str_replace($root . DIRECTORY_SEPARATOR, '', $path),
                        $lineNumber + 1
                    );
                }
            }
        }

        $this->assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    public function testHealthEndpointDoesNotContainPreviouslyLeakedFields(): void
    {
        $path = dirname(__DIR__, 2) . '/API/system/health.php';
        $source = file_get_contents($path);

        $this->assertIsString($source);
        $this->assertStringNotContainsString("'error_msg'", $source);
        $this->assertStringNotContainsString('"error_msg"', $source);
        $this->assertStringNotContainsString("'ip_address'", $source);
        $this->assertStringNotContainsString('"ip_address"', $source);
        $this->assertStringContainsString("unset($schema['error'])", $source);
        $this->assertStringContainsString("$user = AuthMiddleware::requireAuth(true);", $source);
        $this->assertStringContainsString("$level !== 'full'", $source);
    }
}
