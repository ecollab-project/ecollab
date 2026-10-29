-- A-AD-1: isolated fresh synthetic fixtures ONLY. No unknown-account backfill.
-- The dedicated owned bootstrap must acknowledge this boundary on this connection.
SET @identity_sql = IF(COALESCE(@phase3ad_owned_fresh_identity,0)=1, 'DO 0', 'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Fresh owned identity authorization required''');
PREPARE identity_stmt FROM @identity_sql;
EXECUTE identity_stmt;
DEALLOCATE PREPARE identity_stmt;
SET @identity_lock = CONCAT('ec-id069-',LEFT(SHA2(DATABASE(),256),32));
SET @identity_locked = GET_LOCK(@identity_lock,10);
SET @identity_ok = COALESCE(@identity_locked,0)=1
 AND DATABASE()='classroom_perf_test'
 AND EXISTS(SELECT 1 FROM schema_migrations WHERE filename='002_core_schema.sql' AND checksum='9fc0f0f2e9d0161fed090ece3516eaccfba7318364ddacbb5e14c690fa5d728e' AND success=1)
 AND (SELECT COUNT(*) FROM users)=10
 AND (SELECT COUNT(*) FROM users WHERE (id=1 AND BINARY username=BINARY 'super_admin' AND BINARY email=BINARY 'admin@fatima.edu.ph' AND BINARY student_id=BINARY 'ADMIN-001' AND institution_id=1 AND deleted_at IS NULL) OR
    (id=2 AND BINARY username=BINARY 'mod_carlos' AND BINARY email=BINARY 'carlos.reyes@fatima.edu.ph' AND BINARY student_id=BINARY 'MOD-001' AND institution_id=1 AND deleted_at IS NULL) OR
    (id=3 AND BINARY username=BINARY 'adam_smith' AND BINARY email=BINARY 'adam.smith@fatima.edu.ph' AND BINARY student_id=BINARY 'FAC-001' AND institution_id=1 AND deleted_at IS NULL) OR
    (id=4 AND BINARY username=BINARY 'prof_santos' AND BINARY email=BINARY 'maria.santos@fatima.edu.ph' AND BINARY student_id=BINARY 'FAC-002' AND institution_id=1 AND deleted_at IS NULL) OR
    (id=5 AND BINARY username=BINARY 'fatima_student' AND BINARY email=BINARY 'fatima.student@fatima.edu.ph' AND BINARY student_id=BINARY '2025-001' AND institution_id=1 AND deleted_at IS NULL) OR
    (id=6 AND BINARY username=BINARY 'john_doe' AND BINARY email=BINARY 'john.doe@fatima.edu.ph' AND BINARY student_id=BINARY '2025-002' AND institution_id=1 AND deleted_at IS NULL) OR
    (id=7 AND BINARY username=BINARY 'sara_kim' AND BINARY email=BINARY 'sara.kim@fatima.edu.ph' AND BINARY student_id=BINARY '2025-003' AND institution_id=1 AND deleted_at IS NULL) OR
    (id=8 AND BINARY username=BINARY 'mike_lee' AND BINARY email=BINARY 'mike.lee@fatima.edu.ph' AND BINARY student_id=BINARY '2025-004' AND institution_id=1 AND deleted_at IS NULL) OR
    (id=9 AND BINARY username=BINARY 'david_wilson' AND BINARY email=BINARY 'david.wilson@fatima.edu.ph' AND BINARY student_id=BINARY '2025-005' AND institution_id=1 AND deleted_at IS NULL) OR
    (id=10 AND BINARY username=BINARY 'leyla_ahmed' AND BINARY email=BINARY 'leyla.ahmed@fatima.edu.ph' AND BINARY student_id=BINARY '2025-006' AND institution_id=1 AND deleted_at IS NULL))=10
 AND EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='users' AND engine='InnoDB');
SET @identity_sql = IF(COALESCE(@identity_ok,0), 'DO 0', 'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Unknown seed identity or provenance - no classification permitted''');
PREPARE identity_stmt FROM @identity_sql;
EXECUTE identity_stmt;
DEALLOCATE PREPARE identity_stmt;
SET @identity_exists = EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='is_system');
SET @identity_shape = EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='is_system' AND BINARY column_type=BINARY 'tinyint(1)' AND is_nullable='NO' AND TRIM(BOTH '''' FROM column_default)='0' AND extra='' AND column_comment='');
SET @identity_constraint = EXISTS(SELECT 1 FROM information_schema.check_constraints WHERE constraint_schema=DATABASE() AND table_name='users' AND constraint_name='chk_users_is_system' AND REPLACE(REPLACE(REPLACE(REPLACE(LOWER(check_clause),'`',''),' ',''),'(',''),')','')='is_systemin0,1');
SET @identity_sql = IF(@identity_exists=0 OR (@identity_shape AND @identity_constraint AND EXISTS(SELECT 1 FROM schema_migrations WHERE filename='069_system_identity.sql' AND success=1)), 'DO 0', 'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Unverified existing identity schema - review required''');
PREPARE identity_stmt FROM @identity_sql;
EXECUTE identity_stmt;
DEALLOCATE PREPARE identity_stmt;
SET @identity_sql = IF(@identity_exists=0, 'ALTER TABLE users ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0, ADD CONSTRAINT chk_users_is_system CHECK (is_system IN (0,1))', 'DO 0');
PREPARE identity_stmt FROM @identity_sql;
EXECUTE identity_stmt;
DEALLOCATE PREPARE identity_stmt;
-- On rerun, canonical seed humans must still be human. Never rewrite a flag.
SET @identity_sql = IF((SELECT COUNT(*) FROM users WHERE is_system=0)=10, 'DO 0', 'SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Conflicting seed identity flags''');
PREPARE identity_stmt FROM @identity_sql;
EXECUTE identity_stmt;
DEALLOCATE PREPARE identity_stmt;
SELECT RELEASE_LOCK(@identity_lock);
