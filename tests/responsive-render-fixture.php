<?php
declare(strict_types=1);
// CLI-only presentation fixtures. No connection to production data or services.
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/config.php';
require ROOT_PATH.'/database/config/db.php';
require ROOT_PATH.'/security/middleware/AuthMiddleware.php';
class ResponsiveStatement extends PDOStatement {
    public function __construct(private string $sql){}
    public function execute(?array $params=null):bool{return true;}
    public function fetchColumn(int $column=0):mixed{return str_contains(strtolower($this->sql),'select role')?($_SESSION['role']??'student'):1;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {
        if(preg_match('/FROM\s+servers\b/i',$this->sql))return array_map(fn($i)=>['id'=>$i,'name'=>'Learning community with a long descriptive name '.$i,'slug'=>'space-'.$i,'icon_emoji'=>'📚','icon_url'=>'','category'=>'academic','type'=>'community','member_count'=>16,'server_role'=>'owner'],range(1,12));
        if(preg_match('/FROM\s+channels\b/i',$this->sql))return [['id'=>11,'server_id'=>1,'name'=>'general','slug'=>'general','type'=>'text','description'=>'Learning together','position'=>0,'is_private'=>0,'is_locked'=>0,'member_count'=>16,'unread_count'=>0,'is_new'=>0],['id'=>12,'server_id'=>1,'name'=>'general-voice','slug'=>'general-voice','type'=>'voice','description'=>'','position'=>1,'is_private'=>0,'is_locked'=>0,'member_count'=>16,'unread_count'=>0,'is_new'=>0]];
        return [];
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed{return false;}
}
class ResponsivePDO extends PDO {
    public function __construct(){}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return new ResponsiveStatement($query);}
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false{return new ResponsiveStatement($query);}
    public function exec(string $statement):int|false{return 0;}
}
(new ReflectionProperty(Database::class,'instance'))->setValue(null,new ResponsivePDO());
$pages=['landing'=>'index.php','policy'=>'terms.php','login'=>'modules/auth/login.php','signup'=>'modules/auth/signup.php','forgot'=>'modules/auth/forgot-password.php','admin'=>'modules/admin/dashboard.php','student'=>'modules/student/dashboard.php','facilitator'=>'modules/facilitator/dashboard.php','chat'=>'modules/chat/chat.php','settings'=>'modules/chat/settings.php','library'=>'modules/library/library.php','onboarding'=>'modules/onboarding/server-discovery.php','whiteboard'=>'modules/whiteboard/index.php','myspace'=>'modules/collaboration/my-space.php'];
$name=$argv[1]??'';if(!isset($pages[$name]))exit(1);
AuthMiddleware::startSession();
$_SESSION=in_array($name,['landing','policy','login','signup','forgot'],true)?[]:['user_id'=>1,'username'=>'responsive_learner','full_name'=>'Responsive Test Learner','email'=>'responsive@example.invalid','role'=>$name==='admin'?'admin':($name==='facilitator'?'facilitator':'student'),'csrf_token'=>'test-csrf'];
$_GET=['server_id'=>1,'channel_id'=>11];$_SERVER['REQUEST_METHOD']='GET';
include ROOT_PATH.'/'.$pages[$name];
