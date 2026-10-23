"""Own a disposable MariaDB, PHP HTTP/CLI and fake loopback model. No deployed resources."""
import concurrent.futures, http.client, http.server, json, os, pathlib, pwd, signal, socket, subprocess, tempfile, threading, time
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BIN','php')
def port():
 with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]
def check(v,label):
 assert v,label
 print('PASS '+label,flush=True)
def packet(s):
 def exact(n):
  data=b''
  while len(data)<n:
   chunk=s.recv(n-len(data))
   if not chunk:raise EOFError()
   data+=chunk
  return data
 h=exact(4);return h+exact(int.from_bytes(h[:3],'little'))
with tempfile.TemporaryDirectory(prefix='jarred-async-') as folder:
 p=pathlib.Path(folder);dbport=port();webport=port();proxyport=port();modefile=p/'fault';modefile.write_text('')
 env=os.environ|{'APP_ENV':'testing','APP_DEBUG':'true','CENTRIFUGO_ENABLED':'true','PHP_CLI_SERVER_WORKERS':'4','JARRED_ASYNC_TEST_DSN':f'mysql:host=127.0.0.1;port={dbport};dbname=jarred_async_test'}
 installer=os.environ.get('MARIADB_INSTALL_DB_BIN','mariadb-install-db');daemon=os.environ.get('MARIADBD_BIN','mariadbd')
 args=[installer,'--no-defaults','--datadir='+str(p/'data'),'--auth-root-authentication-method=normal','--skip-test-db']
 if os.environ.get('MARIADB_BASEDIR'):args.append('--basedir='+os.environ['MARIADB_BASEDIR'])
 log=open(p/'database.log','w');subprocess.run(args,env=env,stdout=log,stderr=log,check=True,timeout=60)
 server=subprocess.Popen([daemon,'--no-defaults','--user='+pwd.getpwuid(os.geteuid()).pw_name,'--datadir='+str(p/'data'),'--bind-address=127.0.0.1','--port='+str(dbport),'--socket=','--pid-file='+str(p/'db.pid')],env=env,stdout=log,stderr=log)
 web=None;workers=[];proxy=None;provider=None
 def sql(statement,fetch=False):
  code='$d=new PDO('+json.dumps(f'mysql:host=127.0.0.1;port={dbport}')+',"root","");$d->exec("USE jarred_async_test");'
  code+=('echo json_encode($d->query('+json.dumps(statement)+')->fetchAll(PDO::FETCH_ASSOC));') if fetch else ('$d->exec('+json.dumps(statement)+');')
  r=subprocess.run([PHP,'-r',code],env=env,capture_output=True,text=True,timeout=15)
  assert r.returncode==0,r.stderr
  return json.loads(r.stdout) if fetch else None
 def count(table):return int(sql('SELECT COUNT(*) AS n FROM '+table,True)[0]['n'])
 def clear():
  sql("DELETE FROM jarred_jobs;DELETE FROM dm_messages;DELETE FROM realtime_outbox;DELETE FROM effects;UPDATE users SET status='active',deleted_at=NULL WHERE id=1;UPDATE dm_conversations SET user_a=1 WHERE id=1;UPDATE user_settings SET jarred_enabled=1")
 # Packet-aware proxy drops actual COMMIT requests or acknowledgments; it never logs SQL.
 listener=socket.socket();listener.setsockopt(socket.SOL_SOCKET,socket.SO_REUSEADDR,1);listener.bind(('127.0.0.1',proxyport));listener.listen()
 def connection(client):
  upstream=socket.create_connection(('127.0.0.1',dbport),3);mode=modefile.read_text();commit=threading.Event()
  def close():
   for s in [client,upstream]:
    try:s.shutdown(socket.SHUT_RDWR)
    except OSError:pass
  def relay(src,dst,requests):
   try:
    while True:
     data=packet(src);payload=data[4:]
     if requests and payload[:1] in (b'\x03',b'\x16'):
      query=payload[1:].strip().upper()
      if (mode=='before_commit' and query==b'COMMIT') or (mode=='before_enqueue' and query.startswith(b'INSERT INTO JARRED_JOBS')):
       close();return
      if mode=='after_commit' and query==b'COMMIT':commit.set()
     if not requests and commit.is_set() and payload[:1]==b'\x00':close();return
     dst.sendall(data)
   except (EOFError,OSError):close()
  t=threading.Thread(target=relay,args=(upstream,client,False),daemon=True);t.start();relay(client,upstream,True);t.join(timeout=2);client.close();upstream.close()
 def accept():
  while True:
   try:c,_=listener.accept()
   except OSError:return
   threading.Thread(target=connection,args=(c,),daemon=True).start()
 threading.Thread(target=accept,daemon=True).start()
 class Model(http.server.BaseHTTPRequestHandler):
  mode='normal';calls=0;entered=threading.Event();release=threading.Event()
  def do_POST(self):
   self.rfile.read(int(self.headers.get('Content-Length','0')));Model.calls+=1;Model.entered.set()
   if Model.mode=='hold':Model.release.wait(8)
   if Model.mode=='timeout':time.sleep(2)
   if Model.mode=='outage':self.send_response(504);data={'error':'PRIVATE_PROVIDER_SENTINEL'}
   else:
    self.send_response(200);data={'done':Model.mode!='incomplete','done_reason':'length' if Model.mode=='incomplete' else 'stop','message':{'content':[] if Model.mode=='malformed' else 'Synthetic reply'},'model':'fixture','prompt_eval_count':1,'eval_count':2}
   self.send_header('Content-Type','application/json');self.end_headers()
   try:self.wfile.write(json.dumps(data).encode())
   except (BrokenPipeError,ConnectionResetError):pass
  def log_message(self,*args):pass
 provider=http.server.ThreadingHTTPServer(('127.0.0.1',0),Model);threading.Thread(target=provider.serve_forever,daemon=True).start()
 env|={'OLLAMA_URL':f'http://127.0.0.1:{provider.server_port}','OLLAMA_MODEL':'fixture','OLLAMA_INFERENCE_LOCK_FILE':str(p/'inference.lock'),'OLLAMA_WORKER_TIMEOUT':'1'}
 (p/'inference.lock').touch(mode=0o600)
 try:
  for _ in range(150):
   assert server.poll() is None,'Owned database exited'
   try:
    r=subprocess.run([PHP,'-r',f'$d=new PDO("mysql:host=127.0.0.1;port={dbport}","root","");$d->exec("CREATE DATABASE jarred_async_test");'],env=env,capture_output=True)
    if r.returncode==0:break
   except OSError:pass
   time.sleep(.1)
  else:raise AssertionError('Owned database unavailable')
  for file in ['tests/fixtures/jarred-async.sql','database/migrations/052_realtime_outbox.sql','database/migrations/063_temporary_voice.sql','database/migrations/066_jarred_jobs.sql']:sql((ROOT/file).read_text())
  print('VERIFIED MariaDB '+sql('SELECT VERSION() version',True)[0]['version'],flush=True)
  router=p/'router.php';router.write_text('''<?php
require_once '''+repr(str(ROOT/'database/config/db.php'))+''';
require_once '''+repr(str(ROOT/'security/middleware/AuthMiddleware.php'))+''';
$db=new PDO('mysql:host=127.0.0.1;port='''+str(proxyport)+''';dbname=jarred_async_test','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
(new ReflectionProperty(Database::class,'instance'))->setValue(null,$db);
$route=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($route==='/seed'){AuthMiddleware::startSession();$_SESSION['user_id']=(int)($_GET['uid']??1);$_SESSION['csrf_token']='fixture';session_write_close();echo 'seed';return;}
if(!in_array($route,['/API/dm/send-message.php','/API/dm/jarred-job.php'],true)){http_response_code(404);return;}
require '''+repr(str(ROOT))+'''.$route;
''')
  operational=p/'errors.log';access=open(p/'http-access.log','w')
  web=subprocess.Popen([PHP,'-d','display_errors=0','-d','log_errors=1','-d','error_log='+str(operational),'-d','session.save_path='+folder,'-S',f'127.0.0.1:{webport}',str(router)],env=env,cwd=ROOT,stdout=access,stderr=access,start_new_session=True)
  def request(path='/API/dm/send-message.php',body=None,cookie='',csrf='fixture',raw=None):
   c=http.client.HTTPConnection('127.0.0.1',webport,timeout=8);payload=raw if raw is not None else (None if body is None else json.dumps(body));c.request('POST' if payload is not None else 'GET',path,payload,{'Content-Type':'application/json','Cookie':cookie,'X-CSRF-Token':csrf});r=c.getresponse();result=(r.status,r.read(),r.getheader('Set-Cookie'));c.close();return result
  for _ in range(100):
   try:seed=request('/seed');break
   except OSError:time.sleep(.05)
  cookie=seed[2].split(';')[0];other=request('/seed?uid=3')[2].split(';')[0]
  body={'conversation_id':1,'body':'Synthetic lesson','surface':'dm'}
  check(request(body=body)[0]==401,'unauthenticated submission denied')
  check(request(body=body,cookie=cookie,csrf='wrong')[0]==403,'forged CSRF denied')
  check(request(body=body|{'surface':'code_lab'},cookie=cookie)[0]==403,'existing protected Code Lab surface policy retained')
  check(request(body={'conversation_id':1,'body':['PRIVATE_SENTINEL']},cookie=cookie)[0]==400,'malformed fields safely rejected')
  check(request(raw='{PRIVATE_SENTINEL',cookie=cookie)[0]==400,'malformed JSON safely rejected')
  sql('UPDATE user_settings SET jarred_enabled=0 WHERE user_id=1');check(request(body=body,cookie=cookie)[0]==403 and count('dm_messages')==0,'disabled preference before source insertion');clear()
  for change in ["UPDATE users SET status='suspended' WHERE id=1","UPDATE users SET deleted_at=CURRENT_TIMESTAMP WHERE id=1","UPDATE dm_conversations SET user_a=99 WHERE id=1"]:
   sql(change);check(request(body=body,cookie=cookie)[0]==403 and count('dm_messages')==0,'current account/conversation denial');clear()
  before=Model.calls
  with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:results=list(pool.map(lambda _:request(body=body,cookie=cookie),range(2)))
  check(sorted(r[0] for r in results)==[202,409],'simultaneous same-conversation requests admit one')
  check(count('dm_messages')==count('jarred_jobs')==count('realtime_outbox')==1,'atomic source/job/outbox admission')
  check(Model.calls==before,'HTTP admission never performs synchronous inference')
  job=int(sql('SELECT id FROM jarred_jobs',True)[0]['id']);url=f'/API/dm/jarred-job.php?conversation_id=1&job_id={job}'
  check(request(url,cookie=other)[0]==403,'unauthorized job retrieval denied')
  for _ in range(2):check(json.loads(request(url,cookie=cookie)[1])['job']['state']=='queued','duplicate polling is read-only')
  check(request('/API/dm/jarred-job.php',{'action':'cancel','conversation_id':1,'job_id':job},cookie)[0]==200,'authorized cancellation');clear()
  for mode in ['before_enqueue','before_commit','after_commit']:
   modefile.write_text(mode);status,raw,_=request(body=body,cookie=cookie);modefile.write_text('');time.sleep(.15)
   check(status!=202 and b'PRIVATE' not in raw,'safe HTTP error for actual TCP '+mode)
   expected=1 if mode=='after_commit' else 0
   check(count('dm_messages')==count('jarred_jobs')==count('realtime_outbox')==expected,'actual TCP '+mode+' leaves complete pair or nothing')
   if mode=='after_commit':check(b'outcome is unknown' in raw,'ambiguous committed acknowledgment never encourages blind replay')
   clear()
  sql("CREATE TRIGGER secret_failure BEFORE INSERT ON dm_messages FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='PRIVATE_PROMPT_KEY_DOCUMENT_SENTINEL'")
  status,raw,_=request(body=body,cookie=cookie);check(status==500 and b'PRIVATE' not in raw and count('jarred_jobs')==0,'SQL diagnostic marker never reaches HTTP');sql('DROP TRIGGER secret_failure');clear()
  # Raw client closes immediately after a complete request. The transaction still persists.
  payload=json.dumps(body).encode();s=socket.create_connection(('127.0.0.1',webport));s.sendall((f'POST /API/dm/send-message.php HTTP/1.1\r\nHost: localhost\r\nCookie: {cookie}\r\nX-CSRF-Token: fixture\r\nContent-Type: application/json\r\nContent-Length: {len(payload)}\r\nConnection: close\r\n\r\n').encode()+payload);s.close()
  for _ in range(100):
   if count('jarred_jobs')==1:break
   time.sleep(.02)
  check(count('jarred_jobs')==count('dm_messages')==count('realtime_outbox')==1,'client disconnect retains durable admission');clear()
  # Existing global queue bound, without executing any synthetic pending tasks.
  sql("INSERT INTO jarred_jobs(user_id,recipient_id,conversation_id,source_message_id,surface_json) SELECT 1,2,100+n,100+n,'{}' FROM (SELECT seq AS n FROM seq_1_to_100) pending")
  check(request(body=body,cookie=cookie)[0]==503 and count('dm_messages')==0,'existing queue saturation bound enforced');clear()
  # Real CLI worker + actual HTTP mock provider, abrupt process death and fresh restart.
  def start_worker():
   h=open(p/'worker.log','a');w=subprocess.Popen([PHP,'-d','display_errors=0','-d','log_errors=1','-d','error_log='+str(operational),str(ROOT/'tests/jarred-async-worker-fixture.php'),'--once'],env=env,cwd=ROOT,stdout=h,stderr=h);workers.append(w);return w
  check(request(body=body,cookie=cookie)[0]==202,'worker fixture source queued');Model.mode='hold';Model.entered.clear();Model.release.clear();w=start_worker();check(Model.entered.wait(5),'real worker reached HTTP inference');w.kill();w.wait(timeout=5);Model.release.set();Model.mode='normal'
  w=start_worker();check(w.wait(timeout=8)==0,'fresh singleton worker recovers claim')
  check(int(sql('SELECT COUNT(*) n FROM dm_messages WHERE sender_id=2',True)[0]['n'])==1,'one persisted assistant response after inference crash/retry')
  check(start_worker().wait(timeout=8)==0 and count('dm_messages')==2,'completed job not replayed by duplicate worker invocation');clear()
  for mode in ['outage','incomplete','malformed','timeout']:
   Model.mode=mode;check(request(body=body,cookie=cookie)[0]==202,'provider failure fixture queued '+mode)
   for _ in range(3):check(start_worker().wait(timeout=8)==0,'safe worker failure accounting '+mode)
   check(sql('SELECT state FROM jarred_jobs',True)[0]['state']=='failed' and count('dm_messages')==1,'no persisted partial/invalid reply '+mode);clear()
  Model.mode='normal'
  before=Model.calls
  for text in ['create a temporary voice room','yes','public']:
   check(request(body=body|{'body':text,'active_server_id':1},cookie=cookie)[0]==202,'temporary voice action queued')
   check(start_worker().wait(timeout=8)==0,'actual temporary voice action committed')
   check(sql('SELECT state FROM jarred_jobs ORDER BY id DESC LIMIT 1',True)[0]['state']=='done','temporary voice action transaction retained')
  check(count('temporary_voice_rooms')==1 and Model.calls==before,'real tool action has one room and no model inference')
  check(start_worker().wait(timeout=8)==0 and count('temporary_voice_rooms')==1,'duplicate worker does not repeat real room creation');clear()
  # Human DM contract remains synchronous and contains no AI job.
  status,raw,_=request(body=body|{'conversation_id':2},cookie=cookie);check(status==200 and not json.loads(raw)['is_ai'] and count('jarred_jobs')==0,'human DM behavior retained');clear()
  subprocess.run([PHP,str(ROOT/'tests/jarred-async-contract.php')],cwd=ROOT,env=env,check=True,timeout=30)
  sql('CREATE DATABASE ecollab_phase2_test')
  subprocess.run([PHP,str(ROOT/'tests/phase2-jarred.php')],cwd=ROOT,env=env|{'CENTRIFUGO_ENABLED':'false','PHASE2_TEST_DSN':f'mysql:host=127.0.0.1;port={dbport};dbname=ecollab_phase2_test'},check=True,timeout=30)
  r=subprocess.run([PHP,'-d','display_errors=0','-d','error_log='+str(operational),str(ROOT/'tests/jarred-async-worker-fixture.php'),'--once'],env=env|{'OLLAMA_INFERENCE_LOCK_FILE':''},cwd=ROOT,capture_output=True,text=True,timeout=5)
  check(r.returncode==1 and 'PRIVATE' not in r.stderr,'worker refuses a missing common lock without a temp fallback')
  errors=operational.read_text() if operational.exists() else ''
  check('PRIVATE' not in errors and 'Synthetic lesson' not in errors and 'Synthetic reply' not in errors,'operational logs exclude synthetic secrets/prompts/provider bodies')
  print('PASS actual MariaDB/HTTP admission, TCP commit ambiguity, CLI recovery and privacy matrix; model execution may repeat',flush=True)
 finally:
  for w in workers:
   if w.poll() is None:w.kill();w.wait(timeout=5)
  if web:
   os.killpg(web.pid,signal.SIGTERM);web.wait(timeout=5)
  provider.shutdown();listener.close();server.terminate();server.wait(timeout=15);log.close()
