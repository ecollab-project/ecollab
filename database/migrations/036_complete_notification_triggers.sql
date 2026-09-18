-- ============================================================
-- 036_complete_notification_triggers.sql
-- Complete server-side notification coverage for Ecollab.
-- Uses single-statement triggers because the migration runner does
-- not support MySQL DELIMITER / BEGIN...END trigger bodies.
-- ============================================================

-- Thread post comment: notify the thread owner when another user replies.
DROP TRIGGER IF EXISTS trg_thread_reply_notification;
CREATE TRIGGER trg_thread_reply_notification
AFTER INSERT ON thread_replies
FOR EACH ROW
INSERT INTO notifications
    (recipient_id, actor_id, type, title, body, link_url, icon, is_read, created_at, read_at)
SELECT
    t.created_by,
    NEW.created_by,
    'thread_comment',
    'Thread Post Comment',
    CONCAT(COALESCE(u.full_name, u.username, 'Someone'), ' commented on your thread post'),
    CONCAT('/modules/chat/chat.php?thread_id=', NEW.thread_id, '&open_comments=1'),
    '💬',
    0,
    CURRENT_TIMESTAMP,
    NULL
FROM threads t
JOIN users u ON u.id = NEW.created_by
WHERE t.id = NEW.thread_id
  AND t.created_by <> NEW.created_by
  AND NEW.is_deleted = 0;

-- Reply to a specific comment: notify the parent comment author.
DROP TRIGGER IF EXISTS trg_thread_nested_reply_notification;
CREATE TRIGGER trg_thread_nested_reply_notification
AFTER INSERT ON thread_replies
FOR EACH ROW
INSERT INTO notifications
    (recipient_id, actor_id, type, title, body, link_url, icon, is_read, created_at, read_at)
SELECT
    parent.created_by,
    NEW.created_by,
    'thread_reply',
    'Someone replied to your comment',
    CONCAT(COALESCE(actor.full_name, actor.username, 'Someone'), ' replied to your comment'),
    CONCAT('/modules/chat/chat.php?thread_id=', NEW.thread_id, '&open_comments=1&reply_id=', NEW.id),
    '↩️',
    0,
    CURRENT_TIMESTAMP,
    NULL
FROM thread_replies parent
JOIN users actor ON actor.id = NEW.created_by
WHERE parent.id = NEW.parent_reply_id
  AND parent.created_by <> NEW.created_by
  AND NEW.parent_reply_id IS NOT NULL
  AND NEW.is_deleted = 0;

-- Normal connection request notification.
DROP TRIGGER IF EXISTS trg_friendship_request_notification;
CREATE TRIGGER trg_friendship_request_notification
AFTER INSERT ON friendships
FOR EACH ROW
INSERT INTO notifications
    (recipient_id, actor_id, type, title, body, link_url, icon, is_read, created_at, read_at)
SELECT
    NEW.addressee_id,
    NEW.requester_id,
    'connection_request',
    'Connection Request',
    CONCAT(COALESCE(u.full_name, u.username, 'Someone'), ' requested a connection'),
    '/modules/chat/chat.php?view=study-partners&open_requests=1',
    '🤝',
    0,
    CURRENT_TIMESTAMP,
    NULL
FROM users u
WHERE u.id = NEW.requester_id
  AND NEW.status = 'pending';

-- Accepted normal connection notification.
DROP TRIGGER IF EXISTS trg_friendship_accepted_notification;
CREATE TRIGGER trg_friendship_accepted_notification
AFTER UPDATE ON friendships
FOR EACH ROW
INSERT INTO notifications
    (recipient_id, actor_id, type, title, body, link_url, icon, is_read, created_at, read_at)
SELECT
    NEW.requester_id,
    NEW.addressee_id,
    'connection_accepted',
    'Connection Accepted',
    CONCAT(COALESCE(u.full_name, u.username, 'Someone'), ' accepted your connection request'),
    '/modules/chat/chat.php?view=study-partners',
    '✅',
    0,
    CURRENT_TIMESTAMP,
    NULL
FROM users u
WHERE u.id = NEW.addressee_id
  AND OLD.status <> 'accepted'
  AND NEW.status = 'accepted';
