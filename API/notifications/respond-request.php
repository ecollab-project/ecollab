<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json; charset=utf-8');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    AuthMiddleware::verifyCsrf();
    $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $requestId = (int)($body['request_id'] ?? 0);
    $action = strtolower(trim((string)($body['action'] ?? '')));
    if ($requestId < 1 || !in_array($action, ['accept', 'reject', 'decline'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit;
    }
    $action = $action === 'decline' ? 'reject' : $action;
    $db = Database::getInstance();
    $db->beginTransaction();

    $stmt = $db->prepare('SELECT id, requester_id, addressee_id, status FROM friendships WHERE id = ? AND addressee_id = ? FOR UPDATE');
    $stmt->execute([$requestId, (int)$me['id']]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);
    $table = 'friendships';

    if (!$req) {
        $stmt = $db->prepare('SELECT id, requester_id, addressee_id, status FROM pm_match_requests WHERE id = ? AND addressee_id = ? FOR UPDATE');
        $stmt->execute([$requestId, (int)$me['id']]);
        $req = $stmt->fetch(PDO::FETCH_ASSOC);
        $table = 'pm_match_requests';
    }

    if (!$req || $req['status'] !== 'pending') {
        $db->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Pending request not found']);
        exit;
    }

    $newStatus = $action === 'accept' ? 'accepted' : 'declined';
    $update = $db->prepare("UPDATE {$table} SET status = ? WHERE id = ? AND addressee_id = ? AND status = 'pending'");
    $update->execute([$newStatus, $requestId, (int)$me['id']]);

    if ($action === 'accept') {
        $name = (string)($me['full_name'] ?: $me['username']);
        $n = $db->prepare("INSERT INTO notifications (recipient_id, actor_id, type, title, body, link_url, icon, is_read, created_at, read_at) VALUES (?, ?, 'connection_accepted', 'Connection Accepted', ?, '/modules/chat/chat.php?view=study-partners', '✅', 0, NOW(), NULL)");
        $n->execute([(int)$req['requester_id'], (int)$me['id'], $name . ' accepted your connection request']);
    }

    $db->commit();
    echo json_encode(['success' => true, 'status' => $newStatus, 'request_id' => $requestId]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    error_log('[notifications/respond-request] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to respond to request']);
}
