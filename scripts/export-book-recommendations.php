<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/database/config/db.php';
$salt=(string)(getenv('RECOMMENDATION_EXPORT_SALT') ?: '');
if (strlen($salt)<32) { fwrite(STDERR,"Set RECOMMENDATION_EXPORT_SALT to a private value of at least 32 characters\n"); exit(1); }
$db=Database::getInstance();
$db->exec("SET time_zone = '+00:00'");
// Current opt-outs and deleted/ineligible accounts never enter the export.
$q=$db->query("SELECT e.user_id,e.work_key,e.event_type,UNIX_TIMESTAMP(e.created_at) occurred_at
    FROM recommendation_book_events e JOIN users u ON u.id=e.user_id
    LEFT JOIN user_settings s ON s.user_id=u.id
    WHERE u.deleted_at IS NULL AND u.status NOT IN ('banned','suspended','deactivated')
      AND COALESCE(u.is_system,0)=0 AND COALESCE(s.ai_matching,1)=1
    ORDER BY e.created_at,e.id LIMIT 100001");
$events=$q->fetchAll(PDO::FETCH_ASSOC);
if(count($events)>100000){fwrite(STDERR,"Export exceeds prototype limit; narrow the cohort before training\n");exit(1);}
$users=[];$items=[];$out=[];
foreach($events as $event){
    $id=(int)$event['user_id'];
    $pseudo=hash_hmac('sha256',(string)$id,$salt);
    // Identity-only users avoid leaking current profile metadata into past evaluation.
    $users[$pseudo]=['id'=>$pseudo,'features'=>[]];
    $items[$event['work_key']]=true;
    $out[]=['user'=>$pseudo,'item'=>$event['work_key'],'type'=>$event['event_type'],'timestamp'=>(int)$event['occurred_at']];
}
$catalog=[];
$q=$db->query('SELECT work_key,features_json,UNIX_TIMESTAMP(first_seen_at) available_at FROM recommendation_books');
while($row=$q->fetch(PDO::FETCH_ASSOC)){
    if(!isset($items[$row['work_key']]))continue;
    $catalog[]=['id'=>$row['work_key'],'features'=>json_decode($row['features_json'],true,16,JSON_THROW_ON_ERROR),'available_at'=>(int)$row['available_at']];
}
echo json_encode(['version'=>1,'users'=>array_values($users),'items'=>$catalog,'events'=>$out],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
