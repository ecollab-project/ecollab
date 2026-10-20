<?php
declare(strict_types=1);

/** Uploads have the same conversation/channel write boundary as messages. */
final class ChatUploadAccess
{
    public static function check($db, int $uid, string $kind, int $id): void
    {
        if ($id < 1 || !in_array($kind, ['channel', 'dm', 'group'], true)) {
            throw new RuntimeException('Select a chat before uploading.', 400);
        }
        if ($kind === 'dm' || $kind === 'group') {
            $sql = $kind === 'dm'
                ? 'SELECT id FROM dm_conversations WHERE id=:id AND (user_a=:a OR user_b=:b)'
                : 'SELECT group_id FROM dm_group_members WHERE group_id=:id AND user_id=:a';
            $stmt = $db->prepare($sql);
            $args = [':id'=>$id, ':a'=>$uid];
            if ($kind === 'dm') $args[':b'] = $uid;
            $stmt->execute($args);
            if (!$stmt->fetchColumn()) throw new RuntimeException('Chat access denied.', 403);
            return;
        }
        $stmt = $db->prepare('SELECT role FROM users WHERE id=:uid');
        $stmt->execute([':uid'=>$uid]);
        $privileged = in_array($stmt->fetchColumn(), ['admin','super_admin','moderator'], true);
        $stmt = $db->prepare('SELECT c.type,c.is_private,c.created_by,c.server_id,sm.server_role,
            EXISTS(SELECT 1 FROM channel_members cm WHERE cm.channel_id=c.id AND cm.user_id=:member) AS has_access
            FROM channels c LEFT JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=:uid
            WHERE c.id=:id');
        $stmt->execute([':member'=>$uid, ':uid'=>$uid, ':id'=>$id]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$c || !in_array($c['type'], ['text','announcement'], true)) throw new RuntimeException('Chat access denied.', 403);
        if ($privileged) return;
        if (!$c['server_role']) throw new RuntimeException('Server access denied.', 403);
        $manager = in_array($c['server_role'], ['owner','admin','moderator'], true) || (int)$c['created_by'] === $uid;
        if (!$manager && ((int)$c['is_private'] === 1 && !$c['has_access'] || $c['type'] === 'announcement')) {
            throw new RuntimeException('You cannot upload in this channel.', 403);
        }
        if (!$manager) {
            $stmt = $db->prepare("SELECT id FROM moderation_actions WHERE server_id=:sid AND target_user_id=:uid
                AND is_active=1 AND action_type IN ('mute','suspend') AND (expires_at IS NULL OR expires_at>NOW()) LIMIT 1");
            $stmt->execute([':sid'=>$c['server_id'], ':uid'=>$uid]);
            if ($stmt->fetchColumn()) throw new RuntimeException('Uploads are disabled while muted or suspended.', 403);
        }
    }
}
