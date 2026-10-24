<?php
// Measurement-only PDO instrumentation for owned test fixtures.
declare(strict_types=1);
final class DashboardProfile {
    public static array $queries=[];
    public static float $encodeMs=0;
    public static function encode(mixed $value,int $flags=0,int $depth=512):string|false{$t=hrtime(true);try{return json_encode($value,$flags,$depth);}finally{self::$encodeMs+=(hrtime(true)-$t)/1e6;}}
    public static function cpu():float{$r=getrusage();return ($r['ru_utime.tv_sec']+$r['ru_stime.tv_sec'])*1000+($r['ru_utime.tv_usec']+$r['ru_stime.tv_usec'])/1000;}
}
final class DashboardStatement extends PDOStatement {
    public function execute(?array $params=null):bool{$t=hrtime(true);$ok=false;try{$ok=parent::execute($params);return $ok;}finally{DashboardProfile::$queries[]=['sql'=>$this->queryString,'params'=>$params,'ms'=>(hrtime(true)-$t)/1e6,'success'=>$ok];}}
}
function dashboardFixtureDb():PDO{
 $dsn=getenv('DASHBOARD_PERF_DSN');if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=dashboard_perf_test$/D',$dsn))throw new RuntimeException('Owned loopback fixture required');
 $db=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STATEMENT_CLASS=>[DashboardStatement::class]]);
 $clock=getenv('DASHBOARD_FIXED_CLOCK') ?: $db->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();$db->exec('SET timestamp='.(int)$clock);return $db;
}
