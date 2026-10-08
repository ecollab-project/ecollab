<?php
declare(strict_types=1);
$dsn = getenv('COLLABORA_TEST_DSN') ?: '';
if (!str_ends_with($dsn, ';dbname=collabora_test')) {
    fwrite(STDERR, "Dedicated collabora_test database required.\n"); exit(2);
}
$db = new PDO($dsn, 'root', getenv('COLLABORA_TEST_PASSWORD') ?: 'test', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function verify(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function applySql(PDO $db, string $file): void {
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($file));
    foreach (explode(';', $sql) as $statement) if (trim($statement) !== '') $db->exec($statement);
}
foreach (['fresh', 'partial', 'complete'] as $scenario) {
    $db->exec('CREATE TEMPORARY TABLE dm_messages (id BIGINT PRIMARY KEY, body TEXT)');
    $db->exec('CREATE TEMPORARY TABLE threads (id BIGINT PRIMARY KEY, is_pinned TINYINT NOT NULL DEFAULT 0)');
    $db->exec("INSERT INTO dm_messages VALUES (1,'Keep this message')");
    $db->exec('INSERT INTO threads VALUES (1,1)');
    if ($scenario !== 'fresh') {
        $db->exec('ALTER TABLE dm_messages ADD COLUMN attachment_path VARCHAR(500) NULL');
        $db->exec("UPDATE dm_messages SET attachment_path='/uploads/keep.png'");
        $db->exec('ALTER TABLE threads ADD COLUMN is_bookmarked TINYINT NOT NULL DEFAULT 0');
        $db->exec('UPDATE threads SET is_bookmarked=1');
    }
    if ($scenario === 'complete') {
        $db->exec('ALTER TABLE dm_messages ADD COLUMN attachment_name VARCHAR(255) NULL, ADD COLUMN attachment_size BIGINT UNSIGNED NULL, ADD COLUMN attachment_mime VARCHAR(150) NULL');
        $db->exec("UPDATE dm_messages SET attachment_name='keep.png',attachment_size=123,attachment_mime='image/png'");
    }
    for ($pass=0; $pass<2; $pass++) {
        applySql($db, dirname(__DIR__).'/database/migrations/047_dm_group_attachments.sql');
        applySql($db, dirname(__DIR__).'/database/migrations/048_thread_post_actions.sql');
    }
    $message = $db->query('SELECT * FROM dm_messages WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    foreach (['attachment_path','attachment_name','attachment_size','attachment_mime'] as $column) verify(array_key_exists($column,$message), "$scenario missing $column");
    verify($message['body']==='Keep this message', "$scenario altered message");
    if ($scenario !== 'fresh') {
        verify($message['attachment_path']==='/uploads/keep.png', "$scenario altered attachment");
        verify((int)$db->query('SELECT is_bookmarked FROM threads WHERE id=1')->fetchColumn()===1, "$scenario altered bookmark");
    }
    if ($scenario === 'complete') verify($message['attachment_name']==='keep.png' && (int)$message['attachment_size']===123 && $message['attachment_mime']==='image/png', 'Existing attachment metadata altered');
    $db->exec('DROP TEMPORARY TABLE dm_messages');
    $db->exec('DROP TEMPORARY TABLE threads');
}
echo "Migration rerun checks passed: fresh, partial and complete schemas preserve data.\n";
