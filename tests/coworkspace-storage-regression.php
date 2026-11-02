<?php
declare(strict_types=1);
$dsn = getenv('COWORKSPACE_TEST_DSN') ?: '';
if (!str_ends_with($dsn, ';dbname=coworkspace_test') || !preg_match('/^mysql:host=(127\.0\.0\.1|localhost);/', $dsn)) throw new RuntimeException('A dedicated loopback coworkspace_test database is required.');
$db = new PDO($dsn, 'root', getenv('COWORKSPACE_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
function checkStorage(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
$db->exec('DROP TABLE IF EXISTS collab_workspaces; DROP TABLE IF EXISTS servers');
$db->exec('CREATE TABLE servers(id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
$db->exec("CREATE TABLE collab_workspaces(id INT PRIMARY KEY,server_id INT UNSIGNED NOT NULL,archived TINYINT NOT NULL DEFAULT 0,name VARCHAR(100),active_server_id INT UNSIGNED GENERATED ALWAYS AS(IF(archived=0,server_id,NULL)) STORED,UNIQUE KEY uk_coworkspace_active_server(active_server_id),CONSTRAINT fk_cw_server FOREIGN KEY(server_id) REFERENCES servers(id) ON DELETE RESTRICT ON UPDATE RESTRICT) ENGINE=InnoDB");
$db->exec("INSERT INTO servers VALUES(1);INSERT INTO collab_workspaces(id,server_id,archived,name) VALUES(1,1,1,'Archived data'),(2,1,0,'Active data')");
$file=$argv[1]??dirname(__DIR__).'/database/migrations/024_coworkspace_server_scope_compat.sql';
$sql=preg_replace('/^\s*--.*$/m','',file_get_contents($file));
for($pass=0;$pass<2;$pass++)foreach(explode(';',$sql)as$statement){
 $statement=trim($statement);if($statement==='')continue;
 if(preg_match('/^(SELECT|EXECUTE)\b/i',$statement)){$q=$db->query($statement);if($q->columnCount()>0)$q->fetchAll();$q->closeCursor();}else $db->exec($statement);
}
$extra=$db->query("SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='collab_workspaces' AND COLUMN_NAME='active_server_id'")->fetchColumn();
checkStorage(str_contains($extra,'VIRTUAL'),'Stored derived column was not converted');
checkStorage($db->query('SELECT name FROM collab_workspaces WHERE id=1')->fetchColumn()==='Archived data','Archived row changed');
checkStorage($db->query('SELECT name FROM collab_workspaces WHERE id=2')->fetchColumn()==='Active data','Active row changed');
try{$db->exec("INSERT INTO collab_workspaces(id,server_id,name) VALUES(3,1,'Duplicate')");throw new RuntimeException('Active uniqueness lost');}catch(PDOException $e){checkStorage($e->getCode()==='23000','Wrong duplicate rejection');}
$db->exec('UPDATE collab_workspaces SET archived=1 WHERE id=2');$db->exec("INSERT INTO collab_workspaces(id,server_id,name) VALUES(3,1,'Replacement')");
checkStorage((int)$db->query('SELECT COUNT(*) FROM collab_workspaces')->fetchColumn()===3,'Historical rows were removed');
$db->exec('DELETE FROM servers WHERE id=1');checkStorage((int)$db->query('SELECT COUNT(*) FROM collab_workspaces')->fetchColumn()===0,'Delete cascade lost');
echo "PASS: stored-to-virtual conversion, repeated compatibility migration, preserved rows, uniqueness, archive replacement and delete cascade\n";
