<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/services/OnlyOfficeService.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');

$id = (int)($_GET['id'] ?? 0);
$token = (string)($_GET['token'] ?? '');
if ($id < 1 || $token === '') { http_response_code(404); exit; }

try {
    $claims = OnlyOfficeService::verify($token);
    if ((int)($claims['document_id'] ?? 0) !== $id) throw new RuntimeException('Document mismatch.');
    $db = Database::getInstance();
    $stmt = $db->prepare('SELECT id, document_key, file_name, file_type, storage_path FROM collab_documents WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc || !hash_equals((string)$doc['document_key'], (string)($claims['key'] ?? ''))) throw new RuntimeException('Invalid document.');
    $path = ROOT_PATH . '/' . ltrim((string)$doc['storage_path'], '/\\');
    $real = realpath($path);
    $base = realpath(ROOT_PATH . '/uploads/collab-docs');
    if (!$real || !$base || !str_starts_with($real, $base . DIRECTORY_SEPARATOR) || !is_file($real)) throw new RuntimeException('File unavailable.');

    $mime = match ($doc['file_type']) {
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        default => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)filesize($real));
    header('Content-Disposition: inline; filename="' . addcslashes((string)$doc['file_name'], "\\\"") . '"');
    readfile($real);
} catch (Throwable $e) {
    http_response_code(404);
}
