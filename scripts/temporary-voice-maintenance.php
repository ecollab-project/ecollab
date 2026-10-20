<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/services/TemporaryVoiceService.php';
$db = Database::getInstance();
// Connection-scoped and database-scoped: multiple schedulers/hosts cannot overlap.
$name = 'ec-temp-voice-'.hash('sha224', (string)$db->query('SELECT DATABASE()')->fetchColumn());
$lock = $db->prepare('SELECT GET_LOCK(?,0)');
$lock->execute([$name]);
if ((int)$lock->fetchColumn() !== 1) { fwrite(STDOUT, "Maintenance already running.\n"); exit(0); }
try {
    (new TemporaryVoiceService($db))->cleanupExpired();
    fwrite(STDOUT, "Temporary voice maintenance completed.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Temporary voice maintenance failed; check schema and database availability.\n");
    $failed = true;
} finally {
    $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
}
exit(empty($failed) ? 0 : 1);
