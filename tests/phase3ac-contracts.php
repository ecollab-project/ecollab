<?php
declare(strict_types=1);
require_once __DIR__.'/phase3y-bootstrap.php';
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$uid=(int)$in['user_id'];
$s=$db->prepare('SELECT email FROM users WHERE id=?');$s->execute([$uid]);
if(!preg_match('/^phase3y[a-z0-9_-]+@example\.test$/D',(string)$s->fetchColumn()))throw new RuntimeException('Owned actor required');
$otp=new OtpService();$records=[];
function demand(bool $ok):void{if(!$ok)throw new RuntimeException('Candidate security contract failed');}
$code=$otp->generate($uid);$before=$db->query('CHECKSUM TABLE otp_codes')->fetch(PDO::FETCH_NUM)[1];
foreach([[$uid+1000000,$code,'reset_password'],[$uid,$code,'2fa'],[$uid,'not-a-code','reset_password']]as[$u,$c,$a])demand(!$otp->verify($u,$c,$a)['success']);
demand($before===$db->query('CHECKSUM TABLE otp_codes')->fetch(PDO::FETCH_NUM)[1]);$records['wrong_binding_hash_preserve_rows']=true;
$db->beginTransaction();demand(!$otp->verify($uid,$code)['success']);demand($db->inTransaction());$db->rollBack();$records['caller_transaction_rejected_without_commit']=true;
$db->exec('SET autocommit=0');demand(!$otp->verify($uid,$code)['success']);$db->exec('SET autocommit=1');$records['disabled_autocommit_rejected']=true;
demand($otp->verify($uid,' '.$code.' ')['success']);demand(!$db->inTransaction());$db->beginTransaction();$db->rollBack();demand(!$otp->verify($uid,$code)['success']);$records['durable_success_replay_rejected_after_later_rollback']=true;
// Fail after consumption in the caller; never make the original code usable again.
$code=$otp->generate($uid);demand($otp->verify($uid,$code)['success']);try{throw new RuntimeException('simulated post-consume failure');}catch(RuntimeException $e){}demand(!$otp->verify($uid,$code)['success']);$records['post_consume_failure_does_not_resurrect']=true;
foreach([false,true]as$emulated){$db->setAttribute(PDO::ATTR_EMULATE_PREPARES,$emulated);$code=$otp->generate($uid);demand($otp->verify($uid,$code)['success']);demand(!$otp->verify($uid,$code)['success']);}$db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);$records['native_and_emulated_prepares_single_use']=true;
// Bcrypt deliberately crosses a one-second expiry; only the final DB clock decides.
$code=$otp->generate($uid);$slowHash=password_hash($code,PASSWORD_BCRYPT,['cost'=>16]);
$db->prepare('UPDATE otp_codes SET code_hash=?,expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE user_id=? AND used_at IS NULL')->execute([$slowHash,$uid]);
$start=hrtime(true);demand(!$otp->verify($uid,$code)['success']);$elapsed=(hrtime(true)-$start)/1e9;demand($elapsed>1);$records['expired_during_bcrypt']=['success'=>false,'elapsed_seconds'=>$elapsed,'fixture_bcrypt_cost'=>16];
// Inject a database serialization error at the real UPDATE; never retry or issue a token.
$code=$otp->generate($uid);
$db->exec("CREATE TRIGGER phase3ac_consume_error BEFORE UPDATE ON otp_codes FOR EACH ROW SIGNAL SQLSTATE '40001' SET MESSAGE_TEXT='owned serialization failure'");
try{$auth=new AuthService();$caught=false;try{$auth->verifyOtp($uid,$code);}catch(PDOException $e){$caught=true;}demand($caught);demand(empty($_SESSION['pwd_reset_token']));demand(!$db->inTransaction());$records['injected_serialization_error_no_retry_or_token']=true;}
finally{$db->exec('DROP TRIGGER phase3ac_consume_error');}
echo json_encode(['status'=>'PASS','checks'=>$records],JSON_THROW_ON_ERROR);
