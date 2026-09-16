INSERT INTO notifications
    (recipient_id, actor_id, type, title, body, link_url, icon, is_read, created_at, read_at)
SELECT
    r.addressee_id,
    r.requester_id,
    'connection_request',
    'New study partner request',
    'You received a new study partner request.',
    '/modules/chat/chat.php?view=study-partners',
    '🤝',
    0,
    COALESCE(r.created_at,CURRENT_TIMESTAMP),
    NULL
FROM pm_match_requests r
LEFT JOIN notifications n
    ON n.recipient_id=r.addressee_id
   AND n.actor_id=r.requester_id
   AND n.type='connection_request'
   AND n.created_at >= r.created_at
WHERE r.status='pending' AND n.id IS NULL;
