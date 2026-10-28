<?php
declare(strict_types=1);
// Disposable native-clock experiment only; never included by an application route.
require_once __DIR__.'/phase3y-bootstrap.php';
set_exception_handler(static function(Throwable $e):void {fwrite(STDERR,get_class($e).': clock experiment failed');exit(1);});
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$uid=(int)$input['user_id'];
$s=$db->prepare("SELECT email FROM users WHERE id=?");$s->execute([$uid]);
if(!preg_match('/^phase3y[a-z0-9_-]+@example\.test$/D',(string)$s->fetchColumn()))throw new RuntimeException('Owned actor required');
$originalZone=date_default_timezone_get();$originalDb=(string)$db->query('SELECT @@session.time_zone')->fetchColumn();
function clockRecord(PDO $db):array{return ['php_epoch'=>time(),'php_wall'=>date('Y-m-d H:i:s'),'php_zone'=>date_default_timezone_get(),'database'=>$db->query('SELECT NOW() AS wall,UTC_TIMESTAMP() AS utc,UNIX_TIMESTAMP() AS epoch,@@session.time_zone AS zone')->fetch()];}
function zone(PDO $db,string $value):void {if(!in_array($value,['+00:00','+06:00','+08:00'],true))throw new RuntimeException('Owned zone');$db->exec('SET time_zone='.$db->quote($value));}
function stamp(PDO $db,int $epoch):void {$db->exec('SET timestamp='.$epoch);}
function row(PDO $db,int $uid,string $action):array {$s=$db->prepare('SELECT id,action,created_at,expires_at,used_at,UNIX_TIMESTAMP(expires_at) AS interpreted_expiry_epoch,TIMESTAMPDIFF(SECOND,NOW(),expires_at) AS remaining_seconds,expires_at>NOW() AS valid_by_database FROM otp_codes WHERE user_id=? AND action=? ORDER BY id DESC LIMIT 1');$s->execute([$uid,$action]);return $s->fetch();}
$otp=new OtpService();$records=[];
try{
 $cases=[['name'=>'stable','php_generate'=>$originalZone,'db_generate'=>$originalDb,'php_verify'=>$originalZone,'db_verify'=>$originalDb],['name'=>'php_zone_changes_after_generation','php_generate'=>$originalZone,'db_generate'=>$originalDb,'php_verify'=>$originalZone==='UTC'?'Asia/Manila':'UTC','db_verify'=>$originalDb],['name'=>'db_zone_changes_after_generation','php_generate'=>$originalZone,'db_generate'=>$originalDb,'php_verify'=>$originalZone,'db_verify'=>$originalDb==='+00:00'?'+08:00':'+00:00']];
 foreach($cases as $case)foreach(['reset_password','verify_email','2fa']as $action){
  foreach(['ttl_plus_one','expiry_minus_one','expiry_exact','expiry_plus_one']as $point){
   date_default_timezone_set($case['php_generate']);zone($db,$case['db_generate']);stamp($db,time());
   $code=$otp->generate($uid,$action);$generated=row($db,$uid,$action);$generation=clockRecord($db);
   $generationEpoch=(new DateTimeImmutable($generated['expires_at'],new DateTimeZone($case['php_generate'])))->getTimestamp()-OTP_EXPIRY;
   date_default_timezone_set($case['php_verify']);zone($db,$case['db_verify']);
   $interpreted=(int)row($db,$uid,$action)['interpreted_expiry_epoch'];
   $verifyEpoch=$point==='ttl_plus_one'?$generationEpoch+OTP_EXPIRY+1:$interpreted+(['expiry_minus_one'=>-1,'expiry_exact'=>0,'expiry_plus_one'=>1][$point]);
   stamp($db,$verifyEpoch);$before=row($db,$uid,$action);$count=$otp->countValid($uid,$action);$verification=clockRecord($db);$result=$otp->verify($uid,$code,$action);$after=row($db,$uid,$action);
   $expected=$verifyEpoch<$interpreted;
   if($result['success']!==$expected||$count!==($expected?1:0)||(($after['used_at']!==null)!==$expected))throw new RuntimeException('Native predicate mismatch');
   $reset=null;
   if($action==='reset_password'&&$point==='ttl_plus_one'){
    // Restore only the owned consumed flag so the real reset-token service sees the same original code/time.
    $db->prepare('UPDATE otp_codes SET used_at=NULL WHERE id=?')->execute([$before['id']]);$_SESSION=[];
    $r=(new AuthService())->verifyOtp($uid,$code,'reset_password');$reset=['success'=>$r['success'],'reset_token_present'=>!empty($r['reset_token']),'authenticated'=>!empty($_SESSION['user_id']),'pending_login_present'=>isset($_SESSION['pending_login_expires']),'session_reset_ttl_seconds'=>(int)($_SESSION['pwd_reset_expires']??0)-time()];
    if($r['success']!==$expected||$reset['authenticated'])throw new RuntimeException('Reset contract mismatch');$_SESSION=[];
   }
   $records[]=['scenario'=>$case,'action'=>$action,'point'=>$point,'generation'=>$generation,'generation_epoch_from_stored_php_wall'=>$generationEpoch,'verification'=>$verification,'simulated_database_clock'=>true,'php_clock_advanced'=>false,'elapsed_database_seconds'=>$verifyEpoch-$generationEpoch,'effective_database_ttl_seconds'=>$interpreted-$generationEpoch,'before'=>$before,'valid_count'=>$count,'service_success'=>$result['success'],'error'=>$result['error']??null,'after'=>$after,'reset_service'=>$reset,'expected_predicate'=>$expected];
  }
 }
 date_default_timezone_set($originalZone);zone($db,$originalDb);stamp($db,time());
 $a=$otp->generate($uid,'2fa');$b=$otp->generate($uid,'2fa');for($i=0;$a===$b&&$i<4;$i++)$b=$otp->generate($uid,'2fa');if($a===$b)throw new RuntimeException('Regeneration collision');
 // Force a valid comparison only for the invalidation predicate, not the timezone matrix.
 $db->prepare("UPDATE otp_codes SET expires_at=DATE_ADD(NOW(),INTERVAL 600 SECOND) WHERE user_id=? AND action='2fa'")->execute([$uid]);
 $old=$otp->verify($uid,$a,'2fa');$new=$otp->verify($uid,$b,'2fa');
 if($old['success']||!$new['success'])throw new RuntimeException('Regeneration failed');
 $regeneration=['old_code_success'=>$old['success'],'new_code_success'=>$new['success'],'expiry_fixture_override'=>true];
 $purge=[];$db->prepare('DELETE FROM otp_codes WHERE user_id=?')->execute([$uid]);
 foreach(['expires_at','used_at']as $field)foreach([-1,0,1]as $offset){
  $db->prepare("INSERT INTO otp_codes(user_id,action,code_hash,expires_at,used_at) VALUES (?,'phase3z_purge','non-code',DATE_ADD(NOW(),INTERVAL 1 DAY),NULL)")->execute([$uid]);$id=(int)$db->lastInsertId();
  $db->prepare('UPDATE otp_codes SET '.$field.'=DATE_ADD(DATE_SUB(NOW(),INTERVAL 24 HOUR),INTERVAL ? SECOND) WHERE id=?')->execute([$offset,$id]);
  $removed=$otp->purgeExpired();$s=$db->prepare('SELECT COUNT(*) FROM otp_codes WHERE id=?');$s->execute([$id]);$exists=(bool)$s->fetchColumn();
  if($exists!==($offset>=0))throw new RuntimeException('Purge strict boundary');$purge[]=['field'=>$field,'seconds_relative_24h_cutoff'=>$offset,'removed'=>$removed,'row_exists'=>$exists];$db->prepare('DELETE FROM otp_codes WHERE id=?')->execute([$id]);
 }
 echo json_encode(['status'=>'PASS for current predicate characterization','clock_records'=>$records,'regeneration'=>$regeneration,'purge'=>$purge,'otp_schema'=>$db->query('SHOW CREATE TABLE otp_codes')->fetch(PDO::FETCH_NUM)[1],'sql_controls'=>['SET SESSION time_zone = owned_offset','SET SESSION timestamp = simulated_epoch','UPDATE otp_codes SET used_at=NULL WHERE id=:owned_id (reset service characterization only)','UPDATE otp_codes SET expires_at=DATE_ADD(NOW(),INTERVAL 600 SECOND) WHERE user_id=:owned_uid AND action=2fa (regeneration only)'],'secrets_persisted'=>false],JSON_THROW_ON_ERROR);
}finally{date_default_timezone_set($originalZone);zone($db,$originalDb);stamp($db,0);$_SESSION=[];}
