<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/security/AuditLogger.php';
require_once dirname(__DIR__, 2) . '/security/SecurityHeaders.php';
require_once dirname(__DIR__, 2) . '/security/rate-limit/RateLimiter.php';

header('Content-Type: application/json');
SecurityHeaders::send(isApi: true);
AuthMiddleware::startSession();
AuthMiddleware::requireAuth(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;
}
AuthMiddleware::verifyCsrf();

$apiKey = env('ANTHROPIC_API_KEY', '');
if (!$apiKey || $apiKey === 'your_anthropic_api_key_here') {
    http_response_code(503);
    echo json_encode(['error' => 'AI assist is not configured. Add your ANTHROPIC_API_KEY to .env']);
    exit;
}

try {
    $body    = json_decode(file_get_contents('php://input'), true) ?? [];
    $prompt  = trim($body['prompt'] ?? '');
    $context = trim($body['context'] ?? ''); // recent messages for context

    if ($prompt === '') {
        http_response_code(400); echo json_encode(['error' => 'Prompt is required']); exit;
    }

    // ── Length guards (prevent cost abuse and prompt injection) ──────────────
    if (mb_strlen($prompt) > 500) {
        http_response_code(400);
        echo json_encode(['error' => 'Prompt must be 500 characters or fewer.']);
        exit;
    }
    if (mb_strlen($context) > 2000) {
        http_response_code(400);
        echo json_encode(['error' => 'Context must be 2000 characters or fewer.']);
        exit;
    }

    // ── Per-user rate limit (20 requests / hour) ─────────────────────────────
    $limiter = new \RateLimiter();
    $rlResult = $limiter->attempt('ai_assist', (string)$_SESSION['user_id'], 20, 3600);
    if (!$rlResult['allowed']) {
        http_response_code(429);
        echo json_encode([
            'error'       => 'AI assist limit reached. Please wait before trying again.',
            'retry_after' => $rlResult['retry_after'],
        ]);
        exit;
    }

    $systemPrompt = "You are a helpful study assistant in Ecollab, a collaborative learning platform. " .
        "Keep responses concise, friendly, and relevant to academic/study contexts. " .
        "If given chat context, use it to give a relevant reply suggestion. " .
        "Return only the suggested message text — no explanation, no quotes.";

    // Build the user message — always include the prompt; prepend context when present
    if ($context !== '') {
        $userContent = "Recent chat context:\n{$context}\n\nUser request: {$prompt}";
    } else {
        $userContent = $prompt;
    }
    $messages = [['role' => 'user', 'content' => $userContent]];

    $model = env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001');

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model'      => $model,
            'max_tokens' => 300,
            'system'     => $systemPrompt,
            'messages'   => $messages,
        ]),
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $errData = json_decode($response, true);
        http_response_code(502);
        echo json_encode(['error' => $errData['error']['message'] ?? 'AI API error']);
        exit;
    }

    $data = json_decode($response, true);
    $text = $data['content'][0]['text'] ?? '';

    echo json_encode(['success' => true, 'suggestion' => trim($text)]);

} catch (Throwable $e) {
    error_log('[ai-assist] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Server error']);
}
