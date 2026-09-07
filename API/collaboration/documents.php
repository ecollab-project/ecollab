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

function requestInput(): array
{
    $input = json_decode(file_get_contents('php://input'), true);
    return is_array($input) ? $input : $_POST;
}

function workspaceFromRequest(PDO $db, int $userId, array $input): array
{
    $workspaceId = (int)($input['workspace_id'] ?? $_GET['workspace_id'] ?? 0);
    if ($workspaceId > 0) {
        return CoworkspaceService::get($db, $workspaceId, $userId);
    }

    // Compatibility bridge for older links: resolve the channel's server to
    // the single active server Coworkspace, then continue using workspace scope.
    $channelId = (int)($input['channel_id'] ?? $_GET['channel_id'] ?? 0);
    if ($channelId < 1) {
        throw new RuntimeException('A Coworkspace is required.', 400);
    }

    $stmt = $db->prepare(
        'SELECT c.server_id
         FROM channels c
         INNER JOIN server_members sm
           ON sm.server_id = c.server_id AND sm.user_id = :uid AND sm.status = "active"
         WHERE c.id = :cid
         LIMIT 1'
    );
    $stmt->execute([':uid' => $userId, ':cid' => $channelId]);
    $serverId = (int)$stmt->fetchColumn();
    if ($serverId < 1) throw new RuntimeException('You are not a member of this server.', 403);

    $stmt = $db->prepare('SELECT id FROM collab_workspaces WHERE server_id = :sid AND archived = 0 ORDER BY id ASC LIMIT 1');
    $stmt->execute([':sid' => $serverId]);
    $workspaceId = (int)$stmt->fetchColumn();
    if ($workspaceId < 1) throw new RuntimeException('The server Coworkspace has not been synchronized yet.', 404);

    return CoworkspaceService::get($db, $workspaceId, $userId);
}

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $input = $method === 'GET' ? $_GET : requestInput();
    $workspace = workspaceFromRequest($db, $uid, $input);
    $workspaceId = (int)$workspace['id'];

    if ($method === 'GET') {
        $stmt = $db->prepare(
            'SELECT id, title, file_name, file_type, version, created_by, updated_by, created_at, updated_at
             FROM collab_documents
             WHERE workspace_id = :wid
             ORDER BY updated_at DESC, id DESC'
        );
        $stmt->execute([':wid' => $workspaceId]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $presence = [];
        if ($documents) {
            $ids = array_map(static fn(array $doc): int => (int)$doc['id'], $documents);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $presenceStmt = $db->prepare(
                'SELECT p.document_id, p.user_id, p.mode, p.last_seen,
                        COALESCE(NULLIF(u.full_name, ""), u.username) AS display_name
                 FROM collab_document_presence p
                 INNER JOIN users u ON u.id = p.user_id
                 WHERE p.document_id IN (' . $placeholders . ')
                   AND p.last_seen >= (NOW() - INTERVAL 45 SECOND)
                 ORDER BY p.document_id, p.mode, display_name'
            );
            $presenceStmt->execute($ids);
            foreach ($presenceStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (int)$row['document_id'];
                if (!isset($presence[$id])) $presence[$id] = [];
                $presence[$id][] = [
                    'user_id' => (int)$row['user_id'],
                    'mode' => (string)$row['mode'],
                    'display_name' => (string)$row['display_name'],
                    'last_seen' => (string)$row['last_seen'],
                ];
            }
        }

        foreach ($documents as &$document) {
            $id = (int)$document['id'];
            $users = $presence[$id] ?? [];
            $editing = 0;
            foreach ($users as $person) if ($person['mode'] === 'editing') $editing++;
            $document['presence'] = [
                'total' => count($users),
                'editing' => $editing,
                'viewing' => count($users) - $editing,
                'users' => $users,
            ];
            $document['open_url'] = BASE_URL . '/modules/collaboration/document.php?id=' . $id . '&workspace_id=' . $workspaceId;
            $document['file_url'] = OnlyOfficeService::signedFileUrl($id, (string)$document['document_key']);
        }
        unset($document);

        echo json_encode(['success' => true, 'workspace' => $workspaceId, 'documents' => $documents]);
        exit;
    }

    if ($method !== 'POST') jsonFail('Method not allowed.', 405);
    AuthMiddleware::verifyCsrf();

    $title = trim((string)($input['title'] ?? 'Untitled Document'));
    $type = strtolower(trim((string)($input['type'] ?? 'docx')));
    if ($title === '') $title = 'Untitled Document';
    $title = preg_replace('/[\\\/\:\*\?"\<\>\|]+/', ' ', $title) ?: 'Untitled Document';
    $title = trim(substr($title, 0, 220));
    if (!in_array($type, ['docx', 'xlsx', 'pptx'], true)) jsonFail('Supported formats are DOCX, XLSX and PPTX.');

    $role = (string)($workspace['member_role'] ?? '');
    if ((int)$workspace['allow_create_documents'] !== 1 && !in_array($role, ['host', 'editor'], true)) {
        jsonFail('Document creation is disabled for this Coworkspace.', 403);
    }

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
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/></Types>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="' . ($type === 'docx' ? 'word/document.xml' : ($type === 'xlsx' ? 'xl/workbook.xml' : 'ppt/presentation.xml')) . '"/></Relationships>';
        zipWrite($zip, '[Content_Types].xml', $contentTypes);
        zipWrite($zip, '_rels/.rels', $rels);
        if ($type === 'docx') {
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
            zipWrite($zip, 'word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Start collaborating in eCollab.</w:t></w:r></w:p><w:sectPr/></w:body></w:document>');
            zipWrite($zip, 'word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/relationships"/>');
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

    if (!class_exists('ZipArchive') || !createOoxmlTemplate($type, $absolutePath)) {
        @unlink($absolutePath);
        jsonFail('Could not create the document.', 500);
    }

    $stmt = $db->prepare(
        'INSERT INTO collab_documents
            (channel_id, workspace_id, title, file_name, file_type, storage_path, document_key, created_by, updated_by)
         VALUES (:cid, :wid, :title, :file_name, :type, :path, :key, :uid, :uid)'
    );
    $stmt->execute([
        ':cid' => (int)$workspace['channel_id'],
        ':wid' => $workspaceId,
        ':title' => $title,
        ':file_name' => $fileName,
        ':type' => $type,
        ':path' => 'uploads/collab-docs/' . $storageName,
        ':key' => $documentKey,
        ':uid' => $uid,
    ]);
    $id = (int)$db->lastInsertId();

    echo json_encode([
        'success' => true,
        'document' => [
            'id' => $id,
            'title' => $title,
            'file_name' => $fileName,
            'file_type' => $type,
            'workspace_id' => $workspaceId,
            'open_url' => BASE_URL . '/modules/collaboration/document.php?id=' . $id . '&workspace_id=' . $workspaceId,
        ],
    ]);
} catch (Throwable $e) {
    $status = (int)$e->getCode();
    jsonFail($e->getMessage(), ($status >= 400 && $status < 600) ? $status : 500);
}
