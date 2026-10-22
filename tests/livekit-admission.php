<?php
declare(strict_types=1);
require_once __DIR__.'/../services/LiveKitAdmissionService.php';
require_once __DIR__.'/../services/LiveKitService.php';
$dsn=getenv('ADMISSION_TEST_DSN')?:'sqlite::memory:';
if ($dsn!=='sqlite::memory:' && !preg_match('/^mysql:host=127[.]0[.]0[.]1;port=[0-9]+;dbname=ecollab_admission_test$/D', $dsn)) throw new RuntimeException('Disposable local admission database required');
$db=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
if ($dsn!=='sqlite::memory:' && (int)$db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn()!==0) throw new RuntimeException('Admission database must be empty');
foreach (explode(';',file_get_contents(__DIR__.'/fixtures/livekit-admission.sql')) as $sql) if(trim($sql)!=='')$db->exec($sql);
$key='fixture-key';$secret=str_repeat('s',64);$gate=new LiveKitAdmissionService($db,$key,$secret);
putenv('LIVEKIT_API_KEY='.$key);putenv('LIVEKIT_API_SECRET='.$secret);putenv('LIVEKIT_URL=ws://127.0.0.1:7880');
$issuer=new LiveKitService();$token=$issuer->issueRoomToken(['id'=>1],10,'Private voice')['token'];
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function reject(callable $f,string $label):void{try{$f();}catch(RuntimeException $e){if($e->getCode()===403)return;throw $e;}throw new RuntimeException('Not denied: '.$label);}
function signed(array $claims, string $alg='HS256',?string $secret=null):string{
 $b=static fn($v)=>rtrim(strtr(base64_encode(json_encode($v,JSON_THROW_ON_ERROR)),'+/','-_'),'=');
 $data=$b(['alg'=>$alg,'typ'=>'JWT']).'.'.$b($claims);
 return $data.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256',$data,$secret??str_repeat('s',64),true)),'+/','-_'),'=');
}
$gate->authorize($token);check(LiveKitAdmissionService::requestToken('Bearer '.$token,'access_token=wrong')===$token,'Header precedence matches LiveKit');
check(LiveKitAdmissionService::requestToken('','sdk=js&access_token='.urlencode($token))===$token,'Query token');
foreach (['','access_token=a&access_token=b','access%5Ftoken=a&access_token=b'] as $q)reject(fn()=>LiveKitAdmissionService::requestToken('',$q),'Missing/duplicate query');
reject(fn()=>LiveKitAdmissionService::requestToken('Basic wrong','access_token='.$token),'Invalid header does not fall back');
$claims=['iss'=>$key,'sub'=>'user-1','exp'=>time()+600,'nbf'=>time()-1,'video'=>['roomJoin'=>true,'room'=>'ecollab-channel-10']];
foreach ([['iss'=>'other'],['exp'=>time()-1],['exp'=>(string)(time()+600)],['nbf'=>time()+600],['sub'=>'user-2'],['sub'=>'user-99999999999999999999999999999'],['video'=>['roomJoin'=>false,'room'=>'ecollab-channel-10']],['video'=>['roomJoin'=>true,'room'=>'unknown-room']]] as $change)reject(fn()=>$gate->authorize(signed(array_replace($claims,$change))),'Invalid claims/private access');
foreach ([signed($claims,'none'),signed($claims,'HS256','wrong'),$token.'x','a.b.c',str_repeat('x',16385)] as $bad)reject(fn()=>$gate->authorize($bad),'Forgery/oversized/malformed');
foreach (['suspend','ban','mute'] as $action){
 $db->prepare('INSERT INTO moderation_actions VALUES(1,1,?,1,NULL)')->execute([$action]);
 if($action==='mute')$gate->authorize($token);else reject(fn()=>$gate->authorize($token),'Moderation '.$action);
 $db->exec('DELETE FROM moderation_actions');
}
$db->exec('DELETE FROM channel_members WHERE user_id=1');reject(fn()=>$gate->authorize($token),'Replay after private-channel revocation');
$db->exec('INSERT INTO channel_members VALUES(10,1);DELETE FROM server_members WHERE user_id=1');reject(fn()=>$gate->authorize($token),'Replay after server removal');
$db->exec("INSERT INTO server_members VALUES(1,1,'member')");
foreach (['suspended','deactivated','banned'] as $status){$db->prepare('UPDATE users SET status=? WHERE id=1')->execute([$status]);reject(fn()=>$gate->authorize($token),'Account '.$status);}
$db->exec("UPDATE users SET status='active',deleted_at='2026-01-01' WHERE id=1");reject(fn()=>$gate->authorize($token),'Deleted account');
$db->exec('UPDATE users SET deleted_at=NULL WHERE id=1');
$group=$issuer->issueNamedRoomToken(['id'=>1],'ecollab-dm-group-v2-1','Group')['token'];$gate->authorize($group);
$db->exec('DELETE FROM dm_group_members WHERE user_id=1');reject(fn()=>$gate->authorize($group),'Removed group member');
$call=$issuer->issueNamedRoomToken(['id'=>1],'ecollab-dm-call-1','Call')['token'];$gate->authorize($call);
$db->exec("UPDATE dm_call_history SET status='ended'");reject(fn()=>$gate->authorize($call),'Ended call');
$db->exec("UPDATE users SET username='k6_aabbccdd_1',email='k6_aabbccdd_1@example.invalid' WHERE id=1");
$capacity=$issuer->issueNamedRoomToken(['id'=>1],'ecollab-capacity-20261022-120000-12345678-0','Synthetic',['capacity_fixture'=>'k6-20261022-120000-aabbccdd'])['token'];$gate->authorize($capacity);
$db->exec('DELETE FROM server_members WHERE user_id=1');reject(fn()=>$gate->authorize($capacity),'Synthetic fixture membership revoked');
$db->exec('DROP TABLE users');
try{$gate->authorize($token);throw new LogicException('DB outage allowed');}catch(PDOException){}
echo "PASS LiveKit signed admission, JWT attacks, replay after account/channel/server/group/call revocation, mute semantics, synthetic fixture and DB outage\n";
