<?php
declare(strict_types=1);
require_once __DIR__.'/../vendor/autoload.php';
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
final class FixtureConnection implements Ratchet\ConnectionInterface {
 public function __construct(public int $resourceId){}
 public function send($data){return $this;}
 public function close(){}
}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE channels(id INTEGER PRIMARY KEY); CREATE TABLE users(id INTEGER PRIMARY KEY,voice_channel_id INTEGER); CREATE TABLE channel_members(channel_id INTEGER,user_id INTEGER); CREATE TABLE temporary_voice_rooms(channel_id INTEGER PRIMARY KEY,empty_since TEXT,expires_at TEXT); INSERT INTO channels VALUES(10),(20); INSERT INTO users VALUES(1,10); INSERT INTO temporary_voice_rooms VALUES(10,NULL,NULL)');
$server=(new ReflectionClass(ChatServer::class))->newInstanceWithoutConstructor();
function setField(ChatServer $s,string $name,mixed $value):void{(new ReflectionProperty(ChatServer::class,$name))->setValue($s,$value);}
setField($server,'db',$db);$a=new FixtureConnection(1);$b=new FixtureConnection(2);$clients=new SplObjectStorage();$clients->attach($a);$clients->attach($b);
setField($server,'clients',$clients);setField($server,'userConns',[1=>[$a,$b]]);
// Platform role avoids unrelated online-presence writes; onClose's voice path is role-independent.
setField($server,'connMeta',[1=>['user_id'=>1,'role'=>'admin','voice_channel_id'=>10],2=>['user_id'=>1,'role'=>'admin']]);
setField($server,'voiceRooms',[10=>[['user_id'=>1,'resourceId'=>1]]]);
$server->onClose($a);check((int)$db->query('SELECT voice_channel_id FROM users')->fetchColumn()===10,'Other tab keeps presence');
$server->onClose($b);check($db->query('SELECT voice_channel_id FROM users')->fetchColumn()===null,'Final non-voice tab clears former voice tab');
check((int)$db->query('SELECT COUNT(*) FROM channels WHERE id=10')->fetchColumn()===0,'Final disconnect cleans temporary room');
$db->exec('INSERT INTO channels VALUES(10); INSERT INTO temporary_voice_rooms VALUES(10,NULL,NULL); UPDATE users SET voice_channel_id=20');
$c=new FixtureConnection(3);$clients->attach($c);setField($server,'userConns',[1=>[$c]]);setField($server,'connMeta',[3=>['user_id'=>1,'role'=>'admin','voice_channel_id'=>10]]);setField($server,'voiceRooms',[10=>[['user_id'=>1,'resourceId'=>3]]]);
$server->onClose($c);check((int)$db->query('SELECT voice_channel_id FROM users')->fetchColumn()===20,'Stale socket cannot clear newer room');
echo "PASS WebSocket multiple-tab final disconnect and stale room isolation\n";
