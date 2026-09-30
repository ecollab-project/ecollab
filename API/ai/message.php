<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/security/SecurityHeaders.php';
require_once dirname(__DIR__, 2) . '/security/rate-limit/RateLimiter.php';
require_once dirname(__DIR__, 2) . '/services/AiSessionService.php';
require_once dirname(__DIR__, 2) . '/services/GeminiService.php';

header('Content-Type: application/json; charset=utf-8');
SecurityHeaders::send(isApi: true);
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

function aiJson(array $data, int $status = 200): never { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function aiApproxTokens(string $text): int { return max(1, (int)ceil(mb_strlen($text) / 4)); }

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') aiJson(['success'=>false,'error'=>'Method not allowed.'],405);
    AuthMiddleware::verifyCsrf();
    $body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    $sessionId = filter_var($body['session_id'] ?? null, FILTER_VALIDATE_INT);
    $prompt = trim((string)($body['prompt'] ?? ''));
    if (!$sessionId || $sessionId < 1) aiJson(['success'=>false,'error'=>'A valid session_id is required.'],400);
    if ($prompt === '') aiJson(['success'=>false,'error'=>'Prompt is required.'],400);
    if (mb_strlen($prompt) > 4000) aiJson(['success'=>false,'error'=>'Prompt must be 4000 characters or fewer.'],400);

    $apiKey = (string)env('GEMINI_API_KEY','');
    if ($apiKey === '' || $apiKey === 'your_gemini_api_key_here') aiJson(['success'=>false,'error'=>'AI assist is not configured. Add GEMINI_API_KEY to your .env.'],503);

    $limiter = new RateLimiter();
    $rl = $limiter->attempt('ai_assist',(string)$user['id'],20,3600);
    if (!$rl['allowed']) aiJson(['success'=>false,'error'=>'AI message limit reached. Please try again later.','retry_after'=>$rl['retry_after']],429);

    $service = new AiSessionService();
    $session = $service->getSession((int)$user['id'],(int)$sessionId);
    $history = $service->getRecentConversation((int)$user['id'],(int)$sessionId,20);
    $messages = [];
    foreach ($history as $item) $messages[] = ['role'=>(string)$item['role'],'content'=>mb_substr((string)$item['content'],0,8000)];
    $messages[] = ['role'=>'user','content'=>$prompt];

    $role = (string)($user['role'] ?? 'student');
    $systemPrompt = $role === 'facilitator'
        ? 'You are Ecollab AI, an academic assistant for facilitators. Help with class activity analysis, announcements, study materials, quizzes, and teaching workflows. Be accurate, concise, and practical.'
        : 'You are Ecollab AI, a study assistant for students. Help with explanations, study plans, quizzes, programming, and academic concepts. Be accurate, concise, friendly, and educational.';

    $result = (new GeminiService($apiKey,(string)env('GEMINI_MODEL','gemini-flash-latest')))->generate($messages,$systemPrompt,700);
    $inputTokens = (int)($result['input_tokens'] ?: aiApproxTokens($prompt));
    $outputTokens = (int)$result['output_tokens'];
    $service->appendMessage((int)$user['id'],(int)$sessionId,'user',$prompt,aiApproxTokens($prompt));
    $assistantMessage = $service->appendMessage((int)$user['id'],(int)$sessionId,'assistant',$result['text'],$outputTokens);

    if (($session['message_count'] ?? 0) === 0 || ($session['session_title'] ?? '') === 'New AI Conversation') {
        $title = mb_substr(trim(preg_replace('/\s+/',' ',$prompt) ?? $prompt),0,117);
        if ($title !== '') $service->renameSession((int)$user['id'],(int)$sessionId,$title);
    }

    aiJson(['success'=>true,'session_id'=>(int)$sessionId,'message'=>['id'=>(int)$assistantMessage['id'],'role'=>'assistant','content'=>$result['text'],'token_count'=>$outputTokens,'created_at'=>$assistantMessage['created_at']],'usage'=>['input_tokens'=>$inputTokens,'output_tokens'=>$outputTokens]]);
} catch (Throwable $e) {
    error_log('[ai/message] '.$e->getMessage());
    $status = $e->getCode(); if ($status < 400 || $status > 599) $status = 500;
    aiJson(['success'=>false,'error'=>defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'AI service error.'],$status);
}
