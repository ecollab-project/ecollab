<?php
declare(strict_types=1);

// ── Catch absolutely everything, including fatal errors ──────────────────────
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        $debug = defined('APP_DEBUG') && APP_DEBUG;
        echo json_encode([
            'error' => $debug
                ? '[FATAL] ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line']
                : 'Server error',
        ]);
    }
});

require_once dirname(__DIR__, 2) . '/config.php';

if (defined('APP_DEBUG') && APP_DEBUG) {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/MessageService.php';

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
    $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $channelId = filter_var($body['channel_id'] ?? 0, FILTER_VALIDATE_INT);
    if (!$channelId) {
        http_response_code(400);
        echo json_encode(['error' => 'channel_id is required']);
        exit;
    }

    // Private-channel access must be enforced here as well as in the UI.
    // Without this check a regular server member who knows a private channel ID
    // could post directly to send-message.php.
    $db = Database::getInstance();
    $roleStmt = $db->prepare('SELECT role FROM users WHERE id = :uid LIMIT 1');
    $roleStmt->execute([':uid' => $user['id']]);
    $userRole = $roleStmt->fetchColumn() ?: 'student';
    $isPrivileged = in_array($userRole, ['admin', 'super_admin', 'moderator'], true);

    if (!$isPrivileged) {
        $accessStmt = $db->prepare('
            SELECT c.is_private, c.created_by, sm.server_role,
                   EXISTS(
                       SELECT 1 FROM channel_members cm
                       WHERE cm.channel_id = c.id AND cm.user_id = :uid_access
                   ) AS has_channel_access
            FROM channels c
            JOIN server_members sm
              ON sm.server_id = c.server_id AND sm.user_id = :uid_member
            WHERE c.id = :cid
            LIMIT 1
        ');
        $accessStmt->execute([
            ':uid_access' => $user['id'],
            ':uid_member' => $user['id'],
            ':cid'        => $channelId,
        ]);
        $access = $accessStmt->fetch();

        if (!$access) {
            throw new RuntimeException('Access denied', 403);
        }

        $canManage = in_array($access['server_role'], ['owner', 'admin', 'moderator'], true)
            || (int)$access['created_by'] === (int)$user['id'];

        if ((int)$access['is_private'] === 1 && !(bool)$access['has_channel_access'] && !$canManage) {
            throw new RuntimeException('You do not have access to this private channel', 403);
        }
    }

    $service = new MessageService();
    $message = $service->sendMessage((int)$channelId, $user['id'], $body);

    http_response_code(201);
    echo json_encode(['success' => true, 'message' => $message]);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);

} catch (RuntimeException $e) {
    $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['error' => $e->getMessage()]);

} catch (Throwable $e) {
    error_log('[Ecollab] send-message Throwable: ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'error' => (defined('APP_DEBUG') && APP_DEBUG)
            ? $e->getMessage() . ' — ' . basename($e->getFile()) . ':' . $e->getLine()
            : 'Server error',
    ]);
}
