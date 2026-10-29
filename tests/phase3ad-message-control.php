<?php
declare(strict_types=1);
require_once __DIR__.'/phase3y-bootstrap.php';
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
switch($in['action']) {
case 'inspect':
    echo json_encode(['column'=>$db->query("SHOW FULL COLUMNS FROM messages WHERE Field='content_type'")->fetch(),
        'counts'=>['messages'=>(int)$db->query('SELECT COUNT(*) FROM messages')->fetchColumn(),
        'polls'=>(int)$db->query('SELECT COUNT(*) FROM polls')->fetchColumn(),
        'options'=>(int)$db->query('SELECT COUNT(*) FROM poll_options')->fetchColumn()],
        'business'=>array_map(fn($t)=>$db->query('CHECKSUM TABLE '.$t)->fetch(PDO::FETCH_NUM)[1],['messages','polls','poll_options','message_attachments','realtime_outbox'])],JSON_THROW_ON_ERROR);
    break;
case 'inject':
    $db->exec("CREATE TRIGGER phase3ad_poll_failure BEFORE INSERT ON poll_options FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned poll insert failure'");
    echo '{}'; break;
case 'release':
    $db->exec('DROP TRIGGER IF EXISTS phase3ad_poll_failure'); echo '{}'; break;
default: throw new RuntimeException('Unknown owned message control');
}
