<?php
declare(strict_types=1);
// CLI-only fake PDO: exercise the actual endpoint without a database or ML request.
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__).'/config.php';
require ROOT_PATH.'/database/config/db.php';
require ROOT_PATH.'/security/middleware/AuthMiddleware.php';
class PeerTopStatement extends PDOStatement {
    private array $params=[];
    public function __construct(private string $sql) {}
    public function execute(?array $params=null):bool {$this->params=$params??[];return true;}
    public function fetchColumn(int $column=0):mixed {return 1;}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed {
        if (str_contains($this->sql,'AS attempts')) return ['attempts'=>0,'retry_after'=>1];
        return false;
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {
        if (str_contains($this->sql, 'FROM friendships')) {
            return match ($this->params[1] ?? 0) {
                2 => [['status' => 'accepted', 'requester_id' => 1]],
                4 => [['status' => 'pending', 'requester_id' => 4]],
                default => [],
            };
        }
        if (str_contains($this->sql, 'FROM pm_match_requests')) {
            return ($this->params[1] ?? 0) === 3 ? [1] : [];
        }
        if (str_contains($this->sql,'FROM pm_compatibility c')) throw new RuntimeException('Top read stale rules-only cache');
        if (str_contains($this->sql,'FROM users u')) return array_map(fn($id)=>[
            'id'=>$id,'username'=>'peer'.$id,'full_name'=>'Peer '.$id,'role'=>'student',
            'avatar_url'=>'','avatar_color_gradient'=>'#123456,#654321','bio'=>'','is_online'=>false,
        ],[2,3,4]);
        if (str_contains($this->sql, 'FROM pm_user_study_prefs')) {
            return array_map(fn($id) => ['user_id'=>$id, 'study_style'=>'mixed', 'availability_days'=>127], $this->params);
        }
        if (str_contains($this->sql, 'FROM peer_behavior_profiles')) {
            return array_map(fn($id) => ['user_id'=>$id, 'features_json'=>'{"page:chat":1}', 'event_count'=>10, 'refreshed_at'=>gmdate('Y-m-d H:i:s')], $this->params);
        }
        if (str_contains($this->sql, 'FROM pm_user_interests')) {
            return array_map(fn($id) => ['user_id'=>$id, 'interest_id'=>1, 'name'=>'Programming'], $this->params);
        }
        return [];
    }
}
class PeerTopPDO extends PDO {
    public function __construct() {}
    public function prepare(string $query,array $options=[]):PDOStatement|false {return new PeerTopStatement($query);}
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false {return new PeerTopStatement($query);}
}
(new ReflectionProperty(Database::class,'instance'))->setValue(null,new PeerTopPDO());
AuthMiddleware::startSession();
$_SESSION=['user_id'=>1,'username'=>'fixture','role'=>'student'];
$_SERVER['REQUEST_METHOD']='GET';
$_GET=['action'=>$argv[1]??'get_matches', 'user_id'=>(int)($argv[2]??2)];
$_ENV['PEER_ML_ENABLED']='false';
include ROOT_PATH.'/API/chat/peer-match.php';
