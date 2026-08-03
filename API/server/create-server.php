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
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)) . '-' . substr(md5(uniqid()), 0, 5);
    $template = in_array($body['template'] ?? '', ['study-group', 'research', 'gaming', 'custom']) ? $body['template'] : 'custom';
    $emojis = ['study-group' => '📚', 'research' => '🔬', 'gaming' => '🎮', 'custom' => '⚙️'];
    $db = Database::getInstance();
    $ins = $db->prepare("INSERT INTO servers (owner_id, name, slug, category, icon_emoji, created_at, updated_at) VALUES (:uid,:name,:slug,:cat,:emoji,NOW(),NOW())");
    $ins->execute([':uid' => $user['id'], ':name' => $name, ':slug' => $slug, ':cat' => $template, ':emoji' => $emojis[$template] ?? '⚙️']);
    $serverId = (int)$db->lastInsertId();
    // Add owner as member
    $db->prepare("INSERT INTO server_members (server_id, user_id, server_role, joined_at) VALUES (:sid,:uid,'owner',NOW())")->execute([':sid' => $serverId, ':uid' => $user['id']]);
    // Create default channels
    $db->prepare("INSERT INTO channels (server_id,name,slug,type,position,created_by) VALUES (:sid,'general','general','text',1,:uid)")->execute([':sid' => $serverId, ':uid' => $user['id']]);
    $db->prepare("INSERT INTO channels (server_id,name,slug,type,position,created_by) VALUES (:sid,'general-voice','general-voice','voice',2,:uid)")->execute([':sid' => $serverId, ':uid' => $user['id']]);
    $db->prepare("INSERT INTO channels (server_id,name,slug,type,position,created_by) VALUES (:sid,'whiteboard','whiteboard','whiteboard',3,:uid)")->execute([':sid' => $serverId, ':uid' => $user['id']]);
    echo json_encode(['success' => true, 'server_id' => $serverId, 'name' => $name]);
} catch (Throwable $e) {
    error_log('[create-server] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Server error']);
}
