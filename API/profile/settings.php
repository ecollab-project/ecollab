<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json; charset=utf-8');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
$db = Database::getInstance();
$userId = (int)$user['id'];

$defaults = [
    'connection_requests'=>1,'direct_messages'=>1,'activity_status'=>1,'read_receipts'=>1,'screenshot_alerts'=>1,'ai_matching'=>1,
    'profile_visibility'=>'everyone','avatar_gradient'=>'#a855f7,#ec4899','theme'=>'dark','compact_mode'=>0,'reduce_motion'=>0,
    'high_contrast'=>0,'screen_reader_mode'=>0,'notification_desktop'=>1,'notification_messages'=>1,'notification_mentions'=>1,
    'notification_matches'=>1,'notification_sound'=>1,'input_device'=>null,'output_device'=>null,'mic_volume'=>100,'output_volume'=>100,
    'noise_suppression'=>1,'echo_cancellation'=>1,'auto_gain_control'=>1
];

function ensureSettings(PDO $db, int $userId, array $defaults): void {
    $stmt = $db->prepare('INSERT IGNORE INTO user_settings (user_id) VALUES (:id)');
    $stmt->execute([':id'=>$userId]);
}

function cleanSettings(array $row, array $defaults): array {
    $out=[];
    foreach ($defaults as $key=>$default) {
        $value=$row[$key] ?? $default;
        if (is_int($default)) $value=(int)$value;
        $out[$key]=$value;
    }
    return $out;
}

try {
    ensureSettings($db, $userId, $defaults);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt=$db->prepare('SELECT * FROM user_settings WHERE user_id=:id LIMIT 1');
        $stmt->execute([':id'=>$userId]);
        echo json_encode(['success'=>true,'settings'=>cleanSettings($stmt->fetch(PDO::FETCH_ASSOC) ?: [],$defaults)]);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }
    AuthMiddleware::verifyCsrf();
    $body=json_decode(file_get_contents('php://input'),true) ?? [];
    $allowed=array_keys($defaults);
    $updates=[]; $params=[':id'=>$userId];
    foreach ($allowed as $key) {
        if (!array_key_exists($key,$body)) continue;
        $value=$body[$key];
        if (is_int($defaults[$key])) {
            $value=(int)(bool)$value;
            if (in_array($key,['mic_volume','output_volume'],true)) $value=max(0,min(100,(int)$body[$key]));
        } else {
            $value=(string)$value;
            if ($key==='profile_visibility' && !in_array($value,['everyone','servers','connections'],true)) continue;
            if ($key==='theme' && !in_array($value,['dark','light','system'],true)) continue;
            if ($key==='avatar_gradient' && !preg_match('/^#[0-9a-fA-F]{6},#[0-9a-fA-F]{6}$/',$value)) continue;
            if (in_array($key,['input_device','output_device'],true)) $value=mb_substr($value,0,255);
        }
        $updates[]="`$key` = :$key"; $params[":$key"]=$value;
    }
    if ($updates) {
        $sql='UPDATE user_settings SET '.implode(', ',$updates).' WHERE user_id=:id LIMIT 1';
        $stmt=$db->prepare($sql); $stmt->execute($params);
    }
    $stmt=$db->prepare('SELECT * FROM user_settings WHERE user_id=:id LIMIT 1');
    $stmt->execute([':id'=>$userId]);
    echo json_encode(['success'=>true,'settings'=>cleanSettings($stmt->fetch(PDO::FETCH_ASSOC) ?: [],$defaults)]);
} catch (Throwable $e) {
    error_log('[profile/settings] '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['error'=>defined('APP_DEBUG')&&APP_DEBUG?$e->getMessage():'Server error']);
}
