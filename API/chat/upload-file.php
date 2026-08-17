<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

AuthMiddleware::verifyCsrf();

try {
    if (empty($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['error' => 'No file uploaded']);
        exit;
    }

    $file     = $_FILES['file'];
    $maxBytes = 20 * 1024 * 1024; // 20 MB

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload error code: ' . $file['error'], 400);
    }
    if ($file['size'] > $maxBytes) {
        throw new RuntimeException('File too large. Max 20 MB.', 400);
    }

    // MIME validation matches the attachment UI: images/videos, documents,
    // archives, and audio/voice messages. Keep this allow-list explicit.
    $allowedMimes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'video/mp4',
        'video/webm',
        'video/quicktime',
        'audio/mpeg',
        'audio/mp3',
        'audio/wav',
        'audio/x-wav',
        'audio/wave',
        'audio/ogg',
        'audio/webm',
        'audio/mp4',
        'audio/x-m4a',
        'application/pdf',
        'text/plain',
        'text/csv',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
    ];

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    if (!in_array($mimeType, $allowedMimes, true)) {
        throw new RuntimeException('File type not allowed: ' . htmlspecialchars($mimeType), 400);
    }

    $originalName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', basename($file['name']));
    $extension    = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $uniqueName   = sprintf('%s_%s.%s', date('Ymd_His'), bin2hex(random_bytes(6)), $extension);

    $uploadDir = UPLOAD_DIR;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0750, true)) {
        throw new RuntimeException('Upload directory creation failed', 500);
    }

    $destination = $uploadDir . $uniqueName;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Failed to move uploaded file', 500);
    }

    echo json_encode([
        'success'   => true,
        'file_name' => $originalName,
        'file_path' => 'uploads/' . $uniqueName,
        'file_size' => $file['size'],
        'mime_type' => $mimeType,
        'url'       => BASE_URL . '/uploads/' . $uniqueName,
    ]);
} catch (RuntimeException $e) {
    $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
