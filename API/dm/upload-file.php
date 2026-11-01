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
    $kind=(string)($_POST['kind'] ?? '');$target=(int)($_POST['target'] ?? 0);
    if (!in_array($kind,['channel','dm','group'],true)) throw new RuntimeException('Invalid destination',400);
    ChatUploadAccess::check(Database::getInstance(),(int)$user['id'],$kind,$target);
    if (empty($_FILES['file'])) {
        $tooLarge = (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 20 * 1024 * 1024;
        http_response_code($tooLarge ? 413 : 400);
        echo json_encode(['error' => $tooLarge ? 'Upload exceeds the server limit. Max 20 MB.' : 'No file received. Check the PHP and proxy upload limits.']);
        exit;
    }
    $file = $_FILES['file'];
    $maxBytes = 20 * 1024 * 1024;
    if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException(in_array($file['error'],[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true) ? 'File exceeds the PHP upload limit.' : 'Upload failed. Please retry.', 400);
    if ((int)$file['size'] > $maxBytes) throw new RuntimeException('File too large. Max 20 MB.', 400);

    $allowed = [
      'image/jpeg','image/png','image/gif','image/webp',
      'application/pdf','text/plain','text/csv',
      'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'application/vnd.ms-powerpoint','application/vnd.openxmlformats-officedocument.presentationml.presentation',
      'application/zip'
    ];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($mime, $allowed, true)) throw new RuntimeException('File type not allowed.', 400);

    $name = preg_replace('/[^a-zA-Z0-9._\-]/', '_', basename((string)$file['name']));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $imageExtensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
    if (isset($imageExtensions[$mime])) $ext = $imageExtensions[$mime];
    $stored = 'dm_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . ($ext !== '' ? '.' . $ext : '');
    AttachmentStore::configured()->put($file['tmp_name'],'uploads/'.$stored,(int)$user['id'],$kind,$target,$name,$mime);
    echo json_encode([
      'success'=>true,'file_name'=>$name,'file_path'=>'uploads/'.$stored,
      'file_size'=>(int)$file['size'],'mime_type'=>$mime,'url'=>BASE_URL.'/uploads/'.$stored
    ]);
} catch (RuntimeException $e) {
    $code = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
    http_response_code($code); echo json_encode(['error'=>$e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500); echo json_encode(['error'=>'Server error']);
}

