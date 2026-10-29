<?php
declare(strict_types=1);
require_once __DIR__.'/phase3y-bootstrap.php';
set_exception_handler(function(Throwable $e):void{fwrite(STDERR,get_class($e).': '.$e->getMessage());exit(1);});
require_once dirname(__DIR__).'/services/AdminWorkspaceService.php';
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$action=$in['action'];$checks=[];
function verifyIdentity(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException($label);$checks[]=$label;}
if($action==='contracts'){
    $id=(int)$in['system_id'];$human=(int)$in['human_id'];
    $auth=new AuthService();$s=$db->prepare('SELECT * FROM users WHERE id=?');$s->execute([$id]);$machine=$s->fetch();
    verifyIdentity((int)$machine['is_system']===1&&$machine['password_hash']===null&&$machine['remember_token']===null&&$machine['sso_provider']===null&&$machine['sso_uid']===null,'provisioned machine has no human credentials');
    verifyIdentity(SystemIdentity::humanId($db,$human)&&!SystemIdentity::humanId($db,$id),'authoritative flag distinction');
    verifyIdentity(!SystemIdentity::human([])&&!SystemIdentity::human(['is_system'=>null])&&!SystemIdentity::human(['is_system'=>2]),'missing invalid flag fail closed');
    foreach(['ecollab_ai','ECOLLAB_AI','ecollab_ai ']as$name)verifyIdentity(SystemIdentity::reserved($db,$name),'database collation reserved '.trim($name));
    verifyIdentity(!SystemIdentity::reserved($db,'ecollab_ai2'),'nonreserved suffix');
    verifyIdentity(!$auth->login($machine['email'],'arbitrary',false,false)['success'],'machine password login denied');
    $before=(int)$db->query('SELECT COUNT(*) FROM otp_codes')->fetchColumn();$r=$auth->forgotPassword($machine['email']);verifyIdentity($r['success']&&!isset($r['user_id'])&&(int)$db->query('SELECT COUNT(*) FROM otp_codes')->fetchColumn()===$before,'machine reset request indistinguishable without OTP');
    verifyIdentity(!$auth->verifyOtp($id,'123456')['success']&&!isset($_SESSION['pwd_reset_token']),'machine OTP cannot issue reset token');
    $_SESSION=['pwd_reset_token'=>'owned-forged-reset','pwd_reset_user_id'=>$id,'pwd_reset_expires'=>time()+600];verifyIdentity(!$auth->resetPassword('owned-forged-reset','new-password-42','new-password-42')['success'],'machine forged reset session denied');
    $_SESSION=['user_id'=>$id];verifyIdentity(!$auth->validateSession()['authenticated'],'machine existing session denied');$_SESSION=[];
    // Inject credentials only in this owned fixture to prove flags gate every path.
    $db->prepare('UPDATE users SET password_hash=?,remember_token=?,sso_provider=?,sso_uid=? WHERE id=?')->execute([password_hash('owned-machine-test-42',PASSWORD_BCRYPT,['cost'=>4]),hash('sha256','owned-machine-remember'),'google','owned-machine-provider',$id]);
    verifyIdentity(!$auth->login($machine['email'],'owned-machine-test-42',false,false)['success'],'machine valid password denied');
    $_COOKIE['ecollab_remember']='owned-machine-remember';verifyIdentity(!$auth->loginFromRememberToken()&&!isset($_SESSION['user_id']),'machine valid remember token denied');unset($_COOKIE['ecollab_remember']);
    $oauth=new OAuthService();$method=new ReflectionMethod(OAuthService::class,'upsertOAuthUser');
    foreach(['owned-machine-provider','new-provider-id']as$providerId){$denied=false;try{$method->invoke($oauth,'google',['provider_id'=>$providerId,'email'=>$machine['email'],'email_verified'=>true,'full_name'=>'Machine','avatar_url'=>'']);}catch(RuntimeException $e){$denied=$e->getCode()===403;}verifyIdentity($denied,'machine SSO provider or email linking denied '.$providerId);}
    $db->prepare('UPDATE users SET password_hash=NULL,remember_token=NULL,sso_provider=NULL,sso_uid=NULL WHERE id=?')->execute([$id]);
    // Both supported noninteractive human writers are exercised against real MariaDB.
    $created=$method->invoke($oauth,'google',['provider_id'=>'owned-new-human','email'=>'owned-oauth@example.test','email_verified'=>true,'full_name'=>'Owned OAuth','avatar_url'=>'']);verifyIdentity(SystemIdentity::humanId($db,(int)$created['id']),'OAuth writer human flag');
    $admin=new AdminWorkspaceService($db);$created=$admin->mutate('create_user',['username'=>'owned_admin_created','email'=>'owned-admin@example.test','full_name'=>'Owned Admin Created','password'=>'owned-valid-pass-42','role'=>'student','is_system'=>1],['id'=>1,'role'=>'admin']);
    $s=$db->query("SELECT is_system FROM users WHERE username='owned_admin_created'");verifyIdentity((int)$s->fetchColumn()===0,'admin workspace ignores attempted system promotion');
    $denied=false;try{$admin->mutate('create_user',['username'=>'ECOLLAB_AI','email'=>'reserved@example.test','full_name'=>'Reserved','password'=>'owned-valid-pass-42','role'=>'student'],['id'=>1,'role'=>'admin']);}catch(InvalidArgumentException $e){$denied=true;}verifyIdentity($denied,'admin workspace reserved identity denied');
    $invalid=false;try{$db->prepare('UPDATE users SET is_system=2 WHERE id=?')->execute([$human]);}catch(PDOException $e){$invalid=true;}verifyIdentity($invalid,'database flag domain enforced');
    require_once dirname(__DIR__).'/services/LoadTestFixture.php';
    $directory=getenv('PHASE3Y_PRIVATE').'/identity-load-fixture';$fixtureTool=new LoadTestFixture($db);
    $manifestPath=$fixtureTool->create(2,'http://127.0.0.1','ws://127.0.0.1',$directory);$manifest=json_decode(file_get_contents($manifestPath),true,512,JSON_THROW_ON_ERROR);
    foreach($manifest['users']as$synthetic)verifyIdentity(SystemIdentity::humanId($db,(int)$synthetic['id']),'synthetic CLI fixture writer persists human0');
    $cleanupFinding=null;try{$fixtureTool->cleanup($manifest);}catch(PDOException $e){if(!str_contains($e->getMessage(),"ws_tokens"))throw $e;$cleanupFinding=['status'=>'BLOCKED','reason'=>'Inherited load-fixture cleanup expects ws_tokens absent from canonical migrations; not a defense feature or capacity acceptance'];}
    unlink($manifestPath);unlink($directory.'/LATEST');rmdir($directory);
    $rows=$db->query('SELECT id,is_system FROM users ORDER BY id')->fetchAll();
    echo json_encode(['status'=>'PASS','checks'=>$checks,'identities'=>$rows,'synthetic_fixture_cleanup'=>$cleanupFinding],JSON_THROW_ON_ERROR);
}elseif($action==='scalar'){
    $sql=$in['sql'];echo json_encode(['value'=>$db->query($sql)->fetchColumn()],JSON_THROW_ON_ERROR);
}else throw new RuntimeException('Unknown owned action');
