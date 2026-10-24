"""Actual CLI loops; model/compiler replies supplied by a disposable HTTP fixture, never executed."""
import base64,hashlib,http.server,json,os,pathlib,signal,subprocess,threading,time

def run(root,p,env,php,port):
 def query(db,statement,fetch=False):
  dsn=f'mysql:host=127.0.0.1;port={port};dbname={db}'
  code='$d=new PDO('+json.dumps(dsn)+',"root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);'
  code+=('echo json_encode($d->query($argv[1])->fetchAll(PDO::FETCH_ASSOC));' if fetch else '$d->exec($argv[1]);')
  r=subprocess.run([php,'-r',code,'--',statement],env=env,capture_output=True,text=True,timeout=15);assert r.returncode==0,r.stderr
  return json.loads(r.stdout) if fetch else None
 def create(name):
  subprocess.run([php,'-r',f'$d=new PDO("mysql:host=127.0.0.1;port={port}","root","");$d->exec("CREATE DATABASE {name}");'],env=env,check=True)
 def questions():return int(query('chat_perf_test',"SHOW GLOBAL STATUS LIKE 'Questions'",True)[0]['Value'])
 def usage(pid):
  try:stat=pathlib.Path(f'/proc/{pid}/stat').read_text().split()
  except OSError:return {'cpu_ticks':None,'pss_kib':None}
  pss=None
  try:
   for line in pathlib.Path(f'/proc/{pid}/smaps_rollup').read_text().splitlines():
    if line.startswith('Pss:'):pss=int(line.split()[1])
  except OSError:pass
  return {'cpu_ticks':int(stat[13])+int(stat[14]),'pss_kib':pss}
 class Provider(http.server.BaseHTTPRequestHandler):
  calls=0
  def do_POST(self):
   self.rfile.read(int(self.headers.get('Content-Length','0')));Provider.calls+=1
   if self.path.startswith('/submissions'):data={'token':'synthetic-worker-token'}
   else:data={'done':True,'done_reason':'stop','message':{'content':'Synthetic provider reply'},'model':'fixture','prompt_eval_count':1,'eval_count':1}
   self.send_response(200);self.send_header('Content-Type','application/json');self.end_headers();self.wfile.write(json.dumps(data).encode())
  def do_GET(self):
   data={'status':{'id':3,'description':'Accepted'},'stdout':base64.b64encode(b'42').decode(),'stderr':'','compile_output':'','time':'0','memory':0};self.send_response(200);self.end_headers();self.wfile.write(json.dumps(data).encode())
  def log_message(self,*args):pass
 provider=http.server.ThreadingHTTPServer(('127.0.0.1',0),Provider);threading.Thread(target=provider.serve_forever,daemon=True).start();out={};workers=[]
 try:
  for kind,db in [('jarred','jarred_async_test'),('code_lab','code_worker_perf')]:
   create(db)
   if kind=='jarred':
    for f in ['tests/fixtures/jarred-async.sql','database/migrations/052_realtime_outbox.sql','database/migrations/063_temporary_voice.sql','database/migrations/066_jarred_jobs.sql']:query(db,(root/f).read_text())
    query(db,'INSERT INTO dm_conversations(id,user_a,user_b) VALUES(4,1,2),(5,1,2)')
    table='jarred_jobs'
   else:
    for f in ['database/migrations/054_code_lab.sql','database/migrations/055_code_lab_execution.sql','database/migrations/062_code_lab_server_queue.sql']:query(db,(root/f).read_text())
    query(db,"CREATE TABLE users(id INT PRIMARY KEY,role VARCHAR(30),deleted_at DATETIME NULL,status VARCHAR(20));CREATE TABLE servers(id INT PRIMARY KEY,name VARCHAR(50),owner_id INT,status VARCHAR(20));CREATE TABLE server_members(server_id INT,user_id INT);INSERT INTO users VALUES(1,'student',NULL,'active');INSERT INTO servers VALUES(1,'Fixture',1,'active'),(2,'Second',1,'active');INSERT INTO server_members VALUES(1,1),(2,1)")
    table='code_lab_jobs'
   query(db,'CREATE TABLE worker_claims(job_id BIGINT,at_epoch DECIMAL(20,6))')
   query(db,f"CREATE TRIGGER measure_claim AFTER UPDATE ON {table} FOR EACH ROW BEGIN IF NEW.state='running' AND OLD.state='queued' THEN INSERT INTO worker_claims VALUES(NEW.id,UNIX_TIMESTAMP(NOW(6)));END IF;END")
   lock=p/(kind+'.lock');lock.touch(mode=0o600)
   wenv=env|{'DB_HOST':'127.0.0.1','DB_PORT':str(port),'DB_NAME':db,'DB_USER':'root','DB_PASS':'','WORKER_PERF_DSN':f'mysql:host=127.0.0.1;port={port};dbname={db}','OLLAMA_INFERENCE_LOCK_FILE':str(lock),'OLLAMA_URL':f'http://127.0.0.1:{provider.server_port}','OLLAMA_MODEL':'fixture','CENTRIFUGO_ENABLED':'true','CODE_LAB_JUDGE0_URL':f'http://127.0.0.1:{provider.server_port}','CODE_LAB_EXECUTION_SLOTS':'1'}
   log=open(p/(kind+'-worker.log'),'w');proc=subprocess.Popen([php,str(root/'tests/chat-worker-wrapper.php'),'--loop'],env=wenv,cwd=root,stdout=log,stderr=log,start_new_session=True);workers.append(proc)
   # Wait for the real worker's connection lock; /proc may use another PID namespace locally.
   lockname=('ec-jarred-worker-'+hashlib.sha224(db.encode()).hexdigest()) if kind=='jarred' else 'ec-cl-'+hashlib.sha224((db+':1').encode()).hexdigest()
   for _ in range(80):
    if query(db,"SELECT IS_USED_LOCK('"+lockname+"') owner",True)[0]['owner'] is not None:break
    assert proc.poll() is None,(p/(kind+'-worker.log')).read_text();time.sleep(.05)
   else:raise AssertionError('Actual worker lock not acquired')
   pid=proc.pid
   if kind=='code_lab':
    try:
     children=pathlib.Path(f'/proc/{pid}/task/{pid}/children').read_text().split();pid=int(children[0]) if children else None
    except OSError:pid=None
   time.sleep(.3);assert proc.poll() is None,(p/(kind+'-worker.log')).read_text()
   first=usage(pid);q0=questions();started=time.monotonic();idle_seconds=float(env.get('WORKER_PROFILE_IDLE_SECONDS','4'));assert 4<=idle_seconds<=30;time.sleep(idle_seconds);q1=questions();last=usage(pid);elapsed=time.monotonic()-started
   # Global counter sampling adds one observer SHOW; no other workload runs during idle interval.
   idle={'seconds':elapsed,'global_questions_delta_less_one_observer':q1-q0-1,'questions_per_second':(q1-q0-1)/elapsed,'cpu_seconds':((last['cpu_ticks']-first['cpu_ticks'])/os.sysconf('SC_CLK_TCK') if first['cpu_ticks'] is not None and last['cpu_ticks'] is not None else None),'pss_start_kib':first['pss_kib'],'pss_end_kib':last['pss_kib']}
   started=time.monotonic();calls=Provider.calls;enqueued=[]
   if kind=='jarred':
    # Same admission contract as HTTP endpoint; synthetic messages, three distinct conversation reservations.
    code='require '+repr(str(root/'services/JarredJobService.php'))+';$d=new PDO(getenv("WORKER_PERF_DSN"),"root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$j=new JarredJobService($d);$times=[];foreach([1,4,5]as$cid){$d->beginTransaction();$j->reserveConversation(1,$cid);$d->prepare("INSERT INTO dm_messages(conversation_id,sender_id,body)VALUES(?,1,?)")->execute([$cid,"Explain a synthetic database concept"]);$j->enqueue(1,2,$cid,(int)$d->lastInsertId(),null,[]);$d->commit();$times[]=microtime(true);}echo json_encode($times);'
    r=subprocess.run([php,'-r',code],env=wenv,cwd=root,capture_output=True,text=True,timeout=10);assert r.returncode==0,r.stderr;enqueued=json.loads(r.stdout)
   else:
    for sid in [1,2,1]:
     query(db,f"INSERT INTO code_lab_jobs(server_id,user_id,language,runtime_id,kind,source_code,stdin,created_at) VALUES({sid},1,'python',71,'run','synthetic-no-execution','',NOW())");enqueued.append(time.time())
   for _ in range(150):
    states=query(db,f'SELECT state,COUNT(*) n FROM {table} GROUP BY state',True)
    if sum(int(r['n']) for r in states if r['state']=='done')==3:break
    assert proc.poll() is None,(p/(kind+'-worker.log')).read_text();time.sleep(.04)
   else:raise AssertionError((kind,states,(p/(kind+'-worker.log')).read_text()[-2000:]))
   claims=query(db,'SELECT job_id,at_epoch FROM worker_claims ORDER BY job_id',True);assert len(claims)==3
   if kind=='jarred':assert int(query(db,'SELECT COUNT(*) n FROM dm_messages WHERE sender_id=2',True)[0]['n'])==3
   else:assert all('42' in r['result_json'] for r in query(db,'SELECT result_json FROM code_lab_jobs',True))
   busy=usage(pid);query_metrics={'elapsed_to_three_done_ms':(time.monotonic()-started)*1000,'provider_http_posts':Provider.calls-calls,'commit_ack_to_claim_ms':[(float(r['at_epoch'])-ack)*1000 for r,ack in zip(claims,enqueued)],'cpu_seconds':((busy['cpu_ticks']-last['cpu_ticks'])/os.sysconf('SC_CLK_TCK') if busy['cpu_ticks'] is not None and last['cpu_ticks'] is not None else None),'pss_after_kib':busy['pss_kib'],'claim_order':[int(r['job_id']) for r in query(db,'SELECT job_id FROM worker_claims ORDER BY at_epoch',True)]}
   os.killpg(proc.pid,signal.SIGTERM);proc.wait(timeout=5);log.close();summaries=[]
   for line in (p/(kind+'-worker.log')).read_text().splitlines():
    try:value=json.loads(line)
    except json.JSONDecodeError:continue
    if isinstance(value,dict) and value.get('type')=='code_lab_worker_summary':summaries.append(value)
   out[kind]={'idle':idle,'burst':query_metrics,'worker_exit':proc.returncode,'worker_metric_summaries':summaries}
 finally:
  for proc in workers:
   if proc.poll() is None:os.killpg(proc.pid,signal.SIGTERM);proc.wait(timeout=5)
  provider.shutdown()
 return out
