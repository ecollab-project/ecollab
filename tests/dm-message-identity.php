<?php
declare(strict_types=1);
require dirname(__DIR__).'/services/DmMessageIdentity.php';
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE dm_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,sender_id INTEGER,is_deleted INTEGER DEFAULT 0);CREATE TABLE dm_client_identity(sender_id INTEGER,client_id TEXT,kind TEXT,target_id INTEGER,payload_hash TEXT,message_id INTEGER UNIQUE,created_at TEXT,PRIMARY KEY(sender_id,client_id))');
function di(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$nonce='b7fce7d0-174e-42d5-8ff7-118cf44c7461';$calls=0;
$save=function()use($db,&$calls){$calls++;$db->exec('INSERT INTO dm_messages(sender_id) VALUES(7)');return (int)$db->lastInsertId();};
$a=DmMessageIdentity::record($db,7,$nonce,'dm',1,['same text'],$save);$b=DmMessageIdentity::record($db,7,$nonce,'dm',1,['same text'],$save);
di($calls===1&&$a['id']===$b['id']&&$b['replayed'],'same request persists once');
foreach([['dm',2,['same text']],['dm',1,['changed text']],['group',1,['same text']]] as [$kind,$target,$payload]){try{DmMessageIdentity::record($db,7,$nonce,$kind,$target,$payload,$save);throw new RuntimeException('Identity reuse accepted');}catch(RuntimeException $e){di($e->getCode()===409,'target/content identity reuse denied');}}
$c=DmMessageIdentity::record($db,7,'6d9a31b0-7544-46a2-86ad-81ba2d097044','dm',1,['same text'],$save);di($c['id']!==$a['id'],'intentional repeated text remains separate');
$rows=DmMessageIdentity::enrich($db,[['id'=>$a['id'],'sender_id'=>7]],7);di($rows[0]['client_message_id']===$nonce,'history returns exact identity');di(!isset(DmMessageIdentity::enrich($db,[['id'=>$a['id'],'sender_id'=>7]],9)[0]['client_message_id']),'another viewer does not receive sender identity');
try{DmMessageIdentity::record($db,7,'bad','dm',1,[],$save);throw new RuntimeException('invalid nonce');}catch(RuntimeException $e){di($e->getCode()===400,'invalid identity rejected');}
try{DmMessageIdentity::record($db,7,'f8cf5bed-c684-4c64-93f7-3c849e61f6b0','dm',1,[],fn()=>throw new RuntimeException('fixture failure'));}catch(RuntimeException $e){}
di((int)$db->query('SELECT COUNT(*) FROM dm_client_identity')->fetchColumn()===2,'failed save rolls back identity reservation');
$db->exec('UPDATE dm_messages SET is_deleted=1 WHERE id=1');try{DmMessageIdentity::record($db,7,$nonce,'dm',1,['same text'],$save);throw new RuntimeException('deleted replay');}catch(RuntimeException $e){di($e->getCode()===409,'deleted message not resurrected');}
echo "PASS: send idempotency, exact correlation, repeat text, scope isolation, rollback and deleted-message protection\n";
