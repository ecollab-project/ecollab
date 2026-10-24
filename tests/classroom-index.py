"""Bounded index build/write/metadata-lock experiments on the driver's owned database."""
import json,pathlib,statistics,subprocess,threading,time

def measure(root,p,env,php,port,sql):
    dsn=f'mysql:host=127.0.0.1;port={port};dbname=classroom_perf_test'
    prefix='$d=new PDO('+json.dumps(dsn)+',"root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);'
    def writes():
        code=prefix+'''$out=[];foreach(['insert'=>"INSERT INTO messages(channel_id,sender_id,content,is_deleted,created_at) SELECT 1,1,'Synthetic index fixture',0,NOW() FROM seq_1_to_1000",'update'=>"UPDATE messages SET created_at=DATE_ADD(created_at,INTERVAL 1 SECOND) WHERE id BETWEEN 1000 AND 1999"]as$name=>$statement){for($i=0;$i<5;$i++){$d->beginTransaction();$t=hrtime(true);$n=$d->exec($statement);$out[$name][]=['ms'=>(hrtime(true)-$t)/1e6,'rows'=>$n];$d->rollBack();}}echo json_encode($out);'''
        r=subprocess.run([php,'-r',code],env=env,capture_output=True,text=True,timeout=45);assert r.returncode==0,r.stderr;return json.loads(r.stdout)
    def storage():
        sql('ANALYZE TABLE messages')
        return sql("SELECT index_name,stat_value*@@innodb_page_size allocated_bytes FROM mysql.innodb_index_stats WHERE database_name=DATABASE() AND table_name='messages' AND stat_name='size'",True)
    before_count=sql('SELECT COUNT(*) n FROM messages',True)
    out={'without_index':{'writes':writes(),'storage':storage()}}
    migration=(root/'database/migrations/067_dashboard_unread_covering.sql').read_text()
    held=p/'metadata-held';release=p/'metadata-release'
    code=prefix+'$d->beginTransaction();$d->query("SELECT id FROM messages LIMIT 1")->fetchAll();file_put_contents($argv[1],"held");$t=microtime(true);while(!file_exists($argv[2])&&microtime(true)-$t<5)usleep(10000);$d->rollBack();'
    holder=subprocess.Popen([php,'-r',code,'--',str(held),str(release)],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
    try:
        for _ in range(100):
            if held.exists():break
            assert holder.poll() is None;time.sleep(.01)
        started=time.monotonic()
        try:sql('SET SESSION lock_wait_timeout=1;'+migration);raise RuntimeError('Expected owned metadata-lock timeout')
        except AssertionError as e:
            assert '1205' in str(e),str(e);out['held_transaction_ddl']={'outcome':'1205 metadata-lock timeout','elapsed_ms':(time.monotonic()-started)*1000,'lock_wait_timeout_seconds':1}
    finally:release.touch();holder.wait(timeout=6)
    samples=[];stop=threading.Event()
    def watch():
        while not stop.is_set():
            paths=list((p/'data').rglob('*'));samples.append({'total_bytes':sum(x.stat().st_size for x in paths if x.is_file()),'temporary_named_bytes':sum(x.stat().st_size for x in paths if x.is_file() and ('#sql' in x.name or x.name.startswith('ibtmp'))) });stop.wait(.02)
    watcher=threading.Thread(target=watch,daemon=True);watcher.start();started=time.monotonic()
    try:sql(migration)
    finally:stop.set();watcher.join(timeout=2)
    out['build_ms']=(time.monotonic()-started)*1000;out['storage_samples']=samples
    out['with_index']={'writes':writes(),'storage':storage()}
    assert sql('SELECT COUNT(*) n FROM messages',True)==before_count,'Index/write fixture changed row count'
    out['preserved_message_rows']=before_count[0]['n'];return out
