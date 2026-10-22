"""Real disposable Nginx -> PHP-FPM -> admission endpoint; fake media upstream only.
Requires nginx, php-fpm8.3 and PHP pdo_sqlite. Never targets deployed infrastructure.
"""
import base64, hashlib, hmac, http.client, http.server, json, os, pathlib, shutil, socket, sqlite3, subprocess, tempfile, threading, time
ROOT=pathlib.Path(__file__).resolve().parents[1]
NGINX=os.environ.get('NGINX_BIN') or shutil.which('nginx')
FPM=os.environ.get('PHP_FPM_BIN') or shutil.which('php-fpm8.3')
assert NGINX and FPM, 'Nginx and PHP-FPM required; do not silently skip admission transport test'
def port():
 with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]
seen=[]
class Upstream(http.server.BaseHTTPRequestHandler):
 def do_GET(self):
  seen.append(self.path.split('?')[0])
  if self.headers.get('Upgrade','').lower()=='websocket':
   self.send_response(101);self.send_header('Upgrade','websocket');self.send_header('Connection','Upgrade')
   self.send_header('Sec-WebSocket-Accept',base64.b64encode(hashlib.sha1((self.headers['Sec-WebSocket-Key']+'258EAFA5-E914-47DA-95CA-C5AB0DC85B11').encode()).digest()).decode());self.end_headers();return
  self.send_response(200);self.end_headers();self.wfile.write(b'media-upstream')
 def log_message(self,*args):pass
up=http.server.ThreadingHTTPServer(('127.0.0.1',0),Upstream);threading.Thread(target=up.serve_forever,daemon=True).start()
with tempfile.TemporaryDirectory(prefix='livekit-admission-') as folder:
 p=pathlib.Path(folder);dbfile=p/'fixture.sqlite';db=sqlite3.connect(dbfile);db.executescript((ROOT/'tests/fixtures/livekit-admission.sql').read_text());db.close()
 prepend=p/'fixture.php';prepend.write_text('<?php require_once '+repr(str(ROOT/'database/config/db.php'))+'; (new ReflectionProperty(Database::class,"instance"))->setValue(null,new PDO("sqlite:'+str(dbfile)+'",null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]));')
 fpmport=port();httpport=port()
 (p/'fpm.conf').write_text(f'''[global]
pid = {p}/fpm.pid
error_log = {p}/fpm.log
daemonize = no
[fixture]
user = root
group = root
listen = 127.0.0.1:{fpmport}
pm = static
pm.max_children = 1
clear_env = no
php_admin_value[auto_prepend_file] = {prepend}
php_admin_flag[log_errors] = off
php_admin_flag[display_errors] = off
''')
 # The tested template forwards its custom FastCGI parameters unchanged.
 (p/'fastcgi_params').write_text('fastcgi_param REQUEST_METHOD $request_method;\nfastcgi_param SCRIPT_NAME /API/internal/livekit-admission.php;\nfastcgi_param SERVER_PROTOCOL $server_protocol;\nfastcgi_param REDIRECT_STATUS 200;\n')
 template=(ROOT/'deploy/phase2/livekit-admission.conf.example').read_text().replace('/var/www/ecollab',str(ROOT)).replace('unix:/run/php/php8.3-fpm.sock',f'127.0.0.1:{fpmport}').replace('127.0.0.1:7880',f'127.0.0.1:{up.server_port}')
 (p/'nginx.conf').write_text(f'''daemon off; master_process off; user root root;
pid {p}/nginx.pid; error_log {p}/nginx.log;
events {{ worker_connections 64; }}
http {{ access_log off; client_body_temp_path {p}/body; fastcgi_temp_path {p}/fcgi; proxy_temp_path {p}/proxy; uwsgi_temp_path {p}/uwsgi; scgi_temp_path {p}/scgi;
server {{ listen 127.0.0.1:{httpport}; {template}
location = /direct-php {{ include fastcgi_params; fastcgi_param SCRIPT_FILENAME {ROOT}/API/internal/livekit-admission.php; fastcgi_param HTTP_ECOLLAB_INTERNAL_MEDIA_ADMISSION $http_ecollab_internal_media_admission; fastcgi_pass 127.0.0.1:{fpmport}; }}
}} }}
''')
 env=os.environ|{'LIVEKIT_API_KEY':'fixture-key','LIVEKIT_API_SECRET':'s'*64}
 fpm=subprocess.Popen([FPM,'-R','-F','-y',str(p/'fpm.conf')],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 nginx=None
 def request(path,authorization=None,method='GET',headers=None):
  c=http.client.HTTPConnection('127.0.0.1',httpport,timeout=4);h=headers or {}
  if authorization is not None:h['Authorization']=authorization
  c.request(method,path,headers=h);r=c.getresponse();out=(r.status,r.read());c.close();return out
 def sql(value):
  d=sqlite3.connect(dbfile);d.executescript(value);d.close()
 def jwt(change=None):
  b=lambda v:base64.urlsafe_b64encode(json.dumps(v,separators=(',',':')).encode()).rstrip(b'=')
  c={'iss':'fixture-key','sub':'user-1','exp':int(time.time())+600,'nbf':int(time.time())-1,'video':{'roomJoin':True,'room':'ecollab-channel-10'}};c.update(change or {})
  data=b({'alg':'HS256','typ':'JWT'})+b'.'+b(c);return (data+b'.'+base64.urlsafe_b64encode(hmac.new(b's'*64,data,hashlib.sha256).digest()).rstrip(b'=')).decode()
 try:
  syntax=subprocess.run([NGINX,'-t','-p',folder+'/','-c',str(p/'nginx.conf')],capture_output=True,text=True)
  assert syntax.returncode==0,syntax.stderr
  nginx=subprocess.Popen([NGINX,'-p',folder+'/','-c',str(p/'nginx.conf')],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
  for _ in range(150):
   try:
    if request('/rtc')[0]==403:break
   except OSError:pass
   time.sleep(.02)
  else:raise AssertionError('Admission ingress did not become ready: '+(p/'fpm.log').read_text()+(p/'nginx.log').read_text())
  token=jwt();routes=['/rtc','/rtc/validate','/rtc/v1','/rtc/v1/validate']
  for path in routes:
   assert request(path+'?access_token='+token)==(200,b'media-upstream')
   assert request(path,'Bearer '+token)==(200,b'media-upstream')
  assert len(seen)==8
  upgrade={'Upgrade':'websocket','Connection':'Upgrade','Sec-WebSocket-Version':'13','Sec-WebSocket-Key':'dGhlIHNhbXBsZSBub25jZQ=='}
  assert request('/rtc?access_token='+token,headers=upgrade)[0]==101
  assert request('/rtc?access_token=wrong','Bearer '+token)[0]==200
  assert request('/rtc?access_token='+token,'Bearer wrong')[0]==403
  assert request('/rtc?access_token='+token+'&access_token=wrong')[0]==403
  assert request('/rtc?access_token='+jwt({'sub':'user-2'}))[0]==403
  assert request('/_ecollab_media_admission')[0]==404
  assert request('/API/internal/livekit-admission.php',headers={'Ecollab-Internal-Media-Admission':'1'})[0]==404
  assert request('/direct-php',headers={'Ecollab-Internal-Media-Admission':'1'})[0]==404
  assert request('/rtc/unknown?access_token='+token)[0]==404
  assert request('/twirp/livekit.RoomService/%252e%252e/%252e%252e/rtc?access_token='+token)[0]==404
  before=len(seen)
  sql('DELETE FROM channel_members WHERE user_id=1')
  for path in routes:assert request(path+'?access_token='+token+'&reconnect=1')[0]==403
  assert request('/rtc?access_token='+token+'&reconnect=1',headers=upgrade)[0]==403
  assert len(seen)==before,'Revoked requests reached media upstream'
  sql('INSERT INTO channel_members VALUES(10,1)')
  assert request('/rtc?access_token='+token)[0]==200
  sql("UPDATE users SET status='deactivated' WHERE id=1")
  assert request('/rtc','Bearer '+token)[0]==403
  sql("UPDATE users SET status='active';DROP TABLE moderation_actions")
  before=len(seen);assert request('/rtc?access_token='+token)[0]==500;assert len(seen)==before
  # auth_request maps backend 503/unexpected transport statuses to fail-closed 500.
  fpm.terminate();fpm.wait(timeout=5)
  assert request('/rtc?access_token='+token)[0]==500
  assert token not in (p/'nginx.log').read_text(), 'Credential-bearing request leaked into error log'
  print('PASS real Nginx/PHP-FPM: four routes, synthetic WebSocket upgrade, header/query precedence, forged token, old-token replay after revocation, internal/header-spoof denial, DB/FPM outage fail closed; no real LiveKit/WebRTC capacity claim')
 except Exception:
  # Logs may contain synthetic credentials. Keep them local; report only process states.
  print('Ingress fixture process states:',fpm.poll(),None if nginx is None else nginx.poll());raise
 finally:
  for process in [nginx,fpm]:
   if process and process.poll() is None:process.terminate();process.wait(timeout=5)
  up.shutdown();up.server_close()
