<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once dirname(__DIR__,2).'/database/config/db.php';
require_once dirname(__DIR__,2).'/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__,2).'/services/LiveKitService.php';
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
    $counts=array_fill_keys($allowed,0);
    if ($allowed) {
        $service=new LiveKitService();
        $result=$service->roomRequest('ListRooms',['names'=>array_map(fn($id)=>'ecollab-channel-'.$id,$allowed)],['roomList'=>true]);
        foreach ($result['rooms']??[] as $room) {
            $id=(int)str_replace('ecollab-channel-','',(string)($room['name']??''));
            if (array_key_exists($id,$counts)) $counts[$id]=(int)($room['num_participants']??$room['numParticipants']??0);
        }
    }
    echo json_encode(['success'=>true,'counts'=>(object)$counts]);
} catch (Throwable $e) {
    error_log('[livekit-counts] '.$e->getMessage());http_response_code(503);
    echo json_encode(['success'=>false,'error'=>'Voice counts temporarily unavailable.']);
}
