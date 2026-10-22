<?php
declare(strict_types=1);
require_once __DIR__.'/../services/CentrifugoProxyService.php';
function verify(bool $v,string $s):void{if(!$v)throw new RuntimeException($s);}
class ProxyPDO extends PDO {public int $queries=0;public function prepare(string $query,array $options=[]):PDOStatement|false{$this->queries++;return parent::prepare($query,$options);}}
$db=new ProxyPDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec(file_get_contents(__DIR__.'/fixtures/livekit-admission.sql'));
$service=new CentrifugoProxyService($db,'https://ecollab.tech');
$body=['user'=>'2','channels'=>['inbox:user2'],'data'=>['user'=>'2'],'meta'=>['role'=>'admin']];
$db->queries=0;$reply=$service->authorize('connect',$body,1,'https://ecollab.tech',100);
verify($reply===['result'=>['expire_at'=>160,'user'=>'1','channels'=>['inbox:user1']]],'Session identity only, server-side inbox only');
verify($db->queries===1,'One account query per valid admission');
verify($service->authorize('refresh',['user'=>'1'],1,'https://ecollab.tech',100)===['result'=>['expire_at'=>160]],'Refresh current session/account');
verify(isset($service->authorize('refresh',['user'=>'2'],1,'https://ecollab.tech')['result']['expired']),'Refresh identity mismatch');
foreach (['','https://evil.example','https://ecollab.tech.evil.example','null'] as $origin)verify(isset($service->authorize('connect',[],1,$origin)['disconnect']),'Origin denied');
foreach (['suspended','banned','deactivated','disabled'] as $status){$db->prepare('UPDATE users SET status=? WHERE id=1')->execute([$status]);verify(isset($service->authorize('connect',[],1,'https://ecollab.tech')['disconnect']),'Inactive denied '.$status);verify($service->authorize('refresh',['user'=>'1'],1,'https://ecollab.tech')['result']['expired'],'Refresh expires '.$status);}
$db->exec("UPDATE users SET status='active',deleted_at='2026-01-01' WHERE id=1");verify(isset($service->authorize('connect',[],1,'https://ecollab.tech')['disconnect']),'Deleted denied');
$db->exec('UPDATE users SET deleted_at=NULL');
foreach (['mute','suspend','ban'] as $action){$db->prepare('INSERT INTO moderation_actions VALUES(1,1,?,1,NULL)')->execute([$action]);verify(isset($service->authorize('connect',[],1,'https://ecollab.tech')['result']['user']),'Server moderation does not disable own inbox');$db->exec('DELETE FROM moderation_actions');}
$db->exec('DELETE FROM server_members;DELETE FROM channel_members');verify(isset($service->authorize('connect',[],1,'https://ecollab.tech')['result']['user']),'Resource revocation does not disable own inbox');
$db->exec("INSERT INTO moderation_actions VALUES(1,NULL,'ban',1,NULL)");verify(isset($service->authorize('connect',[],1,'https://ecollab.tech')['disconnect']),'Global ban denied');
$db->exec('DROP TABLE moderation_actions');try{$service->authorize('connect',[],1,'https://ecollab.tech');throw new LogicException('DB failure allowed');}catch(PDOException){}
echo "PASS cookie-derived identity, scoped server-side inbox, one-query admission, refresh mismatch, account/origin/moderation matrix and database failure\n";
