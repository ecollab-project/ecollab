<?php
// Owned MariaDB fixture only; the baseline is extracted from the pinned PR head by the runner.
declare(strict_types=1);
$dsn=getenv('APPLICATION_PERF_DSN');
if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=application_perf_test$/D',$dsn))throw new RuntimeException('Owned fixture required');
require_once __DIR__.'/../database/config/db.php';
require_once __DIR__.'/../services/StudentDashboardService.php';
require_once __DIR__.'/../services/PeerMatchingService.php';
require_once __DIR__.'/../services/JarredGroupRecommendationService.php';
require_once __DIR__.'/../services/AdminDashboardService.php';
require_once __DIR__.'/../services/FacilitatorDashboardService.php';
foreach(['StudentDashboardService','PeerMatchingService','JarredGroupRecommendationService','AdminDashboardService','FacilitatorDashboardService']as$name)require getenv('APPLICATION_PERF_BASELINE').'/'.$name.'.php';
final class PerfStatement extends PDOStatement {
    public function execute(?array $params=null):bool {
        PerfDb::$queries++;
        if(str_contains($this->queryString,'message_reads'))PerfDb::$unread=['query'=>$this->queryString,'params'=>$params];
        try {return parent::execute($params);}catch(Throwable $e){PerfDb::$errors[]=$e->getMessage();throw $e;}
    }
}
final class PerfDb extends PDO {
    public static int $queries=0;public static array $errors=[];public static array $unread=[];
}
$db=new PerfDb($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STATEMENT_CLASS=>[PerfStatement::class]]);
(new ReflectionProperty(Database::class,'instance'))->setValue(null,$db);
function check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label."\n";}
function cpu():float{$r=getrusage();return ($r['ru_utime.tv_sec']+$r['ru_stime.tv_sec'])*1000+($r['ru_utime.tv_usec']+$r['ru_stime.tv_usec'])/1000;}
function measured(callable $fn):array{
    PerfDb::$queries=0;PerfDb::$errors=[];
    if(function_exists('memory_reset_peak_usage'))memory_reset_peak_usage();$memory=memory_get_usage();$cpu=cpu();$start=hrtime(true);$data=$fn();
    return ['elapsed_ms'=>(hrtime(true)-$start)/1e6,'php_cpu_ms'=>cpu()-$cpu,'peak_delta_bytes'=>memory_get_peak_usage()-$memory,'queries'=>PerfDb::$queries,'data'=>$data,'sql_errors'=>PerfDb::$errors];
}
function summary(array $runs):array{
    $out=[];foreach(['elapsed_ms','php_cpu_ms','peak_delta_bytes','queries']as$key){$values=array_column($runs,$key);sort($values);$out[$key]=['median'=>$values[intdiv(count($values),2)],'min'=>$values[0],'max'=>end($values)];}return $out;
}
$old=new BaselineStudentDashboardService($db);$new=new StudentDashboardService($db);
$a=measured(fn()=>$old->getStudentDashboardData(1));$beforeUnread=PerfDb::$unread;$b=measured(fn()=>$new->getStudentDashboardData(1));$afterUnread=PerfDb::$unread;
check(!$a['sql_errors']&&!$b['sql_errors'],'all dashboard SQL succeeds; empty fallback cannot masquerade as improvement');
check($a['data']===$b['data'],'entire dashboard payload unchanged on fixed data');
check($a['queries']-$b['queries']===10,'ten duplicate dashboard executions eliminated');
check($b['data']['total_sessions']>0&&$b['data']['chat_unread']>0&&count($b['data']['courses'])>0,'nonempty dashboard fixture');
$db->exec('INSERT INTO study_sessions VALUES(10001,1,60,NOW())');$changed=$new->getStudentDashboardData(1);check($changed['total_sessions']===$b['data']['total_sessions']+1,'same service reads new data on later call');
check($new->getStudentDashboardData(2)===$old->getStudentDashboardData(2),'reuse never crosses users');
$db->exec('DELETE FROM study_sessions WHERE id=10001');
$dashboard=['before'=>[],'after'=>[]];for($i=0;$i<12;$i++){foreach($i%2?['after','before']:['before','after']as$side){$r=measured(fn()=>($side==='before'?$old:$new)->getStudentDashboardData(1));check(!$r['sql_errors'],'dashboard sample SQL valid');unset($r['data'],$r['sql_errors']);$dashboard[$side][]=$r;}}
$engine=new PeerMatchingService();$baselineEngine=new BaselinePeerMatchingService();$ids=range(1,100);$single=[];foreach($ids as$id)$single[$id]=$baselineEngine->loadProfile($db,$id);$batched=$engine->loadProfiles($db,$ids);
check($single===$batched,'100 sparse/diverse profiles identical to original loader');
check($engine->loadProfiles($db,[])===[],'empty batch executes without SQL');
check($engine->loadProfiles($db,[3,1,3,9999])[9999]===['prefs'=>[],'subjects'=>[],'interests'=>[],'hobbies'=>[]],'duplicate and missing profiles handled');
for($i=1;$i<=50;$i++)check($engine->scoreProfiles($single[$i],$single[101-$i],80.0)===$baselineEngine->scoreProfiles($batched[$i],$batched[101-$i],80.0),'peer scores/reasons and semantic position unchanged');
check((new JarredGroupRecommendationService())->recommend(900,1,4)===(new BaselineJarredGroupRecommendationService())->recommend(900,1,4),'complete deterministic grouping membership/pair evidence unchanged');
$peer=['before'=>[],'after'=>[]];for($i=0;$i<15;$i++)foreach($i%2?['after','before']:['before','after']as$side){$r=measured(function()use($side,$baselineEngine,$engine,$db,$ids){if($side==='after')return $engine->loadProfiles($db,$ids);$p=[];foreach($ids as$id)$p[$id]=$baselineEngine->loadProfile($db,$id);return $p;});check(!$r['sql_errors'],'profile sample SQL valid');unset($r['data'],$r['sql_errors']);$peer[$side][]=$r;}
check(array_unique(array_column($peer['before'],'queries'))===[400] && array_unique(array_column($peer['after'],'queries'))===[4],'100-profile query budget400 to4');
$r=measured(fn()=>$engine->loadProfiles($db,range(1,201)));check($r['queries']===12,'batch size bounded at100 IDs (201 IDs use12 queries)');
$unread=[];
foreach(['before'=>$beforeUnread,'after'=>$afterUnread] as $side=>$captured){
    $explain=$db->prepare('EXPLAIN '.$captured['query']);$explain->execute($captured['params']);
    $times=[];$statement=$db->prepare($captured['query']);
    for($i=0;$i<7;$i++){$start=hrtime(true);$statement->execute($captured['params']);$count=(int)$statement->fetchColumn();$times[]=(hrtime(true)-$start)/1e6;}
    sort($times);$unread[$side]=['explain'=>$explain->fetchAll(),'count'=>$count,'median_ms'=>$times[3]];
}
check($unread['before']['count']===$unread['after']['count'],'explicit unread join preserves actual query count');
foreach(['AdminDashboardService'=>'getPlatformStats','FacilitatorDashboardService'=>'getChannelStats'] as $class=>$method){
    $baselineClass='Baseline'.$class;$oldStats=new ReflectionMethod($baselineClass,$method);$newStats=new ReflectionMethod($class,$method);
    $args=$class==='FacilitatorDashboardService'?[1]:[];
    check($oldStats->invoke(new $baselineClass(),...$args)===$newStats->invoke(new $class(),...$args),'actual '.$class.' aggregation results unchanged');
}
$dateQueries=['messages'=>['old'=>"SELECT COUNT(*) FROM messages WHERE DATE(created_at)=CURDATE() AND is_deleted=0",'new'=>"SELECT COUNT(*) FROM messages WHERE created_at>=CURDATE() AND created_at<CURDATE()+INTERVAL 1 DAY AND is_deleted=0"],'sessions'=>['old'=>"SELECT COUNT(*) FROM study_room_sessions WHERE DATE(scheduled_start)=CURDATE()",'new'=>"SELECT COUNT(*) FROM study_room_sessions WHERE scheduled_start>=CURDATE() AND scheduled_start<CURDATE()+INTERVAL 1 DAY"]];
$dates=[];foreach($dateQueries as$name=>$queries){check($db->query($queries['old'])->fetchColumn()===$db->query($queries['new'])->fetchColumn(),'date boundaries preserved: '.$name);foreach($queries as$side=>$query){$plan=$db->query('EXPLAIN '.$query)->fetchAll();$times=[];for($i=0;$i<15;$i++){$start=hrtime(true);$db->query($query)->fetchColumn();$times[]=(hrtime(true)-$start)/1e6;}sort($times);$dates[$name][$side]=['explain'=>$plan,'median_ms'=>$times[7],'min_ms'=>$times[0],'max_ms'=>end($times)];}}
// Fallback behavior remains graceful if an optional feature table is absent.
$db->exec('RENAME TABLE notifications TO unavailable_notifications');check($new->getStudentDashboardData(1)===$old->getStudentDashboardData(1),'optional notification-table fallback retained');$db->exec('RENAME TABLE unavailable_notifications TO notifications');
$out=['source_baseline'=>'9d5ded1af8ee55364fa87d9c9a05327ffb9193c9','mariadb'=>$db->query('SELECT VERSION()')->fetchColumn(),'php'=>PHP_VERSION,'scope'=>'owned loopback synthetic data; no production or capacity test','fixture'=>['users'=>900,'messages'=>100005,'study_sessions'=>9000,'peer_candidates'=>100],'dashboard'=>['before'=>summary($dashboard['before']),'after'=>summary($dashboard['after']),'samples'=>$dashboard],'profiles'=>['before'=>summary($peer['before']),'after'=>summary($peer['after']),'samples'=>$peer],'date_counts'=>$dates,'unread_join'=>$unread,'not_measured'=>['production throughput','host CPU and total memory','450 users','real Ollama inference','mobile layout/paint timing']];
file_put_contents(getenv('APPLICATION_PERF_EVIDENCE'),json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");echo json_encode(['dashboard'=>[$out['dashboard']['before'],$out['dashboard']['after']],'profiles'=>[$out['profiles']['before'],$out['profiles']['after']]],JSON_THROW_ON_ERROR)."\n";
