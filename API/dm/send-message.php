<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/OllamaService.php';
require_once dirname(__DIR__, 2) . '/services/JarredTools.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$convId = (int)($body['conversation_id'] ?? 0);
$text   = trim($body['body'] ?? '');

if (!$convId || $text === '' || mb_strlen($text) > 4000) {
    http_response_code(400);
    echo json_encode(['error' => 'conversation_id and non-empty body (max 4000 chars) required']);
    exit;
}

try {
    $db = Database::getInstance();

    $check = $db->prepare(
        "SELECT id, user_a, user_b FROM dm_conversations
         WHERE id = :cid AND (user_a = :me OR user_b = :me2)
         LIMIT 1"
    );
    $check->execute([':cid' => $convId, ':me' => $me['id'], ':me2' => $me['id']]);
    $conv = $check->fetch(PDO::FETCH_ASSOC);

    if (!$conv) {
        http_response_code(403);
        echo json_encode(['error' => 'Conversation not found or access denied']);
        exit;
    }

    $recipientId = ((int)$conv['user_a'] === (int)$me['id'])
        ? (int)$conv['user_b']
        : (int)$conv['user_a'];

    $recipientStmt = $db->prepare(
        "SELECT id, username, full_name, avatar_color_gradient, is_system
         FROM users
         WHERE id = :id AND deleted_at IS NULL
         LIMIT 1"
    );
    $recipientStmt->execute([':id' => $recipientId]);
    $recipient = $recipientStmt->fetch(PDO::FETCH_ASSOC);

    if (!$recipient) {
        http_response_code(404);
        echo json_encode(['error' => 'Recipient not found']);
        exit;
    }

    $isAiConversation =
        (int)($recipient['is_system'] ?? 0) === 1
        && ($recipient['username'] ?? '') === 'ecollab_ai';

    $ins = $db->prepare(
        "INSERT INTO dm_messages (conversation_id, sender_id, body)
         VALUES (:cid, :uid, :body)"
    );
    $ins->execute([':cid' => $convId, ':uid' => $me['id'], ':body' => $text]);
    $msgId = (int)$db->lastInsertId();

    $createdStmt = $db->prepare("SELECT created_at FROM dm_messages WHERE id = :id LIMIT 1");
    $createdStmt->execute([':id' => $msgId]);
    $createdAt = (string)($createdStmt->fetchColumn() ?: gmdate('Y-m-d H:i:s'));

    $db->prepare(
        "UPDATE dm_conversations SET last_message = :body, last_msg_at = :created_at WHERE id = :cid"
    )->execute([
        ':body' => mb_substr($text, 0, 120),
        ':created_at' => $createdAt,
        ':cid' => $convId,
    ]);

    try {
        $db->prepare(
            "INSERT INTO dm_reads (user_id, conversation_id, last_read_at)
             VALUES (:uid, :cid, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE last_read_at = UTC_TIMESTAMP()"
        )->execute([':uid' => $me['id'], ':cid' => $convId]);
    } catch (Throwable $e) {
        error_log('[dm/send-message] read cursor update skipped: ' . $e->getMessage());
    }

    if (!$isAiConversation) {
        try {
            $db->prepare(
                "INSERT INTO notifications
                    (recipient_id, actor_id, type, title, body, link_url, icon, is_read)
                 VALUES
                    (:recipient, :actor, 'message', :title, :body2, :link, '💬', 0)"
            )->execute([
                ':recipient' => $recipientId,
                ':actor'     => (int)$me['id'],
                ':title'     => ($me['full_name'] ?: $me['username']) . ' sent you a message',
                ':body2'     => mb_substr($text, 0, 500),
                ':link'      => BASE_URL . '/modules/chat/chat.php?dm=' . $convId,
            ]);
        } catch (Throwable $e) {
            error_log('[dm/send-message] notification insert skipped: ' . $e->getMessage());
        }

        echo json_encode([
            'success'      => true,
            'message_id'   => $msgId,
            'sender_id'    => $me['id'],
            'body'         => $text,
            'created_at'   => $createdAt,
            'recipient_id' => $recipientId,
            'is_ai'        => false,
        ]);
        exit;
    }

    try {
        $historyStmt = $db->prepare(
            "SELECT m.sender_id, m.body
             FROM dm_messages m
             WHERE m.conversation_id = :cid AND m.is_deleted = 0
             ORDER BY m.id DESC
             LIMIT 12"
        );
        $historyStmt->execute([':cid' => $convId]);
        $history = array_reverse($historyStmt->fetchAll(PDO::FETCH_ASSOC));

        $messages = [];
        foreach ($history as $row) {
            $messages[] = [
                'role' => (int)$row['sender_id'] === $recipientId ? 'assistant' : 'user',
                'content' => (string)$row['body'],
            ];
        }

        $jarredContext = (new JarredTools())->contextForPrompt((int)$me['id'], $text);
        if ($jarredContext !== '') {
            $messages[] = [
                'role' => 'system',
                'content' => "Authoritative live eCollab context for the request below. Use it when relevant. Never invent users, presence, messages, servers, channels, or permissions.\n\n" . $jarredContext,
            ];
        }

        $ollama = new OllamaService();
        $result = $ollama->generate(
            $messages,
            'You are Jarred, the built-in eCollab assistant. Help college students with studying, programming, collaboration, research planning, explanations, and project work. You may use permission-scoped eCollab context supplied by the backend, including accessible servers/channels, message and thread search results, and live presence. Treat that backend context as authoritative. Never claim access to information that was not supplied. You have read/search capabilities only: never claim to kick, ban, mute, remove users, change roles or permissions, delete servers/channels/content, access another user\'s private data, reveal secrets, execute SQL/shell/PHP, or bypass eCollab authorization. Be concise, useful, friendly, and honest.',
            400
        );

        $aiText = trim((string)($result['text'] ?? ''));
        if ($aiText === '') {
            throw new RuntimeException('AI returned an empty response');
        }

        $aiInsert = $db->prepare(
            "INSERT INTO dm_messages (conversation_id, sender_id, body)
             VALUES (:cid, :uid, :body)"
        );
        $aiInsert->execute([
            ':cid' => $convId,
            ':uid' => $recipientId,
            ':body' => $aiText,
        ]);
        $aiMsgId = (int)$db->lastInsertId();

        $aiCreatedStmt = $db->prepare("SELECT created_at FROM dm_messages WHERE id = :id LIMIT 1");
        $aiCreatedStmt->execute([':id' => $aiMsgId]);
        $aiCreatedAt = (string)($aiCreatedStmt->fetchColumn() ?: gmdate('Y-m-d H:i:s'));

        $db->prepare(
            "UPDATE dm_conversations SET last_message = :body, last_msg_at = :created_at WHERE id = :cid"
        )->execute([
            ':body' => mb_substr($aiText, 0, 120),
            ':created_at' => $aiCreatedAt,
            ':cid' => $convId,
        ]);

        echo json_encode([
            'success' => true,
            'message_id' => $msgId,
            'sender_id' => $me['id'],
            'body' => $text,
            'created_at' => $createdAt,
            'recipient_id' => $recipientId,
            'is_ai' => true,
            'ai_message' => [
                'id' => $aiMsgId,
                'conversation_id' => $convId,
                'sender_id' => $recipientId,
                'sender_name' => 'Jarred',
                'sender_username' => $recipient['username'],
                'sender_gradient' => $recipient['avatar_color_gradient'] ?: '#6366f1,#8b5cf6',
                'body' => $aiText,
                'created_at' => $aiCreatedAt,
            ],
            'ai_usage' => [
                'input_tokens' => $result['input_tokens'] ?? null,
                'output_tokens' => $result['output_tokens'] ?? null,
                'model' => $result['model'] ?? null,
            ],
        ]);
    } catch (Throwable $aiError) {
        error_log('[dm/send-message][AI] ' . $aiError->getMessage());
        echo json_encode([
            'success' => true,
            'message_id' => $msgId,
            'sender_id' => $me['id'],
            'body' => $text,
            'created_at' => $createdAt,
            'recipient_id' => $recipientId,
            'is_ai' => true,
            'ai_error' => 'AI is temporarily unavailable',
        ]);
    }
} catch (Throwable $e) {
    error_log('[dm/send-message] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
