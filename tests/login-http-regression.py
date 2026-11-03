"""Real login endpoint boundary checks with a failing in-memory PDO; no external DB/mail."""
import http.client,json,os,pathlib,socket,subprocess,tempfile,time
root=pathlib.Path(__file__).resolve().parents[1]
php=os.environ.get('PHP_BIN','php')
with tempfile.TemporaryDirectory(prefix='ecollab-login-test-') as folder:
 p=pathlib.Path(folder)
 router=p/'router.php'
 router.write_text('''<?php
require '''+json.dumps(str(root/'config.php'))+''';
require ROOT_PATH.'/database/config/db.php';
require ROOT_PATH.'/security/middleware/AuthMiddleware.php';
ini_set('error_log', '''+json.dumps(str(p/'errors.log'))+''');
AuthMiddleware::startSession();$_SESSION=['csrf_token'=>'fixture-token'];
class UnavailableLoginPDO extends PDO {
 public function __construct(){}
 public function query(string $query, ?int $fetchMode=null, mixed ...$args): PDOStatement|false {
  throw new PDOException('fixture-secret-must-not-be-returned',2002);
 }
}
(new ReflectionProperty(Database::class,'instance'))->setValue(null,new UnavailableLoginPDO());
require ROOT_PATH.'/API/auth/login.php';
''')
 with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
 with (p/'server.log').open('w') as log:
  server=subprocess.Popen([php,'-d','session.save_path='+folder,'-S','127.0.0.1:'+str(port),str(router)],cwd=root,stdout=log,stderr=log)
  try:
   for _ in range(60):
    try:socket.create_connection(('127.0.0.1',port),.1).close();break
    except OSError:time.sleep(.05)
   def call(method,body,csrf=True):
    conn=http.client.HTTPConnection('127.0.0.1',port,timeout=5)
    headers={'Content-Type':'application/json'}
    if csrf:headers['X-CSRF-Token']='fixture-token'
    conn.request(method,'/API/auth/login.php',body,headers);r=conn.getresponse();payload=r.read().decode();status=r.status;ctype=r.getheader('Content-Type','');conn.close()
    assert 'fixture-secret' not in payload
    assert 'application/json' in ctype,(status,ctype,payload)
    return status,json.loads(payload)
   status,data=call('GET',None);assert status==405 and not data['success']
   status,data=call('POST','{}');assert status==400,(status,data)
   status,data=call('POST','{');assert status==400
   status,data=call('POST','{"identifier":[],"password":"x"}');assert status==400
   status,data=call('POST','{}',False);assert status==403
   status,data=call('POST','{"identifier":"fixture@example.invalid","password":"fixture"}')
   assert status==503 and not data['success'] and len(data['request_id'])==16,(status,data)
   assert data['request_id'] in (p/'errors.log').read_text()
   print('PASS: 6 login HTTP checks; methods, validation, CSRF and fail-closed database outage with reference')
  finally:server.terminate();server.wait(timeout=5)
