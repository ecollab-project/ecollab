<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}
AuthMiddleware::verifyCsrf();

try {
    if (!isset($_FILES['avatar']) || !is_array($_FILES['avatar'])) {
        throw new RuntimeException('Choose a profile picture first.');
    }
    $file = $_FILES['avatar'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Profile picture upload failed.');
    }
    if ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Profile picture must be 5 MB or smaller.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string)$file['tmp_name']);
    $ext = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'][$mime] ?? null;
    if (!$ext) {
        throw new RuntimeException('Use a JPG, PNG, WEBP, or GIF image.');
    }

    $dir = dirname(__DIR__, 2) . '/uploads/avatars';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create avatar storage.');
    }

    $uid = (int)$user['id'];
    $name = 'avatar_' . $uid . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $target = $dir . '/' . $name;
    if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
        throw new RuntimeException('Could not save profile picture.');
    }

    $avatarUrl = rtrim((string)BASE_URL, '/') . '/uploads/avatars/' . $name;
    $db = Database::getInstance();
    $old = $db->prepare('SELECT avatar_url FROM users WHERE id = :id LIMIT 1');
    $old->execute([':id'=>$uid]);
    $oldUrl = (string)($old->fetchColumn() ?: '');

    $stmt = $db->prepare('UPDATE users SET avatar_url = :url WHERE id = :id LIMIT 1');
    $stmt->execute([':url'=>$avatarUrl, ':id'=>$uid]);

    if ($oldUrl && str_contains($oldUrl, '/uploads/avatars/')) {
        $oldFile = $dir . '/' . basename(parse_url($oldUrl, PHP_URL_PATH) ?: '');
        if (is_file($oldFile) && $oldFile !== $target) @unlink($oldFile);
    }

    echo json_encode(['success'=>true,'avatar_url'=>$avatarUrl]);
} catch (Throwable $e) {
    error_log('[profile/upload-avatar] ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
