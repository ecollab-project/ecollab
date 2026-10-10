<?php
declare(strict_types=1);

/** Local-only, best-effort semantic scoring. No identities or messages leave PHP. */
class PeerSemanticClient
{
    public static function profileText(array $profile): string
    {
        $labels = [];
        foreach (['subjects', 'interests', 'hobbies'] as $key) {
            foreach (array_slice($profile[$key] ?? [], 0, 20) as $tag) {
                $name = trim((string)($tag['name'] ?? ''));
                if ($name !== '') $labels[] = function_exists('mb_substr') ? mb_substr($name, 0, 80) : substr($name, 0, 80);
            }
        }
        $labels = array_values(array_unique($labels));
        sort($labels, SORT_STRING);
        $text = implode('; ', $labels);
        return function_exists('mb_substr') ? mb_substr($text, 0, 1500) : substr($text, 0, 1500);
    }

    /** Returns scores in candidate order, or nulls on any protocol/service failure. */
    public function scores(array $profile, array $candidates): array
    {
        $fallback = array_fill(0, count($candidates), null);
        if (!filter_var($_ENV['PEER_ML_ENABLED'] ?? getenv('PEER_ML_ENABLED') ?: false, FILTER_VALIDATE_BOOLEAN)
            || !$candidates || count($candidates) > 100) return $fallback;
        $query = self::profileText($profile);
        if ($query === '') return $fallback;
        $texts = array_map([self::class, 'profileText'], array_values($candidates));
        try {
            $data = $this->request(['query'=>$query, 'candidates'=>$texts]);
            if (($data['version'] ?? null) !== 'semantic-v1' || !is_array($data['scores'] ?? null)
                || count($data['scores']) !== count($texts) || !array_is_list($data['scores'])) return $fallback;
            foreach ($data['scores'] as $i => $score) {
                if ($score === null) continue;
                if ((!is_int($score) && !is_float($score)) || !is_finite((float)$score) || $score < 0 || $score > 100) return array_fill(0, count($candidates), null);
                if ($texts[$i] !== '') $fallback[$i] = (float)$score;
            }
            return $fallback;
        } catch (Throwable $e) {
            return array_fill(0, count($candidates), null);
        }
    }

    protected function request(array $payload): array
    {
        if (!function_exists('curl_init')) return [];
        $url = (string)($_ENV['PEER_ML_URL'] ?? getenv('PEER_ML_URL') ?: 'http://127.0.0.1:8091');
        // Fixed numeric loopback prevents accidental external profile disclosure/SSRF.
        if (!preg_match('~^http://127\.0\.0\.1:[0-9]{1,5}/?$~D', $url)) return [];
        $token = (string)($_ENV['PEER_ML_TOKEN'] ?? getenv('PEER_ML_TOKEN') ?: '');
        if ($token === '' || preg_match('/[\r\n]/', $token)) return [];
        $timeout = max(100, min(3000, (int)($_ENV['PEER_ML_TIMEOUT_MS'] ?? getenv('PEER_ML_TIMEOUT_MS') ?: 1500)));
        $ch = curl_init(rtrim($url, '/') . '/v1/similarity');
        $response = '';
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_CONNECTTIMEOUT_MS => min(200, $timeout),
            CURLOPT_TIMEOUT_MS => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > 32768) return 0;
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            $ok = curl_exec($ch);
            if ($ok === false || curl_getinfo($ch, CURLINFO_RESPONSE_CODE) !== 200) return [];
            $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } finally {
            curl_close($ch);
        }
    }
}
