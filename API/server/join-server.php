<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }
AuthMiddleware::verifyCsrf();
try {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $raw = trim((string)($body['invite_code'] ?? ''));
    if ($raw === '') { http_response_code(400); echo json_encode(['error'=>'Invite code required']); exit; }

    // If a full URL was pasted, pull the token out of its query string
    // (not the path — invite tokens live in ?invite=..., not in the URL path).
    if (filter_var($raw, FILTER_VALIDATE_URL)) {
        $parts = parse_url($raw);
        parse_str((string)($parts['query'] ?? ''), $query);
        $raw = (string)($query['invite'] ?? $raw);
    }

    $db = Database::getInstance();

    // Primary path: treat input as a real invite token from server_invites.
    $hash = hash('sha256', $raw);
    $db->beginTransaction();
    $stmt = $db->prepare("
        SELECT si.*, s.name, s.type
        FROM server_invites si
        JOIN servers s ON s.id = si.server_id
        WHERE si.token_hash = :hash
          AND si.revoked_at IS NULL
          AND (si.expires_at IS NULL OR si.expires_at > NOW())
          AND (si.max_uses = 0 OR si.use_count < si.max_uses)
        LIMIT 1 FOR UPDATE
    ");
    $stmt->execute([':hash' => $hash]);
    $invite = $stmt->fetch();

    if ($invite) {
        $chk = $db->prepare("SELECT id FROM server_members WHERE server_id=:sid AND user_id=:uid");
        $chk->execute([':sid' => $invite['server_id'], ':uid' => $user['id']]);
        $already = (bool)$chk->fetch();
        if (!$already) {
            $db->prepare("INSERT INTO server_members (server_id,user_id,server_role,joined_at) VALUES (:sid,:uid,'member',NOW())")
                ->execute([':sid' => $invite['server_id'], ':uid' => $user['id']]);
            $db->prepare("UPDATE servers SET member_count=(SELECT COUNT(*) FROM server_members WHERE server_id=:sid) WHERE id=:sid2")
                ->execute([':sid' => $invite['server_id'], ':sid2' => $invite['server_id']]);
        }
        $db->prepare("UPDATE server_invites SET use_count=use_count+1 WHERE id=:id")->execute([':id' => $invite['id']]);
        $db->commit();
        echo json_encode(['success' => true, 'server_id' => (int)$invite['server_id'], 'name' => $invite['name'], 'already_member' => $already]);
        exit;
    }
    $db->rollBack();

    // Fallback: treat input as a public server's vanity slug (not a token).
    $srv = $db->prepare("SELECT id, name, type FROM servers WHERE slug = :slug AND status = 'active' LIMIT 1");
    $srv->execute([':slug' => $raw]);
    $server = $srv->fetch();
    if (!$server || $server['type'] !== 'public') {
        http_response_code(404);
        echo json_encode(['error' => 'Invalid or expired invite link']);
        exit;
    }
    $chk = $db->prepare("SELECT id FROM server_members WHERE server_id=:sid AND user_id=:uid");
    $chk->execute([':sid'=>$server['id'],':uid'=>$user['id']]);
    if ($chk->fetch()) { echo json_encode(['success'=>true,'already_member'=>true,'name'=>$server['name']]); exit; }
    $db->prepare("INSERT INTO server_members (server_id,user_id,server_role,joined_at) VALUES (:sid,:uid,'member',NOW())")->execute([':sid'=>$server['id'],':uid'=>$user['id']]);
    echo json_encode(['success'=>true,'server_id'=>$server['id'],'name'=>$server['name']]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
    error_log('[join-server] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error'=> defined('APP_DEBUG')&&APP_DEBUG ? $e->getMessage() : 'Server error']);
}
