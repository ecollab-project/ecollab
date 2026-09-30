-- Add notification types used by the thread reply notification triggers.
-- Idempotent: redefining the ENUM with the complete desired set is safe to rerun.
ALTER TABLE notifications
  MODIFY COLUMN type ENUM(
    'message',
    'mention',
    'room_invite',
    'class_update',
    'server_join',
    'moderation',
    'ai',
    'system',
    'match',
    'connection_request',
    'connection_accepted',
    'thread_comment',
    'thread_reply'
  ) NOT NULL DEFAULT 'system';
