<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH . '/services/CoworkspaceService.php';
require_once ROOT_PATH . '/services/OnlyOfficeService.php';

AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth();
$db = Database::getInstance();
$uid = (int)$user['id'];
$documentId = (int)($_GET['id'] ?? 0);
$workspaceId = (int)($_GET['workspace_id'] ?? 0);

if ($documentId < 1 || $workspaceId < 1) {
    http_response_code(400);
    exit('Document and Coworkspace are required.');
}

try {
    $workspace = CoworkspaceService::get($db, $workspaceId, $uid);
    $stmt = $db->prepare(
        'SELECT d.*
         FROM collab_documents d
         WHERE d.id = :did AND d.workspace_id = :wid
         LIMIT 1'
    );
    $stmt->execute([':did' => $documentId, ':wid' => $workspaceId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$document) throw new RuntimeException('Document not found.', 404);

    $role = (string)($workspace['member_role'] ?? '');
    $canEdit = in_array($role, ['host', 'editor', 'member'], true)
        && (int)$workspace['allow_edit_documents'] === 1;

    $documentType = match ((string)$document['file_type']) {
        'xlsx' => 'cell',
        'pptx' => 'slide',
        default => 'word',
    };

    $displayName = (string)($user['full_name'] ?? $user['username'] ?? 'User');
    $config = [
        'documentType' => $documentType,
        'document' => [
            'fileType' => (string)$document['file_type'],
            'key' => (string)$document['document_key'],
            'title' => (string)$document['file_name'],
            'url' => OnlyOfficeService::signedFileUrl($documentId, (string)$document['document_key']),
            'permissions' => [
                'edit' => $canEdit,
                'download' => true,
                'print' => true,
                'comment' => true,
                'review' => true,
            ],
        ],
        'editorConfig' => [
            'mode' => $canEdit ? 'edit' : 'view',
            'callbackUrl' => OnlyOfficeService::callbackUrl($documentId),
            'user' => [
                'id' => (string)$uid,
                'name' => $displayName,
            ],
        ],
        'height' => '100%',
        'width' => '100%',
        'type' => 'desktop',
    ];
    $config['token'] = OnlyOfficeService::sign($config);
    $documentServer = OnlyOfficeService::documentServerUrl();
    if ($documentServer === '') throw new RuntimeException('ONLYOFFICE_DOCUMENT_SERVER_URL is not configured.');
} catch (Throwable $e) {
    $status = (int)$e->getCode();
    http_response_code(($status >= 400 && $status < 600) ? $status : 500);
    exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars((string)$document['title'], ENT_QUOTES, 'UTF-8') ?> – Collabs</title>
    <style>
        html,
        body {
            height: 100%;
            width: 100%;
            margin: 0;
            padding: 0;
            background: #0f1117;
            color: #f5f7fb;
            font-family: Inter, system-ui, sans-serif;
            overflow: hidden;
        }

        body {
            display: flex;
            flex-direction: column;
        }

        .bar {
            height: 52px;
            min-height: 52px;
            box-sizing: border-box;
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 0 16px;
            background: #171a23;
            border-bottom: 1px solid #292e3b;
        }

        .back {
            color: #cbd3e0;
            text-decoration: none;
            flex-shrink: 0;
        }

        .title {
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            min-width: 0;
        }

        .presence {
            margin-left: auto;
            color: #aeb8ca;
            font-size: 13px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .editor {
            flex: 1;
            min-height: 0;
            min-width: 0;
            width: 100%;
            height: calc(100vh - 52px);
            overflow: hidden;
        }

        @media (max-width: 768px) {
            .bar {
                height: 48px;
                min-height: 48px;
                padding: 0 10px;
                gap: 8px;
            }

            .back {
                font-size: 14px;
            }

            .title {
                font-size: 14px;
            }

            .presence {
                font-size: 11px;
            }

            .editor {
                height: calc(100dvh - 48px);
            }
        }

        @media (max-width: 480px) {
            .bar {
                padding: 0 8px;
            }

            .presence {
                max-width: 90px;
                overflow: hidden;
                text-overflow: ellipsis;
            }
        }
    </style>
    <script src="<?= BASE_URL ?>/assets/js/accessibility-apply.js" defer></script>
</head>

<body>
    <header class="bar">
        <a class="back" href="<?= htmlspecialchars(BASE_URL . '/modules/collaboration/server-coworkspaces.php?server_id=' . (int)$workspace['server_id'], ENT_QUOTES, 'UTF-8') ?>">← Collabs</a>
        <span class="title">📄 <?= htmlspecialchars((string)$document['title'], ENT_QUOTES, 'UTF-8') ?></span>
        <span id="presence" class="presence">Checking collaborators…</span>
    </header>
    <div id="onlyoffice-editor" class="editor"></div>
    <script src="<?= htmlspecialchars($documentServer, ENT_QUOTES, 'UTF-8') ?>/web-apps/apps/api/documents/api.js"></script>
    <script>
        const OO_CONFIG = <?= json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const PRESENCE_API = <?= json_encode(BASE_URL . '/API/collaboration/document-presence.php') ?>;
        const DOC_ID = <?= $documentId ?>;
        const CSRF = <?= json_encode(AuthMiddleware::csrfToken()) ?>;
        let editor;

        function updatePresence() {
            fetch(PRESENCE_API + '?document_id=' + DOC_ID, {
                    credentials: 'same-origin'
                })
                .then(r => r.json()).then(d => {
                    const p = d.presence || [];
                    const editing = p.filter(x => x.mode === 'editing').length;
                    const viewing = p.length - editing;
                    document.getElementById('presence').textContent = p.length ? (editing + ' editing · ' + viewing + ' viewing') : 'Only you';
                }).catch(() => {});
        }

        function heartbeat() {
            fetch(PRESENCE_API, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF
                },
                body: JSON.stringify({
                    document_id: DOC_ID,
                    mode: OO_CONFIG.document.permissions.edit ? 'editing' : 'viewing',
                    csrf_token: CSRF
                })
            }).then(() => updatePresence()).catch(() => {});
        }

        function isMobileDevice() {
            return window.matchMedia('(max-width: 768px)').matches ||
                /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent);
        }

        function initializeOnlyOffice() {
            if (!window.DocsAPI) {
                document.getElementById('onlyoffice-editor').textContent =
                    'ONLYOFFICE editor could not be loaded.';
                return;
            }

            OO_CONFIG.type = isMobileDevice() ? 'mobile' : 'desktop';

            editor = new DocsAPI.DocEditor(
                'onlyoffice-editor',
                OO_CONFIG
            );
        }

        initializeOnlyOffice();
        heartbeat();
        setInterval(heartbeat, 20000);
        setInterval(updatePresence, 10000);
        window.addEventListener('beforeunload', () => {});
    </script>
</body>

</html>