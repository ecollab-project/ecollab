"""Real PHP endpoint, synthetic sessions and a disposable SQLite fixture. No model/VPS access."""
import http.client,json,os,pathlib,re,socket,sqlite3,subprocess,tempfile,time
ROOT=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='jarred-http-') as folder:
 p=pathlib.Path(folder);fixture=p/'fixture.sqlite';db=sqlite3.connect(fixture)
 schema=re.search(r'\$schema="(.*?)";', (ROOT/'tests/phase2-jarred.php').read_text(),re.S).group(1)
 db.executescript(schema)
 for column,typ in [('attachment_path','TEXT'),('attachment_name','TEXT'),('attachment_size','INTEGER'),('attachment_mime','TEXT')]:db.execute('ALTER TABLE dm_messages ADD COLUMN '+column+' '+typ)
 db.commit();db.close()
 router=p/'router.php';router.write_text('''<?php
require_once '''+repr(str(ROOT/'database/config/db.php'))+''';
require_once '''+repr(str(ROOT/'security/middleware/AuthMiddleware.php'))+''';
(new ReflectionProperty(Database::class,'instance'))->setValue(null,new PDO('sqlite:''' + str(fixture)+'''',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]));
if(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/seed'){AuthMiddleware::startSession();$_SESSION['user_id']=(int)($_GET['uid']??1);$_SESSION['csrf_token']='fixture-token';session_write_close();echo 'seed';return;}
$route=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);if(!in_array($route,['/API/dm/send-message.php','/API/dm/jarred-job.php'],true)){http_response_code(404);return;}
require '''+repr(str(ROOT))+'''.$route;
''')
 with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
 env=os.environ.copy();env['CENTRIFUGO_ENABLED']='false'
 php=os.environ.get('PHP_BIN','php');server=subprocess.Popen([php,'-d','session.save_path='+folder,'-S','127.0.0.1:'+str(port),str(router)],env=env,cwd=ROOT,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 def request(path,body=None,cookie='',csrf='fixture-token',referer=''):
  c=http.client.HTTPConnection('127.0.0.1',port,timeout=3);c.request('POST' if body is not None else 'GET',path,None if body is None else json.dumps(body),{'Content-Type':'application/json','Cookie':cookie,'X-CSRF-Token':csrf,'Referer':referer});r=c.getresponse();out=(r.status,r.read(),r.getheader('Set-Cookie'));c.close();return out
 try:
  for _ in range(100):
   try:seed=request('/seed');break
   except OSError:time.sleep(.02)
  cookie=seed[2].split(';')[0];body={'conversation_id':1,'body':'hello','surface':'dm'}
  assert request('/API/dm/send-message.php',body)[0]==401
  assert request('/API/dm/send-message.php',body,cookie,csrf='wrong')[0]==403
  assert request('/API/dm/send-message.php',dict(body,surface='code_lab'),cookie)[0]==403
  started=time.monotonic();status,raw,_=request('/API/dm/send-message.php',body,cookie);elapsed=time.monotonic()-started
  assert status==202,(status,raw);data=json.loads(raw);job=data['ai_job_id'];assert 'ai_message'not in data
  assert request('/API/dm/send-message.php',body,cookie)[0]==409
  db=sqlite3.connect(fixture);assert db.execute('SELECT COUNT(*) FROM dm_messages').fetchone()[0]==1;assert db.execute('SELECT state FROM jarred_jobs').fetchone()[0]=='queued';db.close()
  other=request('/seed?uid=3')[2].split(';')[0];url=f'/API/dm/jarred-job.php?conversation_id=1&job_id={job}'
  assert request(url,cookie=other)[0]==403
  assert json.loads(request(url,cookie=cookie)[1])['job']['state']=='queued'
  cancelled=request('/API/dm/jarred-job.php',{'conversation_id':1,'job_id':job,'action':'cancel'},cookie)
  assert json.loads(cancelled[1])['job']['state']=='cancelled',cancelled
  print(json.dumps({'synthetic_ack_ms':round(elapsed*1000,2),'worker_running':False,'status':202}))
  print('PASS actual enqueue API: auth, CSRF, Code Lab denial, atomic busy rejection, ownership and cancellation')
 finally:server.terminate();server.wait(timeout=5)
