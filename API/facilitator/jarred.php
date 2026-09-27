<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/database/config/db.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/security/middleware/RoleMiddleware.php';
require_once ROOT_PATH.'/services/UserService.php';
require_once ROOT_PATH.'/services/OllamaService.php';
require_once ROOT_PATH.'/services/JarredCapabilityPolicy.php';
require_once ROOT_PATH.'/services/JarredGroupRecommendationService.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user=AuthMiddleware::requireAuth(true);
RoleMiddleware::requireRole(['facilitator','admin','super_admin','moderator']);

if($_SERVER['REQUEST_METHOD']!=='POST'){
    http_response_code(405);
    echo json_encode(['error'=>'Method not allowed']);
    exit;
}

$body=json_decode(file_get_contents('php://input'),true)??[];
$prompt=trim((string)($body['prompt']??''));
if($prompt===''||mb_strlen($prompt)>3000){
    http_response_code(400);
    echo json_encode(['error'=>'A prompt is required (max 3000 characters).']);
    exit;
}

try{
    $uid=(int)$user['id'];
    $serverId=max(0,(int)($body['server_id']??0));
    $dash=(new UserService())->getFacilitatorDashboardData($uid);

    // Optional server scope must belong to a server the facilitator manages.
    $serverContext=null;
    if($serverId>0){
        $db=Database::getInstance();
        $s=$db->prepare("SELECT s.id,s.name,s.description,s.category,s.type,s.member_count,sm.server_role
                         FROM servers s
                         JOIN server_members sm ON sm.server_id=s.id AND sm.user_id=:uid
                         WHERE s.id=:sid AND s.status='active'
                           AND sm.server_role IN ('owner','admin','moderator','facilitator')
                         LIMIT 1");
        $s->execute([':uid'=>$uid,':sid'=>$serverId]);
        $serverContext=$s->fetch(PDO::FETCH_ASSOC)?:null;
        if(!$serverContext){
            http_response_code(403);
            echo json_encode(['error'=>'You do not manage that server.']);
            exit;
        }
    }

    $policy=(new JarredCapabilityPolicy())->context($uid,$serverId?:null,['surface'=>'facilitator_dashboard']);
    $groupEvidence=null;
    if($serverId>0 && preg_match('/\\b(group|groups|grouping|team|teams|compatib|match students)\\b/i',$prompt)){
        $requestedSize=4;
        if(preg_match('/\\b(?:groups? of|team(?:s)? of)\\s*(\\d{1,2})\\b/i',$prompt,$m)) $requestedSize=(int)$m[1];
        $groupEvidence=(new JarredGroupRecommendationService())->recommend($uid,$serverId,$requestedSize);
    }

    // Release the PHP session before the potentially slow local-model call.
    if(session_status()===PHP_SESSION_ACTIVE) session_write_close();

    $evidence=[
        'authority'=>$policy,
        'selected_server'=>$serverContext,
        'dashboard'=>[
            'stats'=>$dash['stats']??[],
            'channel'=>$dash['channel']??null,
            'activity'=>$dash['activity']??[],
            'recent_activity'=>$dash['recent_activity']??[],
            'upcoming_sessions'=>$dash['upcoming_sessions']??[],
            'membership'=>$dash['membership']??[],
            'group_recommendation'=>$groupEvidence,
        ],
    ];

    $messages=[[
        'role'=>'user',
        'content'=>$prompt."\n\nAUTHORIZED FACILITATOR DASHBOARD EVIDENCE:\n".json_encode($evidence,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    ]];

    $result=(new OllamaService())->generate(
        $messages,
        "You are Jarred inside eCollab's Facilitator Dashboard. Analyze only the authorized dashboard evidence supplied with the request. Never invent students, counts, percentages, quiz results, skill scores, participation, trends, or events. If evidence needed for a requested conclusion is absent, say what is missing. Separate measured dashboard facts from interpretation. For reports, use concise headings and actionable observations. Do not make psychological or intelligence judgments about students. Grouping and skill claims require actual matching or assessment evidence; do not infer them from message counts alone.",
        650
    );
    $text=trim((string)($result['text']??''));
    if($text==='') throw new RuntimeException('Jarred returned an empty response.');

    echo json_encode(['success'=>true,'reply'=>$text,'model'=>$result['model']??null]);
}catch(Throwable $e){
    error_log('[facilitator/jarred] '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['error'=>'Jarred is temporarily unavailable.']);
}
