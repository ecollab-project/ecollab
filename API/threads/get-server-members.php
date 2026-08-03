<?php
declare(strict_types=1);
/**
 * threads/get-server-members.php
 * Returns ALL members of the current server for the Threads DM panel.
 * Excludes the current user. Includes any existing DM conversation IDs.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);

$serverId = (int)($_GET['server_id'] ?? 0);
if (!$serverId) {
    http_response_code(400);
    echo json_encode(['error' => 'server_id required']);
    exit;
}

try {
    $db = Database::getInstance();

    // Ensure dm_conversations & dm_messages tables exist
    // (they are created by dm_migration.sql but may be missing)
    $db->exec("
        CREATE TABLE IF NOT EXISTS dm_conversations (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_a      BIGINT UNSIGNED NOT NULL,
            user_b      BIGINT UNSIGNED NOT NULL,
            last_message VARCHAR(120)   DEFAULT NULL,
            last_msg_at  DATETIME       DEFAULT NULL,
            created_at   DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_pair (user_a, user_b)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS dm_messages (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            sender_id       BIGINT UNSIGNED NOT NULL,
            body            TEXT            NOT NULL,
            is_deleted      TINYINT(1)      NOT NULL DEFAULT 0,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_conv (conversation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS dm_reads (
            user_id         BIGINT UNSIGNED NOT NULL,
            conversation_id BIGINT UNSIGNED NOT NULL,
            last_read_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, conversation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Verify current user is a member of this server
    $check = $db->prepare("SELECT 1 FROM server_members WHERE server_id = :sid AND user_id = :uid LIMIT 1");
    $check->execute([':sid' => $serverId, ':uid' => $me['id']]);
    if (!$check->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Not a member of this server']);
        exit;
    }

    // Get all members excluding self
    // Use simple query first to avoid optional-column issues
    $stmt = $db->prepare("
        SELECT
            u.id,
            u.username,
            u.full_name,
            u.role,
            COALESCE(u.avatar_color_gradient, '#a855f7,#ec4899') AS grad,
            COALESCE(u.is_online, 0) AS is_online,
            sm.server_role,
            sm.nickname,
            dc.id AS conversation_id,
            dc.last_message,
            dc.last_msg_at,
            COALESCE((
                SELECT COUNT(*) FROM dm_messages dm
                WHERE dm.conversation_id = dc.id
                  AND dm.sender_id <> :me2
                  AND dm.is_deleted = 0
                  AND dm.created_at > COALESCE(
                      (SELECT dr.last_read_at FROM dm_reads dr
                       WHERE dr.user_id = :me3 AND dr.conversation_id = dc.id),
                      '1970-01-01'
                  )
            ), 0) AS unread_count
        FROM users u
        JOIN server_members sm
            ON sm.user_id = u.id
           AND sm.server_id = :sid
        LEFT JOIN dm_conversations dc
            ON (dc.user_a = :me4 AND dc.user_b = u.id)
            OR (dc.user_b = :me5 AND dc.user_a = u.id)
        WHERE u.id <> :me6
        ORDER BY
            CASE WHEN dc.last_msg_at IS NOT NULL THEN 0 ELSE 1 END,
            dc.last_msg_at DESC,
            u.is_online DESC,
            u.full_name ASC
        LIMIT 300
    ");
    $stmt->execute([
        ':me2' => $me['id'],
        ':me3' => $me['id'],
        ':me4' => $me['id'],
        ':me5' => $me['id'],
        ':me6' => $me['id'],
        ':sid' => $serverId,
    ]);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'members' => $members,
        'count'   => count($members),
        'server_id' => $serverId,
    ]);

} catch (Throwable $e) {
    error_log('[threads/get-server-members] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error'   => 'Server error',
        'detail'  => $e->getMessage(),  // remove this in production
    ]);
}
