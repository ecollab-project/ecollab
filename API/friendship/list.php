<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
$uid = (int)$user['id'];
$q = trim((string)($_GET['q'] ?? ''));

try {
    $db = Database::getInstance();

    $sql = "
        SELECT u.id, u.username, u.full_name, u.avatar_color_gradient, u.is_online
        FROM friendships f
        JOIN users u ON u.id = IF(f.requester_id = :uid1, f.addressee_id, f.requester_id)
        WHERE (f.requester_id = :uid2 OR f.addressee_id = :uid3)
          AND f.status = 'accepted'
          AND u.deleted_at IS NULL
    ";
    $params = [':uid1' => $uid, ':uid2' => $uid, ':uid3' => $uid];
    if ($q !== '') {
        $sql .= " AND (u.username LIKE :q ESCAPE '\\\\' OR u.full_name LIKE :q2 ESCAPE '\\\\')";
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
        $params[':q'] = "%$escaped%";
        $params[':q2'] = "%$escaped%";
    }
    $sql .= " ORDER BY u.is_online DESC, u.full_name ASC LIMIT 100";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true, 'friends' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    error_log('[friendship/list] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : 'Server error']);
}
