<?php
declare(strict_types=1);

/** Forwarded addresses are accepted only from explicitly trusted proxy addresses/CIDRs. */
final class ClientIp
{
    public static function resolve(array $server): string
    {
        $peer = $server['REMOTE_ADDR'] ?? '';
        if (!is_string($peer) || !filter_var($peer, FILTER_VALIDATE_IP)) return '0.0.0.0';
        $trusted = array_filter(array_map('trim', explode(',', (string)(getenv('TRUSTED_PROXIES') ?: ''))));
        if (!self::trusted($peer, $trusted)) return $peer;
        $forwarded = $server['HTTP_X_FORWARDED_FOR'] ?? '';
        if (is_string($forwarded) && $forwarded !== '' && strlen($forwarded) <= 4096) {
            $chain = array_map('trim', explode(',', $forwarded));
            // Walk from the actual peer back to the first untrusted hop, not the spoofable left edge.
            foreach (array_reverse($chain) as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP)) return $peer;
                if (!self::trusted($ip, $trusted)) return $ip;
            }
            return $chain[0] ?: $peer;
        }
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $header) {
            $ip = $server[$header] ?? '';
            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
        return $peer;
    }

    private static function trusted(string $ip, array $ranges): bool
    {
        $packed = inet_pton($ip);
        foreach ($ranges as $range) {
            $parts = explode('/', $range, 2);
            $network = @inet_pton($parts[0]);
            if ($network === false || strlen($network) !== strlen($packed)) continue;
            $bits = $parts[1] ?? (string)(strlen($network) * 8);
            if (!preg_match('/^\d+$/D', $bits) || (int)$bits > strlen($network) * 8) continue;
            $bytes = intdiv((int)$bits, 8); $remainder = (int)$bits % 8;
            if (substr($packed, 0, $bytes) !== substr($network, 0, $bytes)) continue;
            if (!$remainder || ((ord($packed[$bytes]) ^ ord($network[$bytes])) & (255 << (8-$remainder))) === 0) return true;
        }
        return false;
    }
}
