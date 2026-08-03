<?php
declare(strict_types=1);
/**
 * channel-access-request.php
 *
 * GET  ?channel_id=X                         → list pending requests (owner/admin only)
 * POST action=request   channel_id           → non-member requests access
 * POST action=accept    channel_id, user_id  → owner grants access
 * POST action=decline   channel_id, user_id  → owner denies access
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);

$db = Database::getInstance();

// Auto-create table if not already there
$db->exec("
    CREATE TABLE IF NOT EXISTS channel_access_requests (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        channel_id   INT UNSIGNED    NOT NULL,
        user_id      BIGINT UNSIGNED NOT NULL,
        status       ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        resolved_at  DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_channel_user (channel_id, user_id),
        KEY idx_channel (channel_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── helpers ────────────────────────────────────────────────────────────────
function getChannel(PDO $db, int $cid): ?array {
    $s = $db->prepare("SELECT id, server_id, name, is_private, created_by FROM channels WHERE id = :id LIMIT 1");
    $s->execute([':id' => $cid]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function canManage(PDO $db, array $channel, int $userId): bool {
    $s = $db->prepare("SELECT server_role FROM server_members WHERE server_id = :sid AND user_id = :uid LIMIT 1");
    $s->execute([':sid' => $channel['server_id'], ':uid' => $userId]);
    $role = $s->fetchColumn();
    return in_array($role, ['owner', 'admin', 'moderator'], true)
        || (int)$channel['created_by'] === $userId;
}

// ── GET: list pending requests ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $channelId = (int)($_GET['channel_id'] ?? 0);
    if (!$channelId) {
        http_response_code(400);
        echo json_encode(['error' => 'channel_id required']);
        exit;
    }
    $ch = getChannel($db, $channelId);
    if (!$ch) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
    if (!canManage($db, $ch, $me['id'])) {
        http_response_code(403); echo json_encode(['error' => 'Insufficient permissions']); exit;
    }

    $stmt = $db->prepare("
        SELECT r.id, r.user_id, r.status, r.requested_at,
               u.username, u.full_name,
               COALESCE(u.avatar_color_gradient, '#a855f7,#ec4899') AS grad,
               COALESCE(u.is_online, 0) AS is_online,
               u.role AS user_role,
               sm.server_role
        FROM channel_access_requests r
        JOIN users u ON u.id = r.user_id
        LEFT JOIN server_members sm ON sm.user_id = r.user_id AND sm.server_id = :sid
        WHERE r.channel_id = :cid AND r.status = 'pending'
        ORDER BY r.requested_at ASC
    ");
    $stmt->execute([':cid' => $channelId, ':sid' => $ch['server_id']]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'  => true,
        'channel'  => $ch,
        'requests' => $requests,
        'count'    => count($requests),
    ]);
    exit;
}

// ── POST ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $action    = $body['action']     ?? '';
    $channelId = (int)($body['channel_id'] ?? 0);
    $targetId  = (int)($body['user_id']    ?? 0);

    if (!$channelId || !$action) {
        http_response_code(400);
        echo json_encode(['error' => 'action and channel_id are required']);
        exit;
    }

    $ch = getChannel($db, $channelId);
    if (!$ch) { http_response_code(404); echo json_encode(['error' => 'Channel not found']); exit; }

    // ── REQUEST: user asking to join ───────────────────────────────────────
    if ($action === 'request') {
        // Check not already a member
        $already = $db->prepare("SELECT 1 FROM channel_members WHERE channel_id = :cid AND user_id = :uid LIMIT 1");
        $already->execute([':cid' => $channelId, ':uid' => $me['id']]);
        if ($already->fetch()) {
            echo json_encode(['success' => true, 'message' => 'Already a member']);
            exit;
        }
        // Upsert the request (reset to pending if previously declined)
        $db->prepare("
            INSERT INTO channel_access_requests (channel_id, user_id, status, requested_at)
            VALUES (:cid, :uid, 'pending', NOW())
            ON DUPLICATE KEY UPDATE status = 'pending', requested_at = NOW()
        ")->execute([':cid' => $channelId, ':uid' => $me['id']]);

        echo json_encode(['success' => true, 'message' => 'Access request sent']);
        exit;
    }

    // ── ACCEPT / DECLINE: owner action ────────────────────────────────────
    if (in_array($action, ['accept', 'decline'], true)) {
        if (!$targetId) {
            http_response_code(400);
            echo json_encode(['error' => 'user_id required']);
            exit;
        }
        if (!canManage($db, $ch, $me['id'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Insufficient permissions']);
            exit;
        }

        $newStatus = $action === 'accept' ? 'accepted' : 'declined';
        $db->prepare("
            UPDATE channel_access_requests
               SET status = :status, resolved_at = NOW()
             WHERE channel_id = :cid AND user_id = :uid
        ")->execute([':status' => $newStatus, ':cid' => $channelId, ':uid' => $targetId]);

        if ($action === 'accept') {
            // Grant channel access
            $db->prepare("
                INSERT IGNORE INTO channel_members (channel_id, user_id)
                VALUES (:cid, :uid)
            ")->execute([':cid' => $channelId, ':uid' => $targetId]);
            echo json_encode(['success' => true, 'message' => 'Access granted']);
        } else {
            echo json_encode(['success' => true, 'message' => 'Request declined']);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
