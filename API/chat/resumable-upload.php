<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2).'/config.php';
require_once ROOT_PATH.'/database/config/db.php';
require_once ROOT_PATH.'/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH.'/services/ChatUploadAccess.php';
require_once ROOT_PATH.'/services/ChatTusStorage.php';

header('Cache-Control: no-store');
header('Tus-Resumable: 1.0.0');
$method = $_SERVER['REQUEST_METHOD'];
if (!filter_var(env('CHAT_RESUMABLE_UPLOADS', 'false'), FILTER_VALIDATE_BOOLEAN)) { http_response_code(404); exit; }
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
if (in_array($method, ['POST','PATCH','DELETE','GET'], true)) AuthMiddleware::verifyCsrf();
session_write_close(); // Chunk I/O must not block chat requests from the same session.

try {
    if ($method === 'OPTIONS') {
        header('Tus-Version: 1.0.0'); header('Tus-Extension: creation,termination');
        header('Tus-Max-Size: '.ChatTusStorage::MAX_BYTES); header('Allow: OPTIONS,POST,HEAD,PATCH,GET,DELETE');
        http_response_code(204); exit;
    }
    if ($method !== 'GET' && ($_SERVER['HTTP_TUS_RESUMABLE'] ?? '') !== '1.0.0') {
        header('Tus-Version: 1.0.0'); throw new RuntimeException('Unsupported tus version.', 412);
    }
    $root = (string)env('CHAT_TUS_STORAGE', dirname(ROOT_PATH).'/ecollab-upload-parts');
    $realParent = realpath(dirname($root));
    $realRoot = realpath($root) ?: ($realParent ? $realParent.'/'.basename($root) : '');
    if (!$realRoot || str_starts_with($realRoot.'/', realpath(ROOT_PATH).'/')) throw new RuntimeException('Upload parts must be outside the web root.', 503);
    $store = new ChatTusStorage($root, UPLOAD_DIR, BASE_URL);
    $db = Database::getInstance();
    $authorize = fn(string $kind, int $target) => ChatUploadAccess::check($db, (int)$user['id'], $kind, $target);
    if ($method === 'POST') {
        $length = $_SERVER['HTTP_UPLOAD_LENGTH'] ?? '';
        if (!preg_match('/^[0-9]{1,9}$/D', $length)) throw new RuntimeException('Upload-Length required.', 400);
        $raw = $_SERVER['HTTP_UPLOAD_METADATA'] ?? '';
        if (strlen($raw) > 2048) throw new RuntimeException('Upload metadata too large.', 400);
        $meta = [];
        foreach (explode(',', $raw) as $pair) {
            $parts = explode(' ', trim($pair), 2);
            $value = base64_decode($parts[1] ?? '', true);
            if ($value === false) throw new RuntimeException('Invalid upload metadata.', 400);
            $meta[$parts[0]] = $value;
        }
        $kind = $meta['kind'] ?? '';
        $target = filter_var($meta['target'] ?? '', FILTER_VALIDATE_INT) ?: 0;
        $authorize($kind, $target);
        $id = $store->create((int)$user['id'], $kind, $target, (int)$length, $meta['name'] ?? 'attachment');
        header('Location: '.BASE_URL.'/API/chat/resumable-upload.php?id='.$id);
        header('Upload-Offset: 0'); http_response_code(201); exit;
    }
    $store->withUpload((string)($_GET['id'] ?? ''), (int)$user['id'], $authorize, function(array $meta, string $id) use ($store, $method) {
        if ($method === 'HEAD') {
            header('Upload-Length: '.$meta['length']); header('Upload-Offset: '.$store->offset($meta, $id)); http_response_code(200);
        } elseif ($method === 'PATCH') {
            if (strtolower(trim($_SERVER['CONTENT_TYPE'] ?? '')) !== 'application/offset+octet-stream') throw new RuntimeException('Invalid chunk content type.', 415);
            $offset = $_SERVER['HTTP_UPLOAD_OFFSET'] ?? '';
            if (!preg_match('/^[0-9]{1,9}$/D', $offset)) throw new RuntimeException('Upload-Offset required.', 400);
            $chunk = file_get_contents('php://input', false, null, 0, ChatTusStorage::CHUNK_BYTES + 1);
            if ($chunk === false) throw new RuntimeException('Cannot read upload chunk.', 400);
            header('Upload-Offset: '.$store->append($meta, $id, (int)$offset, $chunk)); http_response_code(204);
        } elseif ($method === 'GET') {
            header('Content-Type: application/json'); echo json_encode($store->complete($meta, $id), JSON_THROW_ON_ERROR);
        } elseif ($method === 'DELETE') {
            $store->terminate($id); http_response_code(204);
        } else { header('Allow: OPTIONS,POST,HEAD,PATCH,GET,DELETE'); throw new RuntimeException('Method not allowed.', 405); }
    });
} catch (Throwable $e) {
    $code = $e instanceof RuntimeException && $e->getCode() >= 400 && $e->getCode() <= 599 ? $e->getCode() : 500;
    if ($code >= 500) error_log('[Chat tus] '.$e->getMessage());
    http_response_code($code);
    header('Content-Type: application/json');
    if ($method !== 'HEAD') echo json_encode(['success'=>false,'error'=>$code >= 500 ? 'Upload service unavailable.' : $e->getMessage()]);
}
