<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json; charset=utf-8');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }
AuthMiddleware::verifyCsrf();

try {
    if (empty($_FILES['image']) || !is_array($_FILES['image'])) throw new RuntimeException('No image uploaded',400);
    $file=$_FILES['image'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('Upload failed',400);
    if ((int)$file['size'] > 10*1024*1024) throw new RuntimeException('Image too large. Max 10 MB.',400);
    $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=$finfo->file($file['tmp_name']);
    $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
    if (!isset($allowed[$mime])) throw new RuntimeException('Only JPG, PNG, GIF, and WebP images are allowed.',400);
    $dir=UPLOAD_DIR.'threads/';
    if(!is_dir($dir) && !mkdir($dir,0750,true)) throw new RuntimeException('Upload directory creation failed',500);
    $name=date('Ymd_His').'_'.bin2hex(random_bytes(8)).'.'.$allowed[$mime];
    if(!move_uploaded_file($file['tmp_name'],$dir.$name)) throw new RuntimeException('Failed to save image',500);
    echo json_encode(['success'=>true,'url'=>BASE_URL.'/uploads/threads/'.$name,'path'=>'/uploads/threads/'.$name,'file_name'=>basename($file['name']),'mime_type'=>$mime,'file_size'=>(int)$file['size']]);
} catch(RuntimeException $e) {
    $code=($e->getCode()>=400&&$e->getCode()<600)?$e->getCode():500; http_response_code($code); echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
} catch(Throwable $e) { http_response_code(500); echo json_encode(['success'=>false,'error'=>'Image upload failed']); }
