<?php
declare(strict_types=1);

/** Channel chat mention recipients are determined on the server from the channel roster. */
final class ChatMentions
{
    // Consume fenced and inline code before looking for actual mentions.
    private const TOKENS = <<<'REGEX'
~^[ \t]*(\x60{3,}|\x7e{3,})[^\r\n]*\R[\s\S]*?(?:^[ \t]*\1[ \t]*$|\z)|(\x60+)[^\r\n]*?\2|\[@((?:\\.|[^\[\]\\\r\n])+)\]\(#ecollab-mention-([1-9][0-9]{0,18})\)|!?\[(?:\\.|[^\[\]\\\r\n])*\]\([^\r\n)]*\)|https?://[^\s<>]+|(?<![\p{L}\p{N}_@/\\])@everyone(?![\p{L}\p{N}_/@]|\.[\p{L}\p{N}_])~imu
REGEX;

    private const ROSTER = "
        FROM channels c
        JOIN server_members sm ON sm.server_id = c.server_id
        JOIN users u ON u.id = sm.user_id
        WHERE c.id = ? AND u.id <> ? AND u.deleted_at IS NULL
          AND u.role NOT IN ('admin','super_admin') AND u.status NOT IN ('banned','suspended','deactivated')
          AND (c.is_private = 0 OR sm.server_role IN ('owner','admin')
               OR c.created_by = u.id
               OR EXISTS (SELECT 1 FROM channel_members cm WHERE cm.channel_id = c.id AND cm.user_id = u.id))";

    public static function directory(PDO $db, int $channel, int $sender, array $ids = []): array
    {
        $filter = $ids ? ' AND u.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')' : '';
        $stmt = $db->prepare("SELECT u.id, u.username, u.full_name, sm.nickname, u.role, u.avatar_color_gradient"
            . self::ROSTER . $filter . " ORDER BY COALESCE(NULLIF(TRIM(sm.nickname), ''), u.full_name), u.id");
        $stmt->execute([$channel, $sender, ...$ids]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Rebuild mention labels from the roster and drop invalid or self-targeted links. */
    public static function prepare(PDO $db, int $channel, int $sender, string $text): array
    {
        if (!preg_match_all(self::TOKENS, $text, $matches, PREG_SET_ORDER)) {
            return [$text, [], false];
        }
        $targets = [];
        foreach ($matches as $match) {
            if (isset($match[4])) $targets[(int)$match[4]] = true;
        }
        $names = [];
        if ($targets) {
            foreach (self::directory($db, $channel, $sender, array_keys($targets)) as $member) {
                $names[(int)$member['id']] = trim($member['nickname'] ?? '') ?: (trim($member['full_name'] ?? '') ?: $member['username']);
            }
        }
        $everyone = false;
        $content = preg_replace_callback(self::TOKENS, static function (array $match) use ($names, &$everyone): string {
            if (isset($match[4])) {
                $id = (int)$match[4];
                if (!isset($names[$id])) return '@' . self::unescapeLabel($match[3]);
                $label = str_replace(['\\', '[', ']', '*', '_', "\r", "\n"], ['\\\\', '\\[', '\\]', '\\*', '\\_', ' ', ' '], $names[$id]);
                return '[@' . $label . '](#ecollab-mention-' . $id . ')';
            }
            if (strcasecmp($match[0], '@everyone') === 0) {
                $everyone = true;
                return '@everyone';
            }
            return $match[0];
        }, $text);
        return [$content ?? $text, array_keys($names), $everyone];
    }

    /** One insert for all unique, currently authorized recipients. */
    public static function notify(PDO $db, int $channel, int $sender, int $message, array $ids, bool $everyone): void
    {
        if (!$everyone && !$ids) return;
        $filter = $everyone ? '' : ' AND u.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $stmt = $db->prepare("INSERT INTO notifications (recipient_id,actor_id,type,title,body,link_url,icon)
            SELECT u.id,?,'mention','You were mentioned in Chat','Open the channel to view the message.',
                   CONCAT('/modules/chat/chat.php?server_id=',c.server_id,'&channel_id=',c.id,'&message_id=',?), '@'"
            . self::ROSTER . $filter);
        $stmt->execute([$sender, $message, $channel, $sender, ...($everyone ? [] : $ids)]);
    }

    public static function plainText(string $content): string
    {
        return preg_replace_callback(self::TOKENS, static fn(array $match): string => isset($match[4])
            ? '@' . self::unescapeLabel($match[3]) : $match[0], $content) ?? $content;
    }

    private static function unescapeLabel(string $label): string
    {
        return strtr($label, ['\\[' => '[', '\\]' => ']', '\\*' => '*', '\\_' => '_', '\\\\' => '\\']);
    }

    public static function inbox(PDO $db, array $user, int $after, int $limit): array
    {
        $uid = (int)$user['id'];
        $username = trim($user['username'] ?? '');
        $legacy = $username === '' || strcasecmp($username, 'everyone') === 0 ? '(?!)' : '(?i)(^|[^[:alnum:]_@.])@' . preg_quote($username, '~') . '([^[:alnum:]_.]|$)';
        $stmt = $db->prepare("SELECT m.id, m.content, m.created_at, c.id AS channel_id, c.name AS channel,
                   s.name AS server, COALESCE(NULLIF(u.full_name, ''),u.username) AS author, u.avatar_color_gradient AS grad
            FROM messages m
            JOIN channels c ON c.id=m.channel_id
            JOIN servers s ON s.id=c.server_id
            JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=?
            JOIN users u ON u.id=m.sender_id
            WHERE m.id>? AND m.sender_id<>? AND m.is_deleted=0
              AND (c.is_private=0 OR sm.server_role IN ('owner','admin') OR c.created_by=sm.user_id
                   OR EXISTS (SELECT 1 FROM channel_members cm WHERE cm.channel_id=c.id AND cm.user_id=sm.user_id))
              AND (EXISTS (SELECT 1 FROM notifications n WHERE n.recipient_id=sm.user_id AND n.type='mention'
                       AND n.link_url=CONCAT('/modules/chat/chat.php?server_id=',c.server_id,'&channel_id=',c.id,'&message_id=',m.id))
                   OR (m.content NOT LIKE '%](#ecollab-mention-%' AND m.content REGEXP ?))
            ORDER BY m.id " . ($after > 0 ? 'ASC' : 'DESC') . ' LIMIT ' . max(1, min(50, $limit)));
        $stmt->execute([$uid, $after, $uid, $legacy]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
