<?php
declare(strict_types=1);
require_once __DIR__.'/phase3y-bootstrap.php';require_once __DIR__.'/experiments/phase3aa/UtcOtpTrial.php';
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,get_class($e).': owned transition experiment failed');exit(1);});
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$uid=(int)$in['user_id'];$s=$db->prepare('SELECT email FROM users WHERE id=?');$s->execute([$uid]);
if(!preg_match('/^phase3y[a-z0-9_-]+@example\.test$/D',(string)$s->fetchColumn()))throw new RuntimeException('Owned transition actor');
$db->exec("SET SESSION time_zone='+00:00'");date_default_timezone_set('Asia/Manila');$epoch=time();$db->exec('SET timestamp='.$epoch);$old=new OtpService();$trial=new \Tests\Experiments\Phase3aa\UtcOtpTrial();$codes=[];
foreach(['reset_password','verify_email','2fa']as$a)$codes[$a]=$old->generate($uid,$a);
$db->exec('SET timestamp='.($epoch+OTP_EXPIRY+1));$oldRows=$db->prepare('SELECT action,created_at,expires_at,used_at,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),expires_at) AS trial_remaining FROM otp_codes WHERE user_id=? AND used_at IS NULL ORDER BY id');$oldRows->execute([$uid]);$before=$oldRows->fetchAll();
foreach(array_keys($codes)as$a)if($trial->countValid($uid,$a)!==1)throw new RuntimeException('Legacy unsafe control missing');
// Demonstrate data rollback before release: it restores the unsafe naive records.
$db->beginTransaction();$s=$db->prepare('DELETE FROM otp_codes WHERE user_id=?');$s->execute([$uid]);$inside=$s->rowCount();$db->rollBack();$rollbackRestores=$trial->countValid($uid,'reset_password')===1;
// Simulate approved-scope invalidation on one owned fixture actor only; production table-wide choice remains unapproved.
$s=$db->prepare('DELETE FROM otp_codes WHERE user_id=?');$s->execute([$uid]);$deleted=$s->rowCount();$after=[];
foreach($codes as$a=>$code){$r=$trial->verify($uid,$code,$a);if($r['success'])throw new RuntimeException('Invalidated code resurrected');$after[$a]=$r;}
$db->exec('SET timestamp='.$epoch);$fresh=[];foreach(array_keys($codes)as$a){$c=$trial->generate($uid,$a);$db->exec('SET timestamp='.($epoch+OTP_EXPIRY+1));$r=$trial->verify($uid,$c,$a);if($r['success'])throw new RuntimeException('Fresh UTC overlong');$fresh[$a]=$r;$db->exec('SET timestamp='.$epoch);}
// An old writer after cutover can reintroduce the unsafe time representation.
$old->generate($uid,'reset_password');$db->exec('SET timestamp='.($epoch+OTP_EXPIRY+1));$mixedValid=$trial->countValid($uid,'reset_password');if($mixedValid!==1)throw new RuntimeException('Mixed-writer control missing');
$out=['status'=>'PASS for private transition characterization','scope'=>'Only disposable owned actor rows, not production invalidation','legacy_rows_before'=>$before,'legacy_codes_still_valid_at_simulated_ttl_plus_one'=>true,'transaction_delete_rows'=>$inside,'rollback_restored_unsafe_rows'=>$rollbackRestores,'simulated_invalidation_deleted_rows'=>$deleted,'old_code_verification_after_invalidation'=>$after,'fresh_trial_verification_at_ttl_plus_one'=>$fresh,'old_writer_after_cutover_reintroduces_valid_legacy_row'=>$mixedValid===1,'production_transition_approved'=>false];
$db->prepare('DELETE FROM otp_codes WHERE user_id=?')->execute([$uid]);$db->exec('SET timestamp=0');echo json_encode($out,JSON_THROW_ON_ERROR);
