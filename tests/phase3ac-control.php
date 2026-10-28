<?php
declare(strict_types=1);
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,get_class($e).': '.$e->getMessage());exit(1);});
require_once __DIR__.'/phase3y-bootstrap.php';
require_once dirname(__DIR__).'/vendor/autoload.php';
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$action=$input['action'];
function sessionOpen(array $input):bool{
 $sid=$input['session_id']??'';if($sid==='')return false;if(!preg_match('/^[a-zA-Z0-9,-]{1,128}$/D',$sid))throw new RuntimeException('Synthetic session handle required');
 if(!is_file(session_save_path().'/sess_'.$sid))return false;
 session_id($sid);AuthMiddleware::startSession();return true;
}
function summary(PDO $db):array{
 $out=[];foreach(['users','user_profiles','pm_user_study_prefs','user_settings','user_interests','user_hobbies','user_encrypted_pii','messages','notifications']as$t){$out[$t]=['rows'=>(int)$db->query('SELECT COUNT(*) FROM '.$t)->fetchColumn(),'checksum'=>$db->query('CHECKSUM TABLE '.$t)->fetch(PDO::FETCH_NUM)[1]];}
 return $out;
}
function sessionSummary():array{
 $out=['authenticated'=>!empty($_SESSION['user_id']),'user_id'=>(int)($_SESSION['user_id']??0),'role'=>$_SESSION['role']??null,'keys'=>array_keys($_SESSION??[]),'csrf_present'=>!empty($_SESSION['csrf_token']),'signup_verified'=>!empty($_SESSION['pending_signup_email_verified']),'signup_intent_valid'=>(int)($_SESSION['pending_signup_email_expires']??0)>=time(),'login_intent_valid'=>(int)($_SESSION['pending_login_expires']??0)>=time(),'pending_login_user_id'=>(int)($_SESSION['pending_login_user_id']??0)];sort($out['keys']);return $out;
}
$out=[];
switch($action){
 case 'inventory':
  $out=['php'=>PHP_VERSION,'sapi'=>PHP_SAPI,'php_zone'=>date_default_timezone_get(),'php_epoch'=>time(),'php_wall'=>date('Y-m-d H:i:s'),'database'=>$db->query('SELECT VERSION() AS version,UTC_TIMESTAMP() AS wall,UTC_TIMESTAMP() AS utc,UNIX_TIMESTAMP() AS epoch,@@session.time_zone AS zone,@@system_time_zone AS system_zone')->fetch(),'schema'=>[],'ledger'=>$db->query('SELECT filename,checksum,success FROM schema_migrations ORDER BY filename')->fetchAll()];
  foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)as$t)$out['schema'][$t]=$db->query('SHOW CREATE TABLE `'.str_replace('`','``',$t).'`')->fetch(PDO::FETCH_NUM)[1];
  $out['schema_sha256']=hash('sha256',json_encode($out['schema'],JSON_THROW_ON_ERROR));break;
 case 'snapshot':
  $out=['clock'=>['php_epoch'=>time(),'php_wall'=>date('Y-m-d H:i:s'),'php_zone'=>date_default_timezone_get(),'database'=>$db->query('SELECT UTC_TIMESTAMP() AS wall,UTC_TIMESTAMP() AS utc,UNIX_TIMESTAMP() AS epoch,@@session.time_zone AS zone')->fetch()], 'business'=>summary($db),'session_exists'=>sessionOpen($input)];$out['session']=sessionSummary();
  $out['otp_checksum']=$db->query("SHOW TABLES LIKE 'otp_codes'")->fetchColumn()?$db->query('CHECKSUM TABLE otp_codes')->fetch(PDO::FETCH_NUM)[1]:null;
  $out['otp']=[];if($id=(int)($input['user_id']??0)){
   $s=$db->prepare("SELECT action,expires_at,used_at,expires_at>UTC_TIMESTAMP() AS valid_by_database,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),expires_at) AS remaining_seconds FROM otp_codes WHERE user_id=? ORDER BY id");$s->execute([$id]);$out['otp']=$s->fetchAll();
  }break;
 case 'expire_signup':if(!sessionOpen($input))throw new RuntimeException('Missing session');$_SESSION['pending_signup_email_expires']=time()-60;$out=sessionSummary();break;
 case 'optional_login':
  if(!sessionOpen($input)||!preg_match('/^phase3y[a-z0-9_-]+@example\.test$/D',$input['email']))throw new RuntimeException('Owned optional-login identity required');
  if(!empty($_SESSION['user_id']))throw new RuntimeException('Optional-login fixture must be unauthenticated');$r=(new AuthService())->login($input['email'],$input['password'],false,true);$out=['success'=>$r['success'],'otp_required'=>$r['otp_required']??false,'error'=>$r['error']??null,'session'=>sessionSummary()];break;
 case 'expire_db_otp':
  $s=$db->prepare("UPDATE otp_codes SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE user_id=? AND action='2fa' AND used_at IS NULL");$s->execute([(int)$input['user_id']]);$out=['rows_changed'=>$s->rowCount()];break;
 case 'expire_login_intent':if(!sessionOpen($input))throw new RuntimeException('Missing session');$_SESSION['pending_login_expires']=time()-60;$out=sessionSummary();break;
 case 'expire_storage':
  $sid=$input['session_id'];if(!preg_match('/^[a-zA-Z0-9,-]{1,128}$/D',$sid))throw new RuntimeException('Owned session handle required');$path=session_save_path().'/sess_'.$sid;
  if(!is_file($path))throw new RuntimeException('No session file to expire');touch($path,time()-(int)ini_get('session.gc_maxlifetime')-60);
  AuthMiddleware::startSession();$removed=session_gc();session_destroy();clearstatcache();$out=['removed'=>$removed,'expired_file_absent'=>!is_file($path),'gc_maxlifetime'=>(int)ini_get('session.gc_maxlifetime')];break;
 case 'rollback_on':$db->exec("CREATE TRIGGER phase3y_owned_failure BEFORE INSERT ON pm_user_study_prefs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Phase3Y owned rollback control'");$out=['trigger_created'=>true];break;
 case 'rollback_off':$db->exec('DROP TRIGGER IF EXISTS phase3y_owned_failure');$out=['trigger_removed'=>true];break;
 case 'cleanup':
  $ids=$db->query("SELECT id FROM users WHERE email LIKE 'phase3y%@example.test'")->fetchAll(PDO::FETCH_COLUMN);
  foreach($ids as$id){foreach(['otp_codes','pm_user_study_prefs','user_interests','user_hobbies','user_profiles','user_encrypted_pii','user_settings']as$t){$db->prepare('DELETE FROM '.$t.' WHERE user_id=?')->execute([$id]);}$db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);}
  $out=['deleted_users'=>count($ids),'remaining_synthetic_users'=>(int)$db->query("SELECT COUNT(*) FROM users WHERE email LIKE 'phase3y%@example.test'")->fetchColumn(),'orphan_otp'=>(int)$db->query('SELECT COUNT(*) FROM otp_codes o LEFT JOIN users u ON u.id=o.user_id WHERE u.id IS NULL')->fetchColumn()];break;
 default:throw new RuntimeException('Unsupported owned control');
}
if(session_status()===PHP_SESSION_ACTIVE)session_write_close();echo json_encode($out,JSON_THROW_ON_ERROR);
