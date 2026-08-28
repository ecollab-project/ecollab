<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/security/SecurityHeaders.php';
require_once dirname(__DIR__, 2) . '/security/rate-limit/RateLimiter.php';
require_once dirname(__DIR__, 2) . '/security/ApiErrorResponder.php';
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

function aiApproxTokens(string $text): int
{
    return max(1, (int)ceil(mb_strlen($text) / 4));
}

function callAnthropic(string $apiKey, string $model, array $messages, string $systemPrompt): array
{
    $payload = json_encode([
        'model' => $model,
        'max_tokens' => 700,
        'system' => $systemPrompt,
        'messages' => $messages,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        throw new RuntimeException('Unable to encode AI request.');
    }

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize AI request.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('AI request failed: ' . ($curlError ?: 'network error'));
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        throw new RuntimeException('AI service returned invalid JSON.');
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $message = $data['error']['message'] ?? 'AI service error.';
        throw new RuntimeException($message, 502);
    }

    $text = '';
    foreach (($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= (string)($block['text'] ?? '');
        }
    }

    $text = trim($text);
    if ($text === '') {
        throw new RuntimeException('AI service returned an empty response.', 502);
    }

    return [
        'text' => $text,
        'input_tokens' => (int)($data['usage']['input_tokens'] ?? aiApproxTokens(
            implode("\n", array_map(static fn(array $m): string => (string)$m['content'], $messages))
        )),
        'output_tokens' => (int)($data['usage']['output_tokens'] ?? aiApproxTokens($text)),
    ];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        aiJson(['success' => false, 'error' => 'Method not allowed.'], 405);
    }

    aiRequireCsrf();
    $body = aiBody();
    $sessionId = aiSessionId($body);
    $prompt = trim((string)($body['prompt'] ?? ''));

    if ($prompt === '') {
        aiJson(['success' => false, 'error' => 'Prompt is required.'], 400);
    }

    if (mb_strlen($prompt) > 4000) {
        aiJson(['success' => false, 'error' => 'Prompt must be 4000 characters or fewer.'], 400);
    }

    $apiKey = (string)env('ANTHROPIC_API_KEY', '');
    if ($apiKey === '' || $apiKey === 'your_anthropic_api_key_here') {
        aiJson(['success' => false, 'error' => 'AI assist is not configured. Add ANTHROPIC_API_KEY to your local .env.'], 503);
    }

    $limiter = new RateLimiter();
    $rl = $limiter->attempt('ai_assist', (string)$user['id'], 20, 3600);
    if (!$rl['allowed']) {
        aiJson([
            'success' => false,
            'error' => 'AI message limit reached. Please try again later.',
            'retry_after' => $rl['retry_after'],
        ], 429);
    }

    $service = new AiSessionService();
    $session = $service->getSession((int)$user['id'], $sessionId);
    $history = $service->getRecentConversation((int)$user['id'], $sessionId, 20);

    $messages = [];
    foreach ($history as $item) {
        $messages[] = [
            'role' => $item['role'],
            'content' => mb_substr((string)$item['content'], 0, 8000),
        ];
    }
    $messages[] = ['role' => 'user', 'content' => $prompt];

    $role = (string)($user['role'] ?? 'student');
    $systemPrompt = $role === 'facilitator'
        ? 'You are Ecollab AI, an academic assistant for facilitators. Help with class activity analysis, announcements, study materials, quizzes, and teaching workflows. Be accurate, concise, and practical. Do not claim to have access to private platform data unless it is included in the conversation.'
        : 'You are Ecollab AI, a study assistant for students. Help with explanations, study plans, quizzes, programming, and academic concepts. Be accurate, concise, friendly, and educational. When uncertain, say so rather than inventing facts.';

    $model = (string)env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001');
    $result = callAnthropic($apiKey, $model, $messages, $systemPrompt);

    $userTokens = aiApproxTokens($prompt);
    $assistantTokens = (int)$result['output_tokens'];

    $service->appendMessage((int)$user['id'], $sessionId, 'user', $prompt, $userTokens);
    $assistantMessage = $service->appendMessage(
        (int)$user['id'],
        $sessionId,
        'assistant',
        $result['text'],
        $assistantTokens
    );

    if (($session['message_count'] ?? 0) === 0 || $session['session_title'] === 'New AI Conversation') {
        $title = preg_replace('/\s+/', ' ', $prompt) ?? $prompt;
        $title = mb_substr(trim($title), 0, 117);
        if ($title !== '') {
            $service->renameSession((int)$user['id'], $sessionId, $title);
        }
    }

    aiJson([
        'success' => true,
        'session_id' => $sessionId,
        'message' => [
            'id' => (int)$assistantMessage['id'],
            'role' => 'assistant',
            'content' => $result['text'],
            'token_count' => $assistantTokens,
            'created_at' => $assistantMessage['created_at'],
        ],
        'usage' => [
            'input_tokens' => (int)$result['input_tokens'],
            'output_tokens' => $assistantTokens,
        ],
    ]);
} catch (Throwable $e) {
    $status = $e->getCode();
    if ($status < 400 || $status > 599) {
        $status = 500;
    }
    ApiErrorResponder::throwable('ai/message', $e, $status, 'AI service is temporarily unavailable.');
}
