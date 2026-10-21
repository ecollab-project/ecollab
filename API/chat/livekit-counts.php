<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once dirname(__DIR__,2).'/database/config/db.php';
require_once dirname(__DIR__,2).'/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__,2).'/services/LiveKitCountSnapshot.php';
require_once dirname(__DIR__,2).'/services/ChannelAccessService.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
try {
    $user=AuthMiddleware::requireAuth(true);
    if (($_SERVER['REQUEST_METHOD']??'') !== 'GET') { http_response_code(405); exit; }
    session_write_close();
    $ids=array_slice(array_values(array_unique(array_filter(array_map('intval',explode(',',(string)($_GET['channel_ids']??''))),fn($id)=>$id>0))),0,100);
    if (!$ids) { echo json_encode(['success'=>true,'counts'=>(object)[]]); exit; }
    $db=Database::getInstance();
    $allowed=array_keys((new ChannelAccessService($db))->voiceChannels($ids, (int)$user['id']));
    echo json_encode((new LiveKitCountSnapshot($db))->read($allowed, time(), max(15,3*max(5,min(60,(int)(getenv('LIVEKIT_COUNT_INTERVAL')?:5))))));
} catch (Throwable $e) {
    error_log('[livekit-counts] '.$e->getMessage());http_response_code(503);
    echo json_encode(['success'=>false,'error'=>'Voice counts temporarily unavailable.']);
}
