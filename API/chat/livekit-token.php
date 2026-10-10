<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/ChannelService.php';
require_once dirname(__DIR__, 2) . '/services/LiveKitService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    AuthMiddleware::startSession();
    $user = AuthMiddleware::requireAuth(true);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    AuthMiddleware::verifyCsrf();

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new InvalidArgumentException('Invalid JSON body.');
    }

    $channelId = (int)($input['channel_id'] ?? 0);
    if ($channelId <= 0) {
        throw new InvalidArgumentException('A valid channel_id is required.');
    }

    $channels = new ChannelService();
    $channel = $channels->getChannel($channelId, (int)$user['id']);
    if (!$channel || ($channel['type'] ?? '') !== 'voice') {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'You cannot join this voice channel.']);
        exit;
    }

    // Private-channel authorization must be stricter than server membership.
    if (!empty($channel['is_private']) && !in_array((string)($user['role'] ?? ''), ['admin', 'super_admin'], true)) {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT 1
               FROM channels c
               JOIN server_members sm ON sm.server_id=c.server_id AND sm.user_id=:uid
              WHERE c.id=:cid
                AND (
                    EXISTS (SELECT 1 FROM channel_members cm WHERE cm.channel_id=c.id AND cm.user_id=:uid2)
                    OR sm.server_role IN ('owner','admin')
                    OR c.created_by=:uid3
                )
              LIMIT 1"
        );
        $stmt->execute([':uid'=>(int)$user['id'], ':uid2'=>(int)$user['id'], ':uid3'=>(int)$user['id'], ':cid'=>$channelId]);
        if (!$stmt->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'You cannot join this private voice channel.']);
            exit;
        }
    }

    $livekit = new LiveKitService();
    $result = $livekit->issueRoomToken($user, $channelId, (string)$channel['name']);
    echo json_encode(['success' => true] + $result, JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[livekit-token] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Voice service is temporarily unavailable.']);
}


