<?php
declare(strict_types=1);
// Exercise the route's mail branch in isolated PHP processes. No real mail transport runs.
$source=file_get_contents(dirname(__DIR__).'/API/auth/send-otp.php');
$start=strpos($source,'$sent = false;');$end=strpos($source,'$response =',$start);
if($start===false||$end===false)throw new RuntimeException('OTP mail branch not found');
$branch=substr($source,$start,$end-$start);
foreach([null,'sender@example.invalid',"sender@example.invalid\r\nBcc: other@example.invalid"] as $sender){
    $tmp=tempnam(sys_get_temp_dir(),'ecollab-otp-test-');
    $setup='<?php namespace EcollabOtpFixture; define("APP_NAME","eCollab");define("MAIL_HOST","configured");';
    if($sender!==null)$setup.='define("MAIL_FROM",'.var_export($sender,true).');';
    $setup.='$email="student";$otpPlain="123456";$GLOBALS["mail_calls"]=0;function mail($to,$subject,$body,$headers){$GLOBALS["mail_calls"]++;return true;}';
    $setup.='register_shutdown_function(function(){echo "\nRESULT:".json_encode(["status"=>http_response_code()?:200,"mail_calls"=>$GLOBALS["mail_calls"]]);});';
    file_put_contents($tmp,$setup.$branch);
    $proc=proc_open([PHP_BINARY,$tmp],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($proc))throw new RuntimeException('Unable to start PHP fixture');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);unlink($tmp);
    $result=json_decode(explode('RESULT:',$out)[1]??'',true);
    $valid=$sender==='sender@example.invalid';
    if($code!==0||!$result||$result['status']!==($valid?200:503)||$result['mail_calls']!==($valid?1:0))throw new RuntimeException('OTP sender guard failed: '.$err.$out);
}
echo "PASS: missing/invalid OTP sender fails cleanly; configured transport remains reachable without real mail\n";
