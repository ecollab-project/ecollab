<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require_once dirname(__DIR__).'/config.php';
require_once ROOT_PATH.'/database/config/db.php';
require_once ROOT_PATH.'/services/ChatReadProfiler.php';
try {
    $channel=filter_var($argv[1]??'',FILTER_VALIDATE_INT);
    $viewer=isset($argv[2])?filter_var($argv[2],FILTER_VALIDATE_INT):null;
    $samples=isset($argv[3])?filter_var($argv[3],FILTER_VALIDATE_INT):3;
    if ($channel===false || $viewer===false || $samples===false) throw new InvalidArgumentException('Usage: php scripts/profile-chat-reads.php CHANNEL_ID [USER_ID [SAMPLES]]');
    echo json_encode(ChatReadProfiler::inspect(Database::getInstance(),$channel,$viewer,$samples),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
} catch (Throwable $e) {
    // PDO exceptions can contain deployment details; do not dump them into shareable reports.
    fwrite(STDERR,($e instanceof InvalidArgumentException || ($e instanceof RuntimeException && !($e instanceof PDOException)))
        ? $e->getMessage().PHP_EOL : "Read profiler failed; verify database/schema availability.\n");
    exit(1);
}
