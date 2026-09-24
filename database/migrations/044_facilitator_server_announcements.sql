-- eCollab facilitator server management rollout
-- Adds the required announcement channel to existing servers.
-- New servers are handled by API/chat/create-server.php.

INSERT INTO channels
    (server_id, name, slug, type, description, position, is_private, is_locked, created_by)
SELECT
    s.id,
    'announcements',
    'announcements',
    'announcement',
    'Official server announcements (view only)',
    0,
    0,
    0,
    s.owner_id
FROM servers s
WHERE NOT EXISTS (
    SELECT 1
    FROM channels c
    WHERE c.server_id = s.id
      AND (c.type = 'announcement' OR c.slug = 'announcements')
);
