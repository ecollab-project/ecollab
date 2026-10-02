<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/services/CollaboraService.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json');
$temp = null;
try {
    if (!CollaboraService::enabled()) throw new RuntimeException('Editor disabled.', 404);
    // PATH_INFO is supplied by the documented exact nginx location, not a query parameter.
    if (!preg_match('~^/files/([1-9][0-9]*)(/contents)?$~D', $_SERVER['PATH_INFO'] ?? '', $route)) throw new RuntimeException('Not found.', 404);
    $id = (int)$route[1];
    $token = (string)($_GET['access_token'] ?? '');
    $db = Database::getInstance();
    $a = CollaboraService::authorize($db, $id, $token);
    $d = $a['document'];
    $method = $_SERVER['REQUEST_METHOD'];
    $contents = !empty($route[2]);
    if ($method === 'GET') {
        $path = DocumentAccessService::path($d);
        header('X-WOPI-ItemVersion: ' . (int)$d['version']);
        if ($contents) {
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . filesize($path));
            readfile($path); exit;
        }
        $s = $db->prepare('SELECT COALESCE(NULLIF(full_name,""),username) FROM users WHERE id=?'); $s->execute([$a['uid']]);
        echo json_encode([
            'BaseFileName' => $d['file_name'], 'OwnerId' => (string)$d['created_by'],
            'Size' => filesize($path), 'UserId' => (string)$a['uid'],
            'UserFriendlyName' => (string)$s->fetchColumn(), 'Version' => (string)$d['version'],
            'LastModifiedTime' => CollaboraService::timestamp($d),
            'UserCanWrite' => $a['write'], 'ReadOnly' => !$a['write'],
            'SupportsUpdate' => true, 'SupportsLocks' => false,
            'UserCanRename' => false, 'SupportsRename' => false,
        ], JSON_THROW_ON_ERROR); exit;
    }
    if ($method !== 'POST' || !$contents || ($_SERVER['HTTP_X_WOPI_OVERRIDE'] ?? '') !== 'PUT') throw new RuntimeException('Unsupported operation.', 501);
    if (!$a['write']) throw new RuntimeException('Read-only document.', 403);
    $limit = 25 * 1024 * 1024;
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit) throw new RuntimeException('Document exceeds 25 MB.', 413);
    $temp = tempnam(dirname(DocumentAccessService::path($d)), '.wopi-');
    if ($temp === false) throw new RuntimeException('Storage unavailable.', 500);
    $in = fopen('php://input', 'rb'); $out = fopen($temp, 'wb');
    $size = stream_copy_to_stream($in, $out, $limit + 1); fclose($in); fclose($out);
    if (!$size || $size > $limit) throw new RuntimeException('Invalid document size.', 413);
    $zip = new ZipArchive();
    if ($zip->open($temp) !== true) throw new RuntimeException('Invalid Office document.', 400);
    $part = ['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml'][$d['file_type']] ?? '';
    $valid = $zip->locateName('[Content_Types].xml') !== false && $part !== '' && $zip->locateName($part) !== false;
    $zip->close();
    if (!$valid) throw new RuntimeException('Wrong document format.', 400);
    echo json_encode(CollaboraService::save($db, $id, $token, $temp, $_SERVER['HTTP_X_COOL_WOPI_TIMESTAMP'] ?? null), JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $code = in_array($e->getCode(), [400,401,403,404,409,413,501], true) ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode($code === 409 ? ['COOLStatusCode' => 1010] : ['error' => $code === 500 ? 'Document operation failed.' : $e->getMessage()]);
} finally {
    if (is_string($temp) && is_file($temp)) unlink($temp);
}
