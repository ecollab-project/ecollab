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

    private static function conversationPeer(PDO $db,int $conversationId,int $userId,int $peerId): bool {
        $stmt=$db->prepare('SELECT 1 FROM dm_conversations WHERE id=:cid AND ((user_a=:uid_a AND user_b=:peer_a) OR (user_b=:uid_b AND user_a=:peer_b)) LIMIT 1');
        $stmt->execute([':cid'=>$conversationId,':uid_a'=>$userId,':peer_a'=>$peerId,':uid_b'=>$userId,':peer_b'=>$peerId]); return (bool)$stmt->fetchColumn();
    }
}
