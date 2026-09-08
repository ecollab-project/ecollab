<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH . '/services/OnlyOfficeService.php';
require_once ROOT_PATH . '/services/CoworkspaceService.php';

AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
header('Content-Type: application/json; charset=utf-8');
$db = Database::getInstance();
$uid = (int)$user['id'];

function jsonFail(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function input(): array
{
    $value = json_decode(file_get_contents('php://input'), true);
    return is_array($value) ? $value : $_POST;
}

function resolveWorkspace(PDO $db, int $uid, array $input): array
{
    $workspaceId = (int)($input['workspace_id'] ?? $_GET['workspace_id'] ?? 0);
    if ($workspaceId > 0) return CoworkspaceService::get($db, $workspaceId, $uid);

    $channelId = (int)($input['channel_id'] ?? $_GET['channel_id'] ?? 0);
    if ($channelId < 1) throw new RuntimeException('A Coworkspace is required.', 400);
    $stmt = $db->prepare(
        'SELECT c.server_id
         FROM channels c
         INNER JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=:uid AND sm.status="active"
         WHERE c.id=:cid LIMIT 1'
    );
    $stmt->execute([':uid' => $uid, ':cid' => $channelId]);
    $serverId = (int)$stmt->fetchColumn();
    if ($serverId < 1) throw new RuntimeException('You are not a member of this server.', 403);
    $stmt = $db->prepare('SELECT id FROM collab_workspaces WHERE server_id=:sid AND archived=0 ORDER BY id ASC LIMIT 1');
    $stmt->execute([':sid' => $serverId]);
    $workspaceId = (int)$stmt->fetchColumn();
    if ($workspaceId < 1) throw new RuntimeException('The server Coworkspace has not been synchronized yet.', 404);
    return CoworkspaceService::get($db, $workspaceId, $uid);
}

function writeZip(ZipArchive $zip, string $path, string $content): void { $zip->addFromString($path, $content); }

function createOoxmlTemplate(string $type, string $path): bool
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    if ($type === 'docx') {
        writeZip($zip, '[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        writeZip($zip, '_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        writeZip($zip, 'word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Start collaborating in eCollab.</w:t></w:r></w:p><w:sectPr/></w:body></w:document>');
        writeZip($zip, 'word/_rels/document.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
    } elseif ($type === 'xlsx') {
        writeZip($zip, '[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        writeZip($zip, '_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        writeZip($zip, 'xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        writeZip($zip, 'xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        writeZip($zip, 'xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Start collaborating in eCollab.</t></is></c></row></sheetData></worksheet>');
    } else {
        writeZip($zip, '[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/></Types>');
        writeZip($zip, '_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/></Relationships>');
        writeZip($zip, 'ppt/presentation.xml', '<?xml version="1.0"?><p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"><p:sldMasterIdLst/><p:sldIdLst/><p:sldSz cx="12192000" cy="6858000" type="screen16x9"/></p:presentation>');
        writeZip($zip, 'ppt/_rels/presentation.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>');
    }
    return $zip->close();
}

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $data = $method === 'GET' ? $_GET : input();
    $workspace = resolveWorkspace($db, $uid, $data);
    $wid = (int)$workspace['id'];

    if ($method === 'GET') {
        $stmt = $db->prepare(
            'SELECT id,title,file_name,file_type,document_key,version,created_by,updated_by,created_at,updated_at
             FROM collab_documents WHERE workspace_id=:wid ORDER BY updated_at DESC,id DESC'
        );
        $stmt->execute([':wid' => $wid]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $presence = [];
        if ($documents) {
            $ids = array_map(static fn(array $d): int => (int)$d['id'], $documents);
            $q = implode(',', array_fill(0, count($ids), '?'));
            $p = $db->prepare(
                'SELECT p.document_id,p.user_id,p.mode,p.last_seen,COALESCE(NULLIF(u.full_name,""),u.username) AS display_name
                 FROM collab_document_presence p INNER JOIN users u ON u.id=p.user_id
                 WHERE p.document_id IN (' . $q . ') AND p.last_seen >= (NOW() - INTERVAL 45 SECOND)
                 ORDER BY p.document_id,p.mode,display_name'
            );
            $p->execute($ids);
            foreach ($p->fetchAll(PDO::FETCH_ASSOC) as $row) $presence[(int)$row['document_id']][] = [
                'user_id'=>(int)$row['user_id'],'mode'=>(string)$row['mode'],'display_name'=>(string)$row['display_name'],'last_seen'=>(string)$row['last_seen']
            ];
        }
        foreach ($documents as &$doc) {
            $users = $presence[(int)$doc['id']] ?? [];
            $editing = count(array_filter($users, static fn(array $p): bool => $p['mode'] === 'editing'));
            $doc['presence'] = ['total'=>count($users),'editing'=>$editing,'viewing'=>count($users)-$editing,'users'=>$users];
            $doc['open_url'] = BASE_URL . '/modules/collaboration/document.php?id=' . (int)$doc['id'] . '&workspace_id=' . $wid;
            $doc['file_url'] = OnlyOfficeService::signedFileUrl((int)$doc['id'], (string)$doc['document_key']);
            unset($doc['document_key']);
        }
        unset($doc);
        echo json_encode(['success'=>true,'workspace'=>$wid,'documents'=>$documents]);
        exit;
    }

    if ($method !== 'POST') jsonFail('Method not allowed.', 405);
    AuthMiddleware::verifyCsrf();
    $title = trim((string)($data['title'] ?? 'Untitled Document'));
    $type = strtolower(trim((string)($data['type'] ?? 'docx')));
    $title = trim(substr(
    preg_replace('~[\\\\/:*?"<>|]+~', ' ', $title) ?: 'Untitled Document',
    0,
    220
));
    if ($title === '') $title = 'Untitled Document';
    if (!in_array($type, ['docx','xlsx','pptx'], true)) jsonFail('Supported formats are DOCX, XLSX and PPTX.');
    $role = (string)($workspace['member_role'] ?? '');
    if ((int)$workspace['allow_create_documents'] !== 1 && !in_array($role, ['host','editor'], true)) jsonFail('Document creation is disabled for this Coworkspace.',403);
    $storageDir = ROOT_PATH . '/uploads/collab-docs';
    if (!is_dir($storageDir) && !mkdir($storageDir,0750,true) && !is_dir($storageDir)) jsonFail('Document storage is unavailable.',500);
    $key = bin2hex(random_bytes(24)); $ext='.' . $type; $fileName=$title.$ext; $storageName=$key.$ext; $path=$storageDir.DIRECTORY_SEPARATOR.$storageName;
    if (!class_exists('ZipArchive') || !createOoxmlTemplate($type,$path)) { @unlink($path); jsonFail('Could not create the document.',500); }
    $stmt = $db->prepare(
    'INSERT INTO collab_documents
    (channel_id, workspace_id, title, file_name, file_type, storage_path, document_key, created_by, updated_by)
    VALUES
    (:cid, :wid, :title, :name, :type, :path, :key, :created_by, :updated_by)'
);

$stmt->execute([
    ':cid' => (int)$workspace['channel_id'],
    ':wid' => $wid,
    ':title' => $title,
    ':name' => $fileName,
    ':type' => $type,
    ':path' => 'uploads/collab-docs/' . $storageName,
    ':key' => $key,
    ':created_by' => $uid,
    ':updated_by' => $uid
]);
    $id=(int)$db->lastInsertId();
    echo json_encode(['success'=>true,'document'=>['id'=>$id,'title'=>$title,'file_name'=>$fileName,'file_type'=>$type,'workspace_id'=>$wid,'open_url'=>BASE_URL.'/modules/collaboration/document.php?id='.$id.'&workspace_id='.$wid]]);
} catch (Throwable $e) {
    $status=(int)$e->getCode(); jsonFail($e->getMessage(),($status>=400&&$status<600)?$status:500);
}
