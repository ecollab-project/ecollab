<?php

declare(strict_types=1);

use Ratchet\ConnectionInterface;

class DmHandler
{
    public static function handleDmMessage(ConnectionInterface $from, array $data, array $meta, array $userConns, PDO $db): void {
        $recipientId=(int)($data['recipient_id']??0); $conversationId=(int)($data['conversation_id']??0); $messageId=(int)($data['message_id']??0);
        if (!$recipientId||!$conversationId||!$messageId) return;
        $messageStmt=$db->prepare('SELECT dm.body,dm.created_at FROM dm_messages dm JOIN dm_conversations dc ON dc.id=dm.conversation_id WHERE dm.id=:mid AND dm.conversation_id=:cid AND dm.sender_id=:sender AND dm.is_deleted=0 AND ((dc.user_a=:sender_a AND dc.user_b=:recipient_a) OR (dc.user_b=:sender_b AND dc.user_a=:recipient_b)) LIMIT 1');
        $messageStmt->execute([':mid'=>$messageId,':cid'=>$conversationId,':sender'=>(int)$meta['user_id'],':sender_a'=>(int)$meta['user_id'],':recipient_a'=>$recipientId,':sender_b'=>(int)$meta['user_id'],':recipient_b'=>$recipientId]);
        $message=$messageStmt->fetch(PDO::FETCH_ASSOC); if (!$message) return;
        $payload=json_encode(['type'=>'dm_message','conversation_id'=>$conversationId,'message_id'=>$messageId,'sender_id'=>$meta['user_id'],'sender_name'=>$meta['full_name']??$meta['username'],'sender_gradient'=>$meta['gradient']??'','body'=>$message['body'],'created_at'=>$message['created_at']]);
        if(isset($userConns[$recipientId])){try{$userConns[$recipientId]->send($payload);}catch(\Throwable){}}
        try{$from->send($payload);}catch(\Throwable){}
    }

    public static function handleDmGroupMessage(ConnectionInterface $from, array $data, array $meta, array $userConns, PDO $db): void {
        $groupId=(int)($data['group_id']??0); $messageId=(int)($data['message_id']??0); $callerId=(int)$meta['user_id'];
        if (!$groupId||!$messageId) return;

        // Verify the message exists, belongs to this group, and was actually sent by the caller —
        // same verification discipline as handleDmMessage, not trusted client input.
        $messageStmt=$db->prepare('SELECT dm.body,dm.created_at FROM dm_messages dm JOIN dm_group_members gm ON gm.group_id=dm.group_id AND gm.user_id=:caller WHERE dm.id=:mid AND dm.group_id=:gid AND dm.sender_id=:sender AND dm.is_deleted=0 LIMIT 1');
        $messageStmt->execute([':mid'=>$messageId,':gid'=>$groupId,':sender'=>$callerId,':caller'=>$callerId]);
        $message=$messageStmt->fetch(PDO::FETCH_ASSOC); if (!$message) return;

        $memStmt=$db->prepare('SELECT user_id FROM dm_group_members WHERE group_id=:gid AND user_id != :caller');
        $memStmt->execute([':gid'=>$groupId,':caller'=>$callerId]);
        $memberIds=$memStmt->fetchAll(PDO::FETCH_COLUMN);

        $payload=json_encode(['type'=>'dm_group_message','group_id'=>$groupId,'message_id'=>$messageId,'sender_id'=>$callerId,'sender_name'=>$meta['full_name']??$meta['username'],'sender_gradient'=>$meta['gradient']??'','body'=>$message['body'],'created_at'=>$message['created_at']]);
        foreach ($memberIds as $memberId) {
            $memberId=(int)$memberId;
            if (isset($userConns[$memberId])) { try{$userConns[$memberId]->send($payload);}catch(\Throwable){} }
        }
        try{$from->send($payload);}catch(\Throwable){}
    }

    public static function handleDmGroupTyping(ConnectionInterface $from, array $data, array $meta, array $userConns, PDO $db): void {
        $groupId=(int)($data['group_id']??0); $callerId=(int)$meta['user_id'];
        if (!$groupId) return;
        $mem=$db->prepare('SELECT 1 FROM dm_group_members WHERE group_id=:gid AND user_id=:uid');
        $mem->execute([':gid'=>$groupId,':uid'=>$callerId]);
        if (!$mem->fetchColumn()) return;

        $memStmt=$db->prepare('SELECT user_id FROM dm_group_members WHERE group_id=:gid AND user_id != :caller');
        $memStmt->execute([':gid'=>$groupId,':caller'=>$callerId]);
        $memberIds=$memStmt->fetchAll(PDO::FETCH_COLUMN);

        $payload=json_encode(['type'=>'dm_group_typing','group_id'=>$groupId,'sender_id'=>$callerId,'sender_name'=>$meta['full_name']??$meta['username'],'is_typing'=>(bool)($data['is_typing']??false)]);
        foreach ($memberIds as $memberId) {
            $memberId=(int)$memberId;
            if (isset($userConns[$memberId])) { try{$userConns[$memberId]->send($payload);}catch(\Throwable){} }
        }
    }

    public static function handleDmTyping(ConnectionInterface $from,array $data,array $meta,array $userConns, PDO $db): void {
        $recipientId=(int)($data['recipient_id']??0); $conversationId=(int)($data['conversation_id']??0);
        if(!$recipientId||!$conversationId||!self::conversationPeer($db,$conversationId,(int)$meta['user_id'],$recipientId)||!isset($userConns[$recipientId])) return;
        $payload=json_encode(['type'=>'dm_typing','conversation_id'=>$conversationId,'sender_id'=>$meta['user_id'],'sender_name'=>$meta['full_name']??$meta['username'],'is_typing'=>(bool)($data['is_typing']??false)]);
        try{$userConns[$recipientId]->send($payload);}catch(\Throwable){}
    }

    public static function handleNotifyConnReq(ConnectionInterface $from,array $data,array $meta,array $userConns, PDO $db): void {
        $addresseeId=(int)($data['addressee_id']??0); $requestId=(int)($data['request_id']??0); $callerId=(int)$meta['user_id'];
        if(!$addresseeId||!$requestId||$addresseeId===$callerId) return;
        $stmt=$db->prepare('SELECT 1 FROM connection_requests WHERE id=:rid AND requester_id=:uid AND addressee_id=:aid LIMIT 1');
        $stmt->execute([':rid'=>$requestId,':uid'=>$callerId,':aid'=>$addresseeId]);
        if(!$stmt->fetchColumn()||!isset($userConns[$addresseeId])) return;
        $payload=json_encode(['type'=>'connection_request','request_id'=>$requestId,'requester'=>['id'=>$callerId,'fullName'=>$meta['full_name']??$meta['username'],'gradient'=>$meta['gradient']??'']]);
        try{$userConns[$addresseeId]->send($payload);}catch(\Throwable){}
    }

    public static function handleNotifyConnAccepted(ConnectionInterface $from,array $data,array $meta,array $userConns, PDO $db): void {
        $requesterId=(int)($data['requester_id']??0); $requestId=(int)($data['request_id']??0); $callerId=(int)$meta['user_id'];
        if(!$requesterId||$requesterId===$callerId||!isset($userConns[$requesterId])) return;
        $where='requester_id=:requester AND addressee_id=:caller'; $params=[':requester'=>$requesterId,':caller'=>$callerId];
        if($requestId){$where='id=:rid AND requester_id=:requester AND addressee_id=:caller';$params[':rid']=$requestId;}
        $stmt=$db->prepare("SELECT 1 FROM connection_requests WHERE {$where} AND status='accepted' LIMIT 1"); $stmt->execute($params);
        if(!$stmt->fetchColumn()) return;
        $payload=json_encode(['type'=>'connection_accepted','accepted_by'=>['id'=>$callerId,'fullName'=>$meta['full_name']??$meta['username'],'gradient'=>$meta['gradient']??'']]);
        try{$userConns[$requesterId]->send($payload);}catch(\Throwable){}
    }

    public static function handleVoiceInvite(ConnectionInterface $from, array $data, array $meta, array $userConns, PDO $db): void {
        $targetId  = (int)($data['target_user_id'] ?? 0);
        $channelId = (int)($data['channel_id'] ?? 0);
        $callerId  = (int)$meta['user_id'];
        if (!$targetId || !$channelId || $targetId === $callerId || !isset($userConns[$targetId])) return;

        // Verify: the channel is real and voice-type, the caller belongs to that
        // server, and so does the target — otherwise this is just spam to a
        // stranger who couldn't even join the channel being invited to.
        $stmt = $db->prepare("
            SELECT c.name, c.server_id, s.name AS server_name
            FROM channels c
            JOIN servers s ON s.id = c.server_id
            JOIN server_members caller_sm ON caller_sm.server_id = c.server_id AND caller_sm.user_id = :caller
            JOIN server_members target_sm ON target_sm.server_id = c.server_id AND target_sm.user_id = :target
            WHERE c.id = :cid AND c.type = 'voice'
            LIMIT 1
        ");
        $stmt->execute([':caller' => $callerId, ':target' => $targetId, ':cid' => $channelId]);
        $channel = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$channel) return;

        $payload = json_encode([
            'type'         => 'voice_invite',
            'channel_id'   => $channelId,
            'channel_name' => $channel['name'],
            'server_id'    => (int)$channel['server_id'],
            'server_name'  => $channel['server_name'],
            'from'         => ['id' => $callerId, 'fullName' => $meta['full_name'] ?? $meta['username'], 'gradient' => $meta['gradient'] ?? ''],
        ]);
        try { $userConns[$targetId]->send($payload); } catch (\Throwable) {}
    }

    public static function handleDmCallSignal(ConnectionInterface $from, array $data, array $meta, array $userConns, PDO $db, string $type): void {
        $callerId = (int)$meta['user_id'];
        $targetId = (int)($data['target_user_id'] ?? 0);
        $groupId  = (int)($data['group_id'] ?? 0);
        $logId    = (int)($data['log_id'] ?? 0);
        if (!$targetId || $targetId === $callerId || !isset($userConns[$targetId])) return;

        // Verify a real relationship exists before relaying any signal — a
        // DM conversation with the target, or shared group membership. Same
        // discipline as handleDmMessage: never trust the client's claim
        // that these two people are actually allowed to reach each other.
        if ($groupId) {
            $chk = $db->prepare('SELECT 1 FROM dm_group_members WHERE group_id=:gid AND user_id=:caller
                                  AND EXISTS(SELECT 1 FROM dm_group_members WHERE group_id=:gid2 AND user_id=:target)');
            $chk->execute([':gid' => $groupId, ':caller' => $callerId, ':gid2' => $groupId, ':target' => $targetId]);
        } else {
            $chk = $db->prepare('SELECT 1 FROM dm_conversations WHERE
                (user_a=:a1 AND user_b=:b1) OR (user_a=:a2 AND user_b=:b2)');
            $chk->execute([':a1' => min($callerId,$targetId), ':b1' => max($callerId,$targetId), ':a2' => min($callerId,$targetId), ':b2' => max($callerId,$targetId)]);
        }
        if (!$chk->fetchColumn()) return;

        // Call history logging — kept in the same handler as the signal
        // itself rather than a separate HTTP round trip, so the log always
        // reflects exactly what was actually relayed.
        if ($type === 'dm_call_offer') {
            $ins = $db->prepare('INSERT INTO dm_call_history (caller_id, callee_id, group_id, is_video, status)
                                  VALUES (:caller, :callee, :gid, :video, "ringing")');
            $ins->execute([
                ':caller' => $callerId,
                ':callee' => $groupId ? null : $targetId,
                ':gid'    => $groupId ?: null,
                ':video'  => (int)(bool)($data['is_video'] ?? false),
            ]);
            $logId = (int)$db->lastInsertId();
        } elseif ($logId) {
            if ($type === 'dm_call_answer') {
                $db->prepare('UPDATE dm_call_history SET status="answered", answered_at=NOW() WHERE id=:id')
                    ->execute([':id' => $logId]);
            } elseif ($type === 'dm_call_decline') {
                $db->prepare('UPDATE dm_call_history SET status="declined", ended_at=NOW() WHERE id=:id AND status="ringing"')
                    ->execute([':id' => $logId]);
            } elseif ($type === 'dm_call_end') {
                $db->prepare("
                    UPDATE dm_call_history
                    SET status = IF(status = 'answered', 'ended', 'missed'),
                        ended_at = NOW(),
                        duration_seconds = IF(status = 'answered', TIMESTAMPDIFF(SECOND, answered_at, NOW()), NULL)
                    WHERE id = :id AND status IN ('ringing','answered')
                ")->execute([':id' => $logId]);
            }
        }

        $payload = json_encode([
            'type'          => $type,
            'from_user_id'  => $callerId,
            'from_username' => $meta['full_name'] ?? $meta['username'],
            'from_gradient' => $meta['gradient'] ?? '',
            'group_id'      => $groupId ?: null,
            'log_id'        => $logId ?: null,
            'is_video'      => (bool)($data['is_video'] ?? false),
            'sdp'           => $data['sdp'] ?? null,
            'candidate'     => $data['candidate'] ?? null,
        ]);
        foreach ($userConns[$targetId] as $conn) {
            try { $conn->send($payload); } catch (\Throwable) {}
        }

        // Echo the log id back to the caller too, so both sides can
        // reference it in later signals (answer/decline/end) without a
        // separate round trip.
        if ($type === 'dm_call_offer') {
            $echo = json_encode(['type' => 'dm_call_offer_sent', 'log_id' => $logId]);
            try { $from->send($echo); } catch (\Throwable) {}
        }
    }

    private static function conversationPeer(PDO $db,int $conversationId,int $userId,int $peerId): bool {
        $stmt=$db->prepare('SELECT 1 FROM dm_conversations WHERE id=:cid AND ((user_a=:uid_a AND user_b=:peer_a) OR (user_b=:uid_b AND user_a=:peer_b)) LIMIT 1');
        $stmt->execute([':cid'=>$conversationId,':uid_a'=>$userId,':peer_a'=>$peerId,':uid_b'=>$userId,':peer_b'=>$peerId]); return (bool)$stmt->fetchColumn();
    }
}
