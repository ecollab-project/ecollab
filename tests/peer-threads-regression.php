<?php
declare(strict_types=1);
require_once __DIR__.'/../services/PeerMatchBatch.php';
require_once __DIR__.'/../services/ThreadAttachmentService.php';
$dsn=getenv('PEER_THREADS_DSN');
if(!$dsn||!preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=classroom_perf_test$/D',$dsn))throw new RuntimeException('Owned fixture required');
$db=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
function checkBatch(bool $ok,string $what):void {if(!$ok)throw new RuntimeException($what);}
$rows=[[1,2,50,40,30,20,10,1,2,3,'["Shared subject"]'],[1,3,70,60,50,40,30,2,3,4,'[]']];
$db->exec('TRUNCATE pm_compatibility');
PeerMatchBatch::persistCompatibility($db,[]);
checkBatch(PeerMatchBatch::requestStatuses($db,1,[])===[], 'empty request statuses');
checkBatch(ThreadAttachmentService::forThreads($db,[])===[], 'empty attachment page');
$db->beginTransaction();PeerMatchBatch::persistCompatibility($db,$rows);$db->rollBack();
checkBatch((int)$db->query('SELECT COUNT(*) FROM pm_compatibility')->fetchColumn()===0,'caller rollback preserves atomicity');
PeerMatchBatch::persistCompatibility($db,$rows);
PeerMatchBatch::persistCompatibility($db,$rows);
checkBatch((int)$db->query('SELECT COUNT(*) FROM pm_compatibility')->fetchColumn()===2,'retry upsert no duplicate');
$rows[0][2]=90;PeerMatchBatch::persistCompatibility($db,$rows);
checkBatch((float)$db->query('SELECT score_total FROM pm_compatibility WHERE user_b_id=2')->fetchColumn()===90.,'existing row updated');
$db->exec('TRUNCATE pm_compatibility');
$db->exec('ALTER TABLE pm_compatibility ADD CONSTRAINT fixture_score CHECK(score_total<=100)');
$bad=$rows;$bad[1][2]=101;
try {PeerMatchBatch::persistCompatibility($db,$bad);throw new RuntimeException('Expected constraint failure');}catch(PDOException $e){}
checkBatch((int)$db->query('SELECT COUNT(*) FROM pm_compatibility')->fetchColumn()===0,'statement failure cannot persist partial batch');
$db->exec('ALTER TABLE pm_compatibility DROP CONSTRAINT fixture_score');
foreach(['attachments','statuses','persistence'] as $case){
 try {match($case){'attachments'=>ThreadAttachmentService::forThreads($db,array_fill(0,51,1)),'statuses'=>PeerMatchBatch::requestStatuses($db,1,array_fill(0,101,2)),default=>PeerMatchBatch::persistCompatibility($db,array_fill(0,101,$rows[0]))};throw new RuntimeException('Expected batch bound');}catch(InvalidArgumentException $e){}
}
// Concurrent caller transactions on real InnoDB; the second statement waits for a shared pair.
$other=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->beginTransaction();PeerMatchBatch::persistCompatibility($db,$rows);
$other->exec('SET innodb_lock_wait_timeout=1');
try {PeerMatchBatch::persistCompatibility($other,array_reverse($rows));throw new RuntimeException('Expected lock wait');}catch(PDOException $e){checkBatch((int)$e->errorInfo[1]===1205,'bounded conflicting writer timeout');}
$db->commit();PeerMatchBatch::persistCompatibility($other,array_reverse($rows));
checkBatch((int)$db->query('SELECT COUNT(*) FROM pm_compatibility')->fetchColumn()===2,'retry after concurrent commit no duplicate');
$db->exec('DROP TABLE pm_match_requests');
try {PeerMatchBatch::requestStatuses($db,1,[2]);throw new RuntimeException('Expected database error');}catch(PDOException $e){}
echo "PASS: empty and bounded batches, fresh/upsert/retry, caller rollback, statement failure atomicity, concurrent lock timeout/recovery, database error propagation\n";
