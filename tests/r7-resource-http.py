"""Actual legacy resource endpoints, isolated private grants and view-only suspension."""
import http.client,json,os,pathlib,re,socket,sqlite3,subprocess,tempfile,time
ROOT=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='r7-resource-') as folder:
 p=pathlib.Path(folder);fixture=p/'fixture.sqlite';db=sqlite3.connect(fixture)
 schema=re.search(r'\$schema="(.*?)";', (ROOT/'tests/r7-authorization.php').read_text(),re.S).group(1);db.executescript(schema)
 db.executescript("ALTER TABLE collab_whiteboards ADD COLUMN state_json TEXT DEFAULT '{}';ALTER TABLE collab_whiteboards ADD COLUMN updated_by INTEGER;ALTER TABLE collab_whiteboards ADD COLUMN updated_at TEXT;ALTER TABLE collab_workspaces ADD COLUMN allow_edit_documents INTEGER DEFAULT 1");db.close()
 router=p/'router.php';router.write_text('''<?php
require_once '''+repr(str(ROOT/'database/config/db.php'))+''';require_once '''+repr(str(ROOT/'security/middleware/AuthMiddleware.php'))+''';
(new ReflectionProperty(Database::class,'instance'))->setValue(null,new PDO('sqlite:''' + str(fixture)+'''',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]));
$route=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($route==='/seed'){AuthMiddleware::startSession();$_SESSION['user_id']=1;$_SESSION['csrf_token']='fixture-token';session_write_close();echo 'seed';return;}
if(!in_array($route,['/API/collaboration/whiteboard.php','/API/collaboration/document-presence.php'],true)){http_response_code(404);return;}
require '''+repr(str(ROOT))+'''.$route;
''')
 with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
 server=subprocess.Popen([os.environ.get('PHP_BIN','php'),'-d','session.save_path='+folder,'-S','127.0.0.1:'+str(port),str(router)],env=os.environ,cwd=ROOT,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 def request(path,body=None,cookie=''):
  c=http.client.HTTPConnection('127.0.0.1',port,timeout=3);c.request('POST' if body is not None else 'GET',path,None if body is None else json.dumps(body),{'Cookie':cookie,'Content-Type':'application/json','X-CSRF-Token':'fixture-token'});r=c.getresponse();out=(r.status,r.read(),r.getheader('Set-Cookie'));c.close();return out
 def sql(s):
  d=sqlite3.connect(fixture);d.executescript(s);d.close()
 try:
  for _ in range(100):
   try:seed=request('/seed');break
   except OSError:time.sleep(.02)
  cookie=seed[2].split(';')[0];board='/API/collaboration/whiteboard.php?workspace_id=1'
  assert request(board,cookie=cookie)[0]==200
  sql("INSERT INTO moderation_actions VALUES(1,1,'suspend',1,NULL)")
  assert request(board,cookie=cookie)[0]==200,'Server suspension preserves viewing'
  denied=request('/API/collaboration/whiteboard.php',{'workspace_id':1,'state':{'secret':'must not save'}},cookie);assert denied[0]==403,denied
  sql("DELETE FROM moderation_actions;DELETE FROM collab_resource_permissions WHERE user_id=1")
  assert request(board,cookie=cookie)[0]==403,'Revoked private board grant'
  assert request('/API/collaboration/document-presence.php?document_id=1',cookie=cookie)[0]==403,'Revoked private document presence'
  d=sqlite3.connect(fixture);assert d.execute('SELECT state_json FROM collab_whiteboards WHERE id=1').fetchone()[0]=='{}';d.close()
  print('PASS actual resource endpoints: private grant revocation blocks board state and document presence; suspension preserves reads and prevents persisted edits')
 finally:server.terminate();server.wait(timeout=5)
