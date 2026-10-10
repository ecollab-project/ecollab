-- ============================================================
-- 035_peer_request_notifications.sql
-- Create a notification whenever a study-partner request is created.
--
-- IMPORTANT: Keep the trigger body as a single SQL statement.
-- database/migrate.php executes statements without MySQL DELIMITER
-- support, so BEGIN ... END trigger bodies are not migration-runner safe.
-- ============================================================

DROP TRIGGER IF EXISTS trg_pm_match_request_notification;

CREATE TRIGGER trg_pm_match_request_notification
AFTER INSERT ON pm_match_requests
FOR EACH ROW
INSERT INTO notifications
    (recipient_id, actor_id, type, title, body, link_url, icon, is_read, created_at, read_at)
SELECT
    NEW.addressee_id,
    NEW.requester_id,
    'connection_request',
    'New study partner request',
    'You received a new study partner request.',
    '/modules/chat/chat.php?view=study-partners',
    '🤝',
    0,
    CURRENT_TIMESTAMP,
    NULL
WHERE NEW.status = 'pending';
