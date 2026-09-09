<?php

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH . '/services/CoworkspaceService.php';
AuthMiddleware::startSession(); $user=AuthMiddleware::requireAuth(true); header('Content-Type: application/json; charset=utf-8'); $db=Database::getInstance(); $uid=(int)$user['id'];
function presenceFail(string $message,int $status=400):never{http_response_code($status);echo json_encode(['success'=>false,'error'=>$message]);exit;}
try{
 $method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');$input=json_decode(file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;$documentId=(int)($input['document_id']??$_GET['document_id']??0);if($documentId<1)presenceFail('A document is required.');
 $stmt=$db->prepare('SELECT d.id,d.workspace_id FROM collab_documents d WHERE d.id=:did LIMIT 1');$stmt->execute([':did'=>$documentId]);$document=$stmt->fetch(PDO::FETCH_ASSOC);if(!$document||(int)$document['workspace_id']<1)presenceFail('Document not found.',404);CoworkspaceService::get($db,(int)$document['workspace_id'],$uid);
 if($method==='GET'){$stmt=$db->prepare('SELECT p.user_id,p.mode,p.last_seen,COALESCE(NULLIF(u.full_name,""),u.username) AS display_name FROM collab_document_presence p INNER JOIN users u ON u.id=p.user_id WHERE p.document_id=:did AND p.last_seen >= (NOW() - INTERVAL 45 SECOND) ORDER BY p.mode,display_name');$stmt->execute([':did'=>$documentId]);echo json_encode(['success'=>true,'document_id'=>$documentId,'presence'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);exit;}
 if($method!=='POST')presenceFail('Method not allowed.',405);AuthMiddleware::verifyCsrf();$mode=strtolower(trim((string)($input['mode']??'viewing')));if(!in_array($mode,['editing','viewing'],true))$mode='viewing';$stmt=$db->prepare('INSERT INTO collab_document_presence(document_id,user_id,mode,last_seen) VALUES(:did,:uid,:mode,NOW()) ON DUPLICATE KEY UPDATE mode=VALUES(mode),last_seen=NOW()');$stmt->execute([':did'=>$documentId,':uid'=>$uid,':mode'=>$mode]);echo json_encode(['success'=>true,'document_id'=>$documentId,'mode'=>$mode]);
}catch(Throwable $e){error_log('[document-presence] '.get_class($e).': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());http_response_code(500);echo json_encode(['success'=>false,'error'=>(defined('APP_DEBUG')&&APP_DEBUG)?$e->getMessage():'Something went wrong.']);exit;}
