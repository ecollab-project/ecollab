<?php
declare(strict_types=1);

final class LiveKitService
{
    private string $apiKey;
    private string $apiSecret;
    private string $wsUrl;

    public function __construct()
    {
        $this->apiKey = trim((string)getenv('LIVEKIT_API_KEY'));
        $this->apiSecret = trim((string)getenv('LIVEKIT_API_SECRET'));
        $this->wsUrl = trim((string)getenv('LIVEKIT_URL'));

        if ($this->apiKey === '' || $this->apiSecret === '' || $this->wsUrl === '') {
            throw new RuntimeException('LiveKit is not configured on this server.');
        }
        if (!preg_match('#^wss://#i', $this->wsUrl) && !preg_match('#^ws://(localhost|127\.0\.0\.1)(:|/)#i', $this->wsUrl)) {
            throw new RuntimeException('LIVEKIT_URL must use wss:// outside local development.');
        }
    }

    public function issueRoomToken(array $user, int $channelId, string $channelName): array
    {
        $now = time();
        $room = 'ecollab-channel-' . $channelId;
        $identity = 'user-' . (int)$user['id'];
        $name = trim((string)($user['full_name'] ?? $user['username'] ?? $identity));

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => $this->apiKey,
            'sub' => $identity,
            'nbf' => $now - 5,
            'iat' => $now,
            'exp' => $now + 600,
            'name' => $name,
            'metadata' => json_encode([
                'ecollab_user_id' => (int)$user['id'],
                'ecollab_channel_id' => $channelId,
            ], JSON_UNESCAPED_SLASHES),
            'video' => [
                'roomJoin' => true,
                'room' => $room,
                'canPublish' => true,
                'canSubscribe' => true,
                'canPublishData' => true,
            ],
        ];

        return [
            'url' => $this->wsUrl,
            'room' => $room,
            'room_name' => $channelName,
            'identity' => $identity,
            'token' => $this->jwt($header, $payload),
            'expires_in' => 600,
        ];
    }

    public function issueNamedRoomToken(array $user, string $room, string $roomName, array $metadata = []): array
    {
        $now = time();
        $identity = 'user-' . (int)$user['id'];
        $name = trim((string)($user['full_name'] ?? $user['username'] ?? $identity));
        if (!preg_match('/^ecollab-[a-z0-9-]+$/', $room)) {
            throw new InvalidArgumentException('Invalid LiveKit room name.');
        }

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => $this->apiKey,
            'sub' => $identity,
            'nbf' => $now - 5,
            'iat' => $now,
            'exp' => $now + 600,
            'name' => $name,
            'metadata' => json_encode(['ecollab_user_id' => (int)$user['id']] + $metadata, JSON_UNESCAPED_SLASHES),
            'video' => [
                'roomJoin' => true,
                'room' => $room,
                'canPublish' => true,
                'canSubscribe' => true,
                'canPublishData' => true,
            ],
        ];

        return [
            'url' => $this->wsUrl,
            'room' => $room,
            'room_name' => $roomName,
            'identity' => $identity,
            'token' => $this->jwt($header, $payload),
            'expires_in' => 600,
        ];
    }

    private function jwt(array $header, array $payload): string
    {
        $h = $this->b64(json_encode($header, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $p = $this->b64(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $h . '.' . $p, $this->apiSecret, true);
        return $h . '.' . $p . '.' . $this->b64($signature);
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
