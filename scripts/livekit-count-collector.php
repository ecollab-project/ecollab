<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/database/config/db.php';
require_once dirname(__DIR__).'/services/LiveKitService.php';
require_once dirname(__DIR__).'/services/LiveKitCountSnapshot.php';
$db = Database::getInstance();
$name = 'ec-lk-count-'.hash('sha224', (string)$db->query('SELECT DATABASE()')->fetchColumn());
$q = $db->prepare('SELECT GET_LOCK(?,0)'); $q->execute([$name]);
if ((int)$q->fetchColumn() !== 1) exit(0);
$interval = max(5, min(60, (int)(getenv('LIVEKIT_COUNT_INTERVAL') ?: 5)));
$once = in_array('--once', $argv, true);
try {
    do {
        $owned=$db->prepare('SELECT IS_USED_LOCK(?)=CONNECTION_ID()');$owned->execute([$name]);
        if((int)$owned->fetchColumn()!==1)throw new RuntimeException('Collector lock lost');
        $started = microtime(true);
        try {
            // One bounded RPC per collection, including all rooms; only channel counts are retained.
            $result = (new LiveKitService())->roomRequest('ListRooms', ['names'=>[]], ['roomList'=>true]);
            (new LiveKitCountSnapshot($db))->publish($result, (int)$started);
            fwrite(STDOUT, json_encode(['metric'=>'livekit_count_collection','rpc_count'=>1,'ok'=>true,'duration_ms'=>round((microtime(true)-$started)*1000)])."\n");
        } catch (Throwable $e) {
            // Keep last snapshot, allowing readers to report unavailable once its freshness expires.
            fwrite(STDERR, "{\"metric\":\"livekit_count_collection\",\"rpc_count\":1,\"ok\":false}\n");
            if ($once) exit(1);
        }
        if (!$once) usleep((int)(max(0.1, $interval-(microtime(true)-$started))*1000000));
    } while (!$once);
} finally { $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]); }
