<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';

AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
header('Content-Type: application/json; charset=utf-8');

function serverAccessJson(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $serverId = (int)($_GET['server_id'] ?? $_POST['server_id'] ?? 0);
    $db = Database::getInstance();

    // Workspace UI uses this mode to label every server the current user can see.
    if ($serverId < 1) {
        $stmt = $db->prepare(
            'SELECT s.id, s.name, s.type, s.status, s.owner_id
             FROM servers s
             INNER JOIN server_members sm ON sm.server_id = s.id
             WHERE sm.user_id = :uid AND s.status = \'active\'
             ORDER BY s.id ASC'
        );
        $stmt->execute([':uid' => (int)$user['id']]);
        serverAccessJson([
            'success' => true,
            'servers' => array_map(static function (array $server): array {
                return [
                    'id' => (int)$server['id'],
                    'name' => $server['name'],
                    'type' => $server['type'],
                    'owner_id' => (int)$server['owner_id'],
                ];
            }, $stmt->fetchAll(PDO::FETCH_ASSOC)),
        ]);
    }

    $stmt = $db->prepare('SELECT id, name, type, status, owner_id FROM servers WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $serverId]);
    $server = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$server || $server['status'] !== 'active') {
        serverAccessJson(['success' => false, 'error' => 'Server not found.'], 404);
    }

    $memberStmt = $db->prepare('SELECT server_role FROM server_members WHERE server_id = :sid AND user_id = :uid LIMIT 1');
    $memberStmt->execute([':sid' => $serverId, ':uid' => (int)$user['id']]);
    $memberRole = $memberStmt->fetchColumn();
    $isMember = $memberRole !== false;

    if ($server['type'] === 'private' && !$isMember) {
        serverAccessJson([
            'success' => false,
            'access' => false,
            'type' => 'private',
            'error' => 'This is a private server. You must be granted membership or join using a valid supported access mechanism.'
        ], 403);
    }

    serverAccessJson([
        'success' => true,
        'access' => true,
        'type' => $server['type'],
        'server_id' => (int)$server['id'],
        'name' => $server['name'],
        'member' => $isMember,
        'server_role' => $memberRole !== false ? $memberRole : null,
    ]);
} catch (Throwable $e) {
    error_log('[server-access] ' . $e->getMessage());
    serverAccessJson(['success' => false, 'error' => 'Unable to verify server access.'], 500);
}
