<?php
declare(strict_types=1);
$dsn=getenv('COWORKSPACE_TEST_DSN')?:'';
if(!preg_match('/^mysql:host=127[.]0[.]0[.]1;port=[0-9]+;dbname=coworkspace_025_test$/D',$dsn))throw new RuntimeException('Explicit disposable coworkspace_025_test required');
function connection():PDO {global $dsn;return new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);}
function check(bool $v,string $m):void {if(!$v)throw new RuntimeException($m);}
function sql(string $f):string{return (string)file_get_contents(__DIR__.'/../database/migrations/'.$f);}
// Execute with the actual runner's functions without running its CLI entrypoint.
$runner=file_get_contents(__DIR__.'/../database/migrate.php');eval(substr($runner,strpos($runner,'function applyMigration(')));
function repair(PDO $d):void {applyMigration($d,sql('025_coworkspace_one_active_per_server_mariadb.sql'));}
function error(callable $f,int $code):void {try{$f();throw new RuntimeException('Expected database rejection '.$code);}catch(PDOException $e){check((int)$e->errorInfo[1]===$code,$e->getMessage());}}
function fresh(PDO $d,bool $restrict=false):void {
 $d->exec('SET FOREIGN_KEY_CHECKS=0');foreach($d->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t)$d->exec('DROP TABLE `'.$t.'`');$d->exec('SET FOREIGN_KEY_CHECKS=1');
 $d->exec("CREATE TABLE users(id INT UNSIGNED PRIMARY KEY);INSERT INTO users VALUES(1),(2);CREATE TABLE servers(id INT UNSIGNED PRIMARY KEY,owner_id INT UNSIGNED,status VARCHAR(20));INSERT INTO servers VALUES(1,1,'active'),(2,1,'active'),(3,2,'active');CREATE TABLE channels(id INT UNSIGNED PRIMARY KEY,server_id INT UNSIGNED);INSERT INTO channels VALUES(10,1),(20,2),(30,3);CREATE TABLE server_members(server_id INT UNSIGNED,user_id INT UNSIGNED);INSERT INTO server_members VALUES(1,1),(2,1),(3,2);CREATE TABLE collab_documents(id INT PRIMARY KEY,channel_id INT,updated_at DATETIME)");
 applyMigration($d,sql('023_coworkspaces.sql'));applyMigration($d,sql('024_coworkspace_server_scope.sql'));
 if($restrict){$d->exec('ALTER TABLE collab_workspaces DROP FOREIGN KEY fk_cw_server');$d->exec('ALTER TABLE collab_workspaces ADD CONSTRAINT fk_cw_server FOREIGN KEY(server_id) REFERENCES servers(id) ON DELETE CASCADE ON UPDATE RESTRICT');}
 $d->exec("INSERT INTO collab_workspaces(id,channel_id,server_id,name,host_id,archived) VALUES(1,10,1,'active',1,0),(2,10,1,'archive A',1,1),(3,10,1,'archive B',1,1);INSERT INTO collab_workspace_members(workspace_id,user_id,role) VALUES(1,1,'host'),(2,2,'viewer');INSERT INTO collab_documents(id,channel_id,workspace_id) VALUES(1,10,1),(2,10,2)");
}
function snapshot(PDO $d):array {
 return [$d->query('SELECT id,channel_id,server_id,name,visibility,host_id,archived,created_at,updated_at FROM collab_workspaces ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),$d->query('SELECT * FROM collab_workspace_members ORDER BY workspace_id,user_id')->fetchAll(PDO::FETCH_ASSOC),$d->query('SELECT * FROM collab_documents ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),$d->query("SELECT constraint_name,update_rule,delete_rule FROM information_schema.referential_constraints WHERE constraint_schema=DATABASE() ORDER BY constraint_name")->fetchAll(PDO::FETCH_ASSOC)];
}
$d=connection();echo 'VERSION '.$d->query('SELECT VERSION()')->fetchColumn()."\n";
check(str_starts_with((string)$d->query('SELECT VERSION()')->fetchColumn(),'10.11.'),'MariaDB 10.11 required');
fresh($d);error(fn()=>applyMigration($d,sql('025_coworkspace_one_active_per_server.sql')),1901);echo "REPRODUCED unchanged historical 025: MariaDB error1901\n";
foreach(['fresh','successful-old','partial-old-column','partial-slot','complete'] as $case){
 fresh($d,in_array($case,['successful-old','partial-old-column'],true));
 if($case==='successful-old')applyMigration($d,sql('025_coworkspace_one_active_per_server.sql'));
 if($case==='partial-old-column')$d->exec('ALTER TABLE collab_workspaces ADD COLUMN active_server_id INT UNSIGNED GENERATED ALWAYS AS(IF(archived=0,server_id,NULL)) STORED');
 if($case==='partial-slot')$d->exec('ALTER TABLE collab_workspaces ADD COLUMN active_slot TINYINT UNSIGNED GENERATED ALWAYS AS(IF(archived=0,1,NULL)) STORED');
 if($case==='complete')repair($d);
 $before=snapshot($d);repair($d);repair($d);check(snapshot($d)===$before,$case.' changed records/relationships/FK');
 error(fn()=>$d->exec("INSERT INTO collab_workspaces(channel_id,server_id,name,host_id) VALUES(10,1,'duplicate',1)"),1062);
 $d->exec("INSERT INTO collab_workspaces(channel_id,server_id,name,host_id,archived) VALUES(10,1,'another archive',1,1)");
 $d->exec('UPDATE collab_workspaces SET archived=1 WHERE id=1');$d->exec("INSERT INTO collab_workspaces(channel_id,server_id,name,host_id) VALUES(10,1,'replacement',1)");
 error(fn()=>$d->exec('UPDATE collab_workspaces SET archived=0 WHERE id=2'),1062);
 $d->exec('UPDATE collab_workspaces SET archived=1 WHERE archived=0');$d->exec('UPDATE collab_workspaces SET archived=0 WHERE id=2');check((int)$d->query('SELECT COUNT(*) FROM collab_workspaces WHERE server_id=1 AND archived=0')->fetchColumn()===1,'Reactivation after archive failed');
 echo "PASS $case upgrade/rerun/data preservation/archive/create/reactivation conflict\n";
}
fresh($d);$d->exec("INSERT INTO collab_workspaces(channel_id,server_id,name,host_id) VALUES(10,1,'preexisting duplicate',1)");$before=snapshot($d);error(fn()=>repair($d),1644);check(snapshot($d)===$before,'Duplicate rejection changed records');$d=null;$d=connection();echo "PASS duplicate actives rejected without archiving/deleting/merging\n";
fresh($d);$d->exec('ALTER TABLE collab_workspaces ADD COLUMN active_slot INT NULL');$before=snapshot($d);error(fn()=>repair($d),1644);check(snapshot($d)===$before,'Unknown partial state changed data');$d=null;$d=connection();echo "PASS unknown ordinary partial slot rejected without data changes\n";
fresh($d);repair($d);$d->exec('UPDATE servers SET id=11 WHERE id=1');check((int)$d->query('SELECT server_id FROM collab_workspaces WHERE id=1')->fetchColumn()===11,'Server cascade failed');
$d->exec('UPDATE servers SET owner_id=2 WHERE id=11');check((int)$d->query('SELECT host_id FROM collab_workspaces WHERE id=1')->fetchColumn()===1,'Ownership edit changed workspace host');
$d->exec('UPDATE collab_workspaces SET server_id=2 WHERE id=1');$d->exec("INSERT INTO collab_workspaces(channel_id,server_id,name,host_id) VALUES(30,3,'occupied',2)");error(fn()=>$d->exec('UPDATE collab_workspaces SET server_id=3 WHERE id=1'),1062);error(fn()=>$d->exec('UPDATE collab_workspaces SET server_id=999 WHERE id=1'),1452);
$d->exec('DELETE FROM servers WHERE id=3');check((int)$d->query('SELECT COUNT(*) FROM collab_workspaces WHERE server_id=3')->fetchColumn()===0,'ON DELETE CASCADE changed');echo "PASS parent cascade/delete, ownership and direct server-reference changes\n";
// Two real database sessions contend on the unique key. The loser blocks, then
// receives1062 after the winner commits; both commit and rollback paths matter.
foreach(['commit','rollback'] as $finish){
 fresh($d);repair($d);$d->beginTransaction();$d->exec("INSERT INTO collab_workspaces(channel_id,server_id,name,host_id) VALUES(20,2,'winner',1)");
 $script=tempnam(sys_get_temp_dir(),'cw025-child-');file_put_contents($script,'<?php $d=new PDO('.var_export($dsn,true).',"root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);echo "ready\\n";fflush(STDOUT);try{$d->exec("INSERT INTO collab_workspaces(channel_id,server_id,name,host_id) VALUES(20,2,\'contender\',1)");echo "created";}catch(PDOException $e){echo $e->errorInfo[1];}');
 $proc=proc_open([PHP_BINARY,$script],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);check(trim((string)fgets($pipes[1]))==='ready','Contender did not reach write barrier');$waiting=false;for($i=0;$i<100;$i++){if((int)$d->query('SELECT COUNT(*) FROM information_schema.INNODB_LOCK_WAITS')->fetchColumn()>0){$waiting=true;break;}usleep(10000);}check($waiting&&proc_get_status($proc)['running'],'Contender did not wait on unique key');$finish==='commit'?$d->commit():$d->rollBack();$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($proc);unlink($script);check($out===($finish==='commit'?'1062':'created'),'Unexpected contender: '.$out.$err);check((int)$d->query('SELECT COUNT(*) FROM collab_workspaces WHERE server_id=2 AND archived=0')->fetchColumn()===1,'Concurrent duplicate');echo "PASS concurrent create with winner $finish\n";
}
// Simulated interrupted ALTER: hold metadata lock, kill only that fixture query,
// then retry. The real server remains running; production is never contacted.
fresh($d);$blocker=connection();$blocker->beginTransaction();$blocker->query('SELECT * FROM collab_workspaces')->fetchAll();
$alter=connection();$id=(int)$alter->query('SELECT CONNECTION_ID()')->fetchColumn();$alter->exec('SET lock_wait_timeout=1');$before=snapshot($d);error(fn()=>repair($alter),1205);$blocker->rollBack();$alter=null;repair($d);check(snapshot($d)===$before,'Failed DDL/retry changed rows');echo "PASS metadata-lock failure and retry; no partial DDL/data loss\n";
// Execute the create SQL taken from both application entry points with native
// prepared statements: legacy path must populate NOT NULL server_id too.
fresh($d);repair($d);$d->exec('UPDATE collab_workspaces SET archived=1');
$source=file_get_contents(__DIR__.'/../API/collaboration/workspaces.php');preg_match("/'(INSERT INTO collab_workspaces .*?)'/s",$source,$m);check(!empty($m[1]),'Legacy create SQL not found');
$d->prepare($m[1])->execute([':cid'=>10,':origin_cid'=>10,':name'=>'legacy',':visibility'=>'public',':uid'=>1,':create_docs'=>1,':edit_docs'=>1,':whiteboard'=>1,':invites'=>0]);$legacyId=(int)$d->lastInsertId();check((int)$d->query('SELECT server_id FROM collab_workspaces WHERE archived=0')->fetchColumn()===1,'Legacy creation missing server');echo "PASS actual legacy create SQL populates originating server\n";
require_once __DIR__.'/../services/CoworkspaceService.php';check(CoworkspaceService::get($d,$legacyId,1)['name']==='legacy','Existing coworkspace service read regression');echo "PASS coworkspace authorized service read\n";

// Exercise the actual CLI runner with an isolated migration directory. The later
// fixture migration verifies continuation past superseded025, not the full chain.
$temp=sys_get_temp_dir().'/cw025-runner-'.bin2hex(random_bytes(6));mkdir($temp.'/database/config',0777,true);mkdir($temp.'/database/migrations');
copy(__DIR__.'/../database/migrate.php',$temp.'/database/migrate.php');file_put_contents($temp.'/config.php','<?php');
file_put_contents($temp.'/database/config/db.php','<?php class Database {public static function getInstance():PDO{return new PDO('.var_export($dsn,true).',"root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);}}');
foreach(['000_migration_registry.sql','025_coworkspace_one_active_per_server.sql','025_coworkspace_one_active_per_server_mariadb.sql'] as $f)copy(__DIR__.'/../database/migrations/'.$f,$temp.'/database/migrations/'.$f);
file_put_contents($temp.'/database/migrations/026_after_fixture.sql','CREATE TABLE after_025_fixture(id INT PRIMARY KEY);');
function runFixture(string $temp,array $args=[],bool $success=true):string {
 $p=proc_open(array_merge([PHP_BINARY,$temp.'/database/migrate.php'],$args),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$io);fclose($io[0]);$out=stream_get_contents($io[1]);$err=stream_get_contents($io[2]);fclose($io[1]);fclose($io[2]);$code=proc_close($p);check(($code===0)===$success,'Runner unexpected exit: '.$out.$err);return $out.$err;
}
$old='025_coworkspace_one_active_per_server.sql';$new='025_coworkspace_one_active_per_server_mariadb.sql';
try {
 foreach(['absent','failed','successful'] as $state){
  fresh($d,$state==='successful');if($state==='successful')applyMigration($d,sql($old));applyMigration($d,sql('000_migration_registry.sql'));
  if($state!=='absent')$d->prepare('INSERT INTO schema_migrations(filename,checksum,applied_at,success,error_msg) VALUES(?,?,"2020-01-01",?,?)')->execute([$old,hash('sha256',sql($old)),$state==='successful'?1:0,$state==='failed'?'original error1901':null]);
  $history=$d->query('SELECT * FROM schema_migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC);$before=snapshot($d);
  check(str_contains(runFixture($temp,['--status']),$state==='successful'?'applied; retained':'superseded'),'Missing supersession status');runFixture($temp,['--dry-run']);check(snapshot($d)===$before,'Dry run changed workspace data');
  runFixture($temp);check(snapshot($d)===$before,'Runner changed records');
  $prior=$d->query("SELECT * FROM schema_migrations WHERE filename='$old'")->fetchAll(PDO::FETCH_ASSOC);check($prior===$history,'Historical ledger row was overwritten/invented');
  check((int)$d->query("SELECT success FROM schema_migrations WHERE filename='$new'")->fetchColumn()===1,'Replacement not recorded independently');check($d->query('SHOW TABLES LIKE "after_025_fixture"')->fetchColumn()!==false,'Runner stopped before later migration');
  $ledger=$d->query('SELECT * FROM schema_migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC);runFixture($temp);check($d->query('SELECT * FROM schema_migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC)===$ledger,'Rerun rewrote successful ledger');
  runFixture($temp,['--force='.$new]);check(snapshot($d)===$before,'Explicit replacement force changed data');
  // Simulate committed DDL followed by a lost completion/registry ACK.
  $d->exec("UPDATE schema_migrations SET success=0,error_msg='fixture interruption' WHERE filename='$new'");runFixture($temp);check(snapshot($d)===$before,'Retry after DDL/ledger interruption changed records');
  runFixture($temp,['--force='.$old],false);
  $d->exec("UPDATE schema_migrations SET checksum=REPEAT('0',64) WHERE filename='$new'");runFixture($temp,[],false);check(snapshot($d)===$before,'Checksum failure changed rows');
  echo "PASS actual runner $state ledger / supersession / dry-run / continuation / rerun / lost-ACK retry / checksum rejection\n";
 }
} finally {
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());}rmdir($temp);
}
// Existing collaboration suites use their own guarded schema on this same
// disposable daemon; no shared application database is involved.
$d->exec('CREATE DATABASE collabora_test');putenv('COLLABORA_TEST_DSN='.str_replace('coworkspace_025_test','collabora_test',$dsn));putenv('COLLABORA_TEST_PASSWORD=');
foreach(['collabora-regression.php','whiteboard-realtime-regression.php'] as $suite){
 $proc=proc_open([PHP_BINARY,__DIR__.'/'.$suite],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes);fclose($pipes[0]);check(proc_close($proc)===0,'Existing collaboration suite failed: '.$suite);
}
echo "PASS existing Collabora and whiteboard database regression suites\n";

// Python owns this daemon. Explicit protocol requests stop/start of ONLY that
// disposable process; queries prove failure, then fresh connection retries DDL.
fresh($d);$before=snapshot($d);echo "FIXTURE_STOP_DATABASE\n";flush();check(trim((string)fgets(STDIN))==='continue','Missing stop acknowledgement');
try{$d->query('SELECT 1');throw new RuntimeException('Stopped database unexpectedly answered');}catch(PDOException $e){check(in_array((int)$e->errorInfo[1],[2006,2013],true),'Unexpected database failure: '.$e->getMessage());}
echo "FIXTURE_START_DATABASE\n";flush();check(trim((string)fgets(STDIN))==='continue','Missing restart acknowledgement');$d=connection();repair($d);check(snapshot($d)===$before,'Daemon restart/retry changed records');echo "PASS actual database daemon failure/restart/retry preserves records\n";
echo "ALL coworkspace migration checks passed\n";

