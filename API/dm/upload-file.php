<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }
AuthMiddleware::verifyCsrf();

try {
    if (empty($_FILES['file'])) { http_response_code(400); echo json_encode(['error'=>'No file uploaded']); exit; }
    $file = $_FILES['file'];
    $maxBytes = 20 * 1024 * 1024;
    if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload failed.', 400);
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
    $stored = 'dm_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . ($ext !== '' ? '.' . $ext : '');
    $dir = UPLOAD_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) throw new RuntimeException('Upload directory creation failed', 500);
    if (!move_uploaded_file($file['tmp_name'], $dir . $stored)) throw new RuntimeException('Failed to save upload', 500);

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
