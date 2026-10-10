<?php
declare(strict_types=1);

/** Persistent recipient-scoped events; delivery errors never undo the action. */
final class NotificationService {
    public static function create(PDO $db, int $recipient, int $actor, string $type, string $title, string $body, string $link, string $icon): void {
        if ($recipient <= 0 || $recipient === $actor) return;
        try {
            $db->prepare('INSERT INTO notifications (recipient_id,actor_id,type,title,body,link_url,icon,is_read) VALUES (?,?,?,?,?,?,?,0)')
                ->execute([$recipient,$actor,$type,mb_substr($title,0,120),mb_substr($body,0,500),$link,$icon]);
        } catch (Throwable $e) { error_log('[notifications/create] '.$e->getMessage()); }
    }
    public static function invitationsFromMessage(PDO $db, int $recipient, int $actor, string $text): void {
        preg_match_all('~https?://[^\s<>"\x27]+~u', $text, $matches);
        $seen = [];
        foreach (array_slice($matches[0],0,10) as $raw) {
            $url = parse_url(rtrim($raw, ').,;'));
            $base = parse_url((string)BASE_URL);
            if (!$url || strtolower($url['host'] ?? '') !== strtolower($base['host'] ?? '') ||
                ($url['scheme'] ?? '') !== ($base['scheme'] ?? '') ||
                ($url['port'] ?? null) !== ($base['port'] ?? null) ||
                ($url['path'] ?? '') !== rtrim($base['path'] ?? '', '/').'/modules/chat/chat.php') continue;
            parse_str($url['query'] ?? '', $params);
            foreach (['invite'=>['server_invites','Server invitation'], 'channel_invite'=>['channel_invites','Channel invitation']] as $key=>$kind) {
                $token = $params[$key] ?? '';
                if (!is_string($token) || !preg_match('/^[A-Za-z0-9_-]{20,80}$/', $token) || isset($seen[$key.$token])) continue;
                try {
                    $query = $db->prepare('SELECT 1 FROM '.$kind[0].' WHERE token_hash=? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>NOW()) AND (max_uses=0 OR use_count<max_uses) LIMIT 1');
                    $query->execute([hash('sha256',$token)]);
                    if ($query->fetchColumn()) {
                        $seen[$key.$token] = true;
                        self::create($db,$recipient,$actor,'room_invite',$kind[1],
                            'Open this invitation to join. Access is checked when you open it.',
                            '/modules/chat/chat.php?'.$key.'='.rawurlencode($token),'🔒');
                    }
                } catch (Throwable $e) { error_log('[notifications/invite] '.$e->getMessage()); }
            }
        }
    }

    /** Recover calls abandoned when a browser closes before sending its end signal. */
    public static function recoverMissedCalls(PDO $db, int $recipient): void {
        try {
            $query = $db->prepare("SELECT id,caller_id FROM dm_call_history WHERE callee_id=? AND group_id IS NULL AND status='ringing' AND started_at < DATE_SUB(NOW(),INTERVAL 2 MINUTE) LIMIT 50");
            $query->execute([$recipient]);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $call) {
                $update = $db->prepare("UPDATE dm_call_history SET status='missed',ended_at=NOW() WHERE id=? AND status='ringing'");
                $update->execute([(int)$call['id']]);
                if ($update->rowCount() === 1) self::create($db,$recipient,(int)$call['caller_id'],'system','Missed call',
                    'You missed a call. Open the conversation to respond.',
                    '/modules/chat/chat.php?partner_id='.(int)$call['caller_id'].'&missed_call='.(int)$call['id'],'📞');
            }
        } catch (Throwable $e) { error_log('[notifications/missed-call] '.$e->getMessage()); }
    }
}
