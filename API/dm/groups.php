<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$me = AuthMiddleware::requireAuth(true);
$uid = (int)$me['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = (string)($_GET['action'] ?? '');
$body = $method === 'GET' ? [] : (json_decode(file_get_contents('php://input'), true) ?? []);

if ($method !== 'GET') {
    AuthMiddleware::verifyCsrf();
}

function dmGroupFail(string $msg, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function dmGroupDisplayName(array $members, int $selfId): string
{
    $others = array_values(array_filter($members, fn($m) => (int)$m['id'] !== $selfId));
    $names = array_map(fn($m) => (string)($m['full_name'] ?: $m['username']), array_slice($others, 0, 2));
    $label = implode(', ', $names);
    $remaining = count($others) - count($names);
    if ($remaining > 0) {
        $label .= ' +' . $remaining;
    }
    return $label !== '' ? $label : 'Group';
}

try {
    $db = Database::getInstance();

    if ($action === 'create' && $method === 'POST') {
        $memberIds = array_values(array_unique(array_map('intval', $body['member_ids'] ?? [])));
        $memberIds = array_filter($memberIds, fn($id) => $id > 0 && $id !== $uid);
        if (count($memberIds) < 2) {
            dmGroupFail('A group needs at least 2 other members (3 people total). For just one other person, use a regular direct message.');
        }
        if (count($memberIds) > 24) {
            dmGroupFail('Groups are limited to 25 members.');
        }

        $name = trim((string)($body['name'] ?? ''));
        $name = $name !== '' ? mb_substr($name, 0, 100) : null;

        // Confirm every target user actually exists and isn't deleted/banned
        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $check = $db->prepare("SELECT id FROM users WHERE id IN ($placeholders) AND deleted_at IS NULL AND status != 'banned'");
        $check->execute($memberIds);
        $validIds = array_map('intval', $check->fetchAll(PDO::FETCH_COLUMN));
        if (count($validIds) !== count($memberIds)) {
            dmGroupFail('One or more selected users are unavailable.');
        }

        $db->beginTransaction();
        $db->prepare('INSERT INTO dm_groups (name, created_by) VALUES (:name, :uid)')
            ->execute([':name' => $name, ':uid' => $uid]);
        $groupId = (int)$db->lastInsertId();

        $addMember = $db->prepare('INSERT INTO dm_group_members (group_id, user_id, role) VALUES (:gid, :uid, :role)');
        $addMember->execute([':gid' => $groupId, ':uid' => $uid, ':role' => 'owner']);
        foreach ($validIds as $mid) {
            $addMember->execute([':gid' => $groupId, ':uid' => $mid, ':role' => 'member']);
        }
        $db->commit();

        echo json_encode(['success' => true, 'group_id' => $groupId]);
        exit;
    }

    if ($action === 'list' && $method === 'GET') {
        $stmt = $db->prepare("
            SELECT g.id, g.name, g.last_message, g.last_msg_at, g.created_at
            FROM dm_groups g
            JOIN dm_group_members gm ON gm.group_id = g.id AND gm.user_id = :uid
            ORDER BY COALESCE(g.last_msg_at, g.created_at) DESC
        ");
        $stmt->execute([':uid' => $uid]);
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($groups) {
            $ids = array_column($groups, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $memStmt = $db->prepare("
                SELECT gm.group_id, u.id, u.username, u.full_name, u.avatar_color_gradient
                FROM dm_group_members gm
                JOIN users u ON u.id = gm.user_id
                WHERE gm.group_id IN ($placeholders)
            ");
            $memStmt->execute($ids);
            $byGroup = [];
            foreach ($memStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $byGroup[$row['group_id']][] = $row;
            }
            foreach ($groups as &$g) {
                $members = $byGroup[$g['id']] ?? [];
                $g['members'] = $members;
                $g['display_name'] = $g['name'] ?: dmGroupDisplayName($members, $uid);
            }
            unset($g);
        }

        echo json_encode(['success' => true, 'groups' => $groups]);
        exit;
    }

    if ($action === 'add_member' && $method === 'POST') {
        $groupId = (int)($body['group_id'] ?? 0);
        $targetId = (int)($body['user_id'] ?? 0);
        if (!$groupId || !$targetId) dmGroupFail('group_id and user_id required');

        $mem = $db->prepare('SELECT role FROM dm_group_members WHERE group_id = :gid AND user_id = :uid');
        $mem->execute([':gid' => $groupId, ':uid' => $uid]);
        if (!$mem->fetchColumn()) dmGroupFail('You are not a member of this group', 403);

        $count = $db->prepare('SELECT COUNT(*) FROM dm_group_members WHERE group_id = :gid');
        $count->execute([':gid' => $groupId]);
        if ((int)$count->fetchColumn() >= 25) dmGroupFail('Groups are limited to 25 members.');

        $exists = $db->prepare("SELECT 1 FROM users WHERE id = :id AND deleted_at IS NULL AND status != 'banned'");
        $exists->execute([':id' => $targetId]);
        if (!$exists->fetchColumn()) dmGroupFail('User not found', 404);

        $db->prepare('INSERT IGNORE INTO dm_group_members (group_id, user_id, role) VALUES (:gid, :uid, "member")')
            ->execute([':gid' => $groupId, ':uid' => $targetId]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'leave' && $method === 'POST') {
        $groupId = (int)($body['group_id'] ?? 0);
        if (!$groupId) dmGroupFail('group_id required');
        $db->prepare('DELETE FROM dm_group_members WHERE group_id = :gid AND user_id = :uid')
            ->execute([':gid' => $groupId, ':uid' => $uid]);
        $remaining = $db->prepare('SELECT COUNT(*) FROM dm_group_members WHERE group_id = :gid');
        $remaining->execute([':gid' => $groupId]);
        if ((int)$remaining->fetchColumn() === 0) {
            $db->prepare('DELETE FROM dm_groups WHERE id = :gid')->execute([':gid' => $groupId]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'rename' && $method === 'POST') {
        $groupId = (int)($body['group_id'] ?? 0);
        $name = trim((string)($body['name'] ?? ''));
        if (!$groupId) dmGroupFail('group_id required');
        $mem = $db->prepare('SELECT 1 FROM dm_group_members WHERE group_id = :gid AND user_id = :uid');
        $mem->execute([':gid' => $groupId, ':uid' => $uid]);
        if (!$mem->fetchColumn()) dmGroupFail('You are not a member of this group', 403);
        $db->prepare('UPDATE dm_groups SET name = :name WHERE id = :gid')
            ->execute([':name' => $name !== '' ? mb_substr($name, 0, 100) : null, ':gid' => $groupId]);
        echo json_encode(['success' => true]);
        exit;
    }

    dmGroupFail('Unknown action', 404);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[dm/groups] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => (defined('APP_DEBUG') && APP_DEBUG) ? $e->getMessage() : 'Server error']);
}
