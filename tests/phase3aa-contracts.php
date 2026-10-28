<?php
declare(strict_types=1);
require_once __DIR__.'/phase3y-bootstrap.php';require_once __DIR__.'/experiments/phase3aa/UtcOtpTrial.php';
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,get_class($e).': owned contract experiment failed');exit(1);});
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$uid=(int)$in['user_id'];$s=$db->prepare('SELECT email FROM users WHERE id=?');$s->execute([$uid]);$email=(string)$s->fetchColumn();if(!preg_match('/^phase3y[a-z0-9_-]+@example\.test$/D',$email))throw new RuntimeException('Owned actor');
$db->exec("SET SESSION time_zone='+00:00'");$db->exec('SET timestamp=0');date_default_timezone_set('UTC');$records=[];
foreach(['original','utc_trial']as$v){
 $otp=$v==='original'?new OtpService():new \Tests\Experiments\Phase3aa\UtcOtpTrial();
 $db->prepare('DELETE FROM otp_codes WHERE user_id=?')->execute([$uid]);$code=$otp->generate($uid,'reset_password');$before=$db->query('CHECKSUM TABLE otp_codes')->fetch(PDO::FETCH_NUM)[1];
 $wrong=$code==='000000'?'111111':'000000';$results=['wrong_user'=>$otp->verify($uid+1000000,$code),'wrong_action'=>$otp->verify($uid,$code,'2fa'),'wrong_code'=>$otp->verify($uid,$wrong)];$after=$db->query('CHECKSUM TABLE otp_codes')->fetch(PDO::FETCH_NUM)[1];
 if($before!==$after)throw new RuntimeException('Rejection changed OTP state');foreach($results as$r)if($r['success'])throw new RuntimeException('Unexpected verification');
 $countBefore=$otp->countValid($uid);$trim=$otp->verify($uid,' '.$code.' ');$replay=$otp->verify($uid,$code);$countAfter=$otp->countValid($uid);if($countBefore!==1||$countAfter!==0||!$trim['success']||$replay['success'])throw new RuntimeException('Consumption contract');
 $row=['variant'=>$v,'negative_results'=>$results,'state_preserved'=>$before===$after,'count_before'=>$countBefore,'trimmed_code_success'=>$trim['success'],'replay'=>$replay,'count_after'=>$countAfter];
 if(!empty($in['delivery_offline'])){
  $old=$otp->generate($uid,'reset_password');$delivery=$otp->deliver($email,'Owned Synthetic Actor',$old,'reset_password');if($delivery['success'])throw new RuntimeException('Offline transport unexpectedly worked');
  $auth=new AuthService();if($v==='utc_trial')(new ReflectionProperty(AuthService::class,'otpService'))->setValue($auth,$otp);$outcome=$auth->forgotPassword($email);
  $s=$db->prepare('SELECT id,action,expires_at,created_at,used_at FROM otp_codes WHERE user_id=? ORDER BY id');$s->execute([$uid]);$stored=$s->fetchAll();
  if(!$outcome['success']||array_key_exists('otp_debug',$outcome)||$otp->countValid($uid)!==1)throw new RuntimeException('Existing delivery failure contract changed');
  $row['delivery_failure']=['transport'=>$delivery,'forgot_result_success'=>$outcome['success'],'forgot_result_keys'=>array_keys($outcome),'debug_present'=>array_key_exists('otp_debug',$outcome),'stored_rows'=>$stored,'note'=>'Existing forgotPassword returns success despite delivery failure; prior code invalidated,new unused row persists; not repaired or approved here'];
 }
 $records[]=$row;
}
echo json_encode(['status'=>'PASS for current contracts','records'=>$records,'delivery_offline'=>(bool)($in['delivery_offline']??false)],JSON_THROW_ON_ERROR);
