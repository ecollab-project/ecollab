<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json; charset=utf-8');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);
$db = Database::getInstance();

function channelSettingsJson(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['success' => $status < 400, ...$data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function channelSettingsChannel(PDO $db, int $channelId): ?array
{
    $stmt = $db->prepare('SELECT id, server_id, name, slug, type, description, is_private, is_locked, created_by FROM channels WHERE id = ? LIMIT 1');
    $stmt->execute([$channelId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function channelSettingsRole(PDO $db, int $serverId, int $userId): ?string
{
    $stmt = $db->prepare('SELECT server_role FROM server_members WHERE server_id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$serverId, $userId]);
    return $stmt->fetchColumn() ?: null;
}

function channelSettingsCanManage(PDO $db, array $channel, int $userId): bool
{
    $role = channelSettingsRole($db, (int)$channel['server_id'], $userId);
    return in_array($role, ['owner', 'admin', 'moderator'], true) || (int)$channel['created_by'] === $userId;
}

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $channelId = (int)($_GET['channel_id'] ?? $_POST['channel_id'] ?? 0);
    if (!$channelId) channelSettingsJson(['error' => 'channel_id is required'], 400);

    $channel = channelSettingsChannel($db, $channelId);
    if (!$channel) channelSettingsJson(['error' => 'Channel not found'], 404);
    if (!channelSettingsRole($db, (int)$channel['server_id'], (int)$me['id'])) {
        channelSettingsJson(['error' => 'Server membership required'], 403);
    }
    if (!channelSettingsCanManage($db, $channel, (int)$me['id'])) {
        channelSettingsJson(['error' => 'Only the channel owner or server moderators can manage this channel'], 403);
    }

    if ($method === 'GET') {
        $membersStmt = $db->prepare(
            'SELECT cm.user_id, u.username, u.full_name, u.avatar_color_gradient, u.is_online
             FROM channel_members cm
             JOIN users u ON u.id = cm.user_id
             WHERE cm.channel_id = ? AND u.deleted_at IS NULL
             ORDER BY u.full_name, u.username'
        );
        $membersStmt->execute([$channelId]);
        channelSettingsJson([
            'channel' => $channel,
            'members' => $membersStmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    if ($method !== 'POST') channelSettingsJson(['error' => 'Method not allowed'], 405);
    AuthMiddleware::verifyCsrf();
    $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $name = trim((string)($body['name'] ?? $channel['name']));
    $description = trim((string)($body['description'] ?? $channel['description'] ?? ''));
    $isPrivate = !empty($body['is_private']) ? 1 : 0;

    if ($name === '') channelSettingsJson(['error' => 'Channel name is required'], 400);
    if (mb_strlen($name) > 60) channelSettingsJson(['error' => 'Channel name must be 60 characters or fewer'], 400);
    if (mb_strlen($description) > 255) channelSettingsJson(['error' => 'Description must be 255 characters or fewer'], 400);

    $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
    if ($slug === '') $slug = 'channel';
    $slug = substr($slug, 0, 60);
    $candidate = $slug;
    $suffix = 2;
    while (true) {
        $check = $db->prepare('SELECT 1 FROM channels WHERE server_id = ? AND slug = ? AND id <> ? LIMIT 1');
        $check->execute([(int)$channel['server_id'], $candidate, $channelId]);
        if (!$check->fetchColumn()) break;
        $tail = '-' . $suffix++;
        $candidate = substr($slug, 0, max(1, 60 - strlen($tail))) . $tail;
    }
    $slug = $candidate;

    $db->beginTransaction();
    try {
        $stmt = $db->prepare('UPDATE channels SET name = ?, slug = ?, description = ?, is_private = ? WHERE id = ?');
        $stmt->execute([$name, $slug, $description !== '' ? $description : null, $isPrivate, $channelId]);

        // The creator must always retain access when a channel is private.
        if ($isPrivate && (int)$channel['created_by'] > 0) {
            $db->prepare('INSERT IGNORE INTO channel_members(channel_id, user_id) VALUES(?, ?)')
                ->execute([$channelId, (int)$channel['created_by']]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    channelSettingsJson(['channel' => channelSettingsChannel($db, $channelId)]);
} catch (Throwable $e) {
    error_log('[chat/channel-settings] ' . $e->getMessage());
    channelSettingsJson([
        'error' => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Channel settings unavailable'
    ], 500);
}
