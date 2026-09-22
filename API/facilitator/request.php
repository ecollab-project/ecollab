<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/database/config/db.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
header('Content-Type: application/json');
AuthMiddleware::startSession();
$me=AuthMiddleware::requireAuth(true);
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['success'=>false,'error'=>'Method not allowed']);exit;}
AuthMiddleware::verifyCsrf();
if(($me['role']??'student')!=='student'){http_response_code(403);echo json_encode(['success'=>false,'error'=>'Only student accounts can request facilitator access.']);exit;}
$db=Database::getInstance();
$q=$db->prepare("SELECT id,status FROM facilitator_requests WHERE user_id=? AND status='pending' LIMIT 1");$q->execute([$me['id']]);
if($q->fetch()){http_response_code(409);echo json_encode(['success'=>false,'error'=>'You already have a pending facilitator request.']);exit;}
if(empty($_FILES['proof'])||($_FILES['proof']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK){http_response_code(422);echo json_encode(['success'=>false,'error'=>'A valid proof/ID file is required.']);exit;}
$f=$_FILES['proof'];if((int)$f['size']>5*1024*1024){http_response_code(413);echo json_encode(['success'=>false,'error'=>'Proof file must be 5 MB or smaller.']);exit;}
$mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);$allowed=['image/jpeg'=>'jpg','image/png'=>'png','application/pdf'=>'pdf'];
if(!isset($allowed[$mime])){http_response_code(422);echo json_encode(['success'=>false,'error'=>'Proof must be JPG, PNG, or PDF.']);exit;}
$dir=ROOT_PATH.'/storage/facilitator-proofs';if(!is_dir($dir)&&!mkdir($dir,0750,true)){throw new RuntimeException('Unable to create proof storage.');}
$name='facreq_'.$me['id'].'_'.bin2hex(random_bytes(12)).'.'.$allowed[$mime];$dest=$dir.'/'.$name;
if(!move_uploaded_file($f['tmp_name'],$dest)){throw new RuntimeException('Unable to save proof.');}
$reason=trim((string)($_POST['reason']??''));$path='storage/facilitator-proofs/'.$name;
$s=$db->prepare('INSERT INTO facilitator_requests(user_id,proof_path,proof_original_name,reason) VALUES(?,?,?,?)');$s->execute([$me['id'],$path,basename((string)$f['name']),$reason?:null]);
echo json_encode(['success'=>true,'status'=>'pending','request_id'=>(int)$db->lastInsertId()]);
