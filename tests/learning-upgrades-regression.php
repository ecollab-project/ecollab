<?php
declare(strict_types=1);
// SQLite tests exercise authorization and delivery state without a push vendor.
if(!function_exists('mb_substr')){function mb_substr(string $s,int $offset,?int $length=null):string{return substr($s,$offset,$length);}}
define('BASE_URL','https://example.invalid');
require __DIR__.'/code-lab-regression.php';
require dirname(__DIR__).'/services/CodeLabSimilarityService.php';
require dirname(__DIR__).'/services/WebPushService.php';
foreach(['056_code_lab_similarity.sql','057_web_push.sql']as$file){
 $sql=file_get_contents(dirname(__DIR__).'/database/migrations/'.$file);
 $sql=str_replace('BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY','INTEGER PRIMARY KEY AUTOINCREMENT',$sql);
 $sql=preg_replace('/,\s*INDEX \w+\([^)]*\)/','',$sql);
 $sql=preg_replace('/UNIQUE KEY \w+\(/','UNIQUE(',$sql);
 $sql=str_replace(') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',');',$sql);$db->exec($sql);
}
$db->exec('CREATE TABLE user_settings(user_id INTEGER PRIMARY KEY,notification_desktop INTEGER DEFAULT 1,notification_messages INTEGER DEFAULT 1,notification_announcements INTEGER DEFAULT 1)');
$db->exec("UPDATE users SET status='active';INSERT INTO server_members VALUES(1,2)");
// New independent task, immutable submissions, transport stub only at the parser boundary.
$clock=time();$lab=new CodeLabService($db,fn()=>$GLOBALS['clock']);$task=$lab->create(1,1,['title'=>'Compare','instructions'=>'Solve it','language'=>'python','kind'=>'assignment'])['id'];
foreach([2,3]as$uid){$lab->start(1,$uid,$task);$lab->save(1,$uid,$task,['code'=>'print(42)','version'=>1],true);}
$sim=new CodeLabSimilarityService($db,static function(array $body):array{clCheck(count($body['sources'])===2,'Only submitted snapshot sent');$ids=array_keys($body['sources']);return ['comparisons'=>[['first'=>$ids[0],'second'=>$ids[1],'similarity'=>95,'matches'=>[]]],'engine'=>'test'];});
clReject(fn()=>$sim->queue(1,2,$task),403,'Student cannot request similarity');clReject(fn()=>$sim->latest(1,3,$task),403,'Student cannot read similarity');clReject(fn()=>$sim->queue(2,1,$task),403,'Cross server comparison rejected');
$job=$sim->queue(1,1,$task);clCheck($sim->queue(1,1,$task)===$job,'Concurrent requests reuse queued scan');clCheck($sim->workOne(),'Queued scan processed');$report=$sim->latest(1,1,$task)['job'];clCheck($report['state']==='done'&&$report['result']['comparisons'][0]['first']['name']==='Alice','Report maps anonymous IDs only after processing');clCheck($db->query('SELECT COUNT(*) FROM code_lab_attempts WHERE task_id='.$task.' AND score IS NOT NULL')->fetchColumn()==0,'Similarity never changes grades');
clReject(fn()=>$sim->queue(1,1,$task),429,'Comparison rate limited');
function subscription(string $suffix='one'):array{$b64=fn($v)=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');return ['endpoint'=>'https://fcm.googleapis.com/fcm/send/'.$suffix,'keys'=>['p256dh'=>$b64(chr(4).str_repeat('a',64)),'auth'=>$b64(str_repeat('b',16))]];}
foreach(['http://fcm.googleapis.com/x','https://127.0.0.1/x','https://fcm.googleapis.com.evil.invalid/x','https://fcm.googleapis.com:444/x','https://name:secret@fcm.googleapis.com/x','https://fcm.googleapis.com/x#fragment']as$url){$s=subscription();$s['endpoint']=$url;clReject(fn()=>WebPushService::validate($s),400,'Unsafe endpoint rejected');}
$sent=[];$push=new WebPushService($db,static function($subscription,$payload)use(&$sent){$sent[]=json_decode($payload,true);return 'sent';});$push->subscribe(2,subscription());
$db->exec('DELETE FROM notifications');NotificationService::create($db,2,1,'class_update','Private title','Private source details','/secret','x');
clCheck($push->workOne(),'Push delivered');clCheck(count($sent)===1&&!str_contains(json_encode($sent),'Private'),'Push payload hides content');$push->workOne();clCheck(count($sent)===1,'No duplicate deliveries');
$db->exec('INSERT INTO user_settings(user_id,notification_desktop) VALUES(2,0)');NotificationService::create($db,2,1,'message','Hidden','Hidden','/','x');$push->workOne();clCheck(count($sent)===1,'Disabled desktop preference suppresses push');$db->exec('UPDATE user_settings SET notification_desktop=1,notification_announcements=0 WHERE user_id=2');NotificationService::create($db,2,1,'class_update','Hidden','Hidden','/','x');$push->workOne();clCheck(count($sent)===1,'Category preference suppresses push');
$push->subscribe(3,subscription());clCheck((int)$db->query('SELECT user_id FROM push_subscriptions')->fetchColumn()===3,'Device ownership switches accounts');clCheck((int)$db->query('SELECT COUNT(*) FROM push_deliveries')->fetchColumn()===0,'Switch clears old deliveries');$push->unsubscribe(2,subscription()['endpoint']);clCheck((int)$db->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn()===1,'Cannot unsubscribe another account');$push->unsubscribe(3,subscription()['endpoint']);clCheck((int)$db->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn()===0,'Owner can disable device');
$deadline=$lab->create(1,1,['title'=>'Due soon','instructions'=>'Solve','language'=>'c','kind'=>'assignment','due_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+1800)])['id'];$push->deadlineNotices();$count=(int)$db->query('SELECT COUNT(*) FROM code_lab_deadline_notices')->fetchColumn();$push->deadlineNotices();clCheck($count===2&&(int)$db->query('SELECT COUNT(*) FROM code_lab_deadline_notices')->fetchColumn()===$count,'Deadline notices deduplicated per member');
$push->subscribe(2,subscription('expired'));$db->exec('UPDATE user_settings SET notification_announcements=1 WHERE user_id=2');NotificationService::create($db,2,1,'message','New','New','/','x');$expired=new WebPushService($db,fn()=>'expired');$expired->workOne();clCheck((int)$db->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn()===0,'Expired vendor endpoint removed');
echo "PASS: facilitator-only immutable comparisons, unchanged grades, rate limits, push endpoint validation, privacy, preferences, deduplication, account ownership and deadlines\n";
