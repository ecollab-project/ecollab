"""Owns an ephemeral MariaDB. Actual API scripts, synthetic sessions, no external targets."""
import hashlib,json,os,pathlib,pwd,socket,subprocess,tempfile,time,statistics,sys,http.server,threading
ROOT=pathlib.Path(__file__).resolve().parents[1]
BASE=os.environ.get('PEER_THREADS_BASE', '051cae2ee1005ab063675e8054c2ff5b1801294b')
PHP=os.environ.get('PHP_BIN','php8.3')
def usage(pid):
 try:
  p=pathlib.Path('/proc')/str(pid)
  if not (p/'comm').read_text().startswith('mariadbd'):return None
  f=(p/'stat').read_text().split();return (int(f[13])+int(f[14]))/os.sysconf('SC_CLK_TCK')
 except OSError:return None

def main():
 with tempfile.TemporaryDirectory(prefix='ecollab-peer-threads-') as tmp:
  p=pathlib.Path(tmp)
  with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
  env=os.environ|{'APP_ENV':'testing','APP_DEBUG':'false','PEER_ML_ENABLED':'false','CENTRIFUGO_ENABLED':'false'}
  class Semantic(http.server.BaseHTTPRequestHandler):
   def do_POST(self):
    payload=json.loads(self.rfile.read(int(self.headers['Content-Length'])))
    result=json.dumps({'version':'semantic-v1','scores':[(i*17)%101 for i in range(len(payload['candidates']))]}).encode()
    self.send_response(200);self.send_header('Content-Length',str(len(result)));self.end_headers();self.wfile.write(result)
   def log_message(self,*args):pass
  semantic=http.server.ThreadingHTTPServer(('127.0.0.1',0),Semantic)
  threading.Thread(target=semantic.serve_forever,daemon=True).start()
  log=open(p/'db.log','w')
  args=[env.get('MARIADB_INSTALL_DB_BIN','mariadb-install-db'),'--no-defaults','--datadir='+str(p/'data'),'--auth-root-authentication-method=normal','--skip-test-db']
  if env.get('MARIADB_BASEDIR'):args+=['--basedir='+env['MARIADB_BASEDIR']]
  subprocess.run(args,env=env,stdout=log,stderr=log,check=True,timeout=60)
  db=subprocess.Popen([env.get('MARIADBD_BIN','mariadbd'),'--no-defaults','--user='+pwd.getpwuid(os.geteuid()).pw_name,'--datadir='+str(p/'data'),'--bind-address=127.0.0.1','--port='+str(port),'--socket=','--pid-file='+str(p/'db.pid'),'--innodb-buffer-pool-size=134217728','--max-connections=16'],env=env,stdout=log,stderr=log)
  dsn=f'mysql:host=127.0.0.1;port={port};dbname=classroom_perf_test'
  def sql(text,fetch=False):
   code='$d=new PDO($argv[1],"root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);'+('echo json_encode($d->query($argv[2])->fetchAll(PDO::FETCH_ASSOC));' if fetch else '$d->exec($argv[2]);')
   r=subprocess.run([PHP,'-r',code,'--',dsn,text],env=env,capture_output=True,text=True,timeout=30);assert r.returncode==0,r.stderr
   return json.loads(r.stdout) if fetch else None
  try:
   for _ in range(100):
    r=subprocess.run([PHP,'-r',f'$d=new PDO("mysql:host=127.0.0.1;port={port}","root","");$d->exec("CREATE DATABASE classroom_perf_test");'],env=env,capture_output=True)
    if r.returncode==0:break
    assert db.poll() is None,(p/'db.log').read_text();time.sleep(.1)
   else:raise AssertionError('DB startup')
   for f in ['tests/fixtures/application-performance.sql','database/migrations/013_peer_matching.sql','tests/classroom-fixture.sql']:sql((ROOT/f).read_text())
   sql("ALTER TABLE friendships ADD id BIGINT AUTO_INCREMENT PRIMARY KEY, ADD UNIQUE KEY uq_friendship(requester_id,addressee_id), ADD KEY idx_addressee(addressee_id); ALTER TABLE thread_attachments MODIFY id BIGINT PRIMARY KEY; INSERT INTO thread_attachments SELECT seq,1+MOD(seq,60),IF(MOD(seq,3)=0,seq,NULL),CONCAT('/synthetic/',seq),CONCAT('File ',seq),'text/plain',seq,1,'2026-10-01' FROM seq_1_to_180; INSERT INTO pm_match_requests(requester_id,addressee_id,status) SELECT 1,seq,IF(MOD(seq,2)=0,'accepted','pending') FROM seq_2_to_101;")
   sql("DELETE FROM thread_attachments WHERE thread_id=1 OR (thread_id=2 AND id<>1); CREATE TABLE thread_reply_votes(reply_id BIGINT,user_id BIGINT,vote INT); INSERT INTO friendships(requester_id,addressee_id,status) VALUES(3,1,'accepted'),(1,4,'pending'),(4,1,'blocked'); UPDATE users SET full_name=CONCAT('Person ',id),last_active_at=DATE_SUB('2026-10-01',INTERVAL id SECOND); UPDATE pm_user_study_prefs SET study_style='solo' WHERE MOD(user_id,3)=0;")
   apps={'candidate':ROOT}
   if not os.environ.get('PEER_THREADS_BASELINE_ONLY'):
    baseline=p/'baseline';subprocess.run(['git','worktree','add','--detach',str(baseline),BASE],cwd=ROOT,check=True,capture_output=True);apps['baseline']=baseline
   else:apps={'baseline':ROOT}
   evidence={'base':BASE,'scope':'Owned MariaDB actual API CLI; not FPM/network/capacity. Fixed clock; semantic provider disabled except one deterministic loopback protocol fixture.','database':sql('SELECT VERSION() version',True),'php':subprocess.check_output([PHP,'-v'],env=env,text=True).splitlines()[0],'cpu_affinity':sorted(os.sched_getaffinity(0)),'application_sha256':{side:{f:hashlib.sha256((root/f).read_bytes()).hexdigest() if (root/f).exists() else None for f in ['API/chat/peer-match.php','API/threads/index.php','services/PeerMatchBatch.php','services/ThreadAttachmentService.php']} for side,root in apps.items()},'cases':{}}
   cases={'peer100':('API/chat/peer-match.php','action=get_matches&limit=50'),'threads1':('API/threads/index.php','scope=public&limit=1'),'threads10':('API/threads/index.php','scope=public&limit=10'),'threads50':('API/threads/index.php','scope=public&limit=50')}
   cases |= {'peer1':('API/chat/peer-match.php','action=get_matches&limit=1'),
    'peer10':('API/chat/peer-match.php','action=get_matches&limit=10'),
    'peer_semantic':('API/chat/peer-match.php','action=get_matches&limit=50'),
    'peer_filtered':('API/chat/peer-match.php','action=get_matches&study_style=solo&sort=subjects&min_score=10&limit=24'),
    'peer_no_matches':('API/chat/peer-match.php','action=get_matches&study_style=absent'),
    'thread_detail':('API/threads/index.php','action=get&id=2'),
    'threads_empty':('API/threads/index.php','scope=public&limit=50'),
    'peer_disabled':('API/chat/peer-match.php','action=get_matches'),
    'peer_eligibility':('API/chat/peer-match.php','action=get_matches&limit=50'),
    'peer_large_history':('API/chat/peer-match.php','action=get_matches&limit=50'),
    'threads_large_history':('API/threads/index.php','scope=public&limit=50'),
    'threads_many_attachments':('API/threads/index.php','scope=public&limit=50')}
   # Opt-in Phase 3R edge cases. Preserve the historical Phase 3Q workload by default.
   if os.environ.get('PEER_THREADS_PHASE3R') == '1':
    cases |= {
     'peer_dense':('API/chat/peer-match.php','action=get_matches&limit=50'),
     'peer_missing_candidate':('API/chat/peer-match.php','action=get_matches&limit=50'),
     'peer_missing_current':('API/chat/peer-match.php','action=get_matches'),
     'peer_empty_candidates':('API/chat/peer-match.php','action=get_matches'),
     'peer_min_score':('API/chat/peer-match.php','action=get_matches&min_score=100'),
     'peer_role_filter':('API/chat/peer-match.php','action=get_matches&role=facilitator'),
     **{'peer_sort_'+sort:('API/chat/peer-match.php','action=get_matches&sort='+sort) for sort in ['style','interests','hobbies']}}
   samples=int(os.environ.get('PEER_THREADS_SAMPLES','4'));assert 2<=samples<=100
   evidence['samples_per_side']=samples
   evidence['warmup_per_side']=1
   evidence['candidate_commit']=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
   evidence['working_tree_diff_sha256']=hashlib.sha256(subprocess.check_output(['git','diff','HEAD'],cwd=ROOT)).hexdigest()
   plans={}
   for label,(route,query) in cases.items():
    if label=='peer_large_history':sql("INSERT INTO pm_match_requests(requester_id,addressee_id,status) SELECT 2+MOD(seq-1,500),100+FLOOR((seq-1)/500),'pending' FROM seq_1_to_50000")
    if label=='threads_large_history':sql("INSERT INTO threads SELECT seq+100,CONCAT('Old thread ',seq),'Synthetic','public',NULL,NULL,1,0,0,0,0,'2020-01-01','2020-01-01' FROM seq_1_to_1000; INSERT INTO thread_attachments SELECT seq+1000,101+MOD(seq,1000),NULL,'/synthetic/old','Old','text/plain',100,1,'2020-01-01' FROM seq_1_to_50000")
    if label=='threads_many_attachments':sql("INSERT INTO thread_attachments SELECT seq+100000,1+MOD(seq,50),NULL,'/synthetic/many','Many','text/plain',100,1,'2020-01-01' FROM seq_1_to_5000")
    env['PEER_ML_ENABLED']='true' if label=='peer_semantic' else 'false'
    env['PEER_ML_URL']=f'http://127.0.0.1:{semantic.server_port}'
    env['PEER_ML_TOKEN']='synthetic-fixture'
    sql("UPDATE users SET is_system=0,deleted_at=NULL,status='active'; DELETE FROM user_settings WHERE user_id IN(1,5)")
    if label=='peer_disabled':sql('INSERT INTO user_settings(user_id,ai_matching) VALUES(1,0)')
    if label=='peer_eligibility':sql("UPDATE users SET deleted_at=NOW() WHERE id=2;UPDATE users SET status='banned' WHERE id=3;UPDATE users SET is_system=1 WHERE id=4;INSERT INTO user_settings(user_id,ai_matching) VALUES(5,0)")
    if label in ['peer1','peer10']:sql('UPDATE users SET is_system=1 WHERE id>'+('2' if label=='peer1' else '11'))
    sql('UPDATE threads SET is_deleted=0')
    if label=='threads_empty':sql('UPDATE threads SET is_deleted=1')
    if label=='peer_dense':
     for tags,links,column in [('pm_subjects','pm_user_subjects','subject_id'),('pm_interest_tags','pm_user_interests','interest_id'),('pm_hobby_tags','pm_user_hobbies','hobby_id')]:
      n=19 if column=='subject_id' else 15
      sql("INSERT INTO "+tags+"(id,name,slug) SELECT 10000+seq,CONCAT('Dense ',seq),CONCAT('dense-',seq) FROM seq_1_to_"+str(n))
      sql('INSERT INTO '+links+'(user_id,'+column+') SELECT u.id,10000+s.seq FROM users u CROSS JOIN seq_1_to_'+str(n)+' s')
    # Roll back missing-profile fixtures before the next case.
    removed_profiles = label in ['peer_missing_candidate', 'peer_missing_current']
    if removed_profiles:
     removed_id = 1 if label == 'peer_missing_current' else 3
     # Each sql() opens a new connection, so save/restore rows explicitly.
     saved = {table:sql('SELECT * FROM '+table+' WHERE user_id='+str(removed_id),True) for table in ['pm_user_subjects','pm_user_interests','pm_user_hobbies','pm_user_study_prefs']}
     for table in saved:sql('DELETE FROM '+table+' WHERE user_id='+str(removed_id))
    if label=='peer_empty_candidates':sql('UPDATE users SET is_system=1 WHERE id<>1')
    allruns={side:[] for side in apps};expected=None;expectedcache=None
    for iteration in range(samples+1):
     order=list(apps) if iteration%2==0 else list(reversed(apps))
     for side in order:
      sql('TRUNCATE rate_limit_log;TRUNCATE pm_compatibility')
      native_before=usage(db.pid)
      metrics=p/'metrics.json';r=subprocess.run([PHP,'-d','auto_prepend_file='+str(ROOT/'tests/peer-threads-bootstrap.php'),'-d','session.save_path='+str(p),str(apps[side]/route)],env=env|{'PEER_THREADS_APP':str(apps[side]),'PEER_THREADS_DSN':dsn,'PEER_THREADS_QUERY':query,'PEER_THREADS_ROUTE':route,'PEER_THREADS_METRICS':str(metrics),'APP_URL':'http://127.0.0.1:12345'},capture_output=True,text=True,timeout=30)
      native_after=usage(db.pid)
      assert r.returncode==0,(r.stderr,r.stdout)
      payload=json.loads(r.stdout);assert payload.get('ok',payload.get('success')) is (label!='peer_disabled'),payload
      cache=sql('SELECT * FROM pm_compatibility ORDER BY user_a_id,user_b_id',True)
      if label in ['peer_missing_current','peer_empty_candidates','peer_min_score','peer_role_filter']:
       assert payload['matches']==[] and cache==[],(label,'expected empty response and cache')
      if label=='peer_missing_current':assert payload['profile_ready'] is False
      if label=='peer_missing_candidate':
       assert all(int(row['user_b_id'])!=3 for row in cache),'unready candidate persisted'
       assert len(cache)==99,'expected exactly one skipped candidate'
      if label=='peer_semantic':
       assert any('Related study topics' in row['tags'] for row in payload['matches']),'semantic tag fixture ineffective'
       assert all('Related study topics' not in json.loads(row['match_tags']) for row in cache),'semantic-only tag leaked into rule cache'
      if label=='peer100':assert len(cache)==100,'display limit must not truncate persisted candidates'
      if expected is None:expected=payload;expectedcache=cache
      assert payload==expected,(label,side,'response mismatch')
      assert cache==expectedcache,(label,side,'persisted mismatch')
      m=json.loads(metrics.read_text());m['database_cpu_ms']=None if native_before is None or native_after is None else (native_after-native_before)*1000;m['query_count']=len(m['queries']);m['sql_ms']=sum(q['ms'] for q in m['queries']);m['writes']=sum(q['sql'].lstrip().startswith(('INSERT','UPDATE','DELETE')) for q in m['queries']);
      if iteration>0:allruns[side].append(m)
    evidence['cases'][label]={'runs':allruns,'complete_response_and_cache_equal':True}
    print(label,{side:{k:round(statistics.median(r[k] for r in runs),3) for k in ['query_count','writes','ms','cpu_ms','sql_ms','memory_increment_bytes']} for side,runs in allruns.items()},flush=True)
    for side,runs in allruns.items():
     for q in runs[0]['queries']:
      text=q['sql']
      if text.lstrip().startswith('SELECT') and any(t in text for t in ['FROM pm_match_requests','FROM thread_attachments','FROM friendships']):
       key=side+':'+label+':'+text
       if key in plans:continue
       code='$d=new PDO($argv[1],"root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$s=$d->prepare("EXPLAIN ".$argv[2]);$s->execute(json_decode($argv[3],true));echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));'
       r=subprocess.run([PHP,'-r',code,'--',dsn,text,json.dumps(q['params'])],env=env,capture_output=True,text=True,timeout=10);assert r.returncode==0,r.stderr
       plans[key]=json.loads(r.stdout)
    if label=='peer_dense':
     for table,column in [('pm_user_subjects','subject_id'),('pm_user_interests','interest_id'),('pm_user_hobbies','hobby_id')]:sql('DELETE FROM '+table+' WHERE '+column+'>10000')
    if removed_profiles:
     for table,rows in saved.items():
      for row in rows:
       values=','.join('NULL' if v is None else "'"+str(v).replace("'","''")+"'" for v in row.values())
       sql('INSERT INTO '+table+' ('+','.join(row)+') VALUES ('+values+')')
   evidence['plans']=plans
   regression=subprocess.run([PHP,str(ROOT/'tests/peer-threads-regression.php')],env=env|{'PEER_THREADS_DSN':dsn},capture_output=True,text=True,timeout=30)
   assert regression.returncode==0,(regression.stdout,regression.stderr)
   evidence['batch_regression']=regression.stdout
   sql('CREATE DATABASE settings_fixture_test')
   accounts=sql("SELECT Host FROM mysql.user WHERE User='root'",True)
   sql(";".join("ALTER USER 'root'@'"+a['Host']+"' IDENTIFIED BY 'test-password'" for a in accounts))
   settings=subprocess.run([PHP,str(ROOT/'tests/settings-migration-regression.php')],env=env|{'SETTINGS_TEST_DSN':f'mysql:host=127.0.0.1;port={port};dbname=settings_fixture_test'},capture_output=True,text=True,timeout=15)
   assert settings.returncode==0,(settings.stdout,settings.stderr)
   evidence['settings_migration_regression']=settings.stdout
   target=pathlib.Path(os.environ.get('PEER_THREADS_EVIDENCE','peer-threads-evidence.json'));target.write_text(json.dumps(evidence,indent=2))
  finally:
   semantic.shutdown();semantic.server_close()
   db.terminate();db.wait(timeout=15)
   if 'baseline' in locals():subprocess.run(['git','worktree','remove','--force',str(baseline)],cwd=ROOT,capture_output=True)
if __name__=='__main__':main()
