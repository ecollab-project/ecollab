<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once dirname(__DIR__,2).'/database/config/db.php';
require_once dirname(__DIR__,2).'/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__,2).'/services/LiveKitService.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
try {
    $user=AuthMiddleware::requireAuth(true);
    if (($_SERVER['REQUEST_METHOD']??'') !== 'GET') { http_response_code(405); exit; }
    $ids=array_slice(array_values(array_unique(array_filter(array_map('intval',explode(',',(string)($_GET['channel_ids']??''))),fn($id)=>$id>0))),0,100);
    if (!$ids) { echo json_encode(['success'=>true,'counts'=>(object)[]]); exit; }
    $db=Database::getInstance();
    $params=$ids; $params[]=(int)$user['id']; $params[]=(int)$user['id']; $params[]=(int)$user['id'];
    $q=$db->prepare("SELECT c.id FROM channels c JOIN server_members sm ON sm.server_id=c.server_id
        WHERE c.id IN (".implode(',',array_fill(0,count($ids),'?')).") AND c.type='voice' AND sm.user_id=?
        AND (c.is_private=0 OR sm.server_role IN ('owner','admin') OR c.created_by=?
        OR EXISTS(SELECT 1 FROM channel_members cm WHERE cm.channel_id=c.id AND cm.user_id=?))");
    $q->execute($params); $allowed=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
    session_write_close();
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
