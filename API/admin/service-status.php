<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/security/middleware/RoleMiddleware.php';
require_once ROOT_PATH.'/database/config/db.php';
require_once ROOT_PATH.'/services/CentrifugoService.php';
require_once ROOT_PATH.'/services/RealtimeOutbox.php';
header('Content-Type: application/json');header('Cache-Control: no-store');
AuthMiddleware::requireAuth(true);RoleMiddleware::requireRole(['admin','super_admin'],true);
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');exit;}
session_write_close();
function localProbe(string $url,string $path): ?array {
    if(!function_exists('curl_init')||!preg_match('~^http://(?:127\.0\.0\.1|localhost):[0-9]{1,5}/?$~D',$url))return null;
    $raw='';$ch=curl_init(rtrim($url,'/').$path);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_CONNECTTIMEOUT_MS=>250,CURLOPT_TIMEOUT_MS=>1200,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$raw){if(strlen($raw)+strlen($chunk)>131072)return 0;$raw.=$chunk;return strlen($chunk);}]);
    try{$ok=curl_exec($ch);if($ok===false||curl_getinfo($ch,CURLINFO_RESPONSE_CODE)!==200)return null;$data=json_decode($raw,true);return is_array($data)?$data:null;}finally{curl_close($ch);}
}
$services=[];
try{Database::getInstance()->query('SELECT 1');$services['database']=['state'=>'ready','detail'=>'Database query succeeded'];}catch(Throwable){$services['database']=['state'=>'unavailable','detail'=>'Database check failed'];}
if(!RealtimeOutbox::enabled())$services['realtime']=['state'=>'disabled','detail'=>'Delivery transport disabled'];
else{try{(new CentrifugoService())->command('info',[]);$services['realtime']=['state'=>'ready','detail'=>'Centrifugo broker API responded'];}catch(Throwable){$services['realtime']=['state'=>'unavailable','detail'=>'Centrifugo broker check failed'];}}
$enabled=filter_var(env('PEER_ML_ENABLED',false),FILTER_VALIDATE_BOOLEAN);
$ml=$enabled?localProbe((string)env('PEER_ML_URL','http://127.0.0.1:8091'),'/health'):null;
$services['matching']=['state'=>!$enabled?'disabled':(!empty($ml['ready'])?'ready':'unavailable'),'detail'=>!$enabled?'Semantic scoring disabled; rules can still run':(!empty($ml['ready'])?'Semantic service ready · '.($ml['version']??'unknown version'):'Semantic health check failed; scoring may fall back to rules')];
$ollama=localProbe((string)env('OLLAMA_URL','http://127.0.0.1:11434'),'/api/tags');$model=(string)env('OLLAMA_MODEL','qwen3:1.7b');$available=false;
foreach($ollama['models']??[] as $entry){$name=(string)($entry['name']??'');if($name===$model||$name===$model.':latest')$available=true;}
$services['local_ai']=['state'=>$ollama===null?'unavailable':($available?'ready':'degraded'),'detail'=>$ollama===null?'Local model service did not respond':($available?'Configured local model is installed':'Configured local model was not listed'),'model'=>$model];
$services['cloud_ai']=['state'=>env('GEMINI_API_KEY','')?'configured':'not_configured','detail'=>'Configuration only; no cloud request was sent'];
try{$db=Database::getInstance();$pending=(int)$db->query('SELECT COUNT(*) FROM realtime_outbox WHERE published_at IS NULL')->fetchColumn();$oldest=$db->query('SELECT MIN(created_at) FROM realtime_outbox WHERE published_at IS NULL')->fetchColumn();$outbox=['pending'=>$pending,'oldest_created_at'=>$oldest?:null];}catch(Throwable){$outbox=null;}
echo json_encode(['success'=>true,'services'=>$services,'outbox'=>$outbox,'checked_at'=>gmdate(DATE_ATOM),'limits'=>'Availability checks do not measure model accuracy, training progress or end-to-end delivery.'],JSON_INVALID_UTF8_SUBSTITUTE);
