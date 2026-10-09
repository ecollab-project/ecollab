<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/services/ChatTusStorage.php';
function ensure(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function fails(callable $fn, int $code): void {
    try { $fn(); } catch (RuntimeException $e) { ensure($e->getCode() === $code, 'Unexpected error: '.$e->getMessage()); return; }
    throw new RuntimeException('Expected rejection '.$code);
}
$dir = sys_get_temp_dir().'/ecollab-tus-test-'.bin2hex(random_bytes(8));
mkdir($dir, 0700);
try {
    $store = new ChatTusStorage($dir.'/parts', $dir.'/files', 'https://example.test');
    $allow = function(string $kind, int $target): void { ensure($kind === 'channel' && $target === 20, 'Stored context changed'); };
    $id = $store->create(18, 'channel', 20, 11, '../note.php');
    $op = fn(callable $fn) => $store->withUpload($id, 18, $allow, $fn);
    fails(fn() => $store->withUpload('../bad', 18, $allow, fn()=>null), 404);
    fails(fn() => $store->withUpload($id, 19, $allow, fn()=>null), 404);
    fails(fn() => $store->withUpload($id, 18, function(){throw new RuntimeException('Revoked',403);}, fn()=>null), 403);
    fails(fn() => $store->create(18,'channel',20,ChatTusStorage::MAX_BYTES+1,'too-big'),413);
    fails(fn() => $op(fn($m,$i) => $store->complete($m,$i)),409);
    ensure($op(fn($m,$i) => $store->append($m,$i,0,'hello ')) === 6, 'First chunk');
    fails(fn() => $op(fn($m,$i) => $store->append($m,$i,0,'retry')),409);
    ensure($op(fn($m,$i) => $store->offset($m,$i)) === 6, 'Rejected retry changed data');
    fails(fn() => $op(fn($m,$i) => $store->append($m,$i,6,'oversized')),413);
    ensure($op(fn($m,$i) => $store->append($m,$i,6,'world')) === 11, 'Resume chunk');
    $result = $op(fn($m,$i) => $store->complete($m,$i));
    ensure($result['file_size'] === 11 && str_ends_with($result['file_path'],'.txt'), 'Canonical safe extension');
    ensure(file_get_contents($dir.'/files/'.basename($result['file_path'])) === 'hello world','Final content');
    ensure($op(fn($m,$i) => $store->complete($m,$i)) === $result, 'Finalize idempotence');
    ensure($op(fn($m,$i) => $store->offset($m,$i)) === 11, 'Completed HEAD offset');
    $op(fn($m,$i) => $store->terminate($i));
    fails(fn() => $op(fn()=>null),404);
    ensure(is_file($dir.'/files/'.basename($result['file_path'])), 'Termination deleted a published attachment');

    $html = '<html><script>alert(1)</script></html>';
    $bad = $store->create(18,'channel',20,strlen($html),'pretend.png');
    $store->withUpload($bad,18,$allow,fn($m,$i) => $store->append($m,$i,0,$html));
    fails(fn() => $store->withUpload($bad,18,$allow,fn($m,$i) => $store->complete($m,$i)),415);
    $store->withUpload($bad,18,$allow,fn($m,$i) => $store->terminate($i));
    $big = $store->create(18,'channel',20,ChatTusStorage::CHUNK_BYTES+2,'a.txt');
    fails(fn() => $store->withUpload($big,18,$allow,fn($m,$i) => $store->append($m,$i,0,str_repeat('a',ChatTusStorage::CHUNK_BYTES+1))),413);
    $store->withUpload($big,18,$allow,fn($m,$i) => $store->terminate($i));
    $pending=[];
    for($n=0;$n<10;$n++) $pending[]=$store->create(18,'channel',20,1,'q.txt');
    fails(fn()=>$store->create(18,'channel',20,1,'q.txt'),429);
    $expired=$pending[0];
    $path=$dir.'/parts/'.$expired.'.json';
    $m=json_decode(file_get_contents($path),true); $m['expires']=time()-1; file_put_contents($path,json_encode($m));
    fails(fn()=>$store->withUpload($expired,18,$allow,fn()=>null),410);
    ensure($store->cleanup()===1,'Cleanup must remove only expired metadata');
    ensure(!is_file($dir.'/parts/'.$expired.'.part'),'Cleanup left partial bytes');
    for($n=0;$n<25;$n++) $store->create(100+$n,'channel',20,ChatTusStorage::MAX_BYTES,'reserved.txt');
    fails(fn()=>$store->create(999,'channel',20,ChatTusStorage::MAX_BYTES,'overflow.txt'),429);
    echo "Chat tus storage regression checks passed\n";
} finally {
    foreach (['parts','files'] as $sub) { foreach(glob($dir.'/'.$sub.'/*')?:[] as $p) unlink($p); if(is_dir($dir.'/'.$sub))rmdir($dir.'/'.$sub); }
    rmdir($dir);
}
