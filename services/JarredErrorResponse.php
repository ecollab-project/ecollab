<?php
declare(strict_types=1);
/** Codes select fixed application text. Throwable messages are never client-safe evidence. */
final class JarredErrorResponse
{
    public static function map(Throwable $error, bool $commitUnknown = false): array
    {
        if ($commitUnknown) return [503,'Submission outcome is unknown. Check this conversation before retrying.'];
        return match ($error->getCode()) {
            400 => [400,'Invalid request.'],
            403 => [403,'Access denied.'],
            409 => [409,'Jarred is still replying. Wait or cancel the pending reply.'],
            503 => [503,'Jarred is temporarily unavailable. Try again shortly.'],
            default => [500,'Server error.'],
        };
    }
}
