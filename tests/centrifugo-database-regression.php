<?php
declare(strict_types=1);
function env($name,$default=''){return $name==='CENTRIFUGO_ENABLED'?true:$default;}
require_once dirname(__DIR__).'/services/CentrifugoService.php';require_once dirname(__DIR__).'/services/RealtimeOutbox.php';
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
// Disposable CI database only; fixture schemas never touch the application's production DB.
$dsn=getenv('TEST_DELIVERY_DSN');if(!$dsn)throw new RuntimeException('Set TEST_DELIVERY_DSN to an empty disposable test database');
$db=new PDO($dsn,getenv('TEST_DELIVERY_USER')?:'root',getenv('TEST_DELIVERY_PASSWORD')?:'root',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$schema=[
'CREATE TABLE users(id INT PRIMARY KEY,role VARCHAR(20),deleted_at DATETIME NULL)',
'CREATE TABLE channels(id INT PRIMARY KEY,server_id INT,is_private INT,created_by INT)',
'CREATE TABLE server_members(server_id INT,user_id INT,server_role VARCHAR(20))',
'CREATE TABLE channel_members(channel_id INT,user_id INT)',
'CREATE TABLE messages(id INT PRIMARY KEY,channel_id INT,is_deleted INT)',
'CREATE TABLE dm_conversations(id INT PRIMARY KEY,user_a INT,user_b INT)',
'CREATE TABLE dm_messages(id INT PRIMARY KEY,conversation_id INT NULL,group_id INT NULL,is_deleted INT)',
'CREATE TABLE dm_group_members(group_id INT,user_id INT)'];
foreach($schema as $sql)$db->exec($sql);
$sql=file_get_contents(dirname(__DIR__).'/database/migrations/052_realtime_outbox.sql');$db->exec($sql);$db->exec($sql);
$db->exec("INSERT INTO users VALUES (18,'student',NULL),(19,'student',NULL),(20,'student',NULL),(21,'admin',NULL),(22,'student',NOW()),(23,'student',NULL)");
$db->exec("INSERT INTO channels VALUES(5,2,0,18),(6,2,1,18); INSERT INTO server_members VALUES(2,18,'member'),(2,19,'member'),(2,20,'owner'),(2,22,'member'); INSERT INTO channel_members VALUES(6,19); INSERT INTO messages VALUES(1,5,0),(2,6,0),(3,5,1); INSERT INTO dm_conversations VALUES(7,18,19); INSERT INTO dm_messages VALUES(4,7,NULL,0),(5,NULL,8,0); INSERT INTO dm_group_members VALUES(8,18),(8,19),(8,22)");
function audience($db,$kind,$target,$mid){$a=RealtimeOutbox::recipients($db,['kind'=>$kind,'target_id'=>$target,'message_id'=>$mid]);sort($a);return $a;}
check(audience($db,'channel',5,1)===[18,19,20,21],'Public channel audience excludes outsiders/deleted users');
check(audience($db,'channel',6,2)===[18,19,20,21],'Private channel member, creator, owner and admin');
$db->exec('DELETE FROM channel_members WHERE user_id=19');check(audience($db,'channel',6,2)===[18,20,21],'Revoked private membership excluded');
check(audience($db,'channel',5,3)===[],'Deleted message excluded');
check(audience($db,'dm',7,4)===[18,19],'DM participants only');check(audience($db,'group',8,5)===[18,19],'Live group participants only');
$db->exec('DELETE FROM dm_group_members WHERE user_id=19');check(audience($db,'group',8,5)===[18],'Revoked group membership excluded');
$id=RealtimeOutbox::record($db,'dm',7,function()use($db){$db->exec('INSERT INTO dm_messages VALUES(6,7,NULL,0)');return 6;});check($id===6,'Real transaction records intent');
try{RealtimeOutbox::record($db,'dm',7,function()use($db){$db->exec('INSERT INTO dm_messages VALUES(9,7,NULL,0)');return 6;});throw new LogicException('Expected unique-key failure');}catch(PDOException $e){}
check((int)$db->query('SELECT COUNT(*) FROM dm_messages WHERE id=9')->fetchColumn()===0,'Outbox error rolls message back');
$api=getenv('TEST_BROKER_API')?:'http://127.0.0.1:18001/api';
RealtimeOutbox::dispatch($db,new CentrifugoService(str_repeat('a',64),str_repeat('x',64),$api));
check((int)$db->query('SELECT attempts FROM realtime_outbox')->fetchColumn()===1,'Failure retained for retry');
$db->exec('UPDATE realtime_outbox SET available_at=NOW()');
check(RealtimeOutbox::dispatch($db,new CentrifugoService(str_repeat('a',64),str_repeat('b',64),$api))===1,'Retry succeeds');
check((int)$db->query('SELECT COUNT(*) FROM realtime_outbox WHERE published_at IS NOT NULL')->fetchColumn()===1,'Success marked after publish');
echo "Database audience, atomic save, rollback, migration rerun and delivery retry checks passed\n";
