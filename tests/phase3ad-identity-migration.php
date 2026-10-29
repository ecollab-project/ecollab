<?php
declare(strict_types=1);
require_once __DIR__.'/phase3y-bootstrap.php';
$php=getenv('PHP_BIN')?:PHP_BINARY;$root=dirname(__DIR__);$records=[];
function identitySnapshot():array {global $db;return ['ddl'=>$db->query('SHOW CREATE TABLE users')->fetch(PDO::FETCH_NUM)[1],'rows'=>$db->query('SELECT id,username,email,student_id,institution_id FROM users ORDER BY id')->fetchAll()];}
function identityRun(string $label,bool $pass,array $env=[]):void {global $db,$php,$root,$records;$before=identitySnapshot();$cmd=[$php,'-d','auto_prepend_file='.$root.'/tests/phase3w-migration-bootstrap.php',$root.'/database/migrate.php','--force=069_system_identity.sql'];$pipes=[];$p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root,array_replace(getenv(),$env));fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);$after=identitySnapshot();$records[]=['label'=>$label,'command'=>$cmd,'exit_code'=>$code,'stdout'=>$stdout,'stderr'=>$stderr,'before'=>$before,'after'=>$after];if(($code===0)!==$pass||(!$pass&&$before!==$after))throw new RuntimeException('Migration expectation '.$label);}
// A fresh migrated owned DB still has only the exact ten canonical seed accounts.
if((int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn()!==10)throw new RuntimeException('Pristine owned fixture required');
$db->exec('ALTER TABLE users DROP CONSTRAINT chk_users_is_system, DROP COLUMN is_system');
$db->exec("DELETE FROM schema_migrations WHERE filename='069_system_identity.sql'");
identityRun('no explicit acknowledgement',false,['PHASE3AD_OWNED_FRESH_IDENTITY'=>'0']);
$db->exec("INSERT INTO users(username,email,full_name,role,status) VALUES('unknown_human','unknown@example.test','Unknown','student','offline')");
identityRun('unknown additional account',false);$db->exec("DELETE FROM users WHERE username='unknown_human'");
$db->exec("UPDATE users SET email='changed@example.test' WHERE id=5");identityRun('changed known seed identity',false);$db->exec("UPDATE users SET email='fatima.student@fatima.edu.ph' WHERE id=5");
foreach(['TINYINT(1) NOT NULL DEFAULT 1','TINYINT(1) NULL DEFAULT 0','VARCHAR(2) NOT NULL DEFAULT \'0\'']as$shape){$db->exec('ALTER TABLE users ADD COLUMN is_system '.$shape);identityRun('incompatible column '.$shape,false);$db->exec('ALTER TABLE users DROP COLUMN is_system');}
identityRun('canonical fresh migration',true);identityRun('verified pristine rerun',true);
$successfulLedger=$db->query("SELECT * FROM schema_migrations WHERE filename='069_system_identity.sql'")->fetch();
$db->exec('UPDATE users SET is_system=1 WHERE id=5');identityRun('conflicting seed flag',false);$db->exec('UPDATE users SET is_system=0 WHERE id=5');
$columns=array_keys($successfulLedger);$db->prepare('REPLACE INTO schema_migrations(`'.implode('`,`',$columns).'`) VALUES('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($successfulLedger));
echo json_encode(['status'=>'PASS','cases'=>$records],JSON_THROW_ON_ERROR);
