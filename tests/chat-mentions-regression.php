<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/ChatMentions.php';

function expectMentionValue(mixed $actual, mixed $expected): void
{
    if ($actual !== $expected) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->sqliteCreateFunction('CONCAT', static fn(...$parts) => implode('', $parts));
$db->sqliteCreateFunction('regexp', static fn(string $pattern, string $text) => (int)preg_match('~' . $pattern . '~u', $text), 2);
$db->exec("
    CREATE TABLE users(id INTEGER, username TEXT, full_name TEXT, role TEXT, status TEXT, deleted_at TEXT, avatar_color_gradient TEXT);
    CREATE TABLE servers(id INTEGER, name TEXT);
    CREATE TABLE channels(id INTEGER, server_id INTEGER, name TEXT, is_private INTEGER, created_by INTEGER);
    CREATE TABLE server_members(server_id INTEGER, user_id INTEGER, server_role TEXT, nickname TEXT);
    CREATE TABLE channel_members(channel_id INTEGER, user_id INTEGER);
    CREATE TABLE messages(id INTEGER, sender_id INTEGER, channel_id INTEGER, content TEXT, created_at TEXT, is_deleted INTEGER DEFAULT 0);
    CREATE TABLE notifications(recipient_id INTEGER, actor_id INTEGER, type TEXT, title TEXT, body TEXT, link_url TEXT, icon TEXT);
    INSERT INTO servers VALUES (1,'Campus'), (2,'Other server');
    INSERT INTO channels VALUES (20,1,'Public',0,1), (21,1,'Private',1,1), (99,2,'Other',0,4);
    INSERT INTO users VALUES
        (1,'sender','Sender','student','active',NULL,NULL),
        (2,'acct_two','Account Two','student','active',NULL,NULL),
        (3,'acct_three','Account Three','student','active',NULL,NULL),
        (4,'outsider','Outside User','student','active',NULL,NULL),
        (5,'suspended','Suspended User','student','suspended',NULL,NULL);
    INSERT INTO server_members VALUES
        (1,1,'member',NULL),(1,2,'member','Legal Shota'),(1,3,'member','Legal Shota'),
        (2,4,'member','Outside Nickname'),(1,5,'member',NULL);
    INSERT INTO channel_members VALUES (21,2);
");

$selected = 'Hi [@Legal Shota](#ecollab-mention-2), [@Self](#ecollab-mention-1) and [@Other](#ecollab-mention-4)';
[$content, $ids, $all] = ChatMentions::prepare($db, 21, 1, $selected);
expectMentionValue($ids, [2]);
expectMentionValue($all, false);
expectMentionValue(ChatMentions::plainText($content), 'Hi @Legal Shota, @Self and @Other');
expectMentionValue(str_contains($content, '#ecollab-mention-1'), false);
[$content, $ids, $all] = ChatMentions::prepare($db, 21, 1, '[@Wrong name](#ecollab-mention-2) @EVERYONE.');
expectMentionValue($content, '[@Legal Shota](#ecollab-mention-2) @everyone.');
expectMentionValue($all, true);
expectMentionValue(ChatMentions::prepare($db, 21, 1, '[@Other roster member](#ecollab-mention-3)')[1], []);
expectMentionValue(ChatMentions::prepare($db, 20, 1, '[@Legal Shota](#ecollab-mention-3)')[1], [3]);
expectMentionValue(ChatMentions::prepare($db, 21, 1, '[link @everyone](https://example.com) or user@everyone.test')[2], false);
expectMentionValue(ChatMentions::prepare($db, 21, 1, "\x60@everyone\x60\n\n\x60\x60\x60\n@everyone\n\x60\x60\x60")[2], false);

$db->prepare("INSERT INTO messages VALUES (42,1,21,?,'2026-11-18 08:00:00',0)")->execute([$content]);
ChatMentions::notify($db, 21, 1, 42, $ids, $all);
expectMentionValue($db->query('SELECT recipient_id FROM notifications')->fetchAll(PDO::FETCH_COLUMN), [2]);
expectMentionValue(array_column(ChatMentions::inbox($db, ['id'=>2,'username'=>'acct_two'], 0, 20), 'id'), [42]);
expectMentionValue(ChatMentions::inbox($db, ['id'=>3,'username'=>'acct_three'], 0, 20), []);
$db->exec('DELETE FROM channel_members WHERE channel_id=21 AND user_id=2');
expectMentionValue(ChatMentions::inbox($db, ['id'=>2,'username'=>'acct_two'], 0, 20), []);

ChatMentions::notify($db, 20, 1, 43, [], true);
expectMentionValue($db->query("SELECT recipient_id FROM notifications WHERE link_url LIKE '%message_id=43' ORDER BY recipient_id")->fetchAll(PDO::FETCH_COLUMN), [2, 3]);
echo "PASS: Display-name identity, self exclusion, channel-scoped everyone, duplicate recipients, and access revocation.\n";
