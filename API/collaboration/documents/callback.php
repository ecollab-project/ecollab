<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/services/OnlyOfficeService.php';

header('Content-Type: application/json; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) { http_response_code(400); echo json_encode(['error' => 1]); exit; }

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) { http_response_code(400); echo json_encode(['error' => 1]); exit; }

try {
    $token = (string)($body['token'] ?? '');
    if ($token === '') {
        $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\\s+(.+)$/i', $authorization, $m)) $token = trim($m[1]);
    }
    if ($token === '') throw new RuntimeException('Missing callback token.');
    $payload = OnlyOfficeService::verify($token);

    $db = Database::getInstance();
    $stmt = $db->prepare('SELECT id, document_key, storage_path FROM collab_documents WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$doc || !hash_equals((string)$doc['document_key'], (string)($payload['key'] ?? ''))) throw new RuntimeException('Invalid document callback.');

    $status = (int)($payload['status'] ?? -1);
    if (in_array($status, [2, 6], true)) {
        $url = (string)($payload['url'] ?? '');
        if ($url === '' || !preg_match('~^https?://~i', $url)) throw new RuntimeException('Missing save URL.');

        $tmp = tempnam(sys_get_temp_dir(), 'ecollab-oo-');
        if ($tmp === false) throw new RuntimeException('Temporary storage unavailable.');
        try {
            $fp = fopen($tmp, 'wb');
            if ($fp === false) throw new RuntimeException('Temporary file unavailable.');
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120, CURLOPT_FAILONERROR => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
            $ok = curl_exec($ch);
            $curlError = curl_error($ch);
            curl_close($ch);
            fclose($fp);
            if ($ok === false) throw new RuntimeException('Document download failed: ' . $curlError);

            $target = ROOT_PATH . '/' . ltrim((string)$doc['storage_path'], '/\\');
            $base = realpath(ROOT_PATH . '/uploads/collab-docs');
            $targetDir = realpath(dirname($target));
            if (!$base || !$targetDir || !str_starts_with($targetDir, $base)) throw new RuntimeException('Invalid storage path.');
            if (!rename($tmp, $target) && !copy($tmp, $target)) throw new RuntimeException('Could not replace document.');
            @unlink($tmp);

            $newKey = bin2hex(random_bytes(24));
            $update = $db->prepare('UPDATE collab_documents SET version = version + 1, document_key = :new_key, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $update->execute([':new_key' => $newKey, ':id' => $id]);
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
    }

    echo json_encode(['error' => 0]);
} catch (Throwable $e) {
    error_log('ONLYOFFICE callback failed: ' . $e->getMessage());
    http_response_code(403);
    echo json_encode(['error' => 1]);
}
