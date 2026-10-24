<?php
// Measurement-only CLI prepend. Only accepts the runner's owned loopback schema.
declare(strict_types=1);
$dsn = getenv('PEER_THREADS_DSN');
if (!$dsn || !preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=classroom_perf_test$/D', $dsn) || PHP_SAPI !== 'cli') throw new RuntimeException('Owned CLI fixture required');
$root = getenv('PEER_THREADS_APP');
require_once __DIR__.'/dashboard-profile.php';
require_once $root.'/config.php';
require_once $root.'/database/config/db.php';
final class PeerThreadsPDO extends PDO
{
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $t=hrtime(true);$ok=false;
        try {
            $result=$fetchMode===null ? parent::query($query) : parent::query($query,$fetchMode,...$fetchModeArgs);
            $ok=$result!==false;return $result;
        } finally {
            DashboardProfile::$queries[]=['sql'=>$query,'params'=>null,'ms'=>(hrtime(true)-$t)/1e6,'success'=>$ok];
        }
    }
}
$db = new PeerThreadsPDO($dsn, 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_STATEMENT_CLASS=>[DashboardStatement::class]]);
$db->exec('SET timestamp=1792800000');
(new ReflectionProperty(Database::class, 'instance'))->setValue(null, $db);
parse_str(getenv('PEER_THREADS_QUERY'), $_GET);
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['REQUEST_URI']='/'.getenv('PEER_THREADS_ROUTE').'?'.getenv('PEER_THREADS_QUERY');
require_once $root.'/security/middleware/AuthMiddleware.php';
AuthMiddleware::startSession();
$_SESSION=['user_id'=>1,'username'=>'student1','role'=>'student'];
DashboardProfile::$queries=[];
$cpu=DashboardProfile::cpu();$t=hrtime(true);$mem=memory_get_usage();
if(function_exists('memory_reset_peak_usage'))memory_reset_peak_usage();
register_shutdown_function(static function()use($cpu,$t,$mem){
 $q=DashboardProfile::$queries;
 file_put_contents(getenv('PEER_THREADS_METRICS'),json_encode(['ms'=>(hrtime(true)-$t)/1e6,'cpu_ms'=>DashboardProfile::cpu()-$cpu,'memory_increment_bytes'=>memory_get_peak_usage()-$mem,'peak_bytes'=>memory_get_peak_usage(),'status'=>http_response_code()?:200,'queries'=>$q],JSON_THROW_ON_ERROR));
});
