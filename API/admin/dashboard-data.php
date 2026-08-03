<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once ROOT_PATH . '/database/config/db.php';
require_once ROOT_PATH . '/security/csrf/csrf.php';
require_once ROOT_PATH . '/security/middleware/AuthMiddleware.php';
require_once ROOT_PATH . '/security/middleware/RoleMiddleware.php';
require_once ROOT_PATH . '/services/UserService.php';
require_once ROOT_PATH . '/security/AuditLogger.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
RoleMiddleware::requireRole(['admin','super_admin','moderator'], true);

try {
    $action  = $_GET['action'] ?? 'stats';
    $service = new UserService();

    switch ($action) {
        case 'stats':
            $data = $service->getAdminDashboardData($user['id']);
            echo json_encode(['success' => true, 'stats' => $data['stats']]);
            break;

        case 'create_user':
            CSRF::verify();
            $body     = json_decode(file_get_contents('php://input'), true) ?? [];
            $username = trim($body['username']  ?? '');
            $email    = strtolower(trim($body['email'] ?? ''));
            $fullName = trim($body['full_name']  ?? '');
            $role     = $body['role'] ?? 'student';
            $password = $body['password'] ?? '';

            if ($username === '' || $email === '' || strlen($password) < 8) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Username, email, and password (8+ chars) required']);
                break;
            }
            if (!in_array($role, ['student','facilitator','moderator','admin'], true)) {
                $role = 'student';
            }
            // Same authority ceiling as 'change_role': moderators cannot
            // create admin accounts.
            $actorRole = $user['role'] ?? 'student';
            if ($actorRole === 'moderator' && $role === 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Moderators cannot create admin accounts.']);
                break;
            }

            $db  = Database::getInstance();
            $dup = $db->prepare("SELECT id FROM users WHERE email=:e OR username=:u LIMIT 1");
            $dup->execute([':e' => $email, ':u' => $username]);
            if ($dup->fetch()) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'Email or username already exists']);
                break;
            }

            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
            $ins  = $db->prepare("
                INSERT INTO users (username,email,full_name,password_hash,role,status,created_at,updated_at)
                VALUES (:u,:e,:n,:h,:r,'active',NOW(),NOW())
            ");
            $ins->execute([':u' => $username, ':e' => $email, ':n' => $fullName ?: $username, ':h' => $hash, ':r' => $role]);
            $newId = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO user_profiles (user_id) VALUES (:uid)")->execute([':uid' => $newId]);

            echo json_encode(['success' => true, 'user_id' => $newId]);
            break;

        case 'change_role':
            CSRF::verify();
            $body    = json_decode(file_get_contents('php://input'), true) ?? [];
            $uid     = (int)($body['user_id'] ?? 0);
            $newRole = $body['role'] ?? '';
            if (!$uid || !in_array($newRole, ['student','facilitator','moderator','admin'], true)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'user_id and valid role required']);
                break;
            }

            $actorRole = $user['role'] ?? 'student';
            $db = Database::getInstance();

            // Authority ceiling: a moderator may only grant roles up to and
            // including 'moderator'. Only admin/super_admin may grant 'admin'.
            // (super_admin is never assignable via this endpoint at all.)
            $moderatorCeiling = ['student','facilitator','moderator'];
            if ($actorRole === 'moderator' && !in_array($newRole, $moderatorCeiling, true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Moderators cannot grant the admin role.']);
                break;
            }

            // Prevent changing your own role through this endpoint
            // (avoids accidental/malicious self-demotion or self-escalation).
            if ($uid === (int)$user['id']) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'You cannot change your own role.']);
                break;
            }

            // Fetch the target's CURRENT role so a moderator can't act on
            // peers/superiors (admin, super_admin, or other moderators).
            $targetStmt = $db->prepare("SELECT role FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1");
            $targetStmt->execute([':id' => $uid]);
            $targetRole = $targetStmt->fetchColumn();
            if ($targetRole === false) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'User not found']);
                break;
            }
            if ($targetRole === 'super_admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'super_admin role cannot be changed here.']);
                break;
            }
            // A moderator may only act on accounts strictly below the
            // moderator level (student/facilitator) — not other
            // moderators or admins.
            if ($actorRole === 'moderator' && !in_array($targetRole, ['student','facilitator'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Moderators cannot change the role of another moderator or admin.']);
                break;
            }

            $db->prepare("UPDATE users SET role=:r,updated_at=NOW() WHERE id=:id")
               ->execute([':r' => $newRole, ':id' => $uid]);

            AuditLogger::log(AuditLogger::ROLE_CHANGE, [
                'actor_id'    => $user['id'],
                'actor_role'  => $actorRole,
                'target_id'   => $uid,
                'old_role'    => $targetRole,
                'new_role'    => $newRole,
            ], 'success', AuditLogger::RISK_MEDIUM);

            echo json_encode(['success' => true]);
            break;

        case 'ban_user':
            CSRF::verify();
            $body     = json_decode(file_get_contents('php://input'), true) ?? [];
            $uid      = (int)($body['user_id'] ?? 0);
            $reason   = strip_tags(trim($body['reason']   ?? 'Violation of terms'));
            $duration = $body['duration'] ?? 'permanent';
            if (!$uid) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'user_id required']);
                break;
            }
            $db = Database::getInstance();
            // Prevent banning self or other super_admins (WHERE clause guards this)
            $db->prepare("UPDATE users SET status='banned',updated_at=NOW() WHERE id=:id AND role != 'super_admin'")
               ->execute([':id' => $uid]);
            // Log action — 'action' is a short machine-readable key, human-readable
            // detail goes in 'description'. 'level' must match the activity_logs
            // ENUM ('info','warning','error','critical') — 'warn' is invalid.
            $db->prepare("
                INSERT INTO activity_logs (user_id,action,entity_type,entity_id,description,level,created_at)
                VALUES (:uid,'user.banned','user',:target,:reason,'warning',NOW())
            ")->execute([':uid' => $user['id'], ':target' => $uid, ':reason' => $reason]);
            echo json_encode(['success' => true]);
            break;

        case 'kick_user':
            CSRF::verify();
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $uid  = (int)($body['user_id']   ?? 0);
            $sid  = (int)($body['server_id'] ?? 0);
            if (!$uid) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'user_id required']);
                break;
            }
            $db = Database::getInstance();
            if ($sid) {
                $db->prepare("DELETE FROM server_members WHERE server_id=:sid AND user_id=:uid")
                   ->execute([':sid' => $sid, ':uid' => $uid]);
            } else {
                $db->prepare("DELETE FROM server_members WHERE user_id=:uid")->execute([':uid' => $uid]);
            }
            echo json_encode(['success' => true]);
            break;

        case 'get_reports':
            $db = Database::getInstance();
            $status = $_GET['status'] ?? 'pending';
            $validStatuses = ['pending','reviewing','resolved','dismissed','all'];
            if (!in_array($status, $validStatuses, true)) $status = 'pending';
            // Bound parameter throughout — no string interpolation of user
            // input into SQL, even though $status was already whitelisted
            // above (defense in depth / consistent pattern).
            $stmt = $db->prepare("
                SELECT cr.id, cr.reason, cr.description, cr.status, cr.created_at,
                       cr.message_id,
                       m.content AS message_content,
                       reporter.username AS reporter_username,
                       reported.username AS reported_username,
                       s.name AS server_name
                FROM content_reports cr
                LEFT JOIN messages m ON m.id = cr.message_id
                LEFT JOIN users reporter ON reporter.id = cr.reporter_id
                LEFT JOIN users reported ON reported.id = cr.reported_user_id
                LEFT JOIN servers s ON s.id = cr.server_id
                WHERE (:status = 'all' OR cr.status = :status2)
                ORDER BY cr.created_at DESC
                LIMIT 100
            ");
            $stmt->execute([':status' => $status, ':status2' => $status]);
            $reports = $stmt->fetchAll();
            // Count pending
            $pendingCount = (int)$db->query("SELECT COUNT(*) FROM content_reports WHERE status='pending'")->fetchColumn();
            echo json_encode(['success' => true, 'reports' => $reports, 'pending_count' => $pendingCount]);
            break;

        case 'resolve_report':
            CSRF::verify();
            $body       = json_decode(file_get_contents('php://input'), true) ?? [];
            $reportId   = (int)($body['report_id']   ?? 0);
            $resolution = $body['resolution'] ?? 'dismissed'; // 'resolved' or 'dismissed'
            $note       = trim($body['note'] ?? '');
            if (!$reportId) { http_response_code(400); echo json_encode(['error' => 'report_id required']); break; }
            $db = Database::getInstance();
            // If resolved (action taken), delete the reported message
            $rptStmt = $db->prepare("SELECT message_id FROM content_reports WHERE id = :id");
            $rptStmt->execute([':id' => $reportId]);
            $rptRow = $rptStmt->fetch();
            if ($resolution === 'resolved' && !empty($rptRow['message_id'])) {
                $db->prepare("UPDATE messages SET is_deleted=1,deleted_at=NOW() WHERE id=:id")
                   ->execute([':id' => $rptRow['message_id']]);
            }
            $db->prepare("UPDATE content_reports SET status=:status, resolved_by=:uid, resolved_at=NOW(), resolution_note=:note WHERE id=:id")
               ->execute([':status' => $resolution, ':uid' => $user['id'], ':note' => $note ?: null, ':id' => $reportId]);
            echo json_encode(['success' => true]);
            break;

        case 'send_announcement':
            CSRF::verify();
            $body    = json_decode(file_get_contents('php://input'), true) ?? [];
            $title   = trim(strip_tags($body['title']   ?? ''));
            $message = trim(strip_tags($body['message'] ?? ''));
            $serverId = (int)($body['server_id'] ?? 0);
            if ($title === '' || $message === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'title and message required']);
                break;
            }
            // Broadcast to all announcement channels
            $db   = Database::getInstance();
            $chns = $db->prepare("SELECT id FROM channels WHERE type='announcement'" . ($serverId ? " AND server_id=:sid" : ""));
            $params = $serverId ? [':sid' => $serverId] : [];
            $chns->execute($params);
            $channels = $chns->fetchAll(\PDO::FETCH_COLUMN);
            $ins = $db->prepare("INSERT INTO messages (channel_id,sender_id,content,content_type,is_pinned,created_at,updated_at) VALUES (:cid,:uid,:c,'text',1,NOW(),NOW())");
            foreach ($channels as $cid) {
                $ins->execute([':cid' => $cid, ':uid' => $user['id'], ':c' => "[**{$title}**] {$message}"]);
            }
            echo json_encode(['success' => true, 'channels_reached' => count($channels)]);
            break;

        case 'users':
            $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
            $page  = max(1, (int)($_GET['page'] ?? 1));
            $q     = trim($_GET['q'] ?? '');
            $db    = Database::getInstance();
            $where = $q ? "AND (u.username LIKE :q OR u.full_name LIKE :q2 OR u.email LIKE :q3)" : '';
            $stmt  = $db->prepare("
                SELECT u.id,u.username,u.full_name,u.email,u.avatar_color_gradient,
                       u.role,u.status,
                       COALESCE(ap.name,'N/A') AS course_name,
                       DATE_FORMAT(u.created_at,'%b %d, %Y') AS joined_label
                FROM users u
                LEFT JOIN user_profiles up ON up.user_id = u.id
                LEFT JOIN academic_programs ap ON ap.id = up.academic_program_id
                WHERE u.deleted_at IS NULL {$where}
                ORDER BY u.created_at DESC
                LIMIT :lim OFFSET :off
            ");
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':off', ($page - 1) * $limit, \PDO::PARAM_INT);
            if ($q) {
                $stmt->bindValue(':q',  "%{$q}%");
                $stmt->bindValue(':q2', "%{$q}%");
                $stmt->bindValue(':q3', "%{$q}%");
            }
            $stmt->execute();
            echo json_encode(['success' => true, 'users' => $stmt->fetchAll()]);
            break;

        case 'export':
            // Simple CSV export for users
            $type = $_GET['type'] ?? 'users';
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="ecollab-' . $type . '-' . date('Ymd') . '.csv"');
            $db   = Database::getInstance();
            $rows = $db->query("SELECT u.id,u.username,u.full_name,u.email,u.role,u.status,u.created_at FROM users u WHERE u.deleted_at IS NULL ORDER BY u.created_at DESC LIMIT 5000")->fetchAll();
            $out  = fopen('php://output', 'w');
            fputcsv($out, ['ID','Username','Full Name','Email','Role','Status','Joined']);
            foreach ($rows as $row) fputcsv($out, $row);
            fclose($out);
            exit;

        default:
            $data = $service->getAdminDashboardData($user['id']);
            echo json_encode(['success' => true, 'data' => $data]);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => APP_DEBUG ? $e->getMessage() : 'Server error']);
}
