<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/database/config/db.php';
require_once dirname(__DIR__,2).'/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__,2).'/security/rate-limit/RateLimiter.php';
require_once dirname(__DIR__,2).'/services/BookRecommendationEvents.php';
header('Content-Type: application/json; charset=utf-8');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
AuthMiddleware::verifyCsrf();
try {
    $db = Database::getInstance();
    $uid = (int)$user['id'];
    if (!BookRecommendationEvents::allowed($db, $uid)) {
        http_response_code(403); echo json_encode(['success'=>false]); exit;
    }
    $limit = (new RateLimiter())->attempt('book_recommendation_event', (string)$uid, 120, 3600);
    if (!$limit['allowed']) { http_response_code(429); exit; }
    $raw = file_get_contents('php://input', false, null, 0, 2049);
    if (strlen($raw) > 2048) throw new InvalidArgumentException('Payload too large');
    $body = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($body) || !is_string($body['work_key'] ?? null) || !is_string($body['event_type'] ?? null)) throw new InvalidArgumentException('Invalid event');
    BookRecommendationEvents::record($db, $uid, $body['work_key'], $body['event_type']);
    echo json_encode(['success'=>true]);
} catch (InvalidArgumentException|JsonException $e) {
    http_response_code(400); echo json_encode(['success'=>false,'error'=>'Invalid or expired book event']);
} catch (Throwable $e) {
    http_response_code(503); echo json_encode(['success'=>false,'error'=>'Feedback temporarily unavailable']);
}
