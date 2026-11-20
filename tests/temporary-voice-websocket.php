<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/temporary-voice-lifecycle.php';
$db->exec("INSERT INTO channels VALUES(20,1,'voice',0,1);INSERT INTO temporary_voice_rooms VALUES(20,NULL,NULL)");
$s->joinChannel(1,20);
$conn=new class implements Ratchet\ConnectionInterface {public int $resourceId=7;public array $sent=[];public function send($data){$this->sent[]=$data;return $this;}public function close(){}};
$server=(new ReflectionClass(ChatServer::class))->newInstanceWithoutConstructor();
foreach(['db'=>$db,'voiceRooms'=>[20=>[['user_id'=>1,'resourceId'=>7,'username'=>'fixture']]],'userConns'=>[1=>[$conn]],'connMeta'=>[7=>['user_id'=>1,'username'=>'fixture','voice_channel_id'=>20]],'clients'=>new SplObjectStorage()] as $name=>$value)(new ReflectionProperty($server,$name))->setValue($server,$value);
ob_start();$server->onClose($conn);ob_end_clean();
vcCheck((int)$db->query('SELECT COUNT(*) FROM channels WHERE id=20')->fetchColumn()===0,'WS disconnect removes last temporary participant');
$db->exec("INSERT INTO channels VALUES(21,1,'voice',0,1);INSERT INTO temporary_voice_rooms VALUES(21,NULL,NULL)");$s->joinChannel(1,21);
(new ReflectionProperty($server,'voiceRooms'))->setValue($server,[21=>[['user_id'=>1,'resourceId'=>7],['user_id'=>1,'resourceId'=>8]]]);
$meta=['user_id'=>1,'username'=>'fixture','voice_channel_id'=>21];$method=new ReflectionMethod($server,'handleLeaveVoice');$args=[$conn,[],&$meta];$method->invokeArgs($server,$args);
vcCheck((int)$db->query('SELECT COUNT(*) FROM channels WHERE id=21')->fetchColumn()===1,'another voice connection prevents deletion');
echo "PASS: WS disconnect and multiple connection room preservation\n";

// Exercise the real join path twice; constructing a roster alone misses join regressions.
$db->exec("ALTER TABLE users ADD COLUMN username TEXT;ALTER TABLE users ADD COLUMN full_name TEXT;ALTER TABLE users ADD COLUMN avatar_color_gradient TEXT");
(new ReflectionProperty($server,'voiceRooms'))->setValue($server,[]);
$second=clone $conn;$second->resourceId=8;
$join=new ReflectionMethod($server,'handleJoinVoice');
$firstMeta=['user_id'=>1,'username'=>'fixture','voice_channel_id'=>null];$secondMeta=$firstMeta;
$join->invokeArgs($server,[$conn,['channel_id'=>21],&$firstMeta]);
$join->invokeArgs($server,[$second,['channel_id'=>21],&$secondMeta]);
$rooms=(new ReflectionProperty($server,'voiceRooms'))->getValue($server);
vcCheck(count($rooms[21])===2,'real dual-tab joins preserve both connection entries');
$method->invokeArgs($server,[$second,[],&$secondMeta]);
vcCheck((int)$db->query('SELECT COUNT(*) FROM channels WHERE id=21')->fetchColumn()===1,'second tab disconnect preserves first tab room');
vcCheck((int)$db->query('SELECT voice_channel_id FROM users WHERE id=1')->fetchColumn()===21,'remaining voice tab retains database membership');
echo "PASS: real dual-tab joins and disconnect lifecycle\n";

$db->exec("INSERT INTO channels VALUES(22,1,'voice',0,1);INSERT INTO temporary_voice_rooms VALUES(22,NULL,NULL)");
(new ReflectionProperty($server,'userConns'))->setValue($server,[1=>[$conn,$second]]);
(new ReflectionProperty($server,'connMeta'))->setValue($server,[7=>$firstMeta,8=>$secondMeta]);
$join->invokeArgs($server,[$second,['channel_id'=>22],&$secondMeta]);
vcCheck((int)$db->query('SELECT COUNT(*) FROM channels WHERE id=21')->fetchColumn()===0,'moving account rooms cleans the vacated room');
vcCheck((bool)array_filter($conn->sent,fn($json)=>(json_decode($json,true)['type']??'')==='voice_session_moved'),'old media tab explicitly told to disconnect');
$oldMeta=(new ReflectionProperty($server,'connMeta'))->getValue($server)[7];
$method->invokeArgs($server,[$conn,[],&$oldMeta]);
vcCheck((int)$db->query('SELECT voice_channel_id FROM users WHERE id=1')->fetchColumn()===22,'displaced tab cannot clear new room');
echo "PASS: cross-tab room moves explicitly evict old media and preserve newer membership\n";

$server->persistDirtyCodeSessions();
echo "PASS: worker timer can call public persistence hook\n";

$staleMeta=$secondMeta;$method->invokeArgs($server,[$second,['channel_id'=>21],&$staleMeta]);
vcCheck($staleMeta['voice_channel_id']===22,'stale socket leave cannot erase newer room on same connection');
$db->exec('CREATE TABLE dm_group_members(group_id INTEGER,user_id INTEGER);INSERT INTO dm_group_members VALUES(1,1)');
$offset=(new ReflectionClass(ChatServer::class))->getConstant('DM_GROUP_VOICE_ID_OFFSET');
$join->invokeArgs($server,[$second,['channel_id'=>$offset+1],&$secondMeta]);
vcCheck((int)$db->query('SELECT COUNT(*) FROM channels WHERE id=22')->fetchColumn()===0,'moving into group DM deletes old temporary room');
vcCheck($db->query('SELECT voice_channel_id FROM users WHERE id=1')->fetchColumn()===null,'group DM removes ordinary database voice membership');
$method->invokeArgs($server,[$second,[],&$secondMeta]);
echo "PASS: stale socket leave and temporary-room to DM lifecycle\n";
