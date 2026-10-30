<?php
declare(strict_types=1);
require_once __DIR__.'/phase3y-bootstrap.php';
set_exception_handler(function(Throwable $e):void{fwrite(STDERR,get_class($e).': '.$e->getMessage());exit(1);});
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
if($in['action']==='fingerprint'){
 $out=[];foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)as$table){
  if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table))throw new RuntimeException('Unexpected table');
  $rows=[];foreach($db->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_NUM)as$row)$rows[]=json_encode(array_map(fn($v)=>$v===null?null:bin2hex((string)$v),$row),JSON_THROW_ON_ERROR);
  sort($rows,SORT_STRING);$ddl=$db->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
  $out[$table]=['rows'=>count($rows),'rows_sha256'=>hash('sha256',implode("\n",$rows)),'ddl_sha256'=>hash('sha256',$ddl)];
 }ksort($out);echo json_encode(['tables'=>$out,'sha256'=>hash('sha256',json_encode($out,JSON_THROW_ON_ERROR))],JSON_THROW_ON_ERROR);
}elseif($in['action']==='postbrowser'){
 $out=[];foreach(['servers','channels','messages','message_polls','poll_options','poll_votes','dm_messages','threads','notifications','message_attachments']as$t){try{$out[$t]=(int)$db->query('SELECT COUNT(*) FROM `'.$t.'`')->fetchColumn();}catch(PDOException){$out[$t]='table unavailable';}}
 echo json_encode(['counts'=>$out,'identity_rows'=>$db->query('SELECT id,is_system FROM users ORDER BY id')->fetchAll()],JSON_THROW_ON_ERROR);
}else throw new RuntimeException('Unknown owned operation');
