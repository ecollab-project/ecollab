<?php
declare(strict_types=1);
$deliveryEnabled=true;
function env($name,$default=''){global $deliveryEnabled;return $name==='CENTRIFUGO_ENABLED'?$deliveryEnabled:$default;}
require_once dirname(__DIR__).'/services/CentrifugoService.php';
require_once dirname(__DIR__).'/services/RealtimeOutbox.php';
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
$secret=str_repeat('a',64);$broker=new CentrifugoService($secret,str_repeat('b',64),'http://127.0.0.1:18001/api');
foreach([null,'inbox:user18'] as $channel){
    [$header,$body,$signature]=explode('.',$broker->token(18,$channel,100));
    $claims=json_decode(base64_decode(strtr($body,'-_','+/')),true);
    check($claims['sub']==='18' && $claims['exp']===400,'Subject and expiry');
    check(($claims['channel']??null)===$channel,'Subscription scope');
    check(hash_equals(rtrim(strtr(base64_encode(hash_hmac('sha256',"$header.$body",$secret,true)),'+/','-_'),'='),$signature),'JWT signature');
}
try{$broker->token(18,'inbox:user19');throw new LogicException('Cross-user token issued');}catch(RuntimeException $e){check($e->getCode()===403,'Cross-user rejection');}
foreach(['http://example.com/api','http://user@localhost/api','http://localhost/api?key=x'] as $url){try{new CentrifugoService($secret,str_repeat('b',64),$url);throw new LogicException('Unsafe endpoint accepted');}catch(RuntimeException $e){}}
class OutboxFixture {
    public bool $transaction=false,$fail=false;public array $committed=[], $pending=[];public int $commits=0,$rollbacks=0;
    function inTransaction(){return $this->transaction;}
    function beginTransaction(){$this->transaction=true;}
    function commit(){$this->committed=array_merge($this->committed,$this->pending);$this->pending=[];$this->transaction=false;$this->commits++;}
    function rollBack(){$this->pending=[];$this->transaction=false;$this->rollbacks++;}
    function prepare($sql){return new class($this) {function __construct(private OutboxFixture $db){} function execute($params){if($this->db->fail)throw new RuntimeException('Outbox failed');$this->db->pending[]=$params;}};}
}
$db=new OutboxFixture();check(RealtimeOutbox::record($db,'dm',2,function()use($db){$db->pending[]='message';return 9;})===9,'Saved ID');
check($db->commits===1 && count($db->committed)===2,'Message and outbox committed together');
$db->fail=true;try{RealtimeOutbox::record($db,'dm',2,function()use($db){$db->pending[]='message';return 10;});throw new LogicException('Failed intent committed');}catch(RuntimeException $e){}
check($db->rollbacks===1 && count($db->committed)===2 && !$db->pending,'Failure rolls message back');
$db->fail=false;$db->beginTransaction();RealtimeOutbox::record($db,'group',3,fn()=>11);check($db->inTransaction() && $db->commits===1,'Outer transaction retained');$db->rollBack();
$deliveryEnabled=false;check(RealtimeOutbox::record($db,'dm',2,fn()=>12)===12 && !$db->inTransaction(),'Disabled path stays unchanged');
if(getenv('TEST_BROKER_API')){(new CentrifugoService($secret,str_repeat('b',64),getenv('TEST_BROKER_API')))->command('info',[]);$broker->broadcast([18,18],['type'=>'test','event_id'=>1]);}
echo "Centrifugo token and transaction checks passed\n";
