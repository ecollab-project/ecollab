<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/security/AuditLogger.php';
require_once dirname(__DIR__, 2) . '/security/SecurityHeaders.php';
require_once dirname(__DIR__, 2) . '/security/rate-limit/RateLimiter.php';
require_once dirname(__DIR__, 2) . '/services/GeminiService.php';
require_once dirname(__DIR__, 2) . '/services/UserPreferences.php';
require_once dirname(__DIR__, 2) . '/services/CodeLabIntegrityPolicy.php';

header('Content-Type: application/json');
SecurityHeaders::send(isApi: true);
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;
}
AuthMiddleware::verifyCsrf();

$body = json_decode(file_get_contents('php://input'), true) ?? [];
if (CodeLabIntegrityPolicy::isCodeLab($body,(string)($_SERVER['HTTP_REFERER']??''))) {http_response_code(403);echo json_encode(['error'=>CodeLabIntegrityPolicy::AI_MESSAGE]);exit;}

try {
    $prompt = trim((string)($body['prompt'] ?? ''));
    $context = trim((string)($body['context'] ?? ''));

    if ($prompt === '') {
        http_response_code(400); echo json_encode(['error' => 'Prompt is required']); exit;
    }
    if (mb_strlen($prompt) > 500) {
        http_response_code(400); echo json_encode(['error' => 'Prompt must be 500 characters or fewer.']); exit;
    }
    if (mb_strlen($context) > 2000) {
        http_response_code(400); echo json_encode(['error' => 'Context must be 2000 characters or fewer.']); exit;
    }

    $prefs = UserPreferences::get(Database::getInstance(), (int)$user['id']);
    UserPreferences::assertJarred($prefs);

    $apiKey = (string) env('GEMINI_API_KEY', '');
    if (!$apiKey || $apiKey === 'your_gemini_api_key_here') {
        http_response_code(503);
        echo json_encode(['error' => 'AI assist is not configured. Add your GEMINI_API_KEY to .env']);
        exit;
    }

    $limiter = new \RateLimiter();
    $rlResult = $limiter->attempt('ai_assist', (string)$user['id'], 20, 3600);
    if (!$rlResult['allowed']) {
        http_response_code(429);
        echo json_encode(['error' => 'AI assist limit reached. Please wait before trying again.', 'retry_after' => $rlResult['retry_after']]);
        exit;
    }

    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $systemPrompt = 'You are a helpful study assistant in Ecollab, a collaborative learning platform. Keep responses concise, friendly, and relevant to academic/study contexts. If given chat context, use it to give a relevant reply suggestion. Return only the suggested message text — no explanation, no quotes.';
    $userContent = $context !== '' ? "Recent chat context:\n{$context}\n\nUser request: {$prompt}" : $prompt;

    $service = new GeminiService($apiKey, (string) env('GEMINI_MODEL', 'gemini-flash-latest'));
    $result = $service->generate([['role' => 'user', 'content' => $userContent]], $systemPrompt, 300);

    echo json_encode(['success' => true, 'suggestion' => trim($result['text'])]);
} catch (Throwable $e) {
    error_log('[ai-assist] request failed');
    $status = in_array($e->getCode(), [403, 503], true) ? $e->getCode() : 500;
    http_response_code($status);
    echo json_encode(['error' => $status === 403 ? 'Jarred is disabled in your settings.' : 'AI service unavailable']);
}
