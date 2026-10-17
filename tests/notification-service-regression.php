<?php
declare(strict_types=1);
require dirname(__DIR__).'/services/NotificationService.php';
define('BASE_URL','https://ecollab.test');
final class NotificationTestPDO extends PDO {
    public array $events=[];
    public array $calls=[];
    public array $validTokens=[];
    public function __construct() {}
    public function prepare(string $query, array $options=[]): PDOStatement|false { return new NotificationTestStatement($this,$query); }
}
final class NotificationTestStatement extends PDOStatement {
    private array $params=[];private int $changed=0;
    public function __construct(private NotificationTestPDO $db,private string $query) {}
    public function execute(?array $params=null): bool {
        $this->params=$params??[];$this->changed=0;
        if(str_starts_with($this->query,'INSERT INTO notifications'))$this->db->events[]=$this->params;
        if(str_starts_with($this->query,'UPDATE dm_call_history')){
            $id=(int)$this->params[0];
            if(($this->db->calls[$id]['status']??'')==='ringing'){$this->db->calls[$id]['status']='missed';$this->changed=1;}
        }
        return true;
    }
    public function fetchColumn(int $column=0): mixed { return in_array($this->params[0]??'', $this->db->validTokens,true)?1:false; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT, mixed ...$args): array {
        return array_values(array_filter($this->db->calls,fn($call)=>$call['callee_id']===$this->params[0]&&$call['status']==='ringing'));
    }
    public function rowCount(): int { return $this->changed; }
}
function check(bool $ok,string $message): void { if(!$ok)throw new RuntimeException($message); }
$db=new NotificationTestPDO();
NotificationService::create($db,1,1,'message','Self','test','/modules/chat/chat.php','💬');check(count($db->events)===0,'Self notifications excluded');
NotificationService::create($db,2,1,'message','Test','body','/modules/chat/chat.php?group_id=4','👥');check($db->events[0][0]===2&&$db->events[0][1]===1,'Recipient and actor retained');
$token=str_repeat('a',32);$db->validTokens=[hash('sha256',$token)];
NotificationService::invitationsFromMessage($db,2,1,'https://evil.test/modules/chat/chat.php?invite='.$token);check(count($db->events)===1,'Foreign origin invite rejected');
NotificationService::invitationsFromMessage($db,2,1,'https://ecollab.test/modules/chat/chat.php?invite='.str_repeat('z',32));check(count($db->events)===1,'Invalid or expired token rejected');
$link='https://ecollab.test/modules/chat/chat.php?channel_invite='.$token;
NotificationService::invitationsFromMessage($db,2,1,$link.' '.$link);check(count($db->events)===2,'Duplicate invite URL within one message coalesced');check($db->events[1][2]==='room_invite','Invite notification persisted');
$db->calls=[10=>['id'=>10,'callee_id'=>2,'caller_id'=>1,'status'=>'ringing'],11=>['id'=>11,'callee_id'=>2,'caller_id'=>3,'status'=>'answered'],12=>['id'=>12,'callee_id'=>4,'caller_id'=>1,'status'=>'ringing']];
NotificationService::recoverMissedCalls($db,2);check($db->calls[10]['status']==='missed','Abandoned call recorded as missed');check($db->calls[11]['status']==='answered','Answered call preserved');check($db->calls[12]['status']==='ringing','Other recipient untouched');$count=count($db->events);NotificationService::recoverMissedCalls($db,2);check(count($db->events)===$count,'Recovery does not duplicate missed notifications');
echo "Notification service regression checks passed\n";
