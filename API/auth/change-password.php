<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
header('Content-Type: application/json; charset=utf-8');
AuthMiddleware::startSession();
$user=AuthMiddleware::requireAuth(true);
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }
AuthMiddleware::verifyCsrf();
try {
  $body=json_decode(file_get_contents('php://input'),true) ?? [];
  $current=(string)($body['current_password']??'');
  $next=(string)($body['new_password']??'');
  if ($current==='' || $next==='') { http_response_code(400); echo json_encode(['error'=>'Current and new password are required']); exit; }
  if (strlen($next)<8) { http_response_code(400); echo json_encode(['error'=>'New password must be at least 8 characters']); exit; }
  if ($current===$next) { http_response_code(400); echo json_encode(['error'=>'New password must be different from the current password']); exit; }
  $db=Database::getInstance();
  $stmt=$db->prepare('SELECT password_hash FROM users WHERE id=:id AND deleted_at IS NULL LIMIT 1');
  $stmt->execute([':id'=>(int)$user['id']]);
  $hash=$stmt->fetchColumn();
  if (!$hash || !password_verify($current,(string)$hash)) { http_response_code(400); echo json_encode(['error'=>'Current password is incorrect']); exit; }
  $newHash=password_hash($next,PASSWORD_BCRYPT,['cost'=>defined('BCRYPT_COST')?BCRYPT_COST:12]);
  $db->prepare('UPDATE users SET password_hash=:h, updated_at=CURRENT_TIMESTAMP WHERE id=:id LIMIT 1')->execute([':h'=>$newHash,':id'=>(int)$user['id']]);
  echo json_encode(['success'=>true,'message'=>'Password changed successfully']);
} catch(Throwable $e) { error_log('[change-password] '.$e->getMessage()); http_response_code(500); echo json_encode(['error'=>'Server error']); }
