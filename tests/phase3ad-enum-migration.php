<?php
declare(strict_types=1);
require_once __DIR__.'/phase3y-bootstrap.php';
set_exception_handler(static function(Throwable $e):void{fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);});
$sql=file_get_contents(__DIR__.'/../database/migrations/068_message_code_poll_types.sql');
$statements=array_filter(array_map('trim',preg_split('/;\s*(?:\n|$)/',preg_replace('/^\s*--.*$/m','',$sql))));
$old="enum('text','image','file','system','ai_response')";
$new="enum('text','image','file','system','ai_response','code','poll')";
$records=[];
$shapes=[['original',$old,"NOT NULL DEFAULT 'text'",true],['already-extended',$new,"NOT NULL DEFAULT 'text'",true],['extra-value',"enum('text','image','file','system','ai_response','custom')","NOT NULL DEFAULT 'text'",false],['nullable',$old,"NULL DEFAULT 'text'",false],['wrong-default',$old,"NOT NULL DEFAULT 'image'",false],['wrong-case',"enum('TEXT','image','file','system','ai_response')","NOT NULL DEFAULT 'TEXT'",false],['varchar','varchar(30)',"NOT NULL DEFAULT 'text'",false]];
foreach($shapes as[$name,$type,$suffix,$expected]){
 $database='classroom_enum_test_'.substr(hash('sha256',getenv('PHASE3Y_PRIVATE').$name),0,16);
 $db->exec('CREATE DATABASE `'.$database.'`');
 $dsn=preg_replace('/dbname=classroom_perf_test$/','dbname='.$database,getenv('CLASSROOM_DSN'));
 $test=new PDO($dsn.';charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 try{
  $test->exec("CREATE TABLE messages(id INT PRIMARY KEY,content_type $type CHARACTER SET utf8mb4 COLLATE utf8mb4_bin $suffix) ENGINE=InnoDB");
  foreach(['text','image','file','system','ai_response']as$i=>$value)$test->prepare('INSERT INTO messages VALUES(?,?)')->execute([$i+1,$name==='wrong-case'&&$value==='text'?'TEXT':$value]);
  $before=$test->query('SELECT id,content_type,content_type+0 AS ordinal FROM messages ORDER BY id')->fetchAll();
  $ddlBefore=$test->query('SHOW CREATE TABLE messages')->fetch(PDO::FETCH_NUM)[1];$success=true;$error=null;
  try{foreach($statements as$statement){if(preg_match('/^SELECT\b/i',$statement)){$cursor=$test->query($statement);$cursor->fetchAll();$cursor->closeCursor();}else $test->exec($statement);}}catch(PDOException $e){$success=false;$error=$e->getCode().': '.$e->getMessage();}
  $after=$test->query('SELECT id,content_type,content_type+0 AS ordinal FROM messages ORDER BY id')->fetchAll();
  $ddlAfter=$test->query('SHOW CREATE TABLE messages')->fetch(PDO::FETCH_NUM)[1];
  if($success!==$expected||$before!==$after||(!$expected&&$ddlBefore!==$ddlAfter))throw new RuntimeException('Enum migration shape failed: '.$name.' success='.(int)$success.' error='.$error.' rows_equal='.(int)($before===$after));
  if($success){$column=$test->query("SHOW FULL COLUMNS FROM messages WHERE Field='content_type'")->fetch();if($column['Type']!==$new||$column['Collation']!=='utf8mb4_bin')throw new RuntimeException('Enum attributes changed');}
  $records[]=['name'=>$name,'migration_succeeded'=>$success,'expected'=>$expected,'sqlstate'=>$error,'rows_and_ordinals_preserved'=>true,'unexpected_shape_unchanged'=>!$expected?$ddlBefore===$ddlAfter:null,'before'=>$ddlBefore,'after'=>$ddlAfter];
 }finally{$test=null;$db->exec('DROP DATABASE `'.$database.'`');}
}
echo json_encode(['status'=>'PASS','source_sha256'=>hash('sha256',$sql),'records'=>$records],JSON_THROW_ON_ERROR);
