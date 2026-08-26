<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/database/config/db.php';

class MessageService
{
    private PDO $db;
    private const PAGE_SIZE = 50;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Fetch paginated messages for a channel.
     */
    public function getMessages(int $channelId, int $userId, ?int $before = null, int $limit = self::PAGE_SIZE): array
    {
        // Verify user has access to this channel's server
        // Admins/moderators can read any channel
        $roleCheck = $this->db->prepare("SELECT role FROM users WHERE id = :uid LIMIT 1");
        $roleCheck->execute([':uid' => $userId]);
        $readerRole = $roleCheck->fetchColumn() ?: 'student';

        if (!in_array($readerRole, ['admin', 'super_admin', 'moderator'], true)) {
            $access = $this->db->prepare("
                SELECT 1 FROM channels c
                JOIN server_members sm ON sm.server_id = c.server_id AND sm.user_id = :uid
                WHERE c.id = :cid LIMIT 1
            ");
            $access->execute([':uid' => $userId, ':cid' => $channelId]);
            if (!$access->fetch()) {
                throw new RuntimeException('Access denied', 403);
            }
        } else {
            // Just verify the channel exists
            $access = $this->db->prepare("SELECT 1 FROM channels WHERE id = :cid LIMIT 1");
            $access->execute([':cid' => $channelId]);
            if (!$access->fetch()) {
                throw new RuntimeException('Channel not found', 404);
            }
        }

        $limit = min(max(1, $limit), 100);
        $params = [':cid' => $channelId, ':limit' => $limit];
        $beforeClause = '';
        if ($before !== null) {
            $beforeClause = 'AND m.id < :before';
            $params[':before'] = $before;
        }

        $stmt = $this->db->prepare("
            SELECT m.id, m.channel_id, m.sender_id, m.content, m.content_type,
                   m.parent_id, m.is_edited, m.is_pinned, m.reaction_count,
                   m.created_at, m.updated_at,
                   u.username, u.full_name, u.avatar_url, u.avatar_color_gradient, u.role,
                   u.is_verified,
                   pm.content AS parent_content,
                   pu.full_name AS parent_author
            FROM messages m
            JOIN users u ON u.id = m.sender_id
            LEFT JOIN messages pm ON pm.id = m.parent_id AND pm.is_deleted = 0
            LEFT JOIN users pu ON pu.id = pm.sender_id
            WHERE m.channel_id = :cid AND m.is_deleted = 0 $beforeClause
            ORDER BY m.id DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':cid',   $channelId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit,     PDO::PARAM_INT);
        if ($before !== null) {
            $stmt->bindValue(':before', $before, PDO::PARAM_INT);
        }
        $stmt->execute();
        $messages = array_reverse($stmt->fetchAll());

        // Attach reactions per message
        if (!empty($messages)) {
            $ids = array_column($messages, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rStmt = $this->db->prepare("
                SELECT mr.message_id, mr.emoji, COUNT(*) AS cnt,
                       MAX(CASE WHEN mr.user_id = ? THEN 1 ELSE 0 END) AS reacted_by_me
                FROM message_reactions mr
                WHERE mr.message_id IN ($placeholders)
                GROUP BY mr.message_id, mr.emoji
                ORDER BY mr.message_id, cnt DESC
            ");
            $rStmt->execute(array_merge([$userId], $ids));
            $reactions = [];
            foreach ($rStmt->fetchAll() as $r) {
                $reactions[$r['message_id']][] = $r;
            }

            // Attach attachments
            $aStmt = $this->db->prepare("
                SELECT message_id, file_name, file_path, file_size, mime_type
                FROM message_attachments
                WHERE message_id IN ($placeholders)
            ");
            $aStmt->execute($ids);
            $attachments = [];
            foreach ($aStmt->fetchAll() as $a) {
                $attachments[$a['message_id']][] = $a;
            }

            // Attach poll data for poll messages
            $pollMsgIds = array_column(array_filter($messages, fn($m) => $m['content_type'] === 'poll'), 'id');
            $pollData = [];
            if (!empty($pollMsgIds)) {
                $pphs = implode(',', array_fill(0, count($pollMsgIds), '?'));
                $pStmt = $this->db->prepare("SELECT id, message_id, question, total_votes FROM polls WHERE message_id IN ($pphs)");
                $pStmt->execute($pollMsgIds);
                $polls = [];
                foreach ($pStmt->fetchAll() as $p) {
                    $polls[$p['message_id']] = $p;
                }
                if (!empty($polls)) {
                    $pollIds = array_column($polls, 'id');
                    $ophs = implode(',', array_fill(0, count($pollIds), '?'));
                    $oStmt = $this->db->prepare("SELECT id, poll_id, option_text, vote_count, position FROM poll_options WHERE poll_id IN ($ophs) ORDER BY position ASC");
                    $oStmt->execute($pollIds);
                    $optsByPoll = [];
                    foreach ($oStmt->fetchAll() as $o) {
                        $optsByPoll[$o['poll_id']][] = $o;
                    }
                    $vStmt = $this->db->prepare("SELECT poll_id, option_id FROM poll_votes WHERE poll_id IN ($ophs) AND user_id = ?");
                    $vStmt->execute(array_merge($pollIds, [$userId]));
                    $myVotes = [];
                    foreach ($vStmt->fetchAll() as $v) {
                        $myVotes[$v['poll_id']] = (int)$v['option_id'];
                    }
                    foreach ($polls as $msgId => $poll) {
                        $pollData[$msgId] = [
                            'id'          => (int)$poll['id'],
                            'question'    => $poll['question'],
                            'total_votes' => (int)$poll['total_votes'],
                            'my_vote'     => $myVotes[$poll['id']] ?? null,
                            'options'     => array_map(fn($o) => [
                                'id'         => (int)$o['id'],
                                'text'       => $o['option_text'],
                                'vote_count' => (int)$o['vote_count'],
                            ], $optsByPoll[$poll['id']] ?? []),
                        ];
                    }
                }
            }

            foreach ($messages as &$msg) {
                $msg['reactions']    = $reactions[$msg['id']]    ?? [];
                $msg['attachments']  = $attachments[$msg['id']]  ?? [];
                $msg['poll']         = $pollData[$msg['id']]     ?? null;
            }
            unset($msg);
        }

        return $messages;
    }

    /**
     * Send a new message.
     */
    public function sendMessage(int $channelId, int $senderId, array $data): array
    {
        // Verify access — admins/moderators can post in any unlocked channel
        $roleStmt = $this->db->prepare("SELECT role FROM users WHERE id = :uid LIMIT 1");
        $roleStmt->execute([':uid' => $senderId]);
        $senderRole = $roleStmt->fetchColumn() ?: 'student';
        $isPrivileged = in_array($senderRole, ['admin', 'super_admin', 'moderator'], true);

        if (!$isPrivileged) {
            // Regular users need server_members row
            $access = $this->db->prepare("
                SELECT 1 FROM channels c
                JOIN server_members sm ON sm.server_id = c.server_id AND sm.user_id = :uid
                WHERE c.id = :cid AND c.is_locked = 0
                  AND c.type IN ('text','study_room') LIMIT 1
            ");
            $access->execute([':uid' => $senderId, ':cid' => $channelId]);
            if (!$access->fetch()) {
                throw new RuntimeException('Access denied or channel is locked', 403);
            }
        } else {
            // Privileged: just check channel exists and isn't locked
            $access = $this->db->prepare("SELECT 1 FROM channels WHERE id = :cid AND is_locked = 0 LIMIT 1");
            $access->execute([':cid' => $channelId]);
            if (!$access->fetch()) {
                throw new RuntimeException('Channel not found or is locked', 403);
            }
        }

        $content = trim($data['content'] ?? '');
        if ($content === '' && empty($data['attachment_path'])) {
            throw new InvalidArgumentException('Message content is required', 400);
        }

        $contentType = in_array($data['content_type'] ?? 'text', ['text', 'image', 'file', 'code', 'poll'], true)
            ? $data['content_type'] : 'text';
        $parentId    = isset($data['parent_id']) ? (int)$data['parent_id'] : null;

        // For polls, content = the question
        if ($contentType === 'poll') {
            $pollQuestion = trim($data['poll_question'] ?? $content);
            $pollOptions  = array_values(array_filter(array_map('trim', $data['poll_options'] ?? []), fn($o) => $o !== ''));
            if ($pollQuestion === '') throw new InvalidArgumentException('Poll question is required', 400);
            if (count($pollOptions) < 2) throw new InvalidArgumentException('At least 2 poll options required', 400);
            if (count($pollOptions) > 6) throw new InvalidArgumentException('Maximum 6 poll options allowed', 400);
            $content = $pollQuestion;
        }

        $stmt = $this->db->prepare("
            INSERT INTO messages (channel_id, sender_id, content, content_type, parent_id, created_at, updated_at)
            VALUES (:cid, :uid, :content, :type, :parent, NOW(), NOW())
        ");
        $stmt->execute([
            ':cid'     => $channelId,
            ':uid'     => $senderId,
            ':content' => $content,
            ':type'    => $contentType,
            ':parent'  => $parentId,
        ]);
        $messageId = (int)$this->db->lastInsertId();

        // Insert poll rows if needed
        if ($contentType === 'poll') {
            $pStmt = $this->db->prepare("INSERT INTO polls (message_id, question) VALUES (:mid, :q)");
            $pStmt->execute([':mid' => $messageId, ':q' => $pollQuestion]);
            $pollId = (int)$this->db->lastInsertId();
            $oStmt = $this->db->prepare("INSERT INTO poll_options (poll_id, option_text, position) VALUES (:pid, :txt, :pos)");
            foreach ($pollOptions as $i => $opt) {
                $oStmt->execute([':pid' => $pollId, ':txt' => $opt, ':pos' => $i]);
            }
        }

        // Handle attachment
        if (!empty($data['attachment_path'])) {
            $aStmt = $this->db->prepare("
                INSERT INTO message_attachments (message_id, file_name, file_path, file_size, mime_type)
                VALUES (:mid, :name, :path, :size, :mime)
            ");
            $aStmt->execute([
                ':mid'  => $messageId,
                ':name' => $data['attachment_name'] ?? basename($data['attachment_path']),
                ':path' => $data['attachment_path'],
                ':size' => $data['attachment_size'] ?? 0,
                ':mime' => $data['attachment_mime'] ?? 'application/octet-stream',
            ]);
        }

        return $this->getMessageById($messageId);
    }

    /**
     * Edit a message (sender only, or admin/mod).
     */
    public function editMessage(int $messageId, int $userId, string $newContent, string $userRole): array
    {
        $stmt = $this->db->prepare("SELECT * FROM messages WHERE id=:id AND is_deleted=0");
        $stmt->execute([':id' => $messageId]);
        $msg = $stmt->fetch();
        if (!$msg) {
            throw new RuntimeException('Message not found', 404);
        }
        if ((int)$msg['sender_id'] !== $userId && !in_array($userRole, ['admin', 'moderator', 'super_admin'], true)) {
            throw new RuntimeException('Forbidden', 403);
        }
        $content = trim($newContent);
        if ($content === '') {
            throw new InvalidArgumentException('Content cannot be empty', 400);
        }
        $upd = $this->db->prepare("UPDATE messages SET content=:c, is_edited=1, updated_at=NOW() WHERE id=:id");
        $upd->execute([':c' => $content, ':id' => $messageId]);
        return $this->getMessageById($messageId);
    }

    /**
     * Soft-delete a message.
     */
    public function deleteMessage(int $messageId, int $userId, string $userRole): bool
    {
        $stmt = $this->db->prepare("SELECT sender_id FROM messages WHERE id=:id AND is_deleted=0");
        $stmt->execute([':id' => $messageId]);
        $msg = $stmt->fetch();
        if (!$msg) {
            throw new RuntimeException('Message not found', 404);
        }
        if ((int)$msg['sender_id'] !== $userId && !in_array($userRole, ['admin', 'moderator', 'super_admin'], true)) {
            throw new RuntimeException('Forbidden', 403);
        }
        $del = $this->db->prepare("UPDATE messages SET is_deleted=1, deleted_at=NOW() WHERE id=:id");
        $del->execute([':id' => $messageId]);
        return true;
    }

    /**
     * Toggle pin on a message.
     */
    public function pinMessage(int $messageId, int $userId, string $userRole): array
    {
        $stmt = $this->db->prepare("SELECT * FROM messages WHERE id=:id AND is_deleted=0");
        $stmt->execute([':id' => $messageId]);
        $msg = $stmt->fetch();
        if (!$msg) {
            throw new RuntimeException('Message not found', 404);
        }

        // Authorization mirrors the model already used by
        // API/chat/get-channel.php and the WebSocket layer's
        // canJoinChannel(): access is scoped per-server via
        // server_members.server_role, not the global users.role. A
        // global role (e.g. a site-wide "moderator") is intentionally
        // NOT treated as a blanket bypass here, matching that existing,
        // already-audited precedent -- a per-server owner/admin/moderator,
        // or the channel's own creator, can manage any message in their
        // channel; anyone else additionally needs explicit channel_members
        // access if the channel is private.
        $channel = $this->db->prepare("
            SELECT server_id, is_private, created_by
            FROM channels WHERE id = :cid LIMIT 1
        ");
        $channel->execute([':cid' => (int)$msg['channel_id']]);
        $chan = $channel->fetch();
        if (!$chan) {
            throw new RuntimeException('Forbidden', 403);
        }

        $roleStmt = $this->db->prepare("
            SELECT server_role FROM server_members
            WHERE server_id = :sid AND user_id = :uid LIMIT 1
        ");
        $roleStmt->execute([':sid' => $chan['server_id'], ':uid' => $userId]);
        $serverRole = $roleStmt->fetchColumn();

        if ($serverRole === false) {
            // Not even a member of the server this channel belongs to.
            throw new RuntimeException('Forbidden', 403);
        }

        $canManage = in_array($serverRole, ['owner', 'admin', 'moderator'], true)
            || (int)($chan['created_by'] ?? 0) === $userId;

        if ((int)$chan['is_private'] === 1 && !$canManage) {
            $access = $this->db->prepare("
                SELECT 1 FROM channel_members
                WHERE channel_id = :cid AND user_id = :uid LIMIT 1
            ");
            $access->execute([':cid' => (int)$msg['channel_id'], ':uid' => $userId]);
            if (!$access->fetch()) {
                throw new RuntimeException('Forbidden', 403);
            }
        }

        $newPin = $msg['is_pinned'] ? 0 : 1;
        $upd = $this->db->prepare("UPDATE messages SET is_pinned=:p WHERE id=:id");
        $upd->execute([':p' => $newPin, ':id' => $messageId]);
        return $this->getMessageById($messageId);
    }

    /**
     * Get all pinned messages for a specific channel.
     */
    public function getPinnedMessages(int $channelId, int $userId): array
    {
        // Verify access
        $access = $this->db->prepare("
            SELECT 1 FROM channels ch
            JOIN server_members sm ON sm.server_id = ch.server_id AND sm.user_id = :uid
            WHERE ch.id = :cid LIMIT 1
        ");
        $access->execute([':uid' => $userId, ':cid' => $channelId]);
        if (!$access->fetch()) {
            // Admins/facilitators may still access
            $roleStmt = $this->db->prepare("SELECT role FROM users WHERE id=:uid LIMIT 1");
            $roleStmt->execute([':uid' => $userId]);
            $role = $roleStmt->fetchColumn();
            if (!in_array($role, ['admin', 'moderator', 'super_admin', 'facilitator'], true)) {
                throw new \RuntimeException('Forbidden', 403);
            }
        }

        $stmt = $this->db->prepare("
            SELECT m.id, m.channel_id, m.sender_id, m.content, m.content_type,
                   m.is_pinned, m.created_at, m.updated_at,
                   u.username, u.full_name, u.avatar_color_gradient AS grad
            FROM messages m
            JOIN users u ON u.id = m.sender_id
            WHERE m.channel_id = :cid AND m.is_pinned = 1 AND m.is_deleted = 0
            ORDER BY m.created_at DESC
        ");
        $stmt->execute([':cid' => $channelId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * Toggle a reaction on a message.
     */
    public function toggleReaction(int $messageId, int $userId, string $emoji): array
    {
        // Check if already reacted
        $check = $this->db->prepare("
            SELECT id FROM message_reactions
            WHERE message_id=:mid AND user_id=:uid AND emoji=:emoji
        ");
        $check->execute([':mid' => $messageId, ':uid' => $userId, ':emoji' => $emoji]);
        $existing = $check->fetch();

        if ($existing) {
            $del = $this->db->prepare("DELETE FROM message_reactions WHERE id=:id");
            $del->execute([':id' => $existing['id']]);
            $action = 'removed';
        } else {
            $ins = $this->db->prepare("
                INSERT INTO message_reactions (message_id, user_id, emoji) VALUES (:mid, :uid, :emoji)
            ");
            $ins->execute([':mid' => $messageId, ':uid' => $userId, ':emoji' => $emoji]);
            $action = 'added';
        }

        // Update cached count
        $cnt = $this->db->prepare("SELECT COUNT(*) FROM message_reactions WHERE message_id=:mid");
        $cnt->execute([':mid' => $messageId]);
        $this->db->prepare("UPDATE messages SET reaction_count=:c WHERE id=:mid")
            ->execute([':c' => (int)$cnt->fetchColumn(), ':mid' => $messageId]);

        return ['action' => $action, 'emoji' => $emoji, 'message_id' => $messageId];
    }
    private function getMessageById(int $id): array
    {
        $stmt = $this->db->prepare("
        SELECT m.*, 
               u.username, 
               u.full_name, 
               u.avatar_url, 
               u.avatar_color_gradient,
               u.role, 
               u.is_verified,
               pm.content AS parent_content,
               pu.full_name AS parent_author
        FROM messages m
        JOIN users u ON u.id = m.sender_id
        LEFT JOIN messages pm 
            ON pm.id = m.parent_id 
            AND pm.is_deleted = 0
        LEFT JOIN users pu 
            ON pu.id = pm.sender_id
        WHERE m.id = :id
        LIMIT 1
    ");

        $stmt->execute([':id' => $id]);

        $msg = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$msg) {
            throw new RuntimeException('Message not found', 404);
        }

        $msg['reactions']  = [];
        $msg['attachments'] = [];
        $msg['poll']        = null;

        if ($msg['content_type'] === 'poll') {
            $pStmt = $this->db->prepare("SELECT id, question, total_votes FROM polls WHERE message_id = :mid LIMIT 1");
            $pStmt->execute([':mid' => $id]);
            $poll = $pStmt->fetch(PDO::FETCH_ASSOC);
            if ($poll) {
                $oStmt = $this->db->prepare("SELECT id, option_text, vote_count, position FROM poll_options WHERE poll_id = :pid ORDER BY position ASC");
                $oStmt->execute([':pid' => $poll['id']]);
                $options = $oStmt->fetchAll(PDO::FETCH_ASSOC);
                $msg['poll'] = [
                    'id'          => (int)$poll['id'],
                    'question'    => $poll['question'],
                    'total_votes' => (int)$poll['total_votes'],
                    'my_vote'     => null,
                    'options'     => array_map(fn($o) => [
                        'id'         => (int)$o['id'],
                        'text'       => $o['option_text'],
                        'vote_count' => (int)$o['vote_count'],
                    ], $options),
                ];
            }
        }

        return $msg;
    }
}
