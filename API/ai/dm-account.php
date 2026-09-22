<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json; charset=utf-8');
AuthMiddleware::startSession();
AuthMiddleware::requireAuth(true);

try {
    $db = Database::getInstance();
    $stmt = $db->prepare(
        "SELECT id, username, full_name, avatar_color_gradient
         FROM users
         WHERE username = 'ecollab_ai'
           AND is_system = 1
           AND deleted_at IS NULL
         LIMIT 1"
    );
    $stmt->execute();
    $ai = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ai) {
        http_response_code(404);
        echo json_encode(['error' => 'eCollab AI account not found']);
        exit;
    }

    $ai['full_name'] = 'Jarred';
    echo json_encode(['success' => true, 'ai' => $ai]);
} catch (Throwable $e) {
    error_log('[ai/dm-account] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load eCollab AI account']);
}
