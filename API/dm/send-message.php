<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/services/DmMessageIdentity.php';

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/services/RealtimeOutbox.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/OllamaService.php';
require_once dirname(__DIR__, 2) . '/services/JarredConversation.php';
require_once dirname(__DIR__,2).'/services/JarredReplyQueue.php';
require_once dirname(__DIR__, 2) . '/services/JarredTools.php';
require_once dirname(__DIR__, 2) . '/services/JarredActionService.php';
require_once dirname(__DIR__, 2) . '/services/JarredAssistancePolicy.php';

require_once dirname(__DIR__, 2) . '/services/NotificationService.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);
// Release the user session before local inference or network calls.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

AuthMiddleware::verifyCsrf();if(session_status()===PHP_SESSION_ACTIVE)session_write_close();

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$convId = (int)($body['conversation_id'] ?? 0);
$text   = trim($body['body'] ?? '');
$attachmentPath = trim((string)($body['attachment_path'] ?? ''));
$attachmentName = trim((string)($body['attachment_name'] ?? ''));
$attachmentSize = max(0, (int)($body['attachment_size'] ?? 0));
$attachmentMime = trim((string)($body['attachment_mime'] ?? ''));
$activeServerId = isset($body['active_server_id']) ? (int)$body['active_server_id'] : null;
$jarredSurface = [
    'surface' => trim((string)($body['surface'] ?? 'dm')),
    'channel_id' => (int)($body['channel_id'] ?? 0),
    'voice_channel_id' => (int)($body['voice_channel_id'] ?? 0),
    'workspace_id' => (int)($body['workspace_id'] ?? 0),
    'document_id' => (int)($body['document_id'] ?? 0),
    'whiteboard_id' => (int)($body['whiteboard_id'] ?? 0),
];

if (!$convId || ($text === '' && $attachmentPath === '') || mb_strlen($text) > 4000) {
    http_response_code(400);
    echo json_encode(['error' => 'conversation_id and a message or attachment are required (max 4000 chars)']);
    exit;
}

try {
    $db = Database::getInstance();
    $replyQueue=new JarredReplyQueue($db);

    $check = $db->prepare(
        "SELECT id, user_a, user_b FROM dm_conversations
         WHERE id = :cid AND (user_a = :me OR user_b = :me2)
         LIMIT 1"
    );
    $check->execute([':cid' => $convId, ':me' => $me['id'], ':me2' => $me['id']]);
    $conv = $check->fetch(PDO::FETCH_ASSOC);

    if (!$conv) {
        http_response_code(403);
        echo json_encode(['error' => 'Conversation not found or access denied']);
        exit;
    }

    $recipientId = ((int)$conv['user_a'] === (int)$me['id'])
        ? (int)$conv['user_b']
        : (int)$conv['user_a'];

    $recipientStmt = $db->prepare(
        "SELECT id, username, full_name, avatar_url, avatar_color_gradient, is_system
         FROM users
         WHERE id = :id AND deleted_at IS NULL
         LIMIT 1"
    );
    $recipientStmt->execute([':id' => $recipientId]);
    $recipient = $recipientStmt->fetch(PDO::FETCH_ASSOC);

    if (!$recipient) {
        http_response_code(404);
        echo json_encode(['error' => 'Recipient not found']);
        exit;
    }

    $isAiConversation =
        (int)($recipient['is_system'] ?? 0) === 1
        && ($recipient['username'] ?? '') === 'ecollab_ai';


    if($isAiConversation){$prefs=UserPreferences::get($db,(int)$me['id']);UserPreferences::assertJarred($prefs);$turnLock=JarredConversation::lock((int)$me['id'],'dm',$convId);}

    $saveUserMessage=function()use($db,$convId,$me,$text,$attachmentPath,$attachmentName,$attachmentSize,$attachmentMime){return RealtimeOutbox::record($db,'dm',$convId,function () use ($db, $convId, $me, $text, $attachmentPath, $attachmentName, $attachmentSize, $attachmentMime) {
        $ins = $db->prepare(
            "INSERT INTO dm_messages (conversation_id, sender_id, body, attachment_path, attachment_name, attachment_size, attachment_mime)
             VALUES (:cid, :uid, :body, :apath, :aname, :asize, :amime)"
        );
        $ins->execute([':cid' => $convId, ':uid' => $me['id'], ':body' => $text, ':apath' => $attachmentPath ?: null, ':aname' => $attachmentName ?: null, ':asize' => $attachmentSize ?: null, ':amime' => $attachmentMime ?: null]);
        return (int)$db->lastInsertId();
    });};
    $saveTurn=fn()=>$isAiConversation?$replyQueue->reserve((int)$me['id'],$convId,$recipientId,$text,$activeServerId,$jarredSurface,$saveUserMessage):$saveUserMessage();
    $saved=DmMessageIdentity::record($db,(int)$me['id'],$body['client_message_id']??null,'dm',$convId,[$text,$attachmentPath,$attachmentName,$attachmentSize,$attachmentMime],$saveTurn);
    $msgId = $saved['id'];

    $createdStmt = $db->prepare("SELECT created_at FROM dm_messages WHERE id = :id LIMIT 1");
    $createdStmt->execute([':id' => $msgId]);
    $createdAt = (string)($createdStmt->fetchColumn() ?: gmdate('Y-m-d H:i:s'));
    if($saved['replayed']){echo json_encode(['success'=>true,'message_id'=>$msgId,'created_at'=>$createdAt,'recipient_id'=>$recipientId,'is_ai'=>$isAiConversation,'ai_job'=>$isAiConversation?$replyQueue->latest((int)$me['id'],$convId):null,'replayed'=>true]);exit;}

    $db->prepare(
        "UPDATE dm_conversations SET last_message = :body, last_msg_at = :created_at WHERE id = :cid"
    )->execute([
        ':body' => mb_substr($text !== '' ? $text : ('📎 ' . ($attachmentName ?: 'Attachment')), 0, 120),
        ':created_at' => $createdAt,
        ':cid' => $convId,
    ]);

    try {
        $db->prepare(
            "INSERT INTO dm_reads (user_id, conversation_id, last_read_at)
             VALUES (:uid, :cid, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE last_read_at = UTC_TIMESTAMP()"
        )->execute([':uid' => $me['id'], ':cid' => $convId]);
    } catch (Throwable $e) {
        error_log('[dm/send-message] read cursor update skipped: ' . $e->getMessage());
    }

    if (!$isAiConversation) {
        try {
            $db->prepare(
                "INSERT INTO notifications
                    (recipient_id, actor_id, type, title, body, link_url, icon, is_read)
                 VALUES
                    (:recipient, :actor, 'message', :title, :body2, :link, '💬', 0)"
            )->execute([
                ':recipient' => $recipientId,
                ':actor'     => (int)$me['id'],
                ':title'     => ($me['full_name'] ?: $me['username']) . ' sent you a message',
                ':body2'     => mb_substr($text, 0, 500),
                ':link'      => BASE_URL . '/modules/chat/chat.php?dm=' . $convId . '&partner_id=' . (int)$me['id'] . '&message_id=' . $msgId,
            ]);
        } catch (Throwable $e) {
            error_log('[dm/send-message] notification insert skipped: ' . $e->getMessage());
        }

        NotificationService::invitationsFromMessage($db,$recipientId,(int)$me['id'],$text);

        echo json_encode([
            'success'      => true,
            'message_id'   => $msgId,
            'sender_id'    => $me['id'],
            'body'         => $text,
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
            'attachment_size' => $attachmentSize,
            'attachment_mime' => $attachmentMime,
            'created_at'   => $createdAt,
            'recipient_id' => $recipientId,
            'is_ai'        => false,
        ]);
        exit;
    }

    try {
        // Central action authority runs before Qwen. PHP owns action state,
        // authorization and execution; the model never receives write access.
        $direct = JarredAssistancePolicy::reply($db, (int)$me['id'], $text);
        $actionResult = $direct !== null ? ['reply'=>$direct] : (new JarredActionService())->handleMessage((int)$me['id'], $convId, $text, $activeServerId, $jarredSurface);
        if ($actionResult !== null) {
            $aiText = (string)$actionResult['reply'];
            $aiMsgId = $replyQueue->direct((int)$me['id'],$msgId,fn()=>RealtimeOutbox::record($db, 'dm', $convId, function () use ($db, $convId, $recipientId, $aiText) {
                $aiInsert = $db->prepare("INSERT INTO dm_messages (conversation_id, sender_id, body) VALUES (:cid, :uid, :body)");
                $aiInsert->execute([':cid'=>$convId, ':uid'=>$recipientId, ':body'=>$aiText]);
                return (int)$db->lastInsertId();
            }));
            $aiCreatedStmt = $db->prepare("SELECT created_at FROM dm_messages WHERE id=:id LIMIT 1");
            $aiCreatedStmt->execute([':id'=>$aiMsgId]);
            $aiCreatedAt = (string)($aiCreatedStmt->fetchColumn() ?: gmdate('Y-m-d H:i:s'));
            $db->prepare("UPDATE dm_conversations SET last_message=:body,last_msg_at=:created_at WHERE id=:cid")
               ->execute([':body'=>mb_substr($aiText,0,120),':created_at'=>$aiCreatedAt,':cid'=>$convId]);
            echo json_encode([
                'success'=>true,'message_id'=>$msgId,'sender_id'=>$me['id'],'body'=>$text,'created_at'=>$createdAt,
                'recipient_id'=>$recipientId,'is_ai'=>true,
                'ai_message'=>['id'=>$aiMsgId,'conversation_id'=>$convId,'sender_id'=>$recipientId,'sender_name'=>'Jarred','sender_username'=>$recipient['username'],'sender_avatar_url'=>$recipient['avatar_url']??'','sender_gradient'=>$recipient['avatar_color_gradient']?:'#6366f1,#8b5cf6','body'=>$aiText,'created_at'=>$aiCreatedAt],
                'ai_action'=>$actionResult['action'] ?? null,
            ]);
            exit;
        }

        $replyQueue->activate((int)$me['id'],$msgId);
        echo json_encode(['success'=>true,'message_id'=>$msgId,'sender_id'=>$me['id'],'body'=>$text,'created_at'=>$createdAt,'recipient_id'=>$recipientId,'is_ai'=>true,'ai_pending'=>true,'ai_job'=>$replyQueue->latest((int)$me['id'],$convId)]);
    } catch (Throwable $aiError) {
        $replyQueue->failPreparing((int)$me['id'],$msgId,(int)($aiError->getCode()?:503));
        error_log('[dm/send-message][AI] ' . $aiError->getMessage());
        echo json_encode([
            'success' => true,
            'message_id' => $msgId,
            'sender_id' => $me['id'],
            'body' => $text,
            'created_at' => $createdAt,
            'recipient_id' => $recipientId,
            'is_ai' => true,
            'ai_error' => 'Jarred could not prepare a reply. Your question is saved; use Retry Reply in this conversation.',
            'ai_job'=>$replyQueue->latest((int)$me['id'],$convId),
        ]);
    }
} catch (Throwable $e) {
    error_log('[dm/send-message] ' . $e->getMessage());
    $status=in_array($e->getCode(),[400,403,409,503],true)?$e->getCode():500;http_response_code($status);
    echo json_encode(['error' => $status===500?'Server error':$e->getMessage()]);
}



