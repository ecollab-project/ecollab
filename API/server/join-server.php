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
    $code = trim($body['invite_code'] ?? '');
    if ($code === '') { http_response_code(400); echo json_encode(['error'=>'Invite code required']); exit; }
    // Extract slug from invite URL or treat as slug directly
    $slug = basename(parse_url($code, PHP_URL_PATH) ?: $code);
    $db = Database::getInstance();
    $srv = $db->prepare("SELECT id, name FROM servers WHERE slug = :slug AND status = 'active' LIMIT 1");
    $srv->execute([':slug'=>$slug]);
    $server = $srv->fetch();
    if (!$server) { http_response_code(404); echo json_encode(['error'=>'Invalid invite link']); exit; }
    // Check already member
    $chk = $db->prepare("SELECT id FROM server_members WHERE server_id=:sid AND user_id=:uid");
    $chk->execute([':sid'=>$server['id'],':uid'=>$user['id']]);
    if ($chk->fetch()) { echo json_encode(['success'=>true,'already_member'=>true,'name'=>$server['name']]); exit; }
    $db->prepare("INSERT INTO server_members (server_id,user_id,server_role,joined_at) VALUES (:sid,:uid,'member',NOW())")->execute([':sid'=>$server['id'],':uid'=>$user['id']]);
    echo json_encode(['success'=>true,'server_id'=>$server['id'],'name'=>$server['name']]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=> defined('APP_DEBUG')&&APP_DEBUG ? $e->getMessage() : 'Server error']);
}
