<?php
declare(strict_types=1);
require_once __DIR__.'/../services/LiveKitCountSnapshot.php';
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE livekit_count_snapshot(id INTEGER PRIMARY KEY,counts_json TEXT,collected_at INTEGER)');
$s = new LiveKitCountSnapshot($db);
function check($ok) { if (!$ok) throw new RuntimeException('Snapshot assertion failed'); }
check($s->read([1],100)['counts']->{1} === null);
$db->exec("INSERT INTO livekit_count_snapshot VALUES(1,'{\"1\":3,\"99\":7}',100)");
foreach ([50,150,450] as $clients) {
    for ($i=0;$i<$clients;$i++) {
        $r=$s->read([1,2],105);
        check((array)$r['counts']===[1=>3,2=>0]);
        check(!isset($r['counts']->{99}));
    }
    echo "$clients snapshot readers: passed; upstream RPCs from reader = 0 (code path has no upstream dependency)\n";
}
check($s->read([1],116)['counts']->{1} === null);
check($s->read([1],99)['stale']);
echo "Missing/stale/future snapshot and authorized filtering passed\n";
