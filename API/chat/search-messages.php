<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
header('Content-Type: application/json');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);
$q = trim((string)($_GET['q'] ?? ''));
$serverId = (int)($_GET['server_id'] ?? 0);
$channelId = (int)($_GET['channel_id'] ?? 0);
if (mb_strlen($q) < 2) { echo json_encode(['success'=>true,'results'=>[]]); exit; }
try {
  $db=Database::getInstance();
  $member=$db->prepare('SELECT 1 FROM server_members WHERE server_id=:sid AND user_id=:uid LIMIT 1');
  $member->execute([':sid'=>$serverId,':uid'=>$me['id']]);
  if(!$serverId || !$member->fetchColumn()){http_response_code(403);echo json_encode(['error'=>'Server access denied']);exit;}
  $sql="SELECT m.id,m.channel_id,m.sender_id,m.content,m.created_at,c.name AS channel_name,
               u.username,u.full_name
        FROM messages m
        JOIN channels c ON c.id=m.channel_id
        JOIN users u ON u.id=m.sender_id
        WHERE c.server_id=:sid AND m.is_deleted=0
          AND (:cid=0 OR c.id=:cid2)
          AND (m.content LIKE :term OR u.username LIKE :term2 OR u.full_name LIKE :term3)
          AND (COALESCE(c.is_private,0)=0 OR EXISTS(
              SELECT 1 FROM channel_members cm WHERE cm.channel_id=c.id AND cm.user_id=:uid
          ) OR c.created_by=:uid2)
        ORDER BY m.created_at DESC LIMIT 50";
  $st=$db->prepare($sql);$term='%'.$q.'%';
  $st->execute([':sid'=>$serverId,':cid'=>$channelId,':cid2'=>$channelId,':term'=>$term,':term2'=>$term,':term3'=>$term,':uid'=>$me['id'],':uid2'=>$me['id']]);
  echo json_encode(['success'=>true,'results'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
} catch(Throwable $e){error_log('[chat/search-messages] '.$e->getMessage());http_response_code(500);echo json_encode(['error'=>'Server error']);}
