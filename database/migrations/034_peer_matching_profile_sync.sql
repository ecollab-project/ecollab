-- ============================================================
-- 034_peer_matching_profile_sync.sql
-- ============================================================
-- Keep the canonical signup profile tables and the peer-matching tables
-- synchronized. PeerMatchingService reads pm_user_interests and
-- pm_user_hobbies, while signup historically wrote user_interests and
-- user_hobbies only.
-- ============================================================

-- Backfill existing users first.
INSERT IGNORE INTO pm_user_interests (user_id, interest_id)
SELECT ui.user_id, pit.id
FROM user_interests ui
INNER JOIN interest_tags it ON it.id = ui.interest_tag_id
INNER JOIN pm_interest_tags pit ON pit.slug = it.slug;

INSERT IGNORE INTO pm_user_hobbies (user_id, hobby_id)
SELECT uh.user_id, pht.id
FROM user_hobbies uh
INNER JOIN pm_hobby_tags pht
  ON LOWER(TRIM(uh.hobby)) = LOWER(TRIM(pht.name))
  OR LOWER(REPLACE(TRIM(uh.hobby), ' ', '-')) = LOWER(pht.slug);

-- Create simple single-statement triggers dynamically so this migration is
-- safe to run on MySQL, where CREATE TRIGGER does not support IF NOT EXISTS.
SET @trg_sql = IF(
    (SELECT COUNT(*) FROM information_schema.TRIGGERS
     WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'ecollab_user_interests_pm_ai') = 0,
    'CREATE TRIGGER ecollab_user_interests_pm_ai AFTER INSERT ON user_interests FOR EACH ROW INSERT IGNORE INTO pm_user_interests (user_id, interest_id) SELECT NEW.user_id, pit.id FROM interest_tags it INNER JOIN pm_interest_tags pit ON pit.slug = it.slug WHERE it.id = NEW.interest_tag_id',
    'SELECT 1'
);
PREPARE trg_stmt FROM @trg_sql;
EXECUTE trg_stmt;
DEALLOCATE PREPARE trg_stmt;

SET @trg_sql = IF(
    (SELECT COUNT(*) FROM information_schema.TRIGGERS
     WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'ecollab_user_interests_pm_ad') = 0,
    'CREATE TRIGGER ecollab_user_interests_pm_ad AFTER DELETE ON user_interests FOR EACH ROW DELETE FROM pm_user_interests WHERE user_id = OLD.user_id AND interest_id IN (SELECT pit.id FROM pm_interest_tags pit INNER JOIN interest_tags it ON it.slug = pit.slug WHERE it.id = OLD.interest_tag_id)',
    'SELECT 1'
);
PREPARE trg_stmt FROM @trg_sql;
EXECUTE trg_stmt;
DEALLOCATE PREPARE trg_stmt;

SET @trg_sql = IF(
    (SELECT COUNT(*) FROM information_schema.TRIGGERS
     WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'ecollab_user_hobbies_pm_ai') = 0,
    'CREATE TRIGGER ecollab_user_hobbies_pm_ai AFTER INSERT ON user_hobbies FOR EACH ROW INSERT IGNORE INTO pm_user_hobbies (user_id, hobby_id) SELECT NEW.user_id, pht.id FROM pm_hobby_tags pht WHERE LOWER(TRIM(NEW.hobby)) = LOWER(TRIM(pht.name)) OR LOWER(REPLACE(TRIM(NEW.hobby), '' '', ''-'')) = LOWER(pht.slug)',
    'SELECT 1'
);
PREPARE trg_stmt FROM @trg_sql;
EXECUTE trg_stmt;
DEALLOCATE PREPARE trg_stmt;

SET @trg_sql = IF(
    (SELECT COUNT(*) FROM information_schema.TRIGGERS
     WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'ecollab_user_hobbies_pm_ad') = 0,
    'CREATE TRIGGER ecollab_user_hobbies_pm_ad AFTER DELETE ON user_hobbies FOR EACH ROW DELETE FROM pm_user_hobbies WHERE user_id = OLD.user_id AND hobby_id IN (SELECT pht.id FROM pm_hobby_tags pht WHERE LOWER(TRIM(OLD.hobby)) = LOWER(TRIM(pht.name)) OR LOWER(REPLACE(TRIM(OLD.hobby), '' '', ''-'')) = LOWER(pht.slug)',
    'SELECT 1'
);
PREPARE trg_stmt FROM @trg_sql;
EXECUTE trg_stmt;
DEALLOCATE PREPARE trg_stmt;
