<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
header('Content-Type: application/json');
AuthMiddleware::startSession();
$me=AuthMiddleware::requireAuth(true);
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['error'=>'Method not allowed']);exit;}
$body=json_decode(file_get_contents('php://input'),true)??[];$addresseeId=(int)($body['addressee_id']??0);$addresseeName=trim((string)($body['addressee_name']??''));
try{
 $db=Database::getInstance();
 if(!$addresseeId&&$addresseeName!==''){ $s=$db->prepare('SELECT id FROM users WHERE (username=:n OR full_name=:n2) AND deleted_at IS NULL LIMIT 1');$s->execute([':n'=>$addresseeName,':n2'=>$addresseeName]);$addresseeId=(int)($s->fetchColumn()?:0); }
 if(!$addresseeId||$addresseeId===(int)$me['id']){http_response_code(400);echo json_encode(['error'=>'Invalid addressee']);exit;}
 $pref=$db->prepare('SELECT connection_requests FROM user_settings WHERE user_id=:id LIMIT 1');$pref->execute([':id'=>$addresseeId]);$allow=$pref->fetchColumn();
 if($allow!==false&&(int)$allow===0){http_response_code(403);echo json_encode(['error'=>'This user is not accepting connection requests']);exit;}
 $chk=$db->prepare('SELECT id,status FROM friendships WHERE (requester_id=:me AND addressee_id=:them) OR (requester_id=:them2 AND addressee_id=:me2) LIMIT 1');$chk->execute([':me'=>$me['id'],':them'=>$addresseeId,':them2'=>$addresseeId,':me2'=>$me['id']]);$existing=$chk->fetch(PDO::FETCH_ASSOC);
 if($existing){echo json_encode(['success'=>true,'status'=>$existing['status'],'message'=>$existing['status']==='accepted'?'Already connected':'Request already sent']);exit;}
 $ins=$db->prepare("INSERT INTO friendships (requester_id,addressee_id,status) VALUES (:me,:them,'pending')");$ins->execute([':me'=>$me['id'],':them'=>$addresseeId]);$reqId=(int)$db->lastInsertId();
 try{$senderName=$me['full_name']?:$me['username'];$n=$db->prepare("INSERT INTO notifications (user_id,type,title,body,ref_id,is_read,created_at) VALUES (:uid,'connection_request',:title,:body,:ref_id,0,NOW())");$n->execute([':uid'=>$addresseeId,':title'=>$senderName.' wants to connect with you',':body'=>'Tap Accept or Decline in your notifications',':ref_id'=>$reqId]);}catch(Throwable $ne){error_log('[send-request] notification insert failed: '.$ne->getMessage());}
 echo json_encode(['success'=>true,'status'=>'pending','request_id'=>$reqId,'requester'=>['id'=>$me['id'],'username'=>$me['username'],'fullName'=>$me['full_name'],'gradient'=>$me['avatar_color_gradient']??''],'addressee_id'=>$addresseeId,'message'=>'Connection request sent']);
}catch(Throwable $e){error_log('[send-request] '.$e->getMessage());http_response_code(500);echo json_encode(['error'=>'Server error']);}
