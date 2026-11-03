"""Real PHP HTTP handlers against an explicitly isolated test database.
No email, WebSocket server or external service is used. The synthetic session
router exists only in a temporary directory and is never deployed with the app.
Run after migrations with DB_NAME containing 'test' and DB_HOST on loopback.
"""
import http.client
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.parse

ROOT = Path(__file__).resolve().parents[1]
if 'test' not in os.environ.get('DB_NAME', '') or os.environ.get('DB_HOST') not in ('127.0.0.1', 'localhost'):
    raise SystemExit('An explicit loopback test database is required.')
PHP = os.environ.get('PHP_BIN', 'php')
setup = r"""
require 'config.php';require 'database/config/db.php';$db=Database::getInstance();
$users=$db->query('SELECT id FROM users ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
if(count($users)!==2)throw new RuntimeException('Two fixture users required');
$db->prepare('INSERT INTO servers(owner_id,name,slug) VALUES(?,?,?)')->execute([$users[0],'HTTP fixture','http_'.uniqid()]);$sid=(int)$db->lastInsertId();
$db->prepare('INSERT INTO channels(server_id,name,slug,created_by) VALUES(?,?,?,?)')->execute([$sid,'HTTP fixture','fixture',$users[0]]);$cid=(int)$db->lastInsertId();
$db->prepare('INSERT INTO channel_members(channel_id,user_id) VALUES(?,?)')->execute([$cid,$users[0]]);
echo json_encode(['owner'=>(int)$users[0],'outsider'=>(int)$users[1],'channel'=>$cid]);
"""
fixture = json.loads(subprocess.check_output([PHP, '-r', setup], cwd=ROOT, text=True))
with tempfile.TemporaryDirectory(prefix='ecollab-http-test-') as folder:
    router = Path(folder) / 'router.php'
    router.write_text("<?php\nrequire " + json.dumps(str(ROOT / 'config.php')) + ";\nrequire ROOT_PATH.'/security/middleware/AuthMiddleware.php';\nAuthMiddleware::startSession();\n$_SESSION=[];\n$uid=(int)($_SERVER['HTTP_X_FIXTURE_USER']??0);\nif($uid){$_SESSION=['user_id'=>$uid,'username'=>'fixture','role'=>'student','csrf_token'=>'fixture-token'];}\n$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);\nif(!in_array($path,['/API/collab/collab.php','/API/collab/collab-extra.php'],true)){http_response_code(404);exit;}\nrequire ROOT_PATH.$path;\n")
    with socket.socket() as chosen:
        chosen.bind(('127.0.0.1', 0))
        port = chosen.getsockname()[1]
    with open(Path(folder) / 'server.log', 'w') as logs:
        server = subprocess.Popen([PHP, '-d', 'session.save_path='+folder, '-S', '127.0.0.1:'+str(port), str(router)], cwd=ROOT, stdout=logs, stderr=logs)
        try:
            for _ in range(100):
                if server.poll() is not None:
                    raise RuntimeError('Fixture PHP server stopped')
                try:
                    socket.create_connection(('127.0.0.1', port), .1).close()
                    break
                except OSError:
                    time.sleep(.05)
            else:
                raise RuntimeError('Fixture PHP server was not ready')
            checks = 0
            def call(tool, action, body=None, method='GET', user=None, csrf=True, expected=200, extra=False, **params):
                global checks
                query={'tool':tool,'action':action,'channel_id':fixture['channel'],**params}
                path='/API/collab/'+('collab-extra.php' if extra else 'collab.php')+'?'+urllib.parse.urlencode(query)
                headers={'X-Fixture-User':str(fixture['owner'] if user is None else user),'Content-Type':'application/json'}
                if csrf:headers['X-CSRF-Token']='fixture-token'
                connection=http.client.HTTPConnection('127.0.0.1',port,timeout=10)
                connection.request(method,path,body=None if body is None else json.dumps(body),headers=headers)
                response=connection.getresponse();payload=response.read().decode();connection.close()
                assert response.status==expected,(method,tool,action,response.status,payload)
                data=json.loads(payload)
                if expected==200:assert data.get('ok') is True,data
                checks+=1
                return data
            call('code','get',user=0,expected=401)
            call('code','get',user=fixture['outsider'],expected=403)
            for extra,tool,action in [(False,'timer','reset'),(True,'summary','generate')]:
                call(tool,action,method='GET',csrf=False,expected=405,extra=extra)
                for method in ['POST','PUT','PATCH','DELETE']:
                    call(tool,action,body={},method=method,csrf=False,expected=403,extra=extra)
            saved=call('code','save',body={'title':'Fixture','language':'javascript','code':'1 + 1','version':0},method='POST')
            snippet=int(saved['snippet_id']);assert snippet>0 and saved['version']==1
            loaded=call('code','get');assert loaded['snippet']['code']=='1 + 1'
            call('code','save',body={'title':'Fixture','language':'javascript','code':'2 + 2','version':1},method='POST')
            call('code','save',body={'code':'stale','version':1},method='POST',expected=409)
            call('code','log_run',body={'snippet_id':snippet,'output':'4','error':'','duration_ms':1},method='POST')
            assert len(call('code','run_history')['runs'])==1
            quiz=call('quiz','create',body={'title':'Fixture quiz','questions':[]},method='POST')['quiz_id']
            assert int(call('quiz','get',quiz_id=quiz)['quiz']['id'])==int(quiz)
            assert call('quiz','results',quiz_id=quiz)['results']==[]
            assert call('calendar','list',**{'from':'2026-11-01','to':'2026-11-30'})['events']==[]
            print('PASS:',checks,'real HTTP API checks; auth, membership, CSRF, methods, snippet persistence/version/run log, quiz parameters and calendar range')
        finally:
            server.terminate()
            try:server.wait(timeout=5)
            except subprocess.TimeoutExpired:server.kill()
