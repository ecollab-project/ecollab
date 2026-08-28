<?php

declare(strict_types=1);

/**
 * Safe API error responses.
 *
 * Internal exception details are logged server-side but are never returned to
 * production clients. Development may opt into the existing APP_DEBUG detail
 * behavior without changing the public error contract in production.
 */
final class ApiErrorResponder
{
    public static function publicMessage(Throwable $e, string $fallback = 'Server error.'): string
    {
        return defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : $fallback;
    }

    public static function throwable(string $context, Throwable $e, int $status = 500, string $publicMessage = 'Server error.'): never
    {
        error_log(sprintf('[%s] %s', $context, $e->getMessage()));

        if ($status < 400 || $status > 599) {
            $status = 500;
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => self::publicMessage($e, $publicMessage),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
