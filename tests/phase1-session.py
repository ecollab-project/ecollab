"""Two local PHP servers sharing a synthetic authenticated file session; no DB/network services."""
import concurrent.futures, http.client, json, os, pathlib, socket, subprocess, tempfile, time
ROOT = pathlib.Path(__file__).resolve().parents[1]
def port():
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0)); return s.getsockname()[1]
with tempfile.TemporaryDirectory(prefix='ecollab-session-') as folder:
    path = pathlib.Path(folder)
    router = path / 'router.php'
    router.write_text('''<?php
require_once ''' + repr(str(ROOT / 'security/middleware/AuthMiddleware.php')) + ''';
if ($_SERVER['REQUEST_URI']==='/seed') {
 AuthMiddleware::startSession(); $_SESSION['user_id']=1; $_SESSION['csrf_token']='fixture-token'; session_write_close(); echo 'seeded'; return;
}
if ($_SERVER['REQUEST_URI']==='/probe') { AuthMiddleware::requireAuth(true); session_write_close(); echo 'probe'; return; }
if (isset($_GET['slow'])) register_shutdown_function(function(){file_put_contents(__DIR__.'/ready','ready');usleep(800000);});
$route=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(!in_array($route,['/API/chat/search-messages.php','/API/chat/whiteboard-sync.php'],true)){http_response_code(404);exit;}
require ''' + repr(str(ROOT)) + '''.$route;
''')
    ports = [port(), port()]; servers = []
    def request(p, url, cookie='', method='GET', csrf=None):
        conn = http.client.HTTPConnection('127.0.0.1', p, timeout=5)
        headers = {'Cookie': cookie}
        if csrf is not None: headers['X-CSRF-Token'] = csrf
        conn.request(method, url, headers=headers); response = conn.getresponse()
        result = (response.status, response.read(), response.getheader('Set-Cookie')); conn.close(); return result
    try:
        for p in ports:
            servers.append(subprocess.Popen(['php','-d',f'session.save_path={folder}','-S',f'127.0.0.1:{p}',str(router)],cwd=ROOT,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL))
        for p in ports:
            for _ in range(100):
                try: request(p,'/probe'); break
                except OSError: time.sleep(.02)
            else: raise RuntimeError('PHP server did not start')
        seeded=request(ports[0],'/seed'); cookie=seeded[2].split(';')[0]
        assert request(ports[0],'/API/chat/search-messages.php')[0] == 401
        assert request(ports[0],'/API/chat/whiteboard-sync.php',cookie,'POST','wrong')[0] == 403
        timings=[]
        for route,method in [('search-messages.php','GET'),('whiteboard-sync.php','GET'),('whiteboard-sync.php','POST')]:
            marker=path/'ready'; marker.unlink(missing_ok=True)
            with concurrent.futures.ThreadPoolExecutor() as pool:
                slow=pool.submit(request,ports[0],f'/API/chat/{route}?slow=1',cookie,method,'fixture-token')
                deadline=time.monotonic()+3
                while not marker.exists() and time.monotonic()<deadline: time.sleep(.005)
                assert marker.exists(), 'Slow request did not reach shutdown'
                start=time.monotonic(); result=request(ports[1],'/probe',cookie); elapsed=time.monotonic()-start
                assert result[0]==200 and result[1]==b'probe'
                assert elapsed<.5, f'Session lock blocked parallel request: {elapsed}'
                assert slow.result()[0]==(200 if route.startswith('search') else 400)
                timings.append({'route':route,'method':method,'parallel_probe_ms':round(elapsed*1000,2)})
        print(json.dumps(timings)); print('PASS actual endpoint auth/CSRF and same-session parallel requests (synthetic session; downstream DB behavior tested separately)')
    finally:
        for server in servers: server.terminate()
        for server in servers: server.wait(timeout=5)
