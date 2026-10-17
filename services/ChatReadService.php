<?php
declare(strict_types=1);
/** Called only after each endpoint's existing channel/DM/group authorization. */
final class ChatReadService {
 public function __construct(private PDO $db){}
 private function query(string $sql,array $args=[]):PDOStatement{$s=$this->db->prepare($sql);$s->execute($args);return $s;}
 private static function source(string $scope):array{return match($scope){'channel'=>['messages','channel_id','message_reads'],'dm'=>['dm_messages','conversation_id','dm_reads'],'group'=>['dm_messages','group_id',null],default=>throw new InvalidArgumentException('Invalid chat scope')};}
 public function snapshot(string $scope,int $cid,int $uid):array{
  [$table,$column,$legacy]=self::source($scope);
  $last=$this->query('SELECT last_message_id FROM chat_read_cursors WHERE user_id=? AND scope=? AND conversation_id=?',[$uid,$scope,$cid])->fetchColumn();
  if($last===false){$last=0;if($legacy){$legacyColumn=$scope==='channel'?'channel_id':'conversation_id';$time=$this->query("SELECT last_read_at FROM $legacy WHERE user_id=? AND $legacyColumn=?",[$uid,$cid])->fetchColumn();if($time)$last=$this->query("SELECT COALESCE(MAX(id),0) FROM $table WHERE $column=? AND created_at<=?",[$cid,$time])->fetchColumn();}}
  $first=$this->query("SELECT MIN(id) FROM $table WHERE $column=? AND id>? AND sender_id<>? AND is_deleted=0",[$cid,(int)$last,$uid])->fetchColumn();
  return ['last_read_id'=>(int)$last,'first_unread_id'=>$first?(int)$first:null];
 }
 public function mark(string $scope,int $cid,int $uid,array $messages,?int $after=null):void{
  [$table,$column]=self::source($scope);$max=0;foreach($messages as$m)$max=max($max,(int)$m['id']);if(!$max&&$after!==null)$max=(int)$this->query("SELECT COALESCE(MAX(id),0) FROM $table WHERE $column=? AND id<=?",[$cid,$after])->fetchColumn();if(!$max)return;
  if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')$sql='INSERT INTO chat_read_cursors(user_id,scope,conversation_id,last_message_id) VALUES(?,?,?,?) ON CONFLICT(user_id,scope,conversation_id) DO UPDATE SET last_message_id=MAX(last_message_id,excluded.last_message_id)';
  else $sql='INSERT INTO chat_read_cursors(user_id,scope,conversation_id,last_message_id) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE last_message_id=GREATEST(last_message_id,VALUES(last_message_id))';
  $this->query($sql,[$uid,$scope,$cid,$max]);
 }
}
