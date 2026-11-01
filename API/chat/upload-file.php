<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

require_once ROOT_PATH.'/services/AttachmentStore.php';
require_once ROOT_PATH.'/services/ChatUploadAccess.php';
header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }
AuthMiddleware::verifyCsrf();
// Authentication and CSRF are complete; release this account’s session lock before I/O.
session_write_close();

try {
    $kind='channel';$target=(int)($_POST['target'] ?? 0);
    if (!in_array($kind,['channel','dm','group'],true)) throw new RuntimeException('Invalid destination',400);
    ChatUploadAccess::check(Database::getInstance(),(int)$user['id'],$kind,$target);
    if (empty($_FILES['file'])) {
        $tooLarge = (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 20 * 1024 * 1024;
        http_response_code($tooLarge ? 413 : 400);
        echo json_encode(['error' => $tooLarge ? 'Upload exceeds the server limit. Max 20 MB.' : 'No file received. Check the PHP and proxy upload limits.']);
        exit;
    }
    $file=$_FILES['file']; $maxBytes=20*1024*1024;
    if ($file['error']!==UPLOAD_ERR_OK) throw new RuntimeException(in_array($file['error'],[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true) ? 'File exceeds the PHP upload limit.' : 'Upload failed. Please retry.',400);
    if ($file['size']>$maxBytes) throw new RuntimeException('File too large. Max 20 MB.',400);
    $allowedMimes=['image/jpeg','image/png','image/gif','image/webp','video/mp4','video/webm','video/quicktime','audio/mpeg','audio/mp3','audio/wav','audio/x-wav','audio/wave','audio/ogg','audio/webm','audio/mp4','audio/x-m4a','application/pdf','text/plain','text/csv','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip'];
    $finfo=new finfo(FILEINFO_MIME_TYPE); $mimeType=$finfo->file($file['tmp_name']);
    if(!in_array($mimeType,$allowedMimes,true)) throw new RuntimeException('File type not allowed: '.htmlspecialchars($mimeType),400);
    $originalName=preg_replace('/[^a-zA-Z0-9._\-]/','_',basename($file['name'])); $extension=strtolower(pathinfo($originalName,PATHINFO_EXTENSION)); $imageExtensions=['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp']; if(isset($imageExtensions[$mimeType])) $extension=$imageExtensions[$mimeType]; $uniqueName=sprintf('%s_%s.%s',date('Ymd_His'),bin2hex(random_bytes(6)),$extension);
    AttachmentStore::configured()->put($file['tmp_name'],'uploads/'.$uniqueName,(int)$user['id'],$kind,$target,$originalName,$mimeType);
    echo json_encode(['success'=>true,'file_name'=>$originalName,'file_path'=>'uploads/'.$uniqueName,'file_size'=>$file['size'],'mime_type'=>$mimeType,'url'=>BASE_URL.'/uploads/'.$uniqueName]);
} catch(RuntimeException $e) { $code=($e->getCode()>=400&&$e->getCode()<600)?$e->getCode():500; http_response_code($code); echo json_encode(['error'=>$e->getMessage()]); }
catch(Throwable $e){http_response_code(500);echo json_encode(['error'=>'Server error']);}

