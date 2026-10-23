<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';require_once ROOT_PATH.'/database/config/db.php';require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';require_once ROOT_PATH.'/security/middleware/RoleMiddleware.php';
require_once ROOT_PATH.'/services/AdminGroupRecommendationService.php';
header('Content-Type: application/json');header('Cache-Control: no-store');AuthMiddleware::startSession();$me=AuthMiddleware::requireAuth(true);RoleMiddleware::requireRole(['admin','super_admin'],true);
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['success'=>false,'error'=>'POST required']);exit;}AuthMiddleware::verifyCsrf();session_write_close();
try {
$raw=file_get_contents('php://input',false,null,0,32769);
if($raw===false||strlen($raw)>32768)throw new RuntimeException('Grouping request is too large.',422);
$b=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
if(!is_array($b)||!is_string($b['topic']??'')||!is_string($b['task']??''))throw new RuntimeException('Invalid grouping request.',422);
if(isset($b['group_size'])&&filter_var($b['group_size'],FILTER_VALIDATE_INT)===false)throw new RuntimeException('Invalid group size.',422);
$topic=trim((string)($b['topic']??''));$task=trim((string)($b['task']??''));$size=max(2,min(8,(int)($b['group_size']??4)));if($topic===''&&$task===''){http_response_code(422);echo json_encode(['success'=>false,'error'=>'Topic or task is required']);exit;}
$db=Database::getInstance();$cols=[];foreach($db->query('SHOW COLUMNS FROM user_profiles')->fetchAll(PDO::FETCH_ASSOC) as $r)$cols[$r['Field']]=true;$wanted=['bio','program','year_level','study_style','study_goal','goals','interests','hobbies','skills','strengths','weaknesses'];$select=[];foreach($wanted as $c)if(isset($cols[$c]))$select[]="up.`$c`";
$sql="SELECT u.id,u.username,u.full_name,u.role".($select?','.implode(',',$select):'')." FROM users u LEFT JOIN user_profiles up ON up.user_id=u.id WHERE u.deleted_at IS NULL AND u.status='active' AND u.role IN ('student','facilitator') ORDER BY u.id DESC LIMIT 80";$users=$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
if(count($users)<2){echo json_encode(['success'=>true,'source'=>'database','groups'=>[],'message'=>'Not enough active users with profiles to recommend a group.']);exit;}
$profiles=[];foreach($users as $u){$bits=[];foreach($u as $k=>$v)if(!in_array($k,['id','username','full_name','role'],true)&&$v!==null&&$v!=='')$bits[]="$k: $v";$profiles[]=['id'=>(int)$u['id'],'username'=>$u['username'],'name'=>$u['full_name']?:$u['username'],'role'=>$u['role'],'profile'=>implode('; ',$bits)];}
$result=(new AdminGroupRecommendationService(new OllamaService()))->recommend($profiles,$topic,$task,$size);
echo json_encode($result,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    $status=in_array($e->getCode(),[422,502,503],true)?$e->getCode():503;
    http_response_code($status);
    $message=match($status){422=>'Grouping input cannot be processed safely. No candidates were omitted.',502=>'The model returned incomplete or invalid grouping recommendations.',default=>'Local model is busy or unavailable. Try again later.'};
    echo json_encode(['success'=>false,'error'=>$message]);
}
