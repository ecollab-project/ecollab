<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/LiveKitService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    AuthMiddleware::startSession();
    $user = AuthMiddleware::requireAuth(true);

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    AuthMiddleware::verifyCsrf();
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid JSON body.');
    }

    $groupId = (int)($input['group_id'] ?? 0);
    if ($groupId <= 0) {
        throw new InvalidArgumentException('A valid group_id is required.');
    }

    $db = Database::getInstance();
    $stmt = $db->prepare(
        "SELECT g.id, g.name
           FROM dm_groups g
           JOIN dm_group_members gm ON gm.group_id=g.id
          WHERE g.id=:gid AND gm.user_id=:uid
          LIMIT 1"
    );
    $stmt->execute([':gid' => $groupId, ':uid' => (int)$user['id']]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$group) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You are not a member of this group.']);
        exit;
    }

    $roomName = trim((string)($group['name'] ?? ''));
    if ($roomName === '') {
        $roomName = 'Group Call';
    }

    session_write_close();
    $livekit = new LiveKitService();
    $room = 'ecollab-dm-group-v2-' . $groupId;
    $livekit->ensureRoom($room, 50);
    $result = $livekit->issueNamedRoomToken(
        $user,
        $room,
        $roomName,
        ['ecollab_dm_group_id' => $groupId, 'call_type' => 'dm_group']
    );

    echo json_encode(['success' => true] + $result, JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[dm-group-livekit-token] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Group call service is temporarily unavailable.']);
}

