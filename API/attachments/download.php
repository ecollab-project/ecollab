<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/config.php';
require_once ROOT_PATH.'/database/config/db.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/services/AttachmentStore.php';
header('Cache-Control: private, no-store, max-age=0');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'; sandbox");
AuthMiddleware::startSession();$user=AuthMiddleware::requireAuth(true);session_write_close();
if(!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)){http_response_code(405);exit;}
try{
    $path=(string)($_GET['path']??'');if(!AttachmentAccess::canDownload(Database::getInstance(),(int)$user['id'],$path,BASE_URL)){http_response_code(404);exit;}
    $store=AttachmentStore::configured();$file=$store->file($path);
    if(!$file){
        if($store->receipt($path)){http_response_code(404);exit;}
        $legacy=realpath(ROOT_PATH.'/'.$path);$root=realpath(UPLOAD_DIR);
        if(!$legacy||!$root||!str_starts_with($legacy,$root.'/')||!is_file($legacy)||is_link(ROOT_PATH.'/'.$path)){http_response_code(404);exit;}$file=$legacy;
    }
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file);$inline=in_array($mime,['image/jpeg','image/png','image/gif','image/webp','video/mp4','video/webm','audio/mpeg','audio/ogg','audio/wav'],true);
    header('Content-Type: '.($inline?$mime:'application/octet-stream'));header('Content-Disposition: '.($inline?'inline':'attachment')); // Same-origin download links retain their original filename.
    header('Content-Length: '.filesize($file));if($_SERVER['REQUEST_METHOD']==='GET')readfile($file);
}catch(Throwable $e){http_response_code(503);}
