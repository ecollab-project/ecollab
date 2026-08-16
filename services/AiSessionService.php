<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/database/config/db.php';

final class AiSessionService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        if ($db !== null) {
            $this->db = $db;
            return;
        }

        if (!class_exists('Database', false)) {
            require_once dirname(__DIR__) . '/database/config/db.php';
        }

        $this->db = Database::getInstance();
    }

    public function listSessions(int $userId, int $limit = 50): array
    {
        $limit = min(max($limit, 1), 100);
        $stmt = $this->db->prepare("
            SELECT id, class_id, session_title, message_count, token_cost, created_at, updated_at
            FROM ai_sessions
            WHERE user_id = :user_id
            ORDER BY updated_at DESC, id DESC
            LIMIT {$limit}
        ");
        $stmt->execute([':user_id' => $userId]);

        return array_map([$this, 'normalizeSession'], $stmt->fetchAll());
    }

    public function createSession(int $userId, ?int $classId = null, ?string $title = null): array
    {
        $title = $this->normalizeTitle($title) ?? 'New AI Conversation';

        $stmt = $this->db->prepare("
            INSERT INTO ai_sessions (user_id, class_id, session_title)
            VALUES (:user_id, :class_id, :title)
        ");
        $stmt->execute([
            ':user_id' => $userId,
            ':class_id' => $classId,
            ':title' => $title,
        ]);

        return $this->getSession($userId, (int)$this->db->lastInsertId());
    }

    public function getSession(int $userId, int $sessionId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, user_id, class_id, session_title, message_count, token_cost, created_at, updated_at
            FROM ai_sessions
            WHERE id = :id AND user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute([
            ':id' => $sessionId,
            ':user_id' => $userId,
        ]);

        $session = $stmt->fetch();
        if (!$session) {
            throw new RuntimeException('AI session not found.', 404);
        }

        return $this->normalizeSession($session);
    }

    public function renameSession(int $userId, int $sessionId, string $title): array
    {
        $title = $this->normalizeTitle($title);
        if ($title === null) {
            throw new InvalidArgumentException('Session title is required.');
        }

        $stmt = $this->db->prepare("
            UPDATE ai_sessions
            SET session_title = :title
            WHERE id = :id AND user_id = :user_id
        ");
        $stmt->execute([
            ':title' => $title,
            ':id' => $sessionId,
            ':user_id' => $userId,
        ]);

        if ($stmt->rowCount() === 0) {
            // The session may exist but already have the requested title.
            $this->getSession($userId, $sessionId);
        }

        return $this->getSession($userId, $sessionId);
    }

    public function deleteSession(int $userId, int $sessionId): void
    {
        $stmt = $this->db->prepare("
            DELETE FROM ai_sessions
            WHERE id = :id AND user_id = :user_id
        ");
        $stmt->execute([
            ':id' => $sessionId,
            ':user_id' => $userId,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('AI session not found.', 404);
        }
    }

    public function getMessages(int $userId, int $sessionId, int $limit = 100): array
    {
        $this->getSession($userId, $sessionId);

        $limit = min(max($limit, 1), 200);
        $stmt = $this->db->prepare("
            SELECT id, session_id, role, content, token_count, created_at
            FROM ai_messages
            WHERE session_id = :session_id
            ORDER BY id DESC
            LIMIT {$limit}
        ");
        $stmt->execute([':session_id' => $sessionId]);

        $messages = array_reverse($stmt->fetchAll());

        return array_map(static function (array $message): array {
            return [
                'id' => (int)$message['id'],
                'session_id' => (int)$message['session_id'],
                'role' => $message['role'],
                'content' => $message['content'],
                'token_count' => (int)$message['token_count'],
                'created_at' => $message['created_at'],
            ];
        }, $messages);
    }

    public function appendMessage(
        int $userId,
        int $sessionId,
        string $role,
        string $content,
        int $tokenCount = 0
    ): array {
        if (!in_array($role, ['user', 'assistant'], true)) {
            throw new InvalidArgumentException('Invalid AI message role.');
        }

        if (trim($content) === '') {
            throw new InvalidArgumentException('AI message content cannot be empty.');
        }

        // Verify that the session belongs to the current user.
        $sessionStmt = $this->db->prepare(
            'SELECT id
         FROM ai_sessions
         WHERE id = :session_id
           AND user_id = :user_id
         LIMIT 1'
        );

        $sessionStmt->execute([
            ':session_id' => $sessionId,
            ':user_id' => $userId,
        ]);

        if (!$sessionStmt->fetchColumn()) {
            throw new RuntimeException('AI session not found.');
        }

        // Insert the message.
        $insert = $this->db->prepare(
            'INSERT INTO ai_messages
            (session_id, role, content, token_count)
         VALUES
            (:session_id, :role, :content, :token_count)'
        );

        $insert->execute([
            ':session_id' => $sessionId,
            ':role' => $role,
            ':content' => $content,
            ':token_count' => $tokenCount,
        ]);

        $messageId = (int) $this->db->lastInsertId();

        if ($messageId <= 0) {
            throw new RuntimeException('Failed to create AI message.');
        }

        // Keep session statistics synchronized.
        $update = $this->db->prepare(
            'UPDATE ai_sessions
         SET
            message_count = message_count + 1,
            token_cost = token_cost + :token_count,
            updated_at = CURRENT_TIMESTAMP
         WHERE id = :session_id
           AND user_id = :user_id'
        );

        $update->execute([
            ':token_count' => $tokenCount,
            ':session_id' => $sessionId,
            ':user_id' => $userId,
        ]);

        // Return the actual inserted message.
        $select = $this->db->prepare(
            'SELECT
            id,
            session_id,
            role,
            content,
            token_count,
            created_at
         FROM ai_messages
         WHERE id = :id
         LIMIT 1'
        );

        $select->execute([
            ':id' => $messageId,
        ]);

        $row = $select->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new RuntimeException('AI message was inserted but could not be retrieved.');
        }

        return [
            'id' => (int) $row['id'],
            'session_id' => (int) $row['session_id'],
            'role' => (string) $row['role'],
            'content' => (string) $row['content'],
            'token_count' => (int) $row['token_count'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    public function getRecentConversation(int $userId, int $sessionId, int $limit = 20): array
    {
        $this->getSession($userId, $sessionId);

        $limit = min(max($limit, 2), 40);
        $stmt = $this->db->prepare("
            SELECT role, content
            FROM ai_messages
            WHERE session_id = :session_id
            ORDER BY id DESC
            LIMIT {$limit}
        ");
        $stmt->execute([':session_id' => $sessionId]);

        return array_reverse($stmt->fetchAll());
    }

    public function getQuickPrompts(int $limit = 12): array
    {
        $limit = min(max($limit, 1), 50);
        $stmt = $this->db->query("
            SELECT id, label, prompt_text, category, sort_order
            FROM ai_quick_prompts
            WHERE is_active = 1
            ORDER BY sort_order ASC, id ASC
            LIMIT {$limit}
        ");

        return array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'label' => $row['label'],
                'prompt_text' => $row['prompt_text'],
                'category' => $row['category'],
                'sort_order' => (int)$row['sort_order'],
            ];
        }, $stmt->fetchAll());
    }

    private function normalizeTitle(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }

        $title = trim(preg_replace('/\s+/', ' ', $title) ?? '');
        if ($title === '') {
            return null;
        }

        return mb_substr($title, 0, 120);
    }

    private function normalizeSession(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'user_id' => isset($row['user_id']) ? (int)$row['user_id'] : null,
            'class_id' => $row['class_id'] !== null ? (int)$row['class_id'] : null,
            'session_title' => $row['session_title'] ?: 'New AI Conversation',
            'message_count' => (int)$row['message_count'],
            'token_cost' => (int)$row['token_cost'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
