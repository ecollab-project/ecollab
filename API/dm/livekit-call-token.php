<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once dirname(__DIR__,2).'/database/config/db.php';
require_once dirname(__DIR__,2).'/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__,2).'/services/LiveKitService.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
try {
    $user=AuthMiddleware::requireAuth(true);
    if (($_SERVER['REQUEST_METHOD']??'') !== 'POST') {
        http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method not allowed']); exit;
    }
    AuthMiddleware::verifyCsrf();
    $input=json_decode((string)file_get_contents('php://input'),true);
    $id=(int)($input['call_id']??0);
    $db=Database::getInstance();
    $q=$db->prepare("SELECT id FROM dm_call_history WHERE id=:id AND group_id IS NULL
        AND (caller_id=:caller OR callee_id=:callee)
        AND (status='answered' OR (status='ringing' AND started_at > DATE_SUB(NOW(), INTERVAL 90 SECOND)))");
    $q->execute([':id'=>$id,':caller'=>(int)$user['id'],':callee'=>(int)$user['id']]);
    if (!$q->fetchColumn()) {
        http_response_code(403); echo json_encode(['success'=>false,'error'=>'This call is unavailable or you are not a participant.']); exit;
    }
    session_write_close();
    $service=new LiveKitService(); $room='ecollab-dm-call-'.$id;
    $service->ensureRoom($room,2);
    echo json_encode(['success'=>true]+$service->issueNamedRoomToken($user,$room,'Direct call',['call_id'=>$id]));
} catch (Throwable $e) {
    error_log('[livekit-call-token] '.$e->getMessage());
    http_response_code(503); echo json_encode(['success'=>false,'error'=>'Call media is temporarily unavailable.']);
}
