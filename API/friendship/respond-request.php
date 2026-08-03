<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$reqId  = (int)($body['request_id'] ?? 0);
$action = trim($body['action'] ?? ''); // 'accept' or 'decline'

if (!$reqId || !in_array($action, ['accept', 'decline'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'request_id and action (accept|decline) required']);
    exit;
}

try {
    $db = Database::getInstance();

    // Only the addressee can respond
    $stmt = $db->prepare("SELECT * FROM friendships WHERE id = :id AND addressee_id = :me AND status = 'pending' LIMIT 1");
    $stmt->execute([':id' => $reqId, ':me' => $me['id']]);
    $req = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        http_response_code(404);
        echo json_encode(['error' => 'Request not found or already handled']);
        exit;
    }

    if ($action === 'accept') {
        $upd = $db->prepare("UPDATE friendships SET status = 'accepted' WHERE id = :id");
        $upd->execute([':id' => $reqId]);
        echo json_encode(['success' => true, 'status' => 'accepted', 'message' => 'Connection accepted']);
    } else {
        $del = $db->prepare("DELETE FROM friendships WHERE id = :id");
        $del->execute([':id' => $reqId]);
        echo json_encode(['success' => true, 'status' => 'declined', 'message' => 'Connection declined']);
    }

} catch (Throwable $e) {
    error_log('[respond-request] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
