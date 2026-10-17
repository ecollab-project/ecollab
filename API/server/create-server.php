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
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($body['name'] ?? '');
    if ($name === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Server name required']);
        exit;
    }

    // Server visibility is intentionally backed by the existing servers.type
    // column. Keep public as the backwards-compatible default, while allowing
    // the creation UI to explicitly request a private server.
    $type = strtolower(trim((string)($body['type'] ?? $body['visibility'] ?? 'public')));
    if (!in_array($type, ['public', 'private'], true)) {
        $type = 'public';
    }

    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)) . '-' . substr(md5(uniqid()), 0, 5);
    $template = in_array($body['template'] ?? '', ['study-group', 'research', 'gaming', 'custom']) ? $body['template'] : 'custom';
    $emojis = ['study-group' => '📚', 'research' => '🔬', 'gaming' => '🎮', 'custom' => '⚙️'];
    $db = Database::getInstance();

    // Server creation is atomic: a failure in membership or default-channel
    // creation must not leave a partially-created server behind.
    $db->beginTransaction();
    try {
        $ins = $db->prepare("INSERT INTO servers (owner_id, name, slug, category, icon_emoji, type, created_at, updated_at) VALUES (:uid,:name,:slug,:cat,:emoji,:type,NOW(),NOW())");
        $ins->execute([
            ':uid' => $user['id'],
            ':name' => $name,
            ':slug' => $slug,
            ':cat' => $template,
            ':emoji' => $emojis[$template] ?? '⚙️',
            ':type' => $type,
        ]);
        $serverId = (int)$db->lastInsertId();

        // Add owner as member
        $db->prepare("INSERT INTO server_members (server_id, user_id, server_role, joined_at) VALUES (:sid,:uid,'owner',NOW())")->execute([':sid' => $serverId, ':uid' => $user['id']]);

        // Create default channels
        $db->prepare("INSERT INTO channels (server_id,name,slug,type,position,created_by) VALUES (:sid,'general','general','text',1,:uid)")->execute([':sid' => $serverId, ':uid' => $user['id']]);
        $db->prepare("INSERT INTO channels (server_id,name,slug,type,position,created_by) VALUES (:sid,'general-voice','general-voice','voice',2,:uid)")->execute([':sid' => $serverId, ':uid' => $user['id']]);
        $db->prepare("INSERT INTO channels (server_id,name,slug,type,position,created_by) VALUES (:sid,'whiteboard','whiteboard','whiteboard',3,:uid)")->execute([':sid' => $serverId, ':uid' => $user['id']]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    echo json_encode([
        'success' => true,
        'server_id' => $serverId,
        'name' => $name,
        'type' => $type,
    ]);
} catch (Throwable $e) {
    error_log('[create-server] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Server error']);
}
