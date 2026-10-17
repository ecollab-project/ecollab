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

    /** Server-only room API. Credentials never leave PHP. */
    public function roomRequest(string $method, array $body, array $grant): array
    {
        $now = time();
        $token = $this->jwt(['alg'=>'HS256','typ'=>'JWT'], [
            'iss'=>$this->apiKey, 'nbf'=>$now-5, 'exp'=>$now+60, 'video'=>$grant,
        ]);
        $url = preg_replace('/^ws/', 'http', rtrim($this->wsUrl, '/'));
        $ch = curl_init($url . '/twirp/livekit.RoomService/' . $method);
        curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>8,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json', 'Authorization: Bearer '.$token],
            CURLOPT_POSTFIELDS=>json_encode($body, JSON_THROW_ON_ERROR)]);
        try {
            $response = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($response === false || $status < 200 || $status >= 300) {
                throw new RuntimeException('LiveKit room service unavailable (HTTP '.$status.').');
            }
            return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } finally { curl_close($ch); }
    }

    public function ensureRoom(string $room, int $limit): void
    {
        if (!preg_match('/^ecollab-[a-z0-9-]+$/', $room) || $limit < 2 || $limit > 50) {
            throw new InvalidArgumentException('Invalid room configuration.');
        }
        $created = $this->roomRequest('CreateRoom', ['name'=>$room, 'max_participants'=>$limit,
            'empty_timeout'=>120, 'departure_timeout'=>20], ['roomCreate'=>true]);
        if ((int)($created['max_participants'] ?? $created['maxParticipants'] ?? 0) !== $limit) {
            throw new RuntimeException('This room must be emptied before its participant limit can change.');
        }
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

