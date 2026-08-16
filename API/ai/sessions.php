<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/security/SecurityHeaders.php';
require_once dirname(__DIR__, 2) . '/security/rate-limit/RateLimiter.php';
require_once dirname(__DIR__, 2) . '/services/AiSessionService.php';

header('Content-Type: application/json; charset=utf-8');
SecurityHeaders::send(isApi: true);
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

function aiJson(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function aiBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $body = json_decode($raw, true);
    return is_array($body) ? $body : [];
}

function aiRequireCsrf(): void
{
    AuthMiddleware::verifyCsrf();
}

function aiSessionId(array $body): int
{
    $id = filter_var($body['session_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        aiJson(['success' => false, 'error' => 'A valid session_id is required.'], 400);
    }
    return (int)$id;
}

try {
    $service = new AiSessionService();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        aiJson([
            'success' => true,
            'sessions' => $service->listSessions((int)$user['id']),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        aiRequireCsrf();
        $body = aiBody();

        $classId = null;
        if (isset($body['class_id']) && $body['class_id'] !== '') {
            $classId = filter_var($body['class_id'], FILTER_VALIDATE_INT);
            if ($classId === false || $classId < 1) {
                aiJson(['success' => false, 'error' => 'Invalid class_id.'], 400);
            }
        }

        $session = $service->createSession(
            (int)$user['id'],
            $classId,
            isset($body['title']) ? (string)$body['title'] : null
        );

        aiJson(['success' => true, 'session' => $session], 201);
    }

    aiJson(['success' => false, 'error' => 'Method not allowed.'], 405);
} catch (Throwable $e) {
    error_log('[ai/sessions] ' . $e->getMessage());
    $status = $e->getCode();
    if ($status < 400 || $status > 599) $status = 500;
    aiJson(['success' => false, 'error' => APP_DEBUG ? $e->getMessage() : 'Server error.'], $status);
}
