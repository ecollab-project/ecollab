-- eCollab ownership + visibility normalization
-- Safe additive migration for existing deployments.
SET @db = DATABASE();

SET @q = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='servers' AND COLUMN_NAME='owner_id')=0,
'ALTER TABLE servers ADD COLUMN owner_id BIGINT UNSIGNED NULL AFTER id','SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='servers' AND COLUMN_NAME='visibility')=0,
"ALTER TABLE servers ADD COLUMN visibility ENUM('public','private') NOT NULL DEFAULT 'private'",'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='channels' AND COLUMN_NAME='owner_id')=0,
'ALTER TABLE channels ADD COLUMN owner_id BIGINT UNSIGNED NULL AFTER id','SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;
SET @q = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='channels' AND COLUMN_NAME='visibility')=0,
"ALTER TABLE channels ADD COLUMN visibility ENUM('public','private','inherit') NOT NULL DEFAULT 'inherit'",'SELECT 1'); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Existing deployments may not have a role column in server_members.
-- Only backfill server ownership from server_members when that column exists.
SET @has_sm_role = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='server_members' AND COLUMN_NAME='role');
SET @q = IF(@has_sm_role>0,
"UPDATE servers s JOIN (SELECT server_id, MIN(user_id) user_id FROM server_members WHERE role IN ('owner','host') GROUP BY server_id) sm ON sm.server_id=s.id SET s.owner_id=COALESCE(s.owner_id,sm.user_id) WHERE s.owner_id IS NULL",
"SELECT 1");
PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE channels c
JOIN servers s ON s.id=c.server_id
SET c.owner_id=COALESCE(c.owner_id,s.owner_id)
WHERE c.owner_id IS NULL;
