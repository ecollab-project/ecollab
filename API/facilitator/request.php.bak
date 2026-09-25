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
try {
    $db=Database::getInstance();

    // Give a useful deployment error instead of an opaque HTTP 500 when the
    // facilitator workflow migration has not been applied yet.
    $table=$db->query("SHOW TABLES LIKE 'facilitator_requests'")->fetchColumn();
    if(!$table){
        http_response_code(503);
        echo json_encode(['success'=>false,'error'=>'Facilitator requests are not initialized on this server. Run migration 042_facilitator_requests_and_role_policy.sql.','code'=>'FACILITATOR_SCHEMA_MISSING']);
        exit;
    }

    $q=$db->prepare("SELECT id,status FROM facilitator_requests WHERE user_id=? AND status='pending' LIMIT 1");
    $q->execute([$me['id']]);
    if($q->fetch()){http_response_code(409);echo json_encode(['success'=>false,'error'=>'You already have a pending facilitator request.']);exit;}

    if(empty($_FILES['proof'])){
        http_response_code(422);echo json_encode(['success'=>false,'error'=>'A proof/ID file is required.']);exit;
    }
    $uploadError=(int)($_FILES['proof']['error']??UPLOAD_ERR_NO_FILE);
    if($uploadError!==UPLOAD_ERR_OK){
        $uploadErrors=[
            UPLOAD_ERR_INI_SIZE=>'The proof file exceeds the server upload limit.',
            UPLOAD_ERR_FORM_SIZE=>'The proof file is too large.',
            UPLOAD_ERR_PARTIAL=>'The proof file upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE=>'A proof/ID file is required.',
            UPLOAD_ERR_NO_TMP_DIR=>'The server upload temporary directory is unavailable.',
            UPLOAD_ERR_CANT_WRITE=>'The server could not write the uploaded proof file.',
            UPLOAD_ERR_EXTENSION=>'The proof upload was blocked by a server extension.'
        ];
        http_response_code(422);echo json_encode(['success'=>false,'error'=>$uploadErrors[$uploadError]??'The proof upload failed.','code'=>'PROOF_UPLOAD_FAILED']);exit;
    }

    $f=$_FILES['proof'];
    if((int)$f['size']>5*1024*1024){http_response_code(413);echo json_encode(['success'=>false,'error'=>'Proof file must be 5 MB or smaller.']);exit;}
    if(!class_exists('finfo')){throw new RuntimeException('PHP fileinfo extension is required for proof uploads.');}
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $allowed=['image/jpeg'=>'jpg','image/png'=>'png','application/pdf'=>'pdf'];
    if(!isset($allowed[$mime])){http_response_code(422);echo json_encode(['success'=>false,'error'=>'Proof must be JPG, PNG, or PDF.']);exit;}

    $dir=ROOT_PATH.'/storage/facilitator-proofs';
    if(!is_dir($dir)&&!mkdir($dir,0750,true)){throw new RuntimeException('Unable to create proof storage directory.');}
    if(!is_writable($dir)){throw new RuntimeException('Proof storage directory is not writable by PHP.');}

    $name='facreq_'.$me['id'].'_'.bin2hex(random_bytes(12)).'.'.$allowed[$mime];
    $dest=$dir.'/'.$name;
    if(!move_uploaded_file($f['tmp_name'],$dest)){throw new RuntimeException('Unable to save proof upload.');}

    $reason=trim((string)($_POST['reason']??''));
    $path='storage/facilitator-proofs/'.$name;
    try{
        $s=$db->prepare('INSERT INTO facilitator_requests(user_id,proof_path,proof_original_name,reason) VALUES(?,?,?,?)');
        $s->execute([$me['id'],$path,basename((string)$f['name']),$reason?:null]);
    }catch(Throwable $e){
        @unlink($dest);
        throw $e;
    }

    echo json_encode(['success'=>true,'status'=>'pending','request_id'=>(int)$db->lastInsertId()]);
} catch(Throwable $e) {
    error_log('[facilitator/request] '.$e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success'=>false,
        'error'=>(defined('APP_DEBUG')&&APP_DEBUG)?$e->getMessage():'Unable to submit facilitator request.',
        'code'=>'FACILITATOR_REQUEST_FAILED'
    ]);
}
