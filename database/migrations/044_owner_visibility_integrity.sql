-- eCollab server/channel ownership + visibility enforcement
-- Run after 043_server_channel_ownership_visibility.sql.
-- Idempotent: safe to run more than once.

SET @db = DATABASE();

-- Keep legacy is_private synchronized for existing channels.
UPDATE channels
SET visibility = CASE
    WHEN visibility IS NULL OR visibility = '' THEN
        CASE WHEN is_private = 1 THEN 'private' ELSE 'inherit' END
    ELSE visibility
END;

UPDATE channels
SET is_private = CASE WHEN visibility = 'private' THEN 1 ELSE 0 END;

-- Backfill channel ownership from the owning server.
UPDATE channels c
JOIN servers s ON s.id = c.server_id
SET c.owner_id = s.owner_id
WHERE c.owner_id IS NULL
  AND s.owner_id IS NOT NULL;

-- Backfill missing server visibility conservatively.
UPDATE servers
SET visibility = 'private'
WHERE visibility IS NULL OR visibility = '';

-- Index ownership/visibility columns when missing.
SET @q = IF(
 (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='servers' AND INDEX_NAME='idx_servers_owner')=0,
 'ALTER TABLE servers ADD INDEX idx_servers_owner (owner_id)',
 'SELECT 1'
); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = IF(
 (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='servers' AND INDEX_NAME='idx_servers_visibility')=0,
 'ALTER TABLE servers ADD INDEX idx_servers_visibility (visibility)',
 'SELECT 1'
); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = IF(
 (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='channels' AND INDEX_NAME='idx_channels_owner')=0,
 'ALTER TABLE channels ADD INDEX idx_channels_owner (owner_id)',
 'SELECT 1'
); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

SET @q = IF(
 (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='channels' AND INDEX_NAME='idx_channels_visibility')=0,
 'ALTER TABLE channels ADD INDEX idx_channels_visibility (visibility)',
 'SELECT 1'
); PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

-- Foreign keys are intentionally not added here.
-- Existing deployments may use different integer widths/signedness for users.id
-- versus servers.owner_id/channels.owner_id, which makes MySQL reject the FK.
-- Application-level ownership checks remain enforced, and the indexes above
-- still keep owner lookups efficient.
