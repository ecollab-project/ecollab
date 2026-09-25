<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';require_once ROOT_PATH.'/database/config/db.php';require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
header('Content-Type: application/json');AuthMiddleware::startSession();$user=AuthMiddleware::requireAuth(true);
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['error'=>'Method not allowed']);exit;}AuthMiddleware::verifyCsrf();
$b=json_decode(file_get_contents('php://input'),true)??$_POST;$name=trim((string)($b['name']??''));if($name===''){http_response_code(422);echo json_encode(['error'=>'Server name is required']);exit;}
$v=strtolower((string)($b['visibility']??'private'));if(!in_array($v,['public','private'],true)){http_response_code(422);echo json_encode(['error'=>'visibility must be public or private']);exit;}
$db=Database::getInstance();$base=strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',$name),'-'))?:'server';$slug=substr($base,0,60);$n=2;while(true){$q=$db->prepare('SELECT 1 FROM servers WHERE slug=:slug LIMIT 1');$q->execute([':slug'=>$slug]);if(!$q->fetchColumn())break;$tail='-'.$n++;$slug=substr($base,0,max(1,60-strlen($tail))).$tail;}
$cols=[];foreach($db->query('SHOW COLUMNS FROM servers')->fetchAll(PDO::FETCH_ASSOC) as $r)$cols[$r['Field']]=true;
$fields=['name','slug','owner_id','visibility'];$vals=[':name',':slug',':owner',':visibility'];$p=[':name'=>$name,':slug'=>$slug,':owner'=>(int)$user['id'],':visibility'=>$v];
foreach(['type','category','description','icon_emoji'] as $f)if(isset($cols[$f])&&isset($b[$f])){$fields[]=$f;$vals[]=':'.$f;$p[':'.$f]=trim((string)$b[$f]);}
if(isset($cols['created_by'])){$fields[]='created_by';$vals[]=':creator';$p[':creator']=(int)$user['id'];}
$db->beginTransaction();try{$st=$db->prepare('INSERT INTO servers ('.implode(',',$fields).') VALUES ('.implode(',',$vals).')');$st->execute($p);$id=(int)$db->lastInsertId();$smCols=[];foreach($db->query('SHOW COLUMNS FROM server_members')->fetchAll(PDO::FETCH_ASSOC) as $r)$smCols[$r['Field']]=true;if(isset($smCols['server_role']))$db->prepare("INSERT IGNORE INTO server_members(server_id,user_id,server_role) VALUES(?,?,'owner')")->execute([$id,(int)$user['id']]);else $db->prepare("INSERT IGNORE INTO server_members(server_id,user_id) VALUES(?,?)")->execute([$id,(int)$user['id']]);
$chCols=[];foreach($db->query('SHOW COLUMNS FROM channels')->fetchAll(PDO::FETCH_ASSOC) as $r)$chCols[$r['Field']]=true;
$annFields=['server_id','name','slug','type','description','position','created_by'];$annVals=['?','?','?','?','?','?','?'];
$db->prepare('INSERT IGNORE INTO channels('.implode(',',$annFields).') VALUES('.implode(',',$annVals).')')->execute([$id,'announcements','announcements','announcement','Official server announcements (view only)',0,(int)$user['id']]);
$db->commit();$q=$db->prepare('SELECT * FROM servers WHERE id=?');$q->execute([$id]);http_response_code(201);echo json_encode(['success'=>true,'server'=>$q->fetch(PDO::FETCH_ASSOC)]);}catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('[create-server] '.$e->getMessage());http_response_code(500);echo json_encode(['error'=>'Unable to create server']);}
