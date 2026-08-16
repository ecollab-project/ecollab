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
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            aiJson(['success' => false, 'error' => 'A valid session id is required.'], 400);
        }

        aiJson([
            'success' => true,
            'session' => $service->getSession((int)$user['id'], (int)$id),
            'messages' => $service->getMessages((int)$user['id'], (int)$id),
        ]);
    }

    $body = aiBody();
    $id = aiSessionId($body);

    if ($method === 'PATCH') {
        aiRequireCsrf();
        $title = trim((string)($body['title'] ?? ''));
        if ($title === '') {
            aiJson(['success' => false, 'error' => 'Title is required.'], 400);
        }

        aiJson([
            'success' => true,
            'session' => $service->renameSession((int)$user['id'], $id, $title),
        ]);
    }

    if ($method === 'DELETE') {
        aiRequireCsrf();
        $service->deleteSession((int)$user['id'], $id);
        aiJson(['success' => true]);
    }

    aiJson(['success' => false, 'error' => 'Method not allowed.'], 405);
} catch (Throwable $e) {
    error_log('[ai/session] ' . $e->getMessage());
    $status = $e->getCode();
    if ($status < 400 || $status > 599) $status = 500;
    aiJson(['success' => false, 'error' => APP_DEBUG ? $e->getMessage() : 'Server error.'], $status);
}
