<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH . '/services/OnlyOfficeService.php';

AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
header('Content-Type: application/json; charset=utf-8');

$db = Database::getInstance();
$channelId = (int)($_GET['channel_id'] ?? $_POST['channel_id'] ?? 0);

function jsonFail(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

if ($channelId < 1) jsonFail('A channel is required.');

$member = $db->prepare('SELECT 1 FROM channel_members WHERE channel_id = :cid AND user_id = :uid LIMIT 1');
$member->execute([':cid' => $channelId, ':uid' => (int)$user['id']]);
if (!$member->fetchColumn()) jsonFail('You are not a member of this channel.', 403);

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method === 'GET') {
    $stmt = $db->prepare('SELECT id, title, file_name, file_type, version, created_by, updated_by, created_at, updated_at FROM collab_documents WHERE channel_id = :cid ORDER BY updated_at DESC');
    $stmt->execute([':cid' => $channelId]);
    echo json_encode(['success' => true, 'documents' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($method !== 'POST') jsonFail('Method not allowed.', 405);
AuthMiddleware::verifyCsrf();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
$title = trim((string)($input['title'] ?? 'Untitled Document'));
$type = strtolower(trim((string)($input['type'] ?? 'docx')));

if ($title === '') $title = 'Untitled Document';
$title = preg_replace('/[\\\/\:\*\?"\<\>\|]+/', ' ', $title) ?: 'Untitled Document';
$title = trim(substr($title, 0, 220));
if (!in_array($type, ['docx', 'xlsx', 'pptx'], true)) jsonFail('Supported formats are DOCX, XLSX and PPTX.');

$ext = '.' . $type;
$fileName = $title . $ext;
$documentKey = bin2hex(random_bytes(24));
$storageDir = ROOT_PATH . '/uploads/collab-docs';
if (!is_dir($storageDir) && !mkdir($storageDir, 0750, true) && !is_dir($storageDir)) {
    jsonFail('Document storage is unavailable.', 500);
}
$storageName = $documentKey . $ext;
$absolutePath = $storageDir . DIRECTORY_SEPARATOR . $storageName;

function zipWrite(ZipArchive $zip, string $path, string $content): void
{
    $zip->addFromString($path, $content);
}

function createOoxmlTemplate(string $type, string $path): bool
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="' . ($type === 'docx' ? 'word/document.xml' : ($type === 'xlsx' ? 'xl/workbook.xml' : 'ppt/presentation.xml')) . '"/></Relationships>';
    zipWrite($zip, '[Content_Types].xml', $contentTypes . '</Types>');
    zipWrite($zip, '_rels/.rels', $rels);

    if ($type === 'docx') {
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        zipWrite($zip, 'word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Start collaborating in eCollab.</w:t></w:r></w:p><w:sectPr/></w:body></w:document>');
        zipWrite($zip, 'word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
    } elseif ($type === 'xlsx') {
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        zipWrite($zip, 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        zipWrite($zip, 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        zipWrite($zip, 'xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Start collaborating in eCollab.</t></is></c></row></sheetData></worksheet>');
    } else {
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/></Types>');
        zipWrite($zip, 'ppt/presentation.xml', '<?xml version="1.0" encoding="UTF-8"?><p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"><p:sldMasterIdLst/><p:sldIdLst/><p:sldSz cx="12192000" cy="6858000" type="screen16x9"/></p:presentation>');
        zipWrite($zip, 'ppt/_rels/presentation.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
    }
    return $zip->close();
}

if (!createOoxmlTemplate($type, $absolutePath)) {
    @unlink($absolutePath);
    jsonFail('Could not create the document.', 500);
}

$stmt = $db->prepare('INSERT INTO collab_documents (channel_id,title,file_name,file_type,storage_path,document_key,created_by,updated_by) VALUES (:cid,:title,:file_name,:type,:path,:key,:uid,:uid)');
$stmt->execute([
    ':cid' => $channelId,
    ':title' => $title,
    ':file_name' => $fileName,
    ':type' => $type,
    ':path' => 'uploads/collab-docs/' . $storageName,
    ':key' => $documentKey,
    ':uid' => (int)$user['id'],
]);
$id = (int)$db->lastInsertId();

echo json_encode(['success' => true, 'document' => ['id' => $id, 'title' => $title, 'file_name' => $fileName, 'file_type' => $type]]);
