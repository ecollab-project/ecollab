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
$attachmentPath = trim((string)($body['attachment_path'] ?? ''));
$attachmentName = trim((string)($body['attachment_name'] ?? ''));
$attachmentSize = max(0, (int)($body['attachment_size'] ?? 0));
$attachmentMime = trim((string)($body['attachment_mime'] ?? ''));
$activeServerId = isset($body['active_server_id']) ? (int)$body['active_server_id'] : null;
$jarredSurface = [
    'surface' => trim((string)($body['surface'] ?? 'dm')),
    'channel_id' => (int)($body['channel_id'] ?? 0),
    'voice_channel_id' => (int)($body['voice_channel_id'] ?? 0),
    'workspace_id' => (int)($body['workspace_id'] ?? 0),
    'document_id' => (int)($body['document_id'] ?? 0),
    'whiteboard_id' => (int)($body['whiteboard_id'] ?? 0),
];

if (!$convId || ($text === '' && $attachmentPath === '') || mb_strlen($text) > 4000) {
    http_response_code(400);
    echo json_encode(['error' => 'conversation_id and a message or attachment are required (max 4000 chars)']);
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
        "SELECT id, username, full_name, avatar_url, avatar_color_gradient, is_system
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
        "INSERT INTO dm_messages (conversation_id, sender_id, body, attachment_path, attachment_name, attachment_size, attachment_mime)
         VALUES (:cid, :uid, :body, :apath, :aname, :asize, :amime)"
    );
    $ins->execute([':cid' => $convId, ':uid' => $me['id'], ':body' => $text, ':apath' => $attachmentPath ?: null, ':aname' => $attachmentName ?: null, ':asize' => $attachmentSize ?: null, ':amime' => $attachmentMime ?: null]);
    $msgId = (int)$db->lastInsertId();

    $createdStmt = $db->prepare("SELECT created_at FROM dm_messages WHERE id = :id LIMIT 1");
    $createdStmt->execute([':id' => $msgId]);
    $createdAt = (string)($createdStmt->fetchColumn() ?: gmdate('Y-m-d H:i:s'));

    $db->prepare(
        "UPDATE dm_conversations SET last_message = :body, last_msg_at = :created_at WHERE id = :cid"
    )->execute([
        ':body' => mb_substr($text !== '' ? $text : ('📎 ' . ($attachmentName ?: 'Attachment')), 0, 120),
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
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
            'attachment_size' => $attachmentSize,
            'attachment_mime' => $attachmentMime,
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

        $jarredTools = new JarredTools();
        $jarredContext = $jarredTools->contextForPrompt((int)$me['id'], $text, $activeServerId, $jarredSurface);

        // Presence is authoritative application state. Answer simple presence questions
        // directly instead of asking the language model to reinterpret live eCollab data.
        $directJarredText = null;
        $isPresenceQuestion =
            preg_match('/\\b(online|active|connected)\\b/i', $text)
            || preg_match('/\\bwho(?:\\'s| is)\\s+(?:here|online|active)\\b/i', $text)
            || preg_match('/\\bwho(?:\\'s| is)\\s+(?:on|in)\\s+(?:my|this|the|our)\\s+server\\b/i', $text)
            || preg_match('/\\banyone\\s+(?:here|online|active)\\b/i', $text);

        if ($isPresenceQuestion) {
            $resolvedServerId = ($activeServerId && $jarredTools->canAccessServer((int)$me['id'], $activeServerId))
                ? $activeServerId
                : null;

            if ($resolvedServerId) {
                $activeMembers = $jarredTools->activeMembers((int)$me['id'], $resolvedServerId);
                if (!$activeMembers) {
                    $directJarredText = 'No members are currently active on this server.';
                } else {
                    $names = array_map(
                        static fn(array $member): string => trim((string)($member['nickname'] ?: $member['full_name'] ?: $member['username'])),
                        $activeMembers
                    );
                    $count = count($names);
                    $directJarredText = $count === 1
                        ? $names[0] . ' is currently active on this server.'
                        : implode(', ', array_slice($names, 0, -1)) . ' and ' . $names[$count - 1] . ' are currently active on this server.';
                }
            } else {
                $directJarredText = 'I need an active eCollab server context to check who is active.';
            }
        }

        if ($jarredContext !== '') {
            // Keep authoritative tool data before the conversational history so it is
            // not buried behind prior assistant replies.
            array_unshift($messages, [
                'role' => 'system',
                'content' => "Authoritative live eCollab context. Treat these application results as facts when answering the current request. Never contradict them or replace them with generic advice about other platforms.\n\n" . $jarredContext,
            ]);
        }

        if ($directJarredText !== null) {
            $result = [
                'text' => $directJarredText,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'model' => 'ecollab-direct',
            ];
        } else {
            $ollama = new OllamaService();
            $result = $ollama->generate(
            $messages,
            'You are Jarred, the central built-in AI assistant for eCollab. You assist users across servers, channels, voice channels, Coworkspaces, documents, whiteboards, study workflows, and intelligent peer matching. The backend may provide permission-scoped live ECOLLAB CONTEXT. That context is authoritative application data: use it directly and never replace it with generic advice about Discord, Slack, Teams, or other platforms. When peer-match results are supplied, explain the deterministic eCollab compatibility scores and reasons; do not invent scores or people. When document or whiteboard context is supplied, discuss only content/metadata actually provided. Voice context tells you presence and channel membership, not spoken audio unless a transcript is explicitly supplied. If required eCollab data is missing, say exactly what context is missing instead of pretending the feature is unavailable. Never invent users, messages, presence, servers, channels, documents, whiteboards, permissions, or private information. You have read/search/recommendation capabilities only: never claim to kick, ban, mute, remove users, change roles or permissions, delete content, access unauthorized private data, reveal secrets, execute SQL/shell/PHP, or bypass eCollab authorization. Be concise, practical, educational, and eCollab-specific.',
            400
            );
        }

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
                'sender_avatar_url' => $recipient['avatar_url'] ?? '',
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
