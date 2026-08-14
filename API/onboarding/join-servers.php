<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/security/csrf/csrf.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
AuthMiddleware::requireAuth(true);
CSRF::verify();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$db     = Database::getInstance();
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

// Validate server IDs
$serverIds = array_filter(
    array_map('intval', (array)($body['server_ids'] ?? [])),
    fn($id) => $id > 0
);

if (empty($serverIds)) {
    echo json_encode(['success' => true, 'joined' => 0]);
    exit;
}

// Cap at 10 joins per request
$serverIds = array_slice(array_unique($serverIds), 0, 10);

try {
    // Only join servers that are actually public/institution and active
    $placeholders = implode(',', array_fill(0, count($serverIds), '?'));
    $validStmt = $db->prepare("
        SELECT id FROM servers
        WHERE id IN ($placeholders)
          AND status = 'active'
          AND type IN ('public', 'institution')
    ");
    $validStmt->execute($serverIds);
    $validIds = $validStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($validIds)) {
        echo json_encode(['success' => true, 'joined' => 0]);
        exit;
    }

    $db->beginTransaction();

    $joinStmt = $db->prepare("
        INSERT IGNORE INTO server_members (server_id, user_id, server_role, joined_at)
        VALUES (:sid, :uid, 'member', NOW())
    ");
    $countStmt = $db->prepare("
        UPDATE servers
        SET member_count = member_count + 1
        WHERE id = :sid
          AND NOT EXISTS (
              SELECT 1 FROM server_members
              WHERE server_id = :sid2 AND user_id = :uid2
              LIMIT 1
          )
    ");

    $joined = 0;
    foreach ($validIds as $sid) {
        // Check not already a member
        $checkStmt = $db->prepare("
            SELECT 1 FROM server_members WHERE server_id = :sid AND user_id = :uid LIMIT 1
        ");
        $checkStmt->execute([':sid' => $sid, ':uid' => $userId]);
        if ($checkStmt->fetchColumn()) continue;

        $joinStmt->execute([':sid' => $sid, ':uid' => $userId]);
        $db->prepare("UPDATE servers SET member_count = member_count + 1 WHERE id = :sid")
           ->execute([':sid' => $sid]);
        $joined++;
    }

    $db->commit();

    // Mark onboarding as complete in session so we don't redirect again
    $_SESSION['onboarding_done'] = true;

    echo json_encode([
        'success'  => true,
        'joined'   => $joined,
        'redirect' => BASE_URL . '/modules/chat/chat.php',
    ]);

} catch (Throwable $e) {
    $db->rollBack();
    error_log('[onboarding/join-servers] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not join servers. Please try again.']);
}
