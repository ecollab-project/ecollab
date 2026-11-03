<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config.php';
require ROOT_PATH . '/database/config/db.php';
error_reporting(E_ALL);
ini_set('display_errors','1');
if (!str_contains(DB_NAME, 'test') || !in_array(DB_HOST, ['127.0.0.1','localhost'], true)) throw new RuntimeException('An explicit loopback test database is required.');
$db = Database::getInstance();
if ($db->query('SHOW TABLES')->fetchColumn() !== false) throw new RuntimeException('Run preview checks on a fresh disposable test database before migrations.');
foreach (['--status','--dry-run'] as $flag) {
    $command = [PHP_BINARY, '-c', php_ini_loaded_file() ?: '', ROOT_PATH.'/database/migrate.php', $flag];
    $pipes = [];
    $process = proc_open($command, [1=>['pipe','w'],2=>['pipe','w']], $pipes, ROOT_PATH);
    if (!is_resource($process)) throw new RuntimeException('Unable to start migration preview');
    $output = stream_get_contents($pipes[1]);$error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException($error . $output);
    if ($db->query('SHOW TABLES')->fetchColumn() !== false) throw new RuntimeException($flag . ' mutated the empty database');
}
echo "PASS: migration status/dry-run leave a fresh test database unchanged\n";
