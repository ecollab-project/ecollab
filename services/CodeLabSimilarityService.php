<?php
declare(strict_types=1);
require_once __DIR__.'/CodeLabService.php';
/** Facilitator-only comparison queue; never changes an attempt or a grade. */
final class CodeLabSimilarityService {
 public function __construct(private PDO $db,private ?Closure $transport=null){}
 private function q(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
 private function authorize(int $sid,int $uid,int $tid):array{
  if(!(new CodeLabService($this->db))->member($sid,$uid)['can_manage'])throw new RuntimeException('Facilitator access required.',403);
  $t=$this->q('SELECT * FROM code_lab_tasks WHERE id=? AND server_id=?',[$tid,$sid])->fetch(PDO::FETCH_ASSOC);if(!$t)throw new RuntimeException('Task not found.',404);return $t;
 }
 public function queue(int $sid,int $uid,int $tid):array{
  $t=$this->authorize($sid,$uid,$tid);
  if(!in_array($t['language'],CodeLabService::LANGUAGES,true))throw new RuntimeException('Unsupported comparison language.',400);
  if(!getenv('CODE_LAB_SIMILARITY_TOKEN')&&!$this->transport)throw new RuntimeException('Similarity worker is not configured.',503);
  $this->db->beginTransaction();try{
  if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite')$this->q('SELECT id FROM code_lab_tasks WHERE id=? FOR UPDATE',[$tid]);
  $existing=$this->q("SELECT id FROM code_lab_similarity_jobs WHERE task_id=? AND state IN ('queued','running') ORDER BY id DESC LIMIT 1",[$tid])->fetchColumn();if($existing){$this->db->commit();return ['job_id'=>(int)$existing];}
  if((int)$this->q("SELECT COUNT(*) FROM code_lab_similarity_jobs WHERE state IN ('queued','running')")->fetchColumn()>=100)throw new RuntimeException('Comparison queue is full. Try later.',429);
  $recent=$this->q('SELECT created_at FROM code_lab_similarity_jobs WHERE task_id=? ORDER BY id DESC LIMIT 1',[$tid])->fetchColumn();if($recent&&strtotime($recent.' UTC')>time()-300)throw new RuntimeException('Wait five minutes before scanning this task again.',429);
  $a=$this->q('SELECT id,code FROM code_lab_attempts WHERE task_id=? AND submitted_at IS NOT NULL ORDER BY id LIMIT 101',[$tid])->fetchAll(PDO::FETCH_ASSOC);
  if(count($a)<2||count($a)>100)throw new RuntimeException('Compare 2–100 submitted attempts at a time.',400);
  $snap=[];foreach($a as$row)$snap[]=['id'=>(int)$row['id'],'sha256'=>hash('sha256',$row['code'])];
  $this->q('INSERT INTO code_lab_similarity_jobs(task_id,server_id,requested_by,snapshot_json,created_at) VALUES(?,?,?,?,?)',[$tid,$sid,$uid,json_encode($snap,JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s')]);$id=(int)$this->db->lastInsertId();$this->db->commit();return ['job_id'=>$id];
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
 public function latest(int $sid,int $uid,int $tid):array{
  $this->authorize($sid,$uid,$tid);$j=$this->q('SELECT id,state,result_json,created_at,finished_at FROM code_lab_similarity_jobs WHERE task_id=? AND server_id=? ORDER BY id DESC LIMIT 1',[$tid,$sid])->fetch(PDO::FETCH_ASSOC);
  if(!$j)return ['job'=>null];$j['result']=$j['result_json']?json_decode($j['result_json'],true):null;unset($j['result_json']);return ['job'=>$j];
 }
 public function workOne():bool{
  $this->q("UPDATE code_lab_similarity_jobs SET state='failed',result_json=?,finished_at=? WHERE state='running' AND started_at<?",[json_encode(['error'=>'Comparison worker stopped. Request a new scan.']),gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s',time()-600)]);
  $j=$this->q("SELECT * FROM code_lab_similarity_jobs WHERE state='queued' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);if(!$j)return false;
  if($this->q("UPDATE code_lab_similarity_jobs SET state='running',started_at=? WHERE id=? AND state='queued'",[gmdate('Y-m-d H:i:s'),$j['id']])->rowCount()!==1)return true;
  try{
   $task=$this->authorize((int)$j['server_id'],(int)$j['requested_by'],(int)$j['task_id']);$sources=[];$names=[];
   foreach(json_decode($j['snapshot_json'],true,512,JSON_THROW_ON_ERROR)as$entry){$a=$this->q('SELECT a.code,u.full_name,u.username FROM code_lab_attempts a JOIN users u ON u.id=a.user_id WHERE a.id=? AND a.task_id=? AND a.submitted_at IS NOT NULL',[$entry['id'],$task['id']])->fetch(PDO::FETCH_ASSOC);if(!$a||!hash_equals($entry['sha256'],hash('sha256',$a['code'])))throw new RuntimeException('Snapshot changed.');$key='attempt_'.$entry['id'];$sources[$key]=$a['code'];$names[$key]=['attempt_id'=>$entry['id'],'name'=>$a['full_name']?:$a['username']];}
   $body=['language'=>$task['language'],'sources'=>$sources,'base_code'=>$task['starter_code']];
   $result=$this->transport?($this->transport)($body):$this->request($body);
   if(!is_array($result)||!is_array($result['comparisons']??null)||count($result['comparisons'])>1000)throw new RuntimeException('Invalid worker response.');
   foreach($result['comparisons'] as&$pair){if(!isset($names[$pair['first']??''],$names[$pair['second']??'']))throw new RuntimeException('Invalid comparison.');$pair['first']=$names[$pair['first']];$pair['second']=$names[$pair['second']];$score=$pair['similarity']??null;if(!is_numeric($score)||!is_finite((float)$score)||(float)$score<0||(float)$score>100)throw new RuntimeException('Invalid similarity.');}unset($pair);
   $result['submission_count']=count($sources);$result['notice']='Similarity is evidence for facilitator review, not proof of cheating. No grades were changed.';
   $this->q("UPDATE code_lab_similarity_jobs SET state='done',result_json=?,finished_at=? WHERE id=? AND state='running'",[json_encode($result,JSON_THROW_ON_ERROR),gmdate('Y-m-d H:i:s'),$j['id']]);
  }catch(Throwable $e){$this->q("UPDATE code_lab_similarity_jobs SET state='failed',result_json=?,finished_at=? WHERE id=? AND state='running'",[json_encode(['error'=>'Comparison unavailable. Check the worker or parser support, then request a new scan.']),gmdate('Y-m-d H:i:s'),$j['id']]);}
  return true;
 }
 private function request(array $body):array{
  if(!function_exists('curl_init'))throw new RuntimeException('cURL required.');$token=(string)getenv('CODE_LAB_SIMILARITY_TOKEN');if(strlen($token)<32)throw new RuntimeException('Not configured.');
  $ch=curl_init('http://127.0.0.1:2359/compare');$raw='';try{curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Auth-Token: '.$token],CURLOPT_TIMEOUT=>150,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_PROXY=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_WRITEFUNCTION=>static function($c,$chunk)use(&$raw){if(strlen($raw)+strlen($chunk)>4000000)return 0;$raw.=$chunk;return strlen($chunk);}]);$ok=curl_exec($ch);if($ok===false||curl_getinfo($ch,CURLINFO_RESPONSE_CODE)!==200)throw new RuntimeException('Worker failed.');return json_decode($raw,true,512,JSON_THROW_ON_ERROR);}finally{curl_close($ch);}
 }
}
