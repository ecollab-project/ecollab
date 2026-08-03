<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/csrf/csrf.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH . '/security/middleware/RoleMiddleware.php';
require_once ROOT_PATH . '/services/UserService.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
RoleMiddleware::requireRole(['student','facilitator','admin','super_admin','moderator'], true);

try {
    $action  = $_GET['action'] ?? 'all';
    $service = new UserService();

    switch ($action) {
        case 'notifications':
            $db    = Database::getInstance();
            $stmt  = $db->prepare("
                SELECT id, title, message, is_read, icon,
                       CASE WHEN created_at >= DATE_SUB(NOW(),INTERVAL 1 HOUR)
                            THEN CONCAT(TIMESTAMPDIFF(MINUTE,created_at,NOW()),'m ago')
                            WHEN created_at >= DATE_SUB(NOW(),INTERVAL 24 HOUR)
                            THEN CONCAT(TIMESTAMPDIFF(HOUR,created_at,NOW()),'h ago')
                            ELSE CONCAT(TIMESTAMPDIFF(DAY,created_at,NOW()),'d ago')
                       END AS time_ago
                FROM notifications
                WHERE user_id = :uid
                ORDER BY created_at DESC LIMIT 10
            ");
            $stmt->execute([':uid' => $user['id']]);
            echo json_encode(['success' => true, 'notifications' => $stmt->fetchAll()]);
            break;

        case 'mark_notif_read':
            CSRF::verify();
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $id   = (int)($body['id'] ?? 0);
            if ($id) {
                $db = Database::getInstance();
                $db->prepare("UPDATE notifications SET is_read=1 WHERE id=:id AND user_id=:uid")
                   ->execute([':id' => $id, ':uid' => $user['id']]);
                $db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=:uid")
                   ->execute([':uid' => $user['id']]);
            }
            echo json_encode(['success' => true]);
            break;

        case 'mark_all_read':
            CSRF::verify();
            $db = Database::getInstance();
            $db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=:uid")
               ->execute([':uid' => $user['id']]);
            echo json_encode(['success' => true]);
            break;

        case 'join_server':
            CSRF::verify();
            $body     = json_decode(file_get_contents('php://input'), true) ?? [];
            $serverId = (int)($body['server_id'] ?? 0);
            if (!$serverId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'server_id required']);
                break;
            }
            $db   = Database::getInstance();
            $ins  = $db->prepare("INSERT IGNORE INTO server_members (server_id,user_id,server_role,joined_at) VALUES (:sid,:uid,'member',NOW())");
            $ins->execute([':sid' => $serverId, ':uid' => $user['id']]);
            // bump member count
            $db->prepare("UPDATE servers SET member_count = (SELECT COUNT(*) FROM server_members WHERE server_id=:sid) WHERE id=:sid2")
               ->execute([':sid' => $serverId, ':sid2' => $serverId]);
            echo json_encode(['success' => true]);
            break;

        case 'save_note':
            CSRF::verify();
            $body    = json_decode(file_get_contents('php://input'), true) ?? [];
            $title   = trim($body['title']   ?? '');
            $content = trim($body['content'] ?? '');
            if ($title === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Title required']);
                break;
            }
            $db  = Database::getInstance();
            $ins = $db->prepare("INSERT INTO student_notes (user_id,title,content,created_at,updated_at) VALUES (:uid,:t,:c,NOW(),NOW())");
            $ins->execute([':uid' => $user['id'], ':t' => $title, ':c' => $content]);
            echo json_encode(['success' => true, 'note_id' => (int)$db->lastInsertId()]);
            break;

        case 'update_profile':
            CSRF::verify();
            $body     = json_decode(file_get_contents('php://input'), true) ?? [];
            $fullName = trim(strip_tags($body['full_name'] ?? ''));
            $bio      = trim(strip_tags($body['bio']       ?? ''));
            if ($fullName === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Name required']);
                break;
            }
            $db = Database::getInstance();
            $db->prepare("UPDATE users SET full_name=:n, updated_at=NOW() WHERE id=:uid")
               ->execute([':n' => $fullName, ':uid' => $user['id']]);
            $db->prepare("UPDATE user_profiles SET bio=:b WHERE user_id=:uid")
               ->execute([':b' => $bio, ':uid' => $user['id']]);
            $_SESSION['full_name'] = $fullName;
            echo json_encode(['success' => true]);
            break;

        case 'activity':
            $data = $service->getStudentDashboardData($user['id']);
            echo json_encode([
                'success'       => true,
                'activity_chart'=> $data['activity_chart'],
                'total_sessions'=> $data['total_sessions'],
                'hours_studied' => $data['hours_studied'],
                'study_streak'  => $data['study_streak'],
            ]);
            break;

        default:
            $data = $service->getStudentDashboardData($user['id']);
            echo json_encode(['success' => true, 'data' => $data]);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => APP_DEBUG ? $e->getMessage() : 'Server error']);
}
