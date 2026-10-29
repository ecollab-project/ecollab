-- Preserve existing enum ordinals; append the two already-supported API types.
-- Refuse unexpected shapes rather than narrowing an installation-specific enum.
SET @msg_lock = CONCAT('ec-msg068-', LEFT(SHA2(DATABASE(),256),32));
SET @msg_locked = GET_LOCK(@msg_lock,10);
SET @msg_sql = IF(COALESCE(@msg_locked,0)=1,'DO 0','SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Message enum migration lock unavailable''');
PREPARE msg_stmt FROM @msg_sql;
EXECUTE msg_stmt;
DEALLOCATE PREPARE msg_stmt;
SET @msg_old = 'enum(''text'',''image'',''file'',''system'',''ai_response'')';
SET @msg_new = 'enum(''text'',''image'',''file'',''system'',''ai_response'',''code'',''poll'')';
SET @msg_type = (SELECT column_type FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='messages' AND column_name='content_type');
SET @msg_charset = (SELECT character_set_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='messages' AND column_name='content_type');
SET @msg_collation = (SELECT collation_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='messages' AND column_name='content_type');
SET @msg_ok = EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='messages' AND column_name='content_type' AND BINARY column_type IN (BINARY @msg_old,BINARY @msg_new) AND is_nullable='NO' AND BINARY TRIM(BOTH '''' FROM column_default)='text' AND extra='' AND column_comment='');
SET @msg_ok = @msg_ok AND EXISTS(SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='messages' AND engine='InnoDB') AND @msg_charset REGEXP '^[a-zA-Z0-9_]+$' AND @msg_collation REGEXP '^[a-zA-Z0-9_]+$';
SET @msg_sql = IF(COALESCE(@msg_ok,0), 'DO 0','SIGNAL SQLSTATE ''45000'' SET MESSAGE_TEXT = ''Unexpected messages.content_type shape; review required''');
PREPARE msg_stmt FROM @msg_sql;
EXECUTE msg_stmt;
DEALLOCATE PREPARE msg_stmt;
SET @msg_sql = IF(BINARY @msg_type=BINARY @msg_new,'DO 0',CONCAT('ALTER TABLE messages MODIFY COLUMN content_type ',@msg_new,' CHARACTER SET ',@msg_charset,' COLLATE ',@msg_collation,' NOT NULL DEFAULT ''text'', ALGORITHM=INPLACE, LOCK=NONE'));
PREPARE msg_stmt FROM @msg_sql;
EXECUTE msg_stmt;
DEALLOCATE PREPARE msg_stmt;
SELECT RELEASE_LOCK(@msg_lock);
