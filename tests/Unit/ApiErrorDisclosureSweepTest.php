<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ApiErrorDisclosureSweepTest extends TestCase
{
    public function testApiSourceHasNoDirectExceptionDetailsAtResponseSinks(): void
    {
        $root = dirname(__DIR__, 2);
        $directory = $root . '/API';
        $violations = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
            $path = $file->getPathname(); $lines = file($path, FILE_IGNORE_NEW_LINES);
            if ($lines === false) { $violations[] = $path . ': unable to read source file'; continue; }
            foreach ($lines as $lineNumber => $line) {
                if (!str_contains($line, '->getMessage()') || str_contains($line, 'error_log(')) continue;
                if (str_contains($line, "'detail'") || str_contains($line, '"detail"')) continue;
                $violations[] = sprintf('%s:%d contains Throwable::getMessage() outside a safe response boundary', str_replace($root . DIRECTORY_SEPARATOR, '', $path), $lineNumber + 1);
            }
        }
        $this->assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    public function testHealthEndpointDoesNotContainPreviouslyLeakedFields(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/API/system/health.php');
        $this->assertIsString($source);
        $this->assertStringNotContainsString("'error_msg'", $source);
        $this->assertStringNotContainsString('"error_msg"', $source);
        $this->assertStringNotContainsString("'ip_address'", $source);
        $this->assertStringNotContainsString('"ip_address"', $source);
        $this->assertStringContainsString("unset(\$schema['error'])", $source);
        $this->assertStringContainsString("\$user = AuthMiddleware::requireAuth(true);", $source);
        $this->assertStringContainsString("\$level !== 'full'", $source);
    }

    public function testAppDebugIsServerControlled(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/config.php');
        $this->assertIsString($source);
        $this->assertMatchesRegularExpression("/define\('APP_DEBUG',\s*env\('APP_DEBUG',\s*'false'\) === 'true'\);/", $source);
        $this->assertStringNotContainsString("\$_GET['APP_DEBUG']", $source);
        $this->assertStringNotContainsString("\$_POST['APP_DEBUG']", $source);
        $this->assertStringNotContainsString("\$_REQUEST['APP_DEBUG']", $source);
        $this->assertStringNotContainsString('HTTP_APP_DEBUG', $source);
    }

    public function testCentralDisclosureGuardRemovesLegacyDetailFields(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/config.php');
        $this->assertIsString($source);
        $this->assertStringContainsString('ECOLLAB_API_DISCLOSURE_GUARD', $source);
        $this->assertStringContainsString("array_key_exists('detail', \$decoded)", $source);
        $this->assertStringContainsString("unset(\$decoded['detail'])", $source);
    }
}
