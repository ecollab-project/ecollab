<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ChatUploadAccess.php';
class UploadTestStatement {
    private $db; private string $sql;
    public function __construct($db,string $sql){$this->db=$db;$this->sql=$sql;}
    public function execute(array $args):void { $this->db->lastArgs=$args; }
    public function fetchColumn(){
        if(str_contains($this->sql,'FROM users'))return $this->db->role;
        if(str_contains($this->sql,'moderation_actions'))return $this->db->moderated?1:false;
        return $this->db->member?1:false;
    }
    public function fetch($mode){return $this->db->channel;}
}
class UploadTestDb {
    public string $role='student'; public bool $member=true; public bool $moderated=false; public array $lastArgs=[];
    public array $channel=['type'=>'text','is_private'=>0,'created_by'=>5,'server_id'=>5,'server_role'=>'member','has_access'=>0];
    public function prepare(string $sql){return new UploadTestStatement($this,$sql);}
}
function denied(callable $fn,int $code):void {try{$fn();}catch(RuntimeException $e){if($e->getCode()===$code)return;throw $e;}throw new RuntimeException('Expected access denial');}
$db=new UploadTestDb();
ChatUploadAccess::check($db,18,'channel',20);
$db->channel['is_private']=1; denied(fn()=>ChatUploadAccess::check($db,18,'channel',20),403);
$db->channel['has_access']=1; ChatUploadAccess::check($db,18,'channel',20);
$db->channel['type']='announcement'; denied(fn()=>ChatUploadAccess::check($db,18,'channel',20),403);
$db->channel['type']='text';$db->moderated=true;denied(fn()=>ChatUploadAccess::check($db,18,'channel',20),403);
$db->channel['server_role']='owner';ChatUploadAccess::check($db,18,'channel',20);
$db->channel['server_role']=null;denied(fn()=>ChatUploadAccess::check($db,18,'channel',20),403);
$db->member=false;denied(fn()=>ChatUploadAccess::check($db,18,'dm',1),403);denied(fn()=>ChatUploadAccess::check($db,18,'group',1),403);
$db->member=true;ChatUploadAccess::check($db,18,'dm',1);
if($db->lastArgs!==[':id'=>1,':a'=>18,':b'=>18])throw new RuntimeException('DM owner predicates');
ChatUploadAccess::check($db,18,'group',2);
denied(fn()=>ChatUploadAccess::check($db,18,'unknown',2),400);
echo "Chat upload access regression checks passed\n";
