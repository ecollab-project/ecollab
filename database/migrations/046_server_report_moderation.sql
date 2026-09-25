-- Adds server suspension as a first-class moderation action.
-- Existing databases may already have moderation_actions; MODIFY preserves current rows.
ALTER TABLE moderation_actions
  MODIFY action_type ENUM('ban','kick','mute','suspend','warn','unmute','unsuspend','unban','delete_message') NOT NULL;
