-- DM and group-message attachments
-- Adds one optional attachment per DM/group message. Text remains optional only when an attachment exists.

ALTER TABLE dm_messages
  ADD COLUMN attachment_path VARCHAR(500) NULL AFTER body,
  ADD COLUMN attachment_name VARCHAR(255) NULL AFTER attachment_path,
  ADD COLUMN attachment_size BIGINT UNSIGNED NULL AFTER attachment_name,
  ADD COLUMN attachment_mime VARCHAR(150) NULL AFTER attachment_size;
