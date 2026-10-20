<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

require_once dirname(__DIR__, 2) . '/services/UserPreferences.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
$db = Database::getInstance();
$userId = (int)$user['id'];
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();

$defaults = UserPreferences::defaults();

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
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt=$db->prepare('SELECT * FROM user_settings WHERE user_id=:id LIMIT 1');
        $stmt->execute([':id'=>$userId]);
        echo json_encode(['success'=>true,'settings'=>cleanSettings($stmt->fetch(PDO::FETCH_ASSOC) ?: [],$defaults)]);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }
    AuthMiddleware::verifyCsrf();
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    $raw=file_get_contents('php://input',false,null,0,32769);
    if(strlen($raw)>32768){http_response_code(422);echo json_encode(['error'=>'Settings request is too large']);exit;}
    $body=json_decode($raw,true);if(!is_array($body)){http_response_code(400);echo json_encode(['error'=>'Invalid settings request']);exit;}
    $allowed=array_keys($defaults);
    $updates=[]; $params=[':id'=>$userId];
    foreach ($allowed as $key) {
        if (!array_key_exists($key,$body)) continue;
        $value=$body[$key];
        if (is_array($value)||is_object($value)) {http_response_code(422);echo json_encode(['error'=>'Invalid setting value']);exit;}
        if ($key==='color_palette' && (!is_string($value) || !ColorPaletteService::valid($value))) { http_response_code(422); echo json_encode(['error'=>'Unknown color palette']); exit; }
        if (is_int($defaults[$key])) {
            $value=(int)(bool)$value;
            if (in_array($key,['mic_volume','output_volume','input_sensitivity'],true)) $value=max(0,min(100,(int)$body[$key]));
            if ($key==='font_scale') $value=max(80,min(140,(int)$body[$key]));
            if ($key==='sidebar_scale') $value=max(80,min(130,(int)$body[$key]));
        } else {
            $value=(string)$value;
            if ($key==='profile_visibility' && !in_array($value,['everyone','servers','connections'],true)) continue;
            if ($key==='theme' && !in_array($value,['dark','light','system'],true)) continue;
            if ($key==='jarred_saved_context') $value=mb_substr(trim($value),0,2000);
            if ($key==='avatar_gradient' && !preg_match('/^#[0-9a-fA-F]{6},#[0-9a-fA-F]{6}$/',$value)) continue;
            if ($key==='message_density' && !in_array($value,['compact','cozy','spacious'],true)) continue;
            if ($key==='voice_mode' && !in_array($value,['voice_activity','push_to_talk'],true)) continue;
            if ($key==='jarred_response_style' && !in_array($value,['concise','balanced','detailed'],true)) continue;
            if (in_array($key,['input_device','output_device','camera_device'],true)) $value=mb_substr($value,0,255);
        }
        $updates[]="`$key` = :$key"; $params[":$key"]=$value;
    }
    if ($updates) {
        ensureSettings($db,$userId,$defaults);
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
