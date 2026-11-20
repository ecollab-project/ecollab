<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/database/config/db.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/security/rate-limit/RateLimiter.php';
require_once ROOT_PATH.'/services/JarredReplyQueue.php';
require_once ROOT_PATH.'/services/UserPreferences.php';
header('Content-Type: application/json');header('Cache-Control: no-store, private');
try{
    $user=AuthMiddleware::requireAuth(true);
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('Method not allowed.',405);
    AuthMiddleware::verifyCsrf();if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $body=json_decode(file_get_contents('php://input')?:'{}',true,512,JSON_THROW_ON_ERROR);$message=(int)($body['message_id']??0);if($message<1)throw new RuntimeException('Saved message is required.',400);
    $db=Database::getInstance();UserPreferences::assertJarred(UserPreferences::get($db,(int)$user['id']));
    $limit=(new RateLimiter())->attempt('jarred_reply_retry',(string)$user['id'],10,3600);if(!$limit['allowed'])throw new RuntimeException('Reply retry limit reached. Please try again later.',429);
    // Only the requester's latest unanswered message to the actual Jarred account.
    $q=$db->prepare("SELECT m.id,m.body,m.conversation_id,r.id AS recipient FROM dm_messages m JOIN dm_conversations c ON c.id=m.conversation_id JOIN users r ON (r.id=c.user_a OR r.id=c.user_b) AND r.id<>m.sender_id AND r.is_system=1 AND r.username='ecollab_ai' AND r.deleted_at IS NULL WHERE m.id=? AND m.sender_id=? AND m.is_deleted=0 AND (c.user_a=? OR c.user_b=?) AND NOT EXISTS(SELECT 1 FROM dm_messages newer WHERE newer.conversation_id=m.conversation_id AND newer.is_deleted=0 AND newer.id>m.id)");
    $uid=(int)$user['id'];$q->execute([$message,$uid,$uid,$uid]);$request=$q->fetch(PDO::FETCH_ASSOC);if(!$request)throw new RuntimeException('This saved question is unavailable or already answered.',409);
    $queue=new JarredReplyQueue($db);$conversation=(int)$request['conversation_id'];
    if(!$queue->retry($uid,$conversation,$message,(string)$request['body'])){
        $server=filter_var($body['server_id']??null,FILTER_VALIDATE_INT);$server=$server!==false&&$server>0?$server:null;
        $queue->reserve($uid,$conversation,(int)$request['recipient'],(string)$request['body'],$server,[],fn()=>$message);$queue->activate($uid,$message);
    }
    echo json_encode(['success'=>true,'conversation_id'=>$conversation,'ai_job'=>$queue->latest($uid,$conversation)]);
}catch(Throwable $e){$code=(int)$e->getCode();$code=$code>=400&&$code<=599?$code:500;http_response_code($code);echo json_encode(['success'=>false,'error'=>$code===500?'Reply could not be queued.':$e->getMessage()]);}
