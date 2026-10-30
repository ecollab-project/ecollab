"""Owned native FPM authentication journeys. No application overlays or external mail."""
import concurrent.futures,hashlib,http.client,http.cookies,http.server,importlib.util,json,mimetypes,os,pathlib,re,shutil,socket,subprocess,tempfile,threading,time,urllib.parse
ROOT=pathlib.Path(__file__).resolve().parents[1];BASE='e4999821b16f8d8085a8cde6c5b08de2fc824ae5'
spec=importlib.util.spec_from_file_location('owned_fcgi',ROOT/'tests/classroom-performance.py');native=importlib.util.module_from_spec(spec);spec.loader.exec_module(native)
COHORTS={'utc':('UTC','+00:00'),'mismatch':('UTC','+06:00'),'manila':('Asia/Manila','+08:00')}
def check(ok,label):
 if not ok:raise AssertionError(label)
def digest(x):return hashlib.sha256(x).hexdigest()
def git(*args):return subprocess.check_output(['git',*args],cwd=ROOT,text=True).strip()
class Client:
 def __init__(self,owner,ip=None,cookie=''):
  self.o=owner;self.ip=ip or owner.ip();self.cookie=cookie;self.token='';self.uid=0
 def sid(self):
  c=http.cookies.SimpleCookie();c.load(self.cookie);return c['PHPSESSID'].value if 'PHPSESSID'in c else ''
 def state(self):return self.o.control('snapshot',session_id=self.sid(),user_id=self.uid)
 def req(self,path,method='GET',body=None,csrf='valid',capture=True):
  before=self.state() if capture else None;headers={'Content-Type':'application/json'}
  if self.cookie:headers['Cookie']=self.cookie
  if csrf=='valid' and self.token:headers['X-CSRF-Token']=self.token
  elif csrf=='invalid':headers['X-CSRF-Token']='invalid-owned-token'
  payload=json.dumps(body).encode()if body is not None else None
  c=http.client.HTTPConnection('127.0.0.1',self.o.port,timeout=20,source_address=(self.ip,0));start=time.monotonic()
  try:
   c.request(method,path,payload,headers);r=c.getresponse();data=r.read();status=r.status;rh=r.getheaders()
  finally:c.close()
  old=self.sid();cookies=[]
  if old:self.o.secrets.add(old)
  for k,v in rh:
   if k.lower()!='set-cookie':continue
   sc=http.cookies.SimpleCookie();sc.load(v)
   for name,a in sc.items():
    cookies.append({'name':name,'httponly':bool(a['httponly']),'secure':bool(a['secure']),'samesite':a['samesite'],'path':a['path'],'expires_present':bool(a['expires']),'value_present':bool(a.value)})
    if name=='PHPSESSID':
     self.cookie=name+'='+a.value if a.value else ''
     if a.value:self.o.secrets.add(a.value)
  try:j=json.loads(data)
  except (ValueError,UnicodeDecodeError):j=None
  if path=='/API/auth/csrf-token.php' and j and j.get('token'):self.token=j['token'];self.o.secrets.add(self.token)
  if path=='/API/auth/signup.php' and j and j.get('success'):self.uid=int(j['user_id'])
  after=self.state() if capture else None
  self.o.records.append({'case':self.o.label,'method':method,'path':path,'peer_label':self.o.handle(self.ip,'peer'),'request':self.o.redact(body),'csrf':csrf,'session_before':self.o.handle(old,'session'),'session_after':self.o.handle(self.sid(),'session'),'status':status,'response':self.o.redact(j) if j is not None else {'html_bytes':len(data)},'response_sha256':digest(data),'set_cookie_attributes':cookies,'state_before':before,'state_after':after,'start_monotonic':start,'end_monotonic':time.monotonic()})
  if j:check(not j.get('otp_debug'),'APP_DEBUG leaked OTP')
  return {'status':status,'json':j,'bytes':data,'cookies':cookies,'before':before,'after':after}
 def csrf(self):
  r=self.req('/API/auth/csrf-token.php');check(r['status']==200 and bool(self.token),'real CSRF endpoint');return self
class Owned:
 def __init__(self,p,zone,dbzone):
  self.p=p;self.zone=zone;self.dbzone=dbzone;self.records=[];self.controls=[];self.cases=[];self.codes={};self.secrets={'owned-fixture-password-42','owned-nonproduction-mail-key'};self.deliveries=0;self.labels={};self.labels_lock=threading.Lock();self.ipn=1;self.label='setup';self.counter=0
  self.php=os.environ.get('PHP_BIN','php8.3');self.port=native.freeport();self.dbport=native.freeport();self.fcgiport=native.freeport();self.mailport=native.freeport();self.dsn=f'mysql:host=127.0.0.1;port={self.dbport};dbname=classroom_perf_test'
  self.env=os.environ|{'APP_ENV':'testing','APP_DEBUG':'false','APP_URL':f'http://127.0.0.1:{self.port}','APP_KEY':'owned-phase3y-nonproduction-key','CLASSROOM_DSN':self.dsn,'PHASE3Y_PRIVATE':str(p),'BREVO_API_URL':f'http://127.0.0.1:{self.mailport}','BREVO_API_KEY':'owned-nonproduction-mail-key','BREVO_SENDER_EMAIL':'fixture@example.test','TRUSTED_PROXIES':'','NO_PROXY':'127.0.0.1,localhost','no_proxy':'127.0.0.1,localhost','SESSION_SECURE':'false','SESSION_LIFETIME':'3600','SESSION_SAMESITE':'Lax','RATE_LIMIT_LOGIN':'10','RATE_LIMIT_SIGNUP':'5','OTP_EXPIRY':'600','OTP_LENGTH':'6','PEER_ML_ENABLED':'false','CENTRIFUGO_ENABLED':'false','TZ':'UTC'}
  self.daemon=self.fpm=self.gateway=self.mail=None
 def ip(self):self.ipn+=1;return '127.0.0.'+str(self.ipn)
 def handle(self,value,kind):
  if not value:return None
  with self.labels_lock:
   key=(kind,value)
   if key not in self.labels:self.labels[key]=kind+'-'+str(sum(k[0]==kind for k in self.labels)+1)
   return self.labels[key]
 def redact(self,x,key=''):
  if key in ['otp_required','email_verified']and isinstance(x,(bool,int)):return x
  if any(k in key.lower() for k in ['password','otp','token','cookie','identifier','email','full_name','username','remember_token']):return None if x is None else '<redacted>'
  if isinstance(x,dict):return {k:self.redact(v,k)for k,v in x.items()}
  if isinstance(x,list):return [self.redact(v,key)for v in x]
  return x
 def scrub_text(self,text):
  for value in sorted(self.secrets,key=len,reverse=True):
   if value:text=text.replace(value,'<redacted>')
  return re.sub(r'[A-Za-z0-9_.+-]+@[A-Za-z0-9.-]+','<redacted-email>',text)
 def control(self,action,**kwargs):
  cmd=[self.php,'-d','date.timezone='+self.zone,'-d','session.save_path='+str(self.p/'sessions'),'-d','session.gc_probability=0','-d','session.gc_maxlifetime=3600',str(ROOT/'tests/phase3ac-control.php')]
  r=subprocess.run(cmd,input=json.dumps({'action':action}|kwargs),env=self.env,capture_output=True,text=True,timeout=30)
  err=r.stderr
  if r.returncode:err+=r.stdout
  if r.returncode and (self.p/'php-errors.log').exists():err+=(self.p/'php-errors.log').read_text()
  for k,v in kwargs.items():
   if k in ['session_id','email','password','otp']and isinstance(v,str)and v:err=err.replace(v,'<redacted>')
  err=self.scrub_text(err)
  self.controls.append({'action':action,'command':cmd,'stdin':'<private fixture arguments omitted>','exit_code':r.returncode,'stderr':err})
  check(r.returncode==0,'private control failed: '+action)
  return json.loads(r.stdout)
 def data(self):
  self.counter+=1
  return {'full_name':'Owned Synthetic Actor','email':f'phase3y{self.p.name.replace("-","")}_{self.counter}@example.test','password':'owned-fixture-password-42','course':'BS Computer Science','year_level':2,'terms_agreed':True,'study_style':'Solo','primary_goal':'Pass exams','interests':[],'hobbies':[]}
 def code(self,data,action='verify_email'):return self.codes[(data['email'],action)]
 def reject(self,r,status):
  check(r['status']==status,'rejection HTTP status');check(r['json'] is not None and r['json'].get('success') is False,'rejection JSON contract')
  check(r['before']['business']==r['after']['business'],'rejection changed business rows');check(not r['after']['session']['authenticated'],'rejection authenticated a session');check(r['before']['otp_checksum']==r['after']['otp_checksum'],'rejection changed persisted OTP rows')
 def verify(self,c,d):
  r=c.req('/API/auth/send-signup-email-otp.php','POST',{'email':d['email'],'full_name':d['full_name']});check(r['status']==200 and r['json']['success'],'send real signup OTP')
  r=c.req('/API/auth/verify-signup-email-otp.php','POST',{'email':d['email'],'otp':self.code(d)});check(r['status']==200 and r['json']['verified'],'verify real signup OTP')
 def registered(self):
  c=Client(self).csrf();d=self.data();self.verify(c,d);r=c.req('/API/auth/signup.php','POST',d);check(r['status']==200 and r['json']['success'],'register verified actor');return c,d
 def case(self,name,fn):
  self.label=name;begin=len(self.records)
  try:fn();self.cases.append({'name':name,'status':'PASS','requests':len(self.records)-begin})
  except Exception as e:self.cases.append({'name':name,'status':'FAIL','error':str(e)[:150],'requests':len(self.records)-begin})
 def start(self):
  (self.p/'sessions').mkdir(exist_ok=True);log=open(self.p/'database.log','w');args=[os.environ.get('MARIADB_INSTALL_DB_BIN','mariadb-install-db'),'--no-defaults','--datadir='+str(self.p/'data'),'--auth-root-authentication-method=normal','--skip-test-db']
  if os.environ.get('MARIADB_BASEDIR'):args+=['--basedir='+os.environ['MARIADB_BASEDIR']]
  subprocess.run(args,env=self.env,stdout=log,stderr=log,check=True,timeout=60)
  import pwd
  self.daemon=subprocess.Popen([os.environ.get('MARIADBD_BIN','mariadbd'),'--no-defaults','--user='+pwd.getpwuid(os.geteuid()).pw_name,'--datadir='+str(self.p/'data'),'--bind-address=127.0.0.1','--port='+str(self.dbport),'--socket=','--pid-file='+str(self.p/'db.pid'),'--default-time-zone='+self.dbzone],env=self.env,stdout=log,stderr=log)
  for _ in range(100):
   r=subprocess.run([self.php,'-r',f'$d=new PDO("mysql:host=127.0.0.1;port={self.dbport}","root","");$d->exec("CREATE DATABASE IF NOT EXISTS classroom_perf_test");'],env=self.env,capture_output=True)
   if r.returncode==0:break
   check(self.daemon.poll() is None,'owned MariaDB exited');time.sleep(.1)
  else:raise RuntimeError('owned DB startup')
  if getattr(self,'restore_dump',None):
   cmd=[os.environ.get('MARIADB_BIN','mariadb'),'--no-defaults','--protocol=TCP','--host=127.0.0.1','--port='+str(self.dbport),'--user=root']
   with open(self.restore_dump,'rb')as dump:r=subprocess.run(cmd,stdin=dump,env=self.env,capture_output=True,timeout=120)
   self.restore_result={'command':cmd,'exit_code':r.returncode,'dump_sha256':digest(pathlib.Path(self.restore_dump).read_bytes()),'stderr':self.scrub_text(r.stderr.decode())};check(r.returncode==0,'owned backup restore failed')
  else:
   cmd=[self.php,'-d','auto_prepend_file='+str(ROOT/'tests/phase3w-migration-bootstrap.php'),str(ROOT/'database/migrate.php')];r=subprocess.run(cmd,env=self.env,capture_output=True,text=True,timeout=120);self.migration={'command':cmd,'exit_code':r.returncode,'stdout':r.stdout,'stderr':r.stderr};check(r.returncode==0,'actual migration runner failed')
  owner=self
  class Mail(http.server.BaseHTTPRequestHandler):
   def log_message(self,*args):pass
   def do_POST(self):
    check(self.path=='/v3/smtp/email','only local delivery route');d=json.loads(self.rfile.read(int(self.headers['Content-Length'])));digits=re.findall(r"font-family:monospace'>(\d)</span>",d['htmlContent']);check(len(digits)==6,'actual generated code in email payload');check(self.headers.get('api-key')=='owned-nonproduction-mail-key','owned delivery credential')
    action='2fa' if 'Login' in d['subject'] else 'verify_email';owner.codes[(d['to'][0]['email'],action)]=''.join(digits);owner.secrets.add(''.join(digits));owner.deliveries+=1;self.send_response(201);self.send_header('Content-Type','application/json');self.end_headers();self.wfile.write(b'{"messageId":"owned-local-delivery"}')
   def do_GET(self):
    q=urllib.parse.parse_qs(urllib.parse.urlparse(self.path).query);email=q.get('email',[''])[0];check(email.startswith('phase3y')and email.endswith('@example.test'),'owned browser delivery handle');code=owner.codes.get((email,'verify_email'));self.send_response(200 if code else 404);self.end_headers();self.wfile.write(json.dumps({'code':code}).encode())
  self.mail=http.server.ThreadingHTTPServer(('127.0.0.1',self.mailport),Mail);threading.Thread(target=self.mail.serve_forever,daemon=True).start()
  cfg=f'''[global]\nerror_log = {self.p}/fpm.log\ndaemonize = no\n[fixture]\nlisten = 127.0.0.1:{self.fcgiport}\nuser = root\ngroup = root\npm = static\npm.max_children = 4\nclear_env = no\ncatch_workers_output = yes\nphp_admin_value[session.save_path] = {self.p}/sessions\nphp_admin_value[session.gc_probability] = 0\nphp_admin_value[session.gc_maxlifetime] = 3600\nphp_admin_value[date.timezone] = {self.zone}\nphp_admin_value[auto_prepend_file] = {ROOT}/tests/phase3y-bootstrap.php\nphp_admin_value[display_errors] = 0\n''';(self.p/'fpm.conf').write_text(cfg);self.fpm_config=cfg
  self.fpm=subprocess.Popen([os.environ.get('PHP_FPM_BIN','php-fpm8.3'),'-F','-R','-y',str(self.p/'fpm.conf')],env=self.env,stdout=open(self.p/'fpm-stdio.log','w'),stderr=subprocess.STDOUT)
  for _ in range(100):
   check(self.fpm.poll() is None,'FPM exited')
   try:
    with socket.create_connection(('127.0.0.1',self.fcgiport),timeout=.1):break
   except OSError:time.sleep(.05)
  class Gateway(http.server.BaseHTTPRequestHandler):
   def log_message(self,*args):pass
   def do_GET(self):self.forward()
   def do_POST(self):self.forward()
   def do_PUT(self):self.forward()
   def do_HEAD(self):self.forward()
   def do_PATCH(self):self.forward()
   def do_DELETE(self):self.forward()
   def do_OPTIONS(self):self.forward()
   def forward(self):
    route=urllib.parse.urlparse(self.path).path;file=(ROOT/route.lstrip('/')).resolve()
    if not file.is_relative_to(ROOT)or not file.is_file()or not(route.startswith('/API/')or route.startswith('/assets/')or route.startswith('/uploads/')or route in ['/modules/auth/signup.php','/modules/auth/login.php','/modules/onboarding/server-discovery.php','/modules/chat/chat.php','/modules/student/dashboard.php','/modules/facilitator/dashboard.php']):self.send_error(404);return
    if route.startswith('/uploads/')and file.suffix.lower()in ['.php','.phtml','.phar','.js','.html','.htm','.svg','.xml','.sh','.py']:
     self.send_error(403);return
    if not file.suffix=='.php':
     self.send_response(200);self.send_header('Content-Type',mimetypes.guess_type(str(file))[0]or'application/octet-stream');self.send_header('X-Content-Type-Options','nosniff');self.end_headers();
     if self.command!='HEAD':self.wfile.write(file.read_bytes())
     return
    body=self.rfile.read(int(self.headers.get('Content-Length','0')));params={'SCRIPT_FILENAME':str(file),'SCRIPT_NAME':route,'REQUEST_URI':self.path,'QUERY_STRING':urllib.parse.urlparse(self.path).query,'REQUEST_METHOD':self.command,'SERVER_PROTOCOL':'HTTP/1.1','SERVER_NAME':'127.0.0.1','SERVER_PORT':owner.port,'HTTP_HOST':f'127.0.0.1:{owner.port}','REMOTE_ADDR':self.client_address[0],'DOCUMENT_ROOT':str(ROOT),'CONTENT_TYPE':self.headers.get('Content-Type',''),'CONTENT_LENGTH':len(body),'HTTP_COOKIE':self.headers.get('Cookie',''),'HTTP_X_CSRF_TOKEN':self.headers.get('X-CSRF-Token','')}
    for key,value in self.headers.items():
     if key.lower()not in ['content-type','content-length','cookie','host','x-csrf-token']:params['HTTP_'+key.upper().replace('-','_')]=value
    raw,err=native.fcgi(owner.fcgiport,params,body);h,_,data=raw.partition(b'\r\n\r\n');headers=[x.decode().split(':',1)for x in h.split(b'\r\n')if b':'in x];status=next((int(v.strip().split()[0])for k,v in headers if k.lower()=='status'),200);self.send_response(status)
    for k,v in headers:
     if k.lower()!='status':self.send_header(k,v.strip())
    self.end_headers();
    if self.command!='HEAD':self.wfile.write(data)
  self.gateway=http.server.ThreadingHTTPServer(('127.0.0.1',self.port),Gateway);threading.Thread(target=self.gateway.serve_forever,daemon=True).start();self.inventory=self.control('inventory')
 def stop(self):
  for s in [self.gateway,self.mail]:
   if s:s.shutdown();s.server_close()
  for p in [self.fpm,self.daemon]:
   if p:p.terminate();p.wait(timeout=20)
 def run(self,full=True):
  def journey():
   c=Client(self).csrf();old=c.cookie;d=self.data();self.verify(c,d);r=c.req('/API/auth/signup.php','POST',d);check(r['status']==200 and r['json']['success'],'signup success');check(old!=c.cookie,'registration session ID rotation');check(any(x['httponly']and x['samesite']=='Lax'for x in r['cookies']),'actual cookie attributes')
   s=c.req('/API/auth/validate-session.php')['json'];check(s['authenticated']and s['role']=='student','authenticated role');check(c.req('/API/profile/settings.php')['status']==200,'protected settings access');oldclient=Client(self,cookie=old);check(oldclient.req('/API/profile/settings.php')['status']==401,'old anonymous session rejected')
   old=c.cookie;check(c.req('/API/auth/logout.php','POST',{})['json']['success'],'real logout');check(Client(self,cookie=old).req('/API/profile/settings.php')['status']==401,'logged-out session rejected');c.csrf();old=c.cookie;r=c.req('/API/auth/login.php','POST',{'identifier':d['email'],'password':d['password']});check(r['status']==200 and r['json']['success'],'real password login');check(old!=c.cookie,'login rotation');check(c.req('/API/profile/settings.php')['status']==200,'protected access after login')
   self.control('expire_storage',session_id=c.sid());check(c.req('/API/profile/settings.php')['status']==401,'garbage-collected expired session rejected')
  self.case('complete_http_journey_rotation_logout_storage_expiry',journey)
  def otp_control(kind):
   c,d=self.registered();c.req('/API/auth/logout.php','POST',{});c.csrf();r=self.control('optional_login',session_id=c.sid(),email=d['email'],password=d['password']);check(r['success']and r['otp_required'],'real optional-login intent');uid=c.uid;before=c.state();check(before['session']['login_intent_valid'],'valid pending intent')
   if kind=='database_expired':
    check(any(x['valid_by_database']for x in before['otp']),'DB code valid before isolated expiry');self.control('expire_db_otp',user_id=uid)
   elif kind=='intent_expired':
    check(any(x['valid_by_database']for x in before['otp']),'otherwise-valid DB code');self.control('expire_login_intent',session_id=c.sid())
   pre=c.state();code=self.code(d,'2fa');r=c.req('/API/auth/verify-otp.php','POST',{'user_id':uid,'otp':code})
   if kind in ['database_expired','intent_expired']:
    self.reject(r,200);check(not c.state()['session']['authenticated'],'expiry must not authenticate')
    if kind=='database_expired':check(pre['session']['login_intent_valid']and all(not x['valid_by_database']for x in pre['otp']),'independent DB expiry, session intent valid')
    else:check(not pre['session']['login_intent_valid']and any(x['valid_by_database']for x in pre['otp']),'independent intent expiry, DB code valid')
   else:
    observed=bool(r['json'].get('success'));expected=True;check(observed==expected,'predefined timezone fresh-code control')
    self.timezone={'php_zone':self.zone,'database_zone':self.dbzone,'before':pre,'http_status':r['status'],'fresh_code_accepted':observed,'observed_result':'PASS'if observed else'FAIL: timezone mismatch rejects fresh OTP','policy_changed':False}
    if observed:
     used=c.state();check(any(x['used_at']is not None for x in used['otp']),'persisted code consumed')
     replay=c.req('/API/auth/verify-otp.php','POST',{'user_id':uid,'otp':code})
     check(replay['status']==200 and replay['json']['success']is False,'replayed optional code rejected')
     check(replay['before']['business']==replay['after']['business']and replay['after']['session']['user_id']==uid,'replay preserves only the existing authenticated actor')
  self.case('fresh_persisted_otp_timezone_control',lambda:otp_control('fresh'))
  if True:
   self.case('database_otp_expired_intent_valid',lambda:otp_control('database_expired'))
   self.case('intent_expired_database_otp_valid',lambda:otp_control('intent_expired'))
  if not full:return
  def fixation():
   fixed='phase3yattackerchosenidentifier123456';c=Client(self,cookie='PHPSESSID='+fixed).csrf();check(c.sid()!=fixed,'strict session mode rejects attacker-chosen unissued ID');self.reject(c.req('/API/profile/settings.php'),401)
  self.case('strict_session_fixation_and_unauthenticated_access',fixation)
  for route in ['signup','login','send-signup-email-otp','verify-signup-email-otp','resend-signup-otp','verify-otp','logout']:
   def methods(route=route):
    c=Client(self).csrf();self.reject(c.req('/API/auth/'+route+'.php'),405)
    for csrf in ['absent','invalid']:self.reject(c.req('/API/auth/'+route+'.php','POST',{},csrf=csrf),403)
   self.case('method_and_csrf_'+route,methods)
  def unverified():
   c=Client(self).csrf();d=self.data();self.reject(c.req('/API/auth/signup.php','POST',d),422);c.req('/API/auth/send-signup-email-otp.php','POST',{'email':d['email'],'full_name':d['full_name']});self.reject(c.req('/API/auth/signup.php','POST',d),422)
  self.case('registration_missing_or_pending_verification',unverified)
  def signup_rejections():
   c=Client(self).csrf();d=self.data();other=self.data();c.req('/API/auth/send-signup-email-otp.php','POST',{'email':d['email'],'full_name':d['full_name']});code=self.code(d);wrong='111111'if code=='000000'else'000000'
   self.reject(c.req('/API/auth/verify-signup-email-otp.php','POST',{'email':d['email'],'otp':wrong}),422);self.reject(c.req('/API/auth/verify-signup-email-otp.php','POST',{'email':other['email'],'otp':code}),422)
   stranger=Client(self).csrf();self.reject(stranger.req('/API/auth/verify-signup-email-otp.php','POST',{'email':d['email'],'otp':code}),422)
   self.control('expire_signup',session_id=c.sid());self.reject(c.req('/API/auth/verify-signup-email-otp.php','POST',{'email':d['email'],'otp':code}),422)
  self.case('wrong_email_session_and_expired_signup_code',signup_rejections)
  def replay():
   c=Client(self).csrf();d=self.data();self.verify(c,d);self.reject(c.req('/API/auth/verify-signup-email-otp.php','POST',{'email':d['email'],'otp':self.code(d)}),422);other=self.data();self.reject(c.req('/API/auth/signup.php','POST',other),422)
  self.case('consumed_signup_code_and_verified_email_binding',replay)
  def duplicate():
   a,b=Client(self).csrf(),Client(self).csrf();d=self.data();self.verify(a,d);self.verify(b,d);r=a.req('/API/auth/signup.php','POST',d);check(r['json']['success'],'first verified registration');r=b.req('/API/auth/signup.php','POST',d);self.reject(r,422);check('already exists'in r['json']['error'],'duplicate-specific failure')
  self.case('duplicate_preverified_sessions',duplicate)
  def password():
   c,d=self.registered();c.req('/API/auth/logout.php','POST',{});c.csrf();r=c.req('/API/auth/login.php','POST',{'identifier':d['email'],'password':'wrong-owned-password'});self.reject(r,401);check('Incorrect password'in r['json']['error'],'wrong password against existing account');self.reject(c.req('/API/auth/login.php','POST',{'identifier':self.data()['email'],'password':'wrong-owned-password'}),401)
  self.case('wrong_password_and_nonexistent_account',password)
  def limiting():
   c=Client(self).csrf();d=self.data()
   for _ in range(5):check(c.req('/API/auth/send-signup-email-otp.php','POST',{'email':d['email'],'full_name':d['full_name']})['status']==200,'first five mail requests')
   n=self.deliveries;self.reject(c.req('/API/auth/send-signup-email-otp.php','POST',{'email':d['email'],'full_name':d['full_name']}),429);check(n==self.deliveries,'limited request sent no mail')
   wrong='111111'if self.code(d)=='000000'else'000000'
   for _ in range(10):self.reject(c.req('/API/auth/verify-signup-email-otp.php','POST',{'email':d['email'],'otp':wrong}),422)
   self.reject(c.req('/API/auth/verify-signup-email-otp.php','POST',{'email':d['email'],'otp':wrong}),429)
   for _ in range(10):self.reject(c.req('/API/auth/login.php','POST',{'identifier':d['email'],'password':'wrong-owned-password'}),401)
   self.reject(c.req('/API/auth/login.php','POST',{'identifier':d['email'],'password':'wrong-owned-password'}),429)
  self.case('real_mail_verify_and_login_rate_limits',limiting)
  def rollback():
   c=Client(self).csrf();d=self.data();self.verify(c,d);self.control('rollback_on')
   try:self.reject(c.req('/API/auth/signup.php','POST',d),422)
   finally:self.control('rollback_off')
  self.case('owned_sql_failure_rolls_back_http_registration',rollback)
  def concurrent_signup():
   c=Client(self).csrf();d=self.data();self.verify(c,d);before=c.state()['business'];children=[Client(self,ip=c.ip,cookie=c.cookie)for _ in range(2)]
   for x in children:x.token=c.token
   with concurrent.futures.ThreadPoolExecutor(max_workers=2)as pool:rr=list(pool.map(lambda x:x.req('/API/auth/signup.php','POST',d,capture=False),children))
   check(sum(bool(r['json'].get('success'))for r in rr)==1,'exactly one concurrent signup success');after=c.state()['business'];check(after['users']['rows']==before['users']['rows']+1,'one stored concurrent account');check(sorted(r['status']for r in rr)==[200,422],'concurrent response statuses');pair=self.records[-2:];overlap=max(r['start_monotonic']for r in pair)<min(r['end_monotonic']for r in pair);check(overlap,'two HTTP request intervals overlap');self.concurrent={'before':before,'after':after,'statuses':[r['status']for r in rr],'overlap':overlap}
  self.case('bounded_two_concurrent_same_session_registrations',concurrent_signup)
  def legacy():
   c=Client(self).csrf();r=c.req('/API/auth/resend-signup-otp.php','POST',{});self.reject(r,200)
  self.case('legacy_resend_requires_its_own_pending_signup_state',legacy)
 def result(self):
  return {'cohort':{'php_zone':self.zone,'database_zone':self.dbzone},'inventory':self.inventory,'migration':self.migration,'cases':self.cases,'controls':self.controls,'requests':self.records,'timezone':getattr(self,'timezone',None),'concurrent':getattr(self,'concurrent',None),'fpm_runtime':[json.loads(line)for line in (self.p/'fpm-runtime.jsonl').read_text().splitlines()],'local_delivery_count':self.deliveries,'fpm_config':self.fpm_config,'cleanup':self.cleanup,'runtime_logs':{p.name:self.scrub_text(p.read_text())for p in [self.p/'php-errors.log',self.p/'fpm.log',self.p/'fpm-stdio.log',self.p/'database.log']if p.exists()},'browser':getattr(self,'browser',{'status':'NOT RUN'}),'status':'PASS'if all(c['status']in ['PASS','NOT RUN']for c in self.cases)else'FAIL'}
def main():
 target=pathlib.Path(os.environ.get('PHASE3AC_HTTP_EVIDENCE','phase3ac-raw/http.json'));target.parent.mkdir(parents=True,exist_ok=True);diff=git('diff',BASE,'--','services','API','security','database','assets','config.php','websocket');check(not git('diff',BASE,'--','services','API','database','assets','config.php','websocket'), 'Unapproved application changes')
 names=['tests/phase3y-http.py','tests/phase3y-report.py','tests/phase3y-bootstrap.php','tests/phase3ac-control.php','tests/phase3ac-browser.cjs','tests/Integration/OtpExpiryTest.php','tests/Support/LocalOtpTransport.php','config.php','API/auth/send-signup-email-otp.php','API/auth/verify-signup-email-otp.php','API/auth/resend-signup-otp.php','API/auth/verify-otp.php','API/auth/logout.php','API/auth/csrf-token.php','API/auth/validate-session.php','API/profile/settings.php','modules/auth/signup.php','modules/auth/login.php','assets/js/auth/signup.js','assets/js/auth/signup-network-fix.js','assets/js/auth/login.js','tests/classroom-performance.py','services/AuthService.php','security/forgot-password/OtpService.php','API/auth/login.php','API/auth/signup.php','security/csrf/csrf.php','security/middleware/AuthMiddleware.php','security/rate-limit/RateLimiter.php']
 evidence={'base':BASE,'head':git('rev-parse','HEAD'),'tree':git('rev-parse','HEAD^{tree}'),'intended_head':os.environ.get('PHASE3Y_EXPECTED_HEAD','UNPUBLISHED'),'harness_diff':git('diff','HEAD'),'harness_status':git('status','--porcelain'),'application_diff':diff,'sha256':{p:digest((ROOT/p).read_bytes())for p in names},'scope':'Owned loopback FPM, real auth API/middleware/SQL; local delivery provider, no external mail or production access','cohorts':[]}
 for name in os.environ.get('PHASE3Y_COHORTS','utc,mismatch,manila').split(','):
  zone,dbzone=COHORTS[name]
  with tempfile.TemporaryDirectory(prefix='phase3y-')as temp:
   owner=Owned(pathlib.Path(temp),zone,dbzone)
   try:
    owner.start();owner.run(full=name=='utc')
    if name=='utc':
     env=owner.env|{'PHASE3Y_BROWSER_ORIGIN':f'http://127.0.0.1:{owner.port}','PHASE3Y_BROWSER_MAIL':f'http://127.0.0.1:{owner.mailport}','PHASE3Y_BROWSER_EMAIL':owner.data()['email'],'PHASE3Y_BROWSER_EVIDENCE':str(owner.p/'browser.json')}
     cmd=['node',str(ROOT/'tests/phase3ac-browser.cjs')];r=subprocess.run(cmd,env=env,capture_output=True,text=True,timeout=120)
     owner.browser=json.loads((owner.p/'browser.json').read_text())if(owner.p/'browser.json').exists()else{'status':'FAIL','reason':'browser subprocess did not produce evidence'}
     owner.browser['command']=cmd;owner.browser['exit_code']=r.returncode
     owner.cases.append({'name':'browser_journey','status':owner.browser['status']if r.returncode==0 else'FAIL','coverage':owner.browser['status']})
    owner.cleanup=owner.control('cleanup');evidence['cohorts'].append(owner.result())
   except Exception as e:evidence['cohorts'].append({'cohort':name,'status':'FAIL','error':owner.scrub_text(str(e)),'exception_class':type(e).__name__,'browser_checkpoint':json.loads((owner.p/'browser.json').read_text())if(owner.p/'browser.json').exists()else None,'runtime_logs':{p.name:owner.scrub_text(p.read_text())for p in [owner.p/'database.log',owner.p/'php-errors.log',owner.p/'fpm.log',owner.p/'fpm-stdio.log']if p.exists()},'cases':owner.cases,'controls':owner.controls,'requests':owner.records})
   finally:owner.stop();target.write_text(json.dumps(evidence,indent=2)+'\n')
 check(all(c['status']=='PASS'for c in evidence['cohorts']),'HTTP/control failures retained in evidence')
 print('Owned HTTP cases:',[(c['cohort'],len(c['cases']),len(c['requests']),c['status'])for c in evidence['cohorts']])
if __name__=='__main__':main()
