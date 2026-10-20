<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';require_once ROOT_PATH.'/database/config/db.php';require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
header('Content-Type: application/json');AuthMiddleware::startSession();$me=AuthMiddleware::requireAuth(true);$db=Database::getInstance();
$s=$db->prepare('SELECT id,status,reason,review_note,created_at,reviewed_at FROM facilitator_requests WHERE user_id=? ORDER BY id DESC LIMIT 1');$s->execute([$me['id']]);
echo json_encode(['success'=>true,'role'=>$me['role'],'request'=>$s->fetch(PDO::FETCH_ASSOC)?:null]);
