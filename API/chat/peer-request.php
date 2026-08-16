<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/PeerMatchingService.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    AuthMiddleware::verifyCsrf();

    $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $peerId = filter_var($body['user_id'] ?? 0, FILTER_VALIDATE_INT);

    if (!$peerId || (int)$peerId === (int)$user['id']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A valid study buddy is required.']);
        exit;
    }

    $db = Database::getInstance();

    $check = $db->prepare("SELECT id FROM users WHERE id = ? AND deleted_at IS NULL AND status != 'banned' LIMIT 1");
    $check->execute([(int)$peerId]);
    if (!$check->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Study buddy not found.']);
        exit;
    }

    $blocked = $db->prepare("SELECT id FROM friendships WHERE (requester_id = ? AND addressee_id = ?) OR (requester_id = ? AND addressee_id = ?) LIMIT 1");
    $blocked->execute([(int)$user['id'], (int)$peerId, (int)$peerId, (int)$user['id']]);
    $friendship = $blocked->fetch(PDO::FETCH_ASSOC);

    if ($friendship) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'You already have a connection with this user.']);
        exit;
    }

    $scoreStmt = $db->prepare("SELECT score_total FROM pm_compatibility WHERE user_a_id = ? AND user_b_id = ? LIMIT 1");
    $a = min((int)$user['id'], (int)$peerId);
    $b = max((int)$user['id'], (int)$peerId);
    $scoreStmt->execute([$a, $b]);
    $score = (float)($scoreStmt->fetchColumn() ?: 0);

    $insert = $db->prepare("INSERT INTO pm_match_requests (requester_id, addressee_id, score, status) VALUES (?, ?, ?, 'pending') ON DUPLICATE KEY UPDATE score = VALUES(score), status = IF(status = 'declined', 'pending', status), note = VALUES(note)");
    $insert->execute([(int)$user['id'], (int)$peerId, $score, $body['note'] ?? null]);

    echo json_encode(['success' => true, 'status' => 'pending']);
} catch (PDOException $e) {
    error_log('[Ecollab] peer request DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to send connection request.']);
} catch (Throwable $e) {
    error_log('[Ecollab] peer request error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to send connection request.']);
}
