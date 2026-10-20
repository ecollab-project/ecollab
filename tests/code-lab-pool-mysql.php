<?php
declare(strict_types=1);
require dirname(__DIR__).'/services/CodeLabExecutionService.php';
require dirname(__DIR__).'/services/CodeLabWorkerPool.php';
// Destructive synthetic schema fixture, restricted to the dedicated local CI DB.
$dsn=getenv('CODE_LAB_POOL_TEST_DSN')?:'';
if($dsn!=='mysql:host=127.0.0.1;port=3306;dbname=code_lab_pool_test')throw new RuntimeException('Use dedicated local CI database');
function poolDb():PDO{return new PDO(getenv('CODE_LAB_POOL_TEST_DSN'),'root','test-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);}
if(($argv[1]??'')==='worker'){
    $db=poolDb();$name=CodeLabWorkerPool::acquireSlot($db,(int)$argv[2]);
    $runner=new CodeLabRunner('http://127.0.0.1:2358','fixture',static function($method,$path,$body)use($db):array{
        if($method==='POST'){
            if($db->inTransaction())throw new LogicException('Dispatcher lock held during execution');
            $source=base64_decode($body['source_code']);
            $db->prepare('INSERT INTO pool_audit(marker) VALUES(?)')->execute([$source]);
            usleep($source==='job-1'?400000:20000);
            if($source==='job-80')throw new RuntimeException('Synthetic infrastructure outage');
            return ['token'=>'pool-fixture-token'];
        }
        return ['status'=>['id'=>3],'stdout'=>base64_encode('42'),'stderr'=>'','compile_output'=>''];
    });
    $service=new CodeLabExecutionService($db,$runner);
    while($service->workOne()){}
    exit(0);
}
$db=poolDb();
foreach(['code_lab_dispatch_cursor','pool_claims','pool_audit','code_lab_test_cases','code_lab_jobs','code_lab_run_gates','code_lab_attempts','code_lab_playgrounds','code_lab_tasks','server_members','servers','users']as$table)$db->exec('DROP TABLE IF EXISTS '.$table);
$db->exec(file_get_contents(dirname(__DIR__).'/database/migrations/054_code_lab.sql'));
$db->exec(file_get_contents(dirname(__DIR__).'/database/migrations/055_code_lab_execution.sql'));
$db->exec(file_get_contents(dirname(__DIR__).'/database/migrations/062_code_lab_server_queue.sql'));
$db->exec("CREATE TABLE users(id INT PRIMARY KEY,role VARCHAR(30),deleted_at DATETIME NULL,status VARCHAR(20));CREATE TABLE servers(id INT PRIMARY KEY,name VARCHAR(50),owner_id INT,status VARCHAR(20));CREATE TABLE server_members(server_id INT,user_id INT);CREATE TABLE pool_audit(marker VARCHAR(50) PRIMARY KEY);INSERT INTO users VALUES(1,'student',NULL,'active');INSERT INTO servers VALUES(1,'Fixture',1,'active');INSERT INTO server_members VALUES(1,1)");
// Separate connections cannot acquire an occupied slot; another slot is independent.
$other=poolDb();$name=CodeLabWorkerPool::acquireSlot($db,1);
try{CodeLabWorkerPool::acquireSlot($other,1);throw new LogicException('Duplicate slot acquired');}catch(RuntimeException $e){}
$second=CodeLabWorkerPool::acquireSlot($other,2);
$db->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);$other->prepare('SELECT RELEASE_LOCK(?)')->execute([$second]);
$db->exec("INSERT INTO servers VALUES(2,'Second',1,'active'),(3,'Third',1,'active');INSERT INTO server_members VALUES(2,1),(3,1);CREATE TABLE pool_claims(sequence INT AUTO_INCREMENT PRIMARY KEY,job_id BIGINT,server_id INT);CREATE TRIGGER pool_claim AFTER UPDATE ON code_lab_jobs FOR EACH ROW BEGIN IF NEW.state='running' AND OLD.state='queued' THEN INSERT INTO pool_claims(job_id,server_id) VALUES(NEW.id,NEW.server_id); END IF; END");
$insert=$db->prepare("INSERT INTO code_lab_jobs(server_id,user_id,language,runtime_id,kind,source_code,stdin,state,created_at) VALUES(?,1,'python',10003,'run',?,'','queued',UTC_TIMESTAMP())");
for($i=1;$i<=80;$i++)$insert->execute([$i<=40?1:($i<=60?2:3),'job-'.$i]);
$processes=[];
for($slot=1;$slot<=2;$slot++){$p=proc_open([PHP_BINARY,__FILE__,'worker',(string)$slot],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);$processes[]=[$p,$pipes];}
foreach($processes as[$p,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);foreach($pipes as$pipe)fclose($pipe);if(proc_close($p)!==0)throw new RuntimeException('Concurrent fixture worker failed: '.$out.$err);}
$counts=$db->query('SELECT state,COUNT(*) n FROM code_lab_jobs GROUP BY state')->fetchAll(PDO::FETCH_KEY_PAIR);
if((int)($counts['done']??0)!==79||(int)($counts['failed']??0)!==1||(int)$db->query('SELECT COUNT(*) FROM pool_audit')->fetchColumn()!==80)throw new RuntimeException('Duplicate, lost or incorrectly graded work: '.json_encode($counts).'; runner calls='.$db->query('SELECT COUNT(*) FROM pool_audit')->fetchColumn());
foreach($db->query("SELECT result_json FROM code_lab_jobs WHERE state='done'")as$row){if(json_decode($row['result_json'],true)['stdout']!=='42')throw new RuntimeException('Result mismatch');}
echo "PASS: MariaDB concurrent claims exactly once, independent slot locks, 79 results, one infrastructure failure without a zero grade\n";

$claims=$db->query('SELECT job_id,server_id FROM pool_claims ORDER BY sequence')->fetchAll(PDO::FETCH_ASSOC);
$last=[];foreach($claims as$i=>$claim){$expected=$i<60?($i%3)+1:1;if((int)$claim['server_id']!==$expected)throw new RuntimeException('Round-robin starvation or skipped server');$sid=(int)$claim['server_id'];if((int)$claim['job_id']<=($last[$sid]??0))throw new RuntimeException('Server FIFO violated');$last[$sid]=(int)$claim['job_id'];}
if(count($claims)!==80)throw new RuntimeException('Lost claims');
echo "PASS: two-process durable round-robin, FIFO per server, empty queues skipped, compiler outside transaction\n";
