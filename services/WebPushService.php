<?php
declare(strict_types=1);
/** Explicit device opt-in, durable delivery, generic lock-screen payloads. */
final class WebPushService {
 public function __construct(private PDO $db,private ?Closure $sender=null){}
 public static function configured():bool{return strlen((string)getenv('WEB_PUSH_PUBLIC_KEY'))>40&&strlen((string)getenv('WEB_PUSH_PRIVATE_KEY'))>30&&(string)getenv('WEB_PUSH_SUBJECT')!=='';}
 private function q(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
 public static function validate(array $input):array{
  $endpoint=$input['endpoint']??'';$parts=is_string($endpoint)?parse_url($endpoint):false;$host=strtolower((string)($parts['host']??''));
  $allowed=in_array($host,['fcm.googleapis.com','updates.push.services.mozilla.com','web.push.apple.com'],true)||str_ends_with($host,'.push.services.mozilla.com')||str_ends_with($host,'.notify.windows.com');
  if(!$parts||!$allowed||($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])||(($parts['port']??443)!==443)||strlen($endpoint)>2048)throw new RuntimeException('Unsupported browser push endpoint.',400);
  $keys=$input['keys']??[];foreach(['p256dh'=>65,'auth'=>16]as$key=>$bytes){$value=$keys[$key]??'';$decoded=is_string($value)&&preg_match('/^[A-Za-z0-9_-]+$/D',$value)?base64_decode(strtr($value,'-_','+/').str_repeat('=',(4-strlen($value)%4)%4),true):false;if($decoded===false||strlen($decoded)!==$bytes||($key==='p256dh'&&ord($decoded[0])!==4))throw new RuntimeException('Invalid browser push keys.',400);}
  return ['endpoint'=>$endpoint,'keys'=>['p256dh'=>$keys['p256dh'],'auth'=>$keys['auth']],'contentEncoding'=>'aes128gcm'];
 }
 public function subscribe(int $uid,array $input):void{
  $s=self::validate($input);$hash=hash('sha256',$s['endpoint']);$this->db->beginTransaction();
  try{
   $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE';
   $this->q('SELECT id FROM users WHERE id=?'.$lock,[$uid]);
   $old=$this->q('SELECT id,user_id FROM push_subscriptions WHERE endpoint_hash=?'.$lock,[$hash])->fetch(PDO::FETCH_ASSOC);
   if(!$old||(int)$old['user_id']!==$uid){$count=(int)$this->q('SELECT COUNT(*) FROM push_subscriptions WHERE user_id=?',[$uid])->fetchColumn();if($count>=10)throw new RuntimeException('Remove an old device before enabling another.',429);}
   if($old){
    if((int)$old['user_id']!==$uid){$this->q('DELETE FROM push_deliveries WHERE subscription_id=?',[$old['id']]);$this->q('UPDATE push_subscriptions SET created_at=? WHERE id=?',[gmdate('Y-m-d H:i:s'),$old['id']]);}
    $this->q('UPDATE push_subscriptions SET user_id=?,subscription_json=?,updated_at=? WHERE id=?',[$uid,json_encode($s),gmdate('Y-m-d H:i:s'),$old['id']]);
   }else $this->q('INSERT INTO push_subscriptions(user_id,endpoint_hash,subscription_json,created_at,updated_at) VALUES(?,?,?,?,?)',[$uid,$hash,json_encode($s),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s')]);
   $this->db->commit();
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function unsubscribe(int $uid,string $endpoint):void{
  $this->db->beginTransaction();try{
   $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'':' FOR UPDATE';
   $id=$this->q('SELECT id FROM push_subscriptions WHERE user_id=? AND endpoint_hash=?'.$lock,[$uid,hash('sha256',$endpoint)])->fetchColumn();
   if($id){$this->q('DELETE FROM push_deliveries WHERE subscription_id=?',[$id]);$this->q('DELETE FROM push_subscriptions WHERE id=? AND user_id=?',[$id,$uid]);}
   $this->db->commit();
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public static function preference(string $type,string $link=''):string{if($type==='message'&&str_contains($link,'group_id='))return 'notification_groups';return match($type){'mention'=>'notification_mentions','reply','thread_reply'=>'notification_replies','thread','thread_comment'=>'notification_threads','reaction'=>'notification_reactions','connection_request','connection_accepted'=>'notification_connections','announcement','class_update'=>'notification_announcements','match'=>'notification_matches',default=>'notification_messages'};}
 public function deadlineNotices():void{
  require_once __DIR__.'/NotificationService.php';
  $rows=$this->q("SELECT t.id,t.server_id,t.created_by,m.user_id FROM code_lab_tasks t JOIN server_members m ON m.server_id=t.server_id LEFT JOIN code_lab_attempts a ON a.task_id=t.id AND a.user_id=m.user_id LEFT JOIN code_lab_deadline_notices d ON d.task_id=t.id AND d.user_id=m.user_id WHERE d.task_id IS NULL AND t.due_at>? AND t.due_at<=? AND (t.opens_at IS NULL OR t.opens_at<=?) AND a.submitted_at IS NULL AND m.user_id<>t.created_by ORDER BY t.due_at LIMIT 500",[gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s',time()+3600),gmdate('Y-m-d H:i:s')])->fetchAll(PDO::FETCH_ASSOC);
  $ignore=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE';
  foreach($rows as$r){$created=$this->q($ignore.' INTO code_lab_deadline_notices(task_id,user_id,created_at) VALUES(?,?,?)',[$r['id'],$r['user_id'],gmdate('Y-m-d H:i:s')]);if($created->rowCount()===1)NotificationService::create($this->db,(int)$r['user_id'],(int)$r['created_by'],'class_update','Code Lab deadline soon','An unfinished Code Lab task is due within one hour.','/modules/code-lab/index.php?server_id='.(int)$r['server_id'],'⏰');}
 }
 public function workOne():bool{
  if(!$this->sender&&!self::configured())return false;
  // Generic payloads reveal no sender, message, source code, or assignment details to the push vendor.
  $this->q("UPDATE push_deliveries SET state='queued',available_at=?,started_at=NULL WHERE state='running' AND started_at<?",[gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s',time()-120)]);
  $rows=$this->q("SELECT n.id,n.type,s.id AS subscription_id FROM notifications n JOIN push_subscriptions s ON s.user_id=n.recipient_id JOIN users u ON u.id=n.recipient_id LEFT JOIN user_settings p ON p.user_id=u.id WHERE NOT EXISTS(SELECT 1 FROM push_deliveries d WHERE d.notification_id=n.id AND d.subscription_id=s.id) AND n.is_read=0 AND n.created_at>=s.created_at AND n.created_at>=? AND u.deleted_at IS NULL AND u.status NOT IN ('banned','suspended','deactivated') AND COALESCE(p.notification_desktop,1)=1 ORDER BY n.id DESC LIMIT 500",[gmdate('Y-m-d H:i:s',time()-86400)])->fetchAll(PDO::FETCH_ASSOC);
  $ignore=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE';
  foreach($rows as$r)$this->q($ignore.' INTO push_deliveries(notification_id,subscription_id,available_at) VALUES(?,?,?)',[$r['id'],$r['subscription_id'],gmdate('Y-m-d H:i:s')]);
  $job=$this->q("SELECT * FROM push_deliveries WHERE state='queued' AND available_at<=? ORDER BY id LIMIT 1",[gmdate('Y-m-d H:i:s')])->fetch(PDO::FETCH_ASSOC);if(!$job)return false;
  if($this->q("UPDATE push_deliveries SET state='running',started_at=?,attempts=attempts+1 WHERE id=? AND state='queued'",[gmdate('Y-m-d H:i:s'),$job['id']])->rowCount()!==1)return true;
  $row=$this->q('SELECT s.subscription_json,n.type,n.link_url,n.is_read,n.recipient_id,u.status,u.deleted_at FROM push_subscriptions s JOIN notifications n ON n.id=? AND n.recipient_id=s.user_id JOIN users u ON u.id=s.user_id WHERE s.id=?',[$job['notification_id'],$job['subscription_id']])->fetch(PDO::FETCH_ASSOC);
  $prefs=$row?$this->q('SELECT * FROM user_settings WHERE user_id=?',[$row['recipient_id']])->fetch(PDO::FETCH_ASSOC):false;
  if(!$row||$row['is_read']||$row['deleted_at']!==null||in_array($row['status'],['banned','suspended','deactivated'],true)||($prefs&&(!(int)$prefs['notification_desktop']||!(int)($prefs[self::preference($row['type'],(string)($row['link_url']??''))]??1)))){$this->finish((int)$job['id'],'skipped');return true;}
  try{
   $subscription=self::validate(json_decode($row['subscription_json'],true,512,JSON_THROW_ON_ERROR));
   $payload=json_encode(['title'=>'Ecollab','body'=>'You have a new notification. Open Ecollab to view it.','tag'=>'ecollab-updates','url'=>rtrim((string)BASE_URL,'/').'/modules/chat/chat.php'],JSON_THROW_ON_ERROR);
   $result=$this->sender?($this->sender)($subscription,$payload):$this->send($subscription,$payload);
   if($result==='expired'){$this->q('DELETE FROM push_subscriptions WHERE id=?',[$job['subscription_id']]);$this->finish((int)$job['id'],'expired');}
   elseif($result==='sent')$this->finish((int)$job['id'],'sent');
   else throw new RuntimeException('Delivery failed.');
  }catch(Throwable $e){if((int)$job['attempts']>=4)$this->finish((int)$job['id'],'failed');else $this->q("UPDATE push_deliveries SET state='queued',available_at=? WHERE id=?",[gmdate('Y-m-d H:i:s',time()+min(3600,60*(2**(int)$job['attempts']))),$job['id']]);}
  $this->q('DELETE FROM push_deliveries WHERE finished_at<?',[gmdate('Y-m-d H:i:s',time()-604800)]);return true;
 }
 private function finish(int $id,string $state):void{$this->q('UPDATE push_deliveries SET state=?,finished_at=? WHERE id=?',[$state,gmdate('Y-m-d H:i:s'),$id]);}
 private function send(array $subscription,string $payload):string{
  require_once dirname(__DIR__).'/tools/web-push/vendor/autoload.php';
  $factory=new GuzzleHttp\Psr7\HttpFactory();$client=new GuzzleHttp\Client(['timeout'=>15,'connect_timeout'=>3,'allow_redirects'=>false,'proxy'=>'']);
  $push=new Minishlink\WebPush\WebPush(['VAPID'=>['subject'=>(string)getenv('WEB_PUSH_SUBJECT'),'publicKey'=>(string)getenv('WEB_PUSH_PUBLIC_KEY'),'privateKey'=>(string)getenv('WEB_PUSH_PRIVATE_KEY')]],['TTL'=>3600],$client,$factory,$factory);
  $report=$push->sendOneNotification(Minishlink\WebPush\Subscription::create($subscription),$payload);return $report->isSuccess()?'sent':($report->isSubscriptionExpired()?'expired':'failed');
 }
}
