"""Disposable real Centrifugo 6.9.6 -> Nginx -> 10-worker PHP-FPM -> SQLite.
No production access. Downloads a checksummed test binary unless CENTRIFUGO_BIN is set.
"""
import hashlib,http.client,json,os,pathlib,shutil,socket,sqlite3,subprocess,tarfile,tempfile,time,urllib.request
ROOT=pathlib.Path(__file__).resolve().parents[1]
NGINX=os.environ.get('NGINX_BIN') or shutil.which('nginx');FPM=os.environ.get('PHP_FPM_BIN') or shutil.which('php-fpm8.3')
assert NGINX and FPM,'Real Nginx and PHP-FPM required; do not silently skip'
def port():
 with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]
with tempfile.TemporaryDirectory(prefix='broker-proxy-') as folder:
 p=pathlib.Path(folder);binary=os.environ.get('CENTRIFUGO_BIN')
 if not binary:
  archive=p/'broker.tar.gz';urllib.request.urlretrieve('https://github.com/centrifugal/centrifugo/releases/download/v6.9.6/centrifugo_6.9.6_linux_amd64.tar.gz',archive)
  assert hashlib.sha256(archive.read_bytes()).hexdigest()=='af50fec73b5fba0630bcce6685ed096f5841fd428aa55f820108174c44a7b347'
  with tarfile.open(archive) as t:t.extract(next(m for m in t.getmembers() if m.name=='centrifugo'),p,filter='data')
  binary=str(p/'centrifugo')
 version=subprocess.check_output([binary,'version'],text=True).strip();assert version.startswith('Centrifugo v6.9.6 '),version
 # Launcher resolves only this pinned executable through PATH; no copied executable in the repository.
 (p/'bin').mkdir();(p/'bin/centrifugo').symlink_to(pathlib.Path(binary).resolve())
 dbfile=p/'fixture.sqlite';dsn=os.environ.get('BROKER_TEST_DSN','sqlite:'+str(dbfile))
 schema=(ROOT/'tests/fixtures/livekit-admission.sql').read_text()+"CREATE TABLE messages(id INTEGER PRIMARY KEY,channel_id INTEGER,is_deleted INTEGER,content TEXT);INSERT INTO messages VALUES(1,10,0,'synthetic saved message');"
 if dsn.startswith('mysql:'):
  import re
  assert re.fullmatch(r'mysql:host=127\.0\.0\.1;port=[0-9]+;dbname=ecollab_broker_test',dsn),'Explicit disposable loopback DB only'
  setup=p/'setup.php';setup.write_text('<?php $d=new PDO('+repr(dsn)+',"root","");if((int)$d->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchColumn()!==0)throw new RuntimeException("Fixture schema must be empty");$d->exec('+repr(schema)+');')
  subprocess.run([os.environ.get('PHP_BIN','php'),str(setup)],check=True)
 else:
  assert not os.environ.get('TEST_EXTENDED_REVOCATION'),'Actual CLI singleton worker requires MariaDB'
  db=sqlite3.connect(dbfile);db.executescript(schema);db.close()
 metrics=p/'metrics.jsonl';mode=p/'mode';mode.write_text('normal')
 brokerport=port();proxyport=port();httpport=port();fpmport=port()
 prepend=p/'prepend.php';prepend.write_text('''<?php
require_once '''+repr(str(ROOT/'database/config/db.php'))+''';
class ProxyFixturePDO extends PDO {public int $prepared=0;public function prepare(string $q,array $opts=[]):PDOStatement|false{$this->prepared++;return parent::prepare($q,$opts);}}
$fixtureDb=new ProxyFixturePDO('''+repr(dsn)+''',"root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
(new ReflectionProperty(Database::class,'instance'))->setValue(null,$fixtureDb);
if(isset($_SERVER['ECOLLAB_BROKER_PROXY_EVENT'])){
 $started=microtime(true);$event=$_SERVER['ECOLLAB_BROKER_PROXY_EVENT'];
 register_shutdown_function(function()use($fixtureDb,$started,$event){file_put_contents('''+repr(str(metrics))+''',json_encode(['event'=>$event,'queries'=>$fixtureDb->prepared,'milliseconds'=>(microtime(true)-$started)*1000,'status'=>http_response_code()])."\\n",FILE_APPEND|LOCK_EX);});
 if(trim(file_get_contents('''+repr(str(mode))+'''))==='proxy-outage'){http_response_code(503);exit;}
 if(trim(file_get_contents('''+repr(str(mode))+'''))==='disabled'){putenv('CENTRIFUGO_ENABLED=false');$_ENV['CENTRIFUGO_ENABLED']='false';}
 if(trim(file_get_contents('''+repr(str(mode))+'''))==='proxy-timeout'){usleep(1500000);}
}
''')
 router=p/'router.php';router.write_text('''<?php
require_once '''+repr(str(ROOT/'security/middleware/AuthMiddleware.php'))+''';
require_once '''+repr(str(ROOT/'services/ChannelAccessService.php'))+''';
require_once '''+repr(str(ROOT/'services/RealtimeOutbox.php'))+''';
require_once '''+repr(str(ROOT/'services/CentrifugoService.php'))+''';
header('Content-Type: application/json');header('Cache-Control: no-store');
if(($_SERVER['HTTP_X_FIXTURE_KEY']??'')!=='fixture-local-only'){http_response_code(403);exit;}
$route=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$db=Database::getInstance();
if($route==='/seed'){AuthMiddleware::startSession();$_SESSION['user_id']=(int)($_GET['user']??1);$_SESSION['csrf_token']='fixture';session_write_close();echo '{}';exit;}
if($route==='/control'){
 $body=json_decode(file_get_contents('php://input'),true);$action=$body['action']??'';
 $commands=['restore'=>"UPDATE users SET status='active',deleted_at=NULL;DELETE FROM moderation_actions;DELETE FROM channel_members;INSERT INTO channel_members VALUES(10,1);DELETE FROM server_members WHERE user_id=1;INSERT INTO server_members VALUES(1,1,'member')",
 'deactivate'=>"UPDATE users SET status='deactivated' WHERE id=1",'suspend'=>"UPDATE users SET status='suspended' WHERE id=1",'delete'=>"UPDATE users SET deleted_at='2026-01-01' WHERE id=1",
 'server-ban'=>"INSERT INTO moderation_actions VALUES(1,1,'ban',1,NULL)",'server-suspend'=>"INSERT INTO moderation_actions VALUES(1,1,'suspend',1,NULL)",'server-mute'=>"INSERT INTO moderation_actions VALUES(1,1,'mute',1,NULL)",'global-ban'=>"INSERT INTO moderation_actions VALUES(1,NULL,'ban',1,NULL)",
 'remove-member'=>'DELETE FROM server_members WHERE user_id=1','remove-private'=>'DELETE FROM channel_members WHERE user_id=1',
 'db-outage'=>'ALTER TABLE moderation_actions RENAME TO offline_moderation_actions','db-restore'=>'ALTER TABLE offline_moderation_actions RENAME TO moderation_actions'];
 if(isset($commands[$action]))$db->exec($commands[$action]);
 elseif($action==='logout'){AuthMiddleware::destroySession();}
 elseif(in_array($action,['proxy-outage','proxy-restore','disable-delivery','proxy-timeout'],true))file_put_contents('''+repr(str(mode))+''',$action==='disable-delivery'?'disabled':($action==='proxy-restore'?'normal':$action));
 else{http_response_code(400);exit;}echo '{}';exit;
}
if($route==='/stats'){$lines=@file('''+repr(str(metrics))+''',FILE_IGNORE_NEW_LINES)?:[];echo json_encode(array_map(fn($l)=>json_decode($l,true),$lines));exit;}
if($route==='/reconcile'){
 if(!getenv('TEST_EXTENDED_REVOCATION')){http_response_code(404);exit;}
 $args=[getenv('PHP_BIN'),'-d','error_log=/dev/stderr','-d','log_errors=1','-d','auto_prepend_file='.'''+repr(str(prepend))+''','''+repr(str(ROOT/'scripts/realtime-revocation-worker.php'))+''','--once'];
 $childEnv=getenv();if(isset($_GET['fail_api']))$childEnv['CENTRIFUGO_API_URL']='http://127.0.0.1:1/api';
 $proc=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$childEnv);
 fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
 echo json_encode(['exit'=>proc_close($proc),'stderr'=>$stderr]);exit;
}
if($route==='/messages'){$user=AuthMiddleware::requireAuth(true);session_write_close();try{(new ChannelAccessService($db))->read(10,(int)$user['id']);echo json_encode(['messages'=>$db->query('SELECT id,content FROM messages')->fetchAll(PDO::FETCH_ASSOC)]);}catch(RuntimeException){http_response_code(403);echo '{}';}exit;}
if($route==='/deliver'){$users=RealtimeOutbox::recipients($db,['kind'=>'channel','target_id'=>10,'message_id'=>1]);if($users)(new CentrifugoService())->broadcast($users,['type'=>'chat_changed','kind'=>'channel','target_id'=>10,'message_id'=>1,'event_id'=>(int)($_GET['event']??1)]);echo json_encode(['recipients'=>count($users)]);exit;}
http_response_code(404);
''')
 (p/'fpm.conf').write_text(f'''[global]
pid = {p}/fpm.pid
error_log = {p}/fpm.log
daemonize = no
[fixture]
user = root
group = root
listen = 127.0.0.1:{fpmport}
pm = static
pm.max_children = 10
clear_env = no
php_admin_value[session.save_path] = {p}
php_admin_value[auto_prepend_file] = {prepend}
php_admin_flag[log_errors] = off
php_admin_flag[display_errors] = off
''')
 (p/'fastcgi_params').write_text('''fastcgi_param REQUEST_METHOD $request_method;
fastcgi_param QUERY_STRING $query_string;
fastcgi_param REQUEST_URI $request_uri;
fastcgi_param CONTENT_TYPE $content_type;
fastcgi_param CONTENT_LENGTH $content_length;
fastcgi_param SERVER_PROTOCOL $server_protocol;
fastcgi_param REMOTE_ADDR $remote_addr;
fastcgi_param REDIRECT_STATUS 200;
fastcgi_param HTTP_COOKIE $http_cookie;
fastcgi_param HTTP_X_FIXTURE_KEY $http_x_fixture_key;
''')
 callback=(ROOT/'deploy/centrifugo/nginx-proxy.conf.example').read_text().replace('127.0.0.1:8002',f'127.0.0.1:{proxyport}').replace('/home/ecollabadmin/ecollab-inspect',str(ROOT)).replace('unix:/run/php/php8.3-fpm.sock',f'127.0.0.1:{fpmport}').replace('/var/log/nginx/ecollab-broker-auth.log',str(p/'auth.log'))
 (p/'nginx.conf').write_text(f'''daemon off; master_process off; user root root; pid {p}/nginx.pid; error_log {p}/nginx.log;
events {{worker_connections 256;}}
http {{access_log off;client_body_temp_path {p}/body;fastcgi_temp_path {p}/fcgi;proxy_temp_path {p}/proxy;uwsgi_temp_path {p}/uwsgi;scgi_temp_path {p}/scgi;
{callback}
server {{listen 127.0.0.1:{httpport};
location = /direct-proxy {{include fastcgi_params;fastcgi_param SCRIPT_FILENAME {ROOT}/API/internal/centrifugo-proxy.php;fastcgi_param HTTP_ECOLLAB_INTERNAL_BROKER_PROXY $http_ecollab_internal_broker_proxy;fastcgi_pass 127.0.0.1:{fpmport};}}
location / {{include fastcgi_params;fastcgi_param SCRIPT_FILENAME {router};fastcgi_pass 127.0.0.1:{fpmport};}}}}
}}
''')
 config=json.loads((ROOT/'deploy/centrifugo/config.json').read_text());config['http_server']['port']=brokerport
 for event in ['connect','refresh']:config['client']['proxy'][event]['endpoint']=f'http://127.0.0.1:{proxyport}/{event}'
 config['client']['ping_interval']='1s';config['client']['pong_timeout']='500ms'
 conf=p/'broker.json';conf.write_text(json.dumps(config))
 env=os.environ|{'PATH':str(p/'bin')+':'+os.environ['PATH'],'CENTRIFUGO_ENABLED':'true','CENTRIFUGO_PROXY_SECRET':'c'*64,'CENTRIFUGO_HTTP_API_KEY':'b'*64,'CENTRIFUGO_API_KEY':'b'*64,'CENTRIFUGO_API_URL':f'http://127.0.0.1:{brokerport}/api','CENTRIFUGO_ALLOWED_ORIGIN':'https://ecollab.tech','CENTRIFUGO_REFRESH_SECONDS':'5','LIVEKIT_URL':'ws://127.0.0.1:1','LIVEKIT_API_KEY':'fixture-key','LIVEKIT_API_SECRET':'s'*64,
 # Poison inherited auth/insecure settings; the production launcher must strip them.
 'CENTRIFUGO_CLIENT_TOKEN_HMAC_SECRET_KEY':'a'*64,'CENTRIFUGO_CLIENT_INSECURE':'true',
 'TEST_BROKER_API':f'http://127.0.0.1:{brokerport}/api','TEST_PROXY_BASE':f'http://127.0.0.1:{proxyport}','TEST_FIXTURE_BASE':f'http://127.0.0.1:{httpport}','TEST_COOKIE_FILE':str(p/'cookie')}
 processes=[];logs=[]
 def start(args,name,overrides=None):
  log=open(p/(name+'.log'),'w');logs.append(log);process=subprocess.Popen(args,env=env|(overrides or {}),stdout=log,stderr=log);processes.append(process);return process
 def ready(number):
  for _ in range(200):
   if any(c.poll() is not None for c in processes):
    for name in ['broker','legacy','fpm','nginx']:
     f=p/(name+'.log')
     if f.exists():
      tail=f.read_text(errors='replace')[-1200:]
      for secret in ['a'*64,'b'*64,'c'*64]:tail=tail.replace(secret,'[redacted]')
      print(name,tail)
    raise RuntimeError('Fixture process exited')
   try:
    with socket.create_connection(('127.0.0.1',number),.1):return
   except OSError:time.sleep(.02)
  raise RuntimeError('Fixture did not start')
 def node(extra=None):
  r=subprocess.run(['node','tests/centrifugo-broker-regression.cjs'],cwd=ROOT,env=env|(extra or {}),capture_output=True,text=True,timeout=350)
  print(r.stdout,end='');assert r.returncode==0,r.stderr
 try:
  fpm=start([FPM,'-R','-F','-y',str(p/'fpm.conf')],'fpm');ready(fpmport)
  syntax=subprocess.run([NGINX,'-t','-p',folder+'/','-c',str(p/'nginx.conf')],capture_output=True,text=True);assert syntax.returncode==0,syntax.stderr
  nginx=start([NGINX,'-p',folder+'/','-c',str(p/'nginx.conf')],'nginx');ready(httpport)
  # First reproduce the old configuration with an actual account revocation in the fixture DB.
  legacy=json.loads(json.dumps(config));legacy['client'].pop('proxy');legacy['client'].pop('ping_interval');legacy['client'].pop('pong_timeout');legacy['client']['insecure']=False
  legacyfile=p/'legacy.json';legacyfile.write_text(json.dumps(legacy))
  legacybroker=start([binary,'--config='+str(legacyfile)],'legacy',{'CENTRIFUGO_CLIENT_INSECURE':'false'});ready(brokerport)
  node({'TEST_LEGACY_REPLAY':'1'});legacybroker.terminate();legacybroker.wait(timeout=5);processes.remove(legacybroker)
  broker=start(['/bin/sh',str(ROOT/'deploy/centrifugo/proxy-entrypoint.sh'),str(conf)],'broker');ready(brokerport)
  node()
  # API/transaction fixture coverage can share this actual broker in disposable MySQL CI.
  if os.environ.get('TEST_DELIVERY_DSN'):
   for test in ['centrifugo-regression','centrifugo-database-regression']:
    subprocess.run([os.environ.get('PHP_BIN','php'),'tests/'+test+'.php'],cwd=ROOT,env=env,check=True)
  # Actual callback transport outage: stop Nginx, broker remains alive and must deny new admission.
  nginx.terminate();nginx.wait(timeout=5);processes.remove(nginx)
  node({'TEST_TRANSPORT_OUTAGE':'1'})
  authlog=(p/'auth.log').read_text()
  assert all(secret not in authlog for secret in ['a'*64,'b'*64,'c'*64,(p/'cookie').read_text()])
  assert ' allow ' in authlog and ' deny ' in authlog and ' unavailable ' in authlog
  print('PASS pinned '+version+'; production launcher, private Nginx callbacks, 10-worker PHP-FPM; synthetic fixture only')
 finally:
  for process in reversed(processes):
   if process.poll() is None:process.terminate();process.wait(timeout=5)
  for log in logs:log.close()
