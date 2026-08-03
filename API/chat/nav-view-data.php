<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);
try {
    $db   = Database::getInstance();
    $view = $_GET['view'] ?? 'mentions';
    $uid  = $user['id'];
    $items = [];
    if ($view === 'mentions') {
        $stmt = $db->prepare("
            SELECT m.id, m.content, m.created_at, u.username AS author, u.full_name,
                   u.avatar_color_gradient AS grad, c.name AS channel, s.name AS server
            FROM messages m
            JOIN users u ON u.id = m.sender_id
            JOIN channels c ON c.id = m.channel_id
            JOIN servers s ON s.id = c.server_id
            JOIN server_members sm ON sm.server_id = s.id AND sm.user_id = :uid
            WHERE m.content LIKE :mention AND m.is_deleted = 0 AND m.sender_id != :uid2
            ORDER BY m.created_at DESC LIMIT 20
        ");
        $stmt->execute([':uid'=>$uid,':uid2'=>$uid,':mention'=>'%@' . $user['username'] . '%']);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            $items[] = ['server'=>$r['server'],'channel'=>$r['channel'],'time'=>date('g:i A',strtotime($r['created_at'])),'author'=>$r['author'],'letter'=>strtoupper(($r['full_name']?:$r['author'])[0]),'text'=>$r['content'],'grad'=>$r['grad']??'#3b82f6,#6366f1'];
        }
    } elseif ($view === 'bookmarks') {
        // bookmarks via message_reads or a dedicated table - show pinned messages for now
        $stmt = $db->prepare("
            SELECT m.id, m.content, m.created_at, u.username AS author, u.full_name,
                   u.avatar_color_gradient AS grad, c.name AS channel, s.name AS server
            FROM messages m
            JOIN users u ON u.id = m.sender_id
            JOIN channels c ON c.id = m.channel_id
            JOIN servers s ON s.id = c.server_id
            JOIN server_members sm ON sm.server_id = s.id AND sm.user_id = :uid
            WHERE m.is_pinned = 1 AND m.is_deleted = 0
            ORDER BY m.created_at DESC LIMIT 20
        ");
        $stmt->execute([':uid'=>$uid]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            $items[] = ['server'=>$r['server'],'channel'=>$r['channel'],'time'=>date('M j',strtotime($r['created_at'])),'author'=>$r['author'],'letter'=>strtoupper(($r['full_name']?:$r['author'])[0]),'text'=>$r['content'],'grad'=>$r['grad']??'#a855f7,#ec4899'];
        }
    } elseif ($view === 'threads') {
        // Messages with replies (parent_id IS NULL but have children)
        $stmt = $db->prepare("
            SELECT m.id, m.content, m.created_at, u.username AS author, u.full_name,
                   u.avatar_color_gradient AS grad, c.name AS channel, s.name AS server,
                   COUNT(r.id) AS reply_count,
                   MAX(r.content) AS last_reply
            FROM messages m
            JOIN users u ON u.id = m.sender_id
            JOIN channels c ON c.id = m.channel_id
            JOIN servers s ON s.id = c.server_id
            JOIN server_members sm ON sm.server_id = s.id AND sm.user_id = :uid
            LEFT JOIN messages r ON r.parent_id = m.id AND r.is_deleted = 0
            WHERE m.parent_id IS NULL AND m.is_deleted = 0
              AND (m.sender_id = :uid2 OR r.sender_id = :uid3)
            GROUP BY m.id
            HAVING reply_count > 0
            ORDER BY m.created_at DESC LIMIT 20
        ");
        $stmt->execute([':uid'=>$uid,':uid2'=>$uid,':uid3'=>$uid]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            $items[] = ['server'=>$r['server'],'channel'=>$r['channel'],'time'=>date('g:i A',strtotime($r['created_at'])),'author'=>$r['author'],'letter'=>strtoupper(($r['full_name']?:$r['author'])[0]),'text'=>$r['content'],'replies'=>(int)$r['reply_count'],'lastReply'=>$r['last_reply']??'','grad'=>$r['grad']??'#ec4899,#f43f5e'];
        }
    } elseif ($view === 'drafts') {
        // No drafts table yet - return empty (drafts are a future feature)
        $items = [];
    }
    echo json_encode(['success'=>true,'items'=>$items]);
} catch (Throwable $e) {
    error_log('[nav-view-data] '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'items'=>[],'error'=>defined('APP_DEBUG')&&APP_DEBUG?$e->getMessage():'Server error']);
}
