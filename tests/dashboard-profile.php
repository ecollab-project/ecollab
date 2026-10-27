<?php
// Measurement-only PDO instrumentation for owned test fixtures.
declare(strict_types=1);
final class DashboardProfile {
    public static array $queries=[];
    public static float $encodeMs=0;
    public static function encode(mixed $value,int $flags=0,int $depth=512):string|false{$t=hrtime(true);try{return json_encode($value,$flags,$depth);}finally{self::$encodeMs+=(hrtime(true)-$t)/1e6;}}
    public static function cpu():float{$r=getrusage();return ($r['ru_utime.tv_sec']+$r['ru_stime.tv_sec'])*1000+($r['ru_utime.tv_usec']+$r['ru_stime.tv_usec'])/1000;}
}
/** Owned test SQL recorder. Values are snapshots, never retained references. */
class DashboardPDO extends PDO {
    public function __construct(string $dsn,?string $username=null,?string $password=null,?array $options=null) {
        $options ??= []; $options[PDO::ATTR_STATEMENT_CLASS] ??= [DashboardStatement::class];
        parent::__construct($dsn,$username,$password,$options);
    }
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false {
        $t=hrtime(true);$ok=false;
        try {$result=$fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$args);$ok=$result!==false;return $result;}
        finally {DashboardProfile::$queries[]=self::record($query,'query',$t,$ok,$this->errorInfo());}
    }
    public function exec(string $statement):int|false {
        $t=hrtime(true);$ok=false;
        try {$result=parent::exec($statement);$ok=$result!==false;return $result;}
        finally {DashboardProfile::$queries[]=self::record($statement,'exec',$t,$ok,$this->errorInfo());}
    }
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        $t=hrtime(true);$ok=false;
        try {$result=parent::prepare($query,$options);$ok=$result!==false;return $result;}
        finally {if(!$ok)DashboardProfile::$queries[]=self::record($query,'prepare',$t,false,$this->errorInfo());}
    }
    public static function record(string $sql,string $operation,int $start,bool $success,array $error):array {
        return ['sql'=>$sql,'operation'=>$operation,'params'=>null,'bindings'=>[],'bindings_replayable'=>true,
            'ms'=>(hrtime(true)-$start)/1e6,'success'=>$success,
            'error'=>$success?null:['sqlstate'=>$error[0]??null,'driver_code'=>$error[1]??null,'message'=>$error[2]??null]];
    }
}
final class DashboardStatement extends PDOStatement {
    private array $bound=[];
    private static function key(string|int $parameter):string|int {return is_int($parameter)?$parameter:':'.ltrim($parameter,':');}
    public function bindValue(string|int $param,mixed $value,int $type=PDO::PARAM_STR):bool {
        $result=parent::bindValue($param,$value,$type);
        if($result)$this->bound[self::key($param)]=['parameter'=>self::key($param),'value'=>$value,'pdo_type'=>$type,'source'=>'bindValue'];
        return $result;
    }
    public function bindParam(string|int $param,mixed &$var,int $type=PDO::PARAM_STR,int $maxLength=0,mixed $driverOptions=null):bool {
        $result=parent::bindParam($param,$var,$type,$maxLength,$driverOptions);
        if($result)$this->bound[self::key($param)]=['parameter'=>self::key($param),'value'=>&$var,'pdo_type'=>$type,'source'=>'bindParam'];
        return $result;
    }
    public function execute(?array $params=null):bool {
        if($params!==null){
            // PDO execute-array replaces previous bindings, including references.
            $this->bound=[];
            foreach($params as$key=>$value){$parameter=is_int($key)?$key+1:self::key($key);$this->bound[$parameter]=['parameter'=>$parameter,'value'=>$value,'pdo_type'=>PDO::PARAM_STR,'source'=>'execute'];}
        }
        $bindings=[];$values=[];$replayable=true;
        foreach($this->bound as$binding){
            $value=$binding['value'];$captured=is_scalar($value)||$value===null;
            if(is_string($value)&&preg_match('//u',$value)!==1)$captured=false;
            $snapshot=$captured?$value:['capture_status'=>'BLOCKED','php_type'=>get_debug_type($value)];
            $bindings[]=['parameter'=>$binding['parameter'],'value'=>$snapshot,'pdo_type'=>$binding['pdo_type'],'source'=>$binding['source'],'captured'=>$captured];
            $key=is_int($binding['parameter'])?$binding['parameter']-1:$binding['parameter'];$values[$key]=$snapshot;$replayable=$replayable&&$captured;
        }
        $t=hrtime(true);$ok=false;
        try {$ok=parent::execute($params);return $ok;}
        finally {$row=DashboardPDO::record($this->queryString,'execute',$t,$ok,$this->errorInfo());$row['params']=$values;$row['bindings']=$bindings;$row['bindings_replayable']=$replayable;DashboardProfile::$queries[]=$row;}
    }
}
function dashboardFixtureDb():PDO{
 $dsn=getenv('DASHBOARD_PERF_DSN');if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=dashboard_perf_test$/D',$dsn))throw new RuntimeException('Owned loopback fixture required');
 $db=new DashboardPDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STATEMENT_CLASS=>[DashboardStatement::class]]);
 $clock=getenv('DASHBOARD_FIXED_CLOCK') ?: $db->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();$db->exec('SET timestamp='.(int)$clock);return $db;
}
