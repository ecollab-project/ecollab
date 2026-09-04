<?php

declare(strict_types=1);

/**
 * Small self-contained ONLYOFFICE integration layer.
 * eCollab remains the source of truth for auth, membership and files.
 */
final class OnlyOfficeService
{
    public static function documentServerUrl(): string
    {
        return rtrim((string)env('ONLYOFFICE_DOCUMENT_SERVER_URL', ''), '/');
    }

    /** URL that the ONLYOFFICE container/server uses to reach eCollab. */
    private static function storageBaseUrl(): string
    {
        return rtrim((string)env('ONLYOFFICE_STORAGE_BASE_URL', APP_URL), '/');
    }

    public static function jwtSecret(): string
    {
        $secret = (string)env('ONLYOFFICE_JWT_SECRET', '');
        if ($secret === '') {
            throw new RuntimeException('ONLYOFFICE_JWT_SECRET is not configured.');
        }
        return $secret;
    }

    public static function sign(array $payload): string
    {
        $header = self::base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $body = self::base64Url(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $sig = hash_hmac('sha256', $header . '.' . $body, self::jwtSecret(), true);
        return $header . '.' . $body . '.' . self::base64Url($sig);
    }

    public static function verify(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) throw new RuntimeException('Invalid JWT.');
        [$header, $body, $signature] = $parts;
        $expected = self::base64Url(hash_hmac('sha256', $header . '.' . $body, self::jwtSecret(), true));
        if (!hash_equals($expected, $signature)) throw new RuntimeException('Invalid JWT signature.');
        $decoded = json_decode(self::base64UrlDecode($body), true);
        if (!is_array($decoded)) throw new RuntimeException('Invalid JWT payload.');
        if (isset($decoded['exp']) && (int)$decoded['exp'] < time()) throw new RuntimeException('Expired JWT.');
        return $decoded;
    }

    public static function signedFileUrl(int $documentId, string $documentKey): string
    {
        $base = self::storageBaseUrl() . '/API/collaboration/documents/file.php';
        $token = self::sign(['document_id' => $documentId, 'key' => $documentKey, 'exp' => time() + 3600]);
        return $base . '?id=' . $documentId . '&token=' . rawurlencode($token);
    }

    public static function callbackUrl(int $documentId): string
    {
        return self::storageBaseUrl() . '/API/collaboration/documents/callback.php?id=' . $documentId;
    }

    private static function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        $data .= str_repeat('=', (4 - strlen($data) % 4) % 4);
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        if ($decoded === false) throw new RuntimeException('Invalid base64 data.');
        return $decoded;
    }
}
