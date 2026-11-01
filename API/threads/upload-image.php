<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

require_once ROOT_PATH.'/services/AttachmentStore.php';

header('Content-Type: application/json; charset=utf-8');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }
AuthMiddleware::verifyCsrf();
// Authentication and CSRF are complete; release this account’s session lock before I/O.
session_write_close();

try {
    $db=Database::getInstance();$uid=(int)$me['id'];$threadId=(int)($_POST['thread_id']??0);
    if($threadId>0) {
        $q=$db->prepare('SELECT scope,server_id,channel_id FROM threads WHERE id=? AND is_deleted=0');$q->execute([$threadId]);$t=$q->fetch(PDO::FETCH_ASSOC);
        if(!$t || ($t['scope']!=='public' && !AttachmentAccess::canRead($db,$uid,$t['scope'],(int)$t[$t['scope'].'_id'],true))) throw new RuntimeException('Thread access denied',403);
        $kind='thread';$target=$threadId;
    } else {
        $scope=(string)($_POST['scope']??'');
        if(!in_array($scope,['public','server','channel'],true)) throw new RuntimeException('Select a thread destination',400);
        $target=$scope==='public'?0:(int)($_POST[$scope.'_id']??0);$kind='thread-'.$scope;
        if($scope!=='public' && !AttachmentAccess::canRead($db,$uid,$scope,$target,true)) throw new RuntimeException('Thread access denied',403);
    }

    if (empty($_FILES['image']) || !is_array($_FILES['image'])) throw new RuntimeException('No image uploaded',400);
    $file=$_FILES['image'];
    $uploadError=(int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError!==UPLOAD_ERR_OK) {
        $errors=[
            UPLOAD_ERR_INI_SIZE=>'Image exceeds the server upload_max_filesize limit.',
            UPLOAD_ERR_FORM_SIZE=>'Image exceeds the allowed form size.',
            UPLOAD_ERR_PARTIAL=>'Image upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE=>'No image was received.',
            UPLOAD_ERR_NO_TMP_DIR=>'Server temporary upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE=>'Server could not write the uploaded image.',
            UPLOAD_ERR_EXTENSION=>'A PHP extension stopped the image upload.',
        ];
        throw new RuntimeException($errors[$uploadError] ?? ('Image upload failed (code '.$uploadError.').'),400);
    }
    if ((int)$file['size'] > 10*1024*1024) throw new RuntimeException('Image too large. Max 10 MB.',400);
    $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=$finfo->file($file['tmp_name']);
    $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
    if (!isset($allowed[$mime])) throw new RuntimeException('Only JPG, PNG, GIF, and WebP images are allowed.',400);
    $name=date('Ymd_His').'_' .bin2hex(random_bytes(8)).'.'.$allowed[$mime];
    AttachmentStore::configured()->put($file['tmp_name'],'uploads/threads/'.$name,$uid,$kind,$target,basename($file['name']),$mime);
    echo json_encode(['success'=>true,'url'=>BASE_URL.'/uploads/threads/'.$name,'path'=>'/uploads/threads/'.$name,'file_name'=>basename($file['name']),'mime_type'=>$mime,'file_size'=>(int)$file['size']]);
} catch(RuntimeException $e) {
    $code=($e->getCode()>=400&&$e->getCode()<600)?$e->getCode():500; http_response_code($code); echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
} catch(Throwable $e) { http_response_code(500); echo json_encode(['success'=>false,'error'=>'Image upload failed']); }
