<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/ApiErrorResponder.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/security/rate-limit/RateLimiter.php';
require_once dirname(__DIR__, 2) . '/services/UploadAuthorizationService.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }
AuthMiddleware::verifyCsrf();

const CHAT_UPLOAD_MAX_BYTES=20*1024*1024;
const CHAT_UPLOAD_RATE_LIMIT=10;
const CHAT_UPLOAD_RATE_WINDOW=600;
const CHAT_UPLOAD_SESSION_TTL=900;

try {
    $uid=(int)$user['id'];
    $rate=(new RateLimiter())->attempt('upload','user:'.$uid,CHAT_UPLOAD_RATE_LIMIT,CHAT_UPLOAD_RATE_WINDOW);
    if (!$rate['allowed']) { http_response_code(429); header('Retry-After: '.max(1,(int)$rate['retry_after'])); echo json_encode(['error'=>'Upload rate limit exceeded. Try again later.','retry_after'=>(int)$rate['retry_after']]); exit; }
    $sessionChannelId=(int)($_SESSION['ecollab_upload_channel_id']??0); $sessionServerId=(int)($_SESSION['ecollab_upload_server_id']??0);
    $requestedChannelId=filter_input(INPUT_POST,'channel_id',FILTER_VALIDATE_INT)?:0; $requestedServerId=filter_input(INPUT_POST,'server_id',FILTER_VALIDATE_INT)?:0;
    if ($sessionChannelId<=0||$sessionServerId<=0) throw new RuntimeException('Select an authorized channel before uploading',403);
    if ($requestedChannelId>0&&$requestedChannelId!==$sessionChannelId) throw new RuntimeException('Upload channel does not match the authorized channel',403);
    if ($requestedServerId>0&&$requestedServerId!==$sessionServerId) throw new RuntimeException('Upload server does not match the authorized server',403);
    $authorization=(new UploadAuthorizationService())->authorize($uid,$sessionServerId,$sessionChannelId);
    $contentLength=(int)($_SERVER['CONTENT_LENGTH']??0);
    if ($contentLength>0&&$contentLength>CHAT_UPLOAD_MAX_BYTES+65536) throw new RuntimeException('File too large. Max 20 MB.',400);
    if (empty($_FILES['file'])) { http_response_code(400); echo json_encode(['error'=>'No file uploaded']); exit; }
    $file=$_FILES['file'];
    if ($file['error']!==UPLOAD_ERR_OK) throw new RuntimeException('Upload error',400);
    if ((int)$file['size']>CHAT_UPLOAD_MAX_BYTES) throw new RuntimeException('File too large. Max 20 MB.',400);
    $allowedMimes=['image/jpeg','image/png','image/gif','image/webp','image/svg+xml','video/mp4','video/webm','video/quicktime','audio/mpeg','audio/mp3','audio/wav','audio/x-wav','audio/wave','audio/ogg','audio/webm','audio/mp4','audio/x-m4a','application/pdf','text/plain','text/csv','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','application/zip'];
    $finfo=new finfo(FILEINFO_MIME_TYPE); $mimeType=$finfo->file($file['tmp_name']);
    if (!in_array($mimeType,$allowedMimes,true)) throw new RuntimeException('File type not allowed',400);
    $originalName=preg_replace('/[^a-zA-Z0-9._\-]/','_',basename($file['name'])); $extension=strtolower(pathinfo($originalName,PATHINFO_EXTENSION)); $uniqueName=sprintf('%s_%s.%s',date('Ymd_His'),bin2hex(random_bytes(6)),$extension);
    $uploadDir=UPLOAD_DIR; if(!is_dir($uploadDir)&&!mkdir($uploadDir,0750,true)) throw new RuntimeException('Upload directory creation failed',500);
    $destination=$uploadDir.$uniqueName; if(!move_uploaded_file($file['tmp_name'],$destination)) throw new RuntimeException('Failed to move uploaded file',500);
    $relativePath='uploads/'.$uniqueName;
    if(!isset($_SESSION['ecollab_uploads'])||!is_array($_SESSION['ecollab_uploads'])) $_SESSION['ecollab_uploads']=[];
    $now=time(); foreach($_SESSION['ecollab_uploads'] as $path=>$meta) if(!is_array($meta)||(int)($meta['expires_at']??0)<$now) unset($_SESSION['ecollab_uploads'][$path]);
    $_SESSION['ecollab_uploads'][$relativePath]=['user_id'=>$uid,'server_id'=>$authorization['server_id'],'channel_id'=>$authorization['channel_id'],'file_name'=>$originalName,'file_size'=>(int)$file['size'],'mime_type'=>$mimeType,'file_path'=>$relativePath,'expires_at'=>$now+CHAT_UPLOAD_SESSION_TTL];
    echo json_encode(['success'=>true,'file_name'=>$originalName,'file_path'=>$relativePath,'file_size'=>(int)$file['size'],'mime_type'=>$mimeType,'url'=>BASE_URL.'/'.$relativePath]);
} catch (PDOException $e) {
    ApiErrorResponder::throwable('chat/upload-file database',$e,500,'Unable to process upload.');
} catch (RuntimeException $e) {
    $code=($e->getCode()>=400&&$e->getCode()<600)?$e->getCode():500;
    ApiErrorResponder::throwable('chat/upload-file runtime',$e,$code,$e->getMessage());
} catch (Throwable $e) {
    ApiErrorResponder::throwable('chat/upload-file throwable',$e,500,'Server error.');
}
