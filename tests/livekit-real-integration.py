"""Bounded, disposable real LiveKit/Nginx/FPM/MariaDB/browser integration.

All executable paths and an expendable local MariaDB datadir must be supplied.
Never connects to a pre-existing DB/server. Only ecollab_media_test is created.
Requires Playwright and the application's pinned LiveKit browser SDK locally.
"""
import base64, hashlib, hmac, http.client, http.server, json, os, pathlib
import socket, subprocess, tempfile, threading, time, pwd

ROOT = pathlib.Path(__file__).resolve().parents[1]
required = ['LIVEKIT_BIN', 'NGINX_BIN', 'PHP_FPM_BIN', 'PHP_BIN',
            'MARIADBD_BIN', 'MARIADB_TEST_DATADIR', 'LIVEKIT_BROWSER_SDK',
            'PLAYWRIGHT_MODULE', 'CHROMIUM_BIN']
for key in required:
    assert os.environ.get(key), 'Required isolated fixture dependency: ' + key
assert subprocess.check_output([os.environ['LIVEKIT_BIN'], '--version'], text=True).strip() == 'livekit-server version 1.13.7'
sdk = pathlib.Path(os.environ['LIVEKIT_BROWSER_SDK'])
assert hashlib.sha256(sdk.read_bytes()).hexdigest() == '03c99cd584ee6d7cdb855615df835bf893fa7760912f24f8b5cb5e831598d832'

def port():
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0))
        return s.getsockname()[1]

def token(room, uid=1, ttl=600, grant=None):
    def b64(v):
        return base64.urlsafe_b64encode(json.dumps(v, separators=(',', ':')).encode()).rstrip(b'=')
    now = int(time.time())
    claim = {'iss': 'fixture-key', 'sub': 'user-' + str(uid), 'nbf': now-5, 'iat': now,
             'exp': now+ttl, 'video': grant or {'roomJoin': True, 'room': room, 'canPublish': True, 'canSubscribe': True}}
    data = b64({'alg': 'HS256', 'typ': 'JWT'}) + b'.' + b64(claim)
    return (data + b'.' + base64.urlsafe_b64encode(hmac.new(b's'*64, data, hashlib.sha256).digest()).rstrip(b'=')).decode()

with tempfile.TemporaryDirectory(prefix='ecollab-real-media-') as folder:
    p = pathlib.Path(folder)
    ports = {k: port() for k in ['db', 'livekit', 'rtc', 'fpm', 'ingress']}
    dsn = f"mysql:host=127.0.0.1;port={ports['db']};dbname=ecollab_media_test"
    env = os.environ | {'LIVEKIT_API_KEY': 'fixture-key', 'LIVEKIT_API_SECRET': 's'*64,
                        'LIVEKIT_URL': f"ws://127.0.0.1:{ports['ingress']}", 'CENTRIFUGO_ENABLED': 'false'}
    processes, logs, worker = [], [], None
    def start(args, name):
        log = open(p/(name+'.log'), 'w'); logs.append(log)
        proc = subprocess.Popen(args, env=env, stdout=log, stderr=log)
        processes.append(proc)
        return proc
    def ready(number):
        for _ in range(200):
            assert all(x.poll() is None for x in processes), 'Disposable process exited; inspect local fixture logs'
            try:
                with socket.create_connection(('127.0.0.1', number), .1): return
            except OSError: time.sleep(.025)
        raise AssertionError('Fixture listener unavailable')
    def php(code, prepend=False):
        args = [env['PHP_BIN']]
        if prepend: args += ['-d', 'auto_prepend_file='+str(p/'prepend.php')]
        # -r does not run auto_prepend_file; explicit require for these helpers.
        code = ('require '+repr(str(p/'prepend.php'))+';' if prepend else '') + code
        r = subprocess.run(args+['-r', code], env=env, capture_output=True, text=True, timeout=15)
        assert r.returncode == 0, 'Fixture PHP operation failed: '+r.stderr[-500:]
        return r.stdout
    def sql(query):
        return float(php('$d=new PDO('+repr(dsn)+',"root","");$d->exec('+repr(query)+');echo sprintf("%.6f",microtime(true)*1000);'))
    def request(path, direct=False, headers=None):
        c = http.client.HTTPConnection('127.0.0.1', ports['livekit' if direct else 'ingress'], timeout=4)
        c.request('GET', path, headers=headers or {})
        r = c.getresponse(); body = r.read(); c.close()
        return r.status, body.decode(errors='replace')
    restore = "UPDATE users SET status='active',deleted_at=NULL;DELETE FROM moderation_actions;DELETE FROM server_members;INSERT INTO server_members VALUES(1,1,'member'),(1,2,'moderator');DELETE FROM channel_members;INSERT INTO channel_members VALUES(10,1),(10,2);DELETE FROM dm_group_members;INSERT INTO dm_group_members VALUES(1,1),(1,2);UPDATE dm_call_history SET status='answered'"
    actions = {'restore': restore, 'remove-member': 'DELETE FROM server_members WHERE user_id=1',
               'remove-private': 'DELETE FROM channel_members WHERE user_id=1',
               'suspend': "UPDATE users SET status='suspended' WHERE id=1",
               'deactivate': "UPDATE users SET status='deactivated' WHERE id=1",
               'ban': "INSERT INTO moderation_actions VALUES(1,1,'ban',1,NULL)",
               'server-suspend': "INSERT INTO moderation_actions VALUES(1,1,'suspend',1,NULL)",
               'remove-group': 'DELETE FROM dm_group_members WHERE user_id=1',
               'end-call': "UPDATE dm_call_history SET status='ended'",
               'both-deactivate': "UPDATE users SET status='deactivated'",
               'db-outage': 'RENAME TABLE moderation_actions TO offline_moderation_actions',
               'db-restore': 'RENAME TABLE offline_moderation_actions TO moderation_actions'}
    class Control(http.server.BaseHTTPRequestHandler):
        def do_POST(self):
            global worker
            try:
                assert self.headers.get('X-Fixture-Key') == 'local-disposable-only'
                data = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                action = data['action']; result = {}
                if action in actions:
                    result['commit_ack_unix_ms'] = sql(actions[action])
                elif action == 'token': result = {'token': token(data['room'], data.get('uid', 1), data.get('ttl', 600))}
                elif action == 'start-worker':
                    assert worker is None or worker.poll() is not None
                    worker = start([env['PHP_BIN'], '-d', 'error_log=/dev/stderr', '-d', 'log_errors=1', '-d', 'auto_prepend_file='+str(p/'prepend.php'), str(ROOT/'scripts/realtime-revocation-worker.php')], 'worker-'+str(len(processes)))
                elif action == 'stop-worker':
                    if worker and worker.poll() is None: worker.terminate(); worker.wait(timeout=4)
                elif action == 'cycle':
                    r = subprocess.run([env['PHP_BIN'], '-d', 'error_log=/dev/stderr', '-d', 'log_errors=1', '-d', 'auto_prepend_file='+str(p/'prepend.php'), str(ROOT/'scripts/realtime-revocation-worker.php'), '--once'], env=env, capture_output=True, text=True, timeout=12)
                    result = {'exit': r.returncode, 'stderr': r.stderr.strip()}
                elif action == 'api-outage': (p/'api-down').touch()
                elif action == 'api-restore': (p/'api-down').unlink(missing_ok=True)
                elif action == 'room-api':
                    result = json.loads(php('require_once '+repr(str(ROOT/'services/LiveKitService.php'))+';echo json_encode((new LiveKitService())->roomRequest('+repr(data['method'])+','+('json_decode('+repr(json.dumps(data.get('body', {})))+',true)')+','+('json_decode('+repr(json.dumps(data['grant']))+',true)')+'));', True))
                elif action == 'routes':
                    t = token('ecollab-channel-10')
                    result = {route: request(route+'?access_token='+t)[0] for route in ['/rtc', '/rtc/validate', '/rtc/v1', '/rtc/v1/validate']}
                elif action == 'room-api-body-types':
                    for name, body in [('array', '[]'), ('object', '{}')]:
                        c = http.client.HTTPConnection('127.0.0.1', ports['livekit'], timeout=4)
                        c.request('POST', '/twirp/livekit.RoomService/ListRooms', body, {'Content-Type':'application/json', 'Authorization':'Bearer '+token('', grant={'roomList':True})})
                        r = c.getresponse(); decoded = json.loads(r.read()); result[name] = {'status': r.status}
                        # Room records contain TURN credentials. Never emit the raw RoomService response.
                        if r.status == 200: result[name]['room_count'] = len(decoded.get('rooms', []))
                        else: result[name]['error_code'] = decoded.get('code')
                        c.close()
                elif action == 'bypass':
                    t = token('ecollab-channel-10'); path = '/rtc/validate?access_token='+t
                    result = {'gated': request(path)[0], 'direct_origin': request(path, True)[0],
                              'alternate_host': request(path, headers={'Host': 'alternate.invalid'})[0],
                              'unknown_route': request('/rtc/unknown?access_token='+t)[0]}
                else: raise ValueError('Unknown fixture action')
                self.send_response(200); self.end_headers(); self.wfile.write(json.dumps(result).encode())
            except Exception as e:
                self.send_response(500); self.end_headers(); self.wfile.write(json.dumps({'error': str(e)}).encode())
        def log_message(self, *args): pass
    control = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Control)
    threading.Thread(target=control.serve_forever, daemon=True).start()
    try:
        # This datadir is explicitly supplied as expendable, never discovered from host config.
        maria = start([env['MARIADBD_BIN'], '--no-defaults', '--user='+pwd.getpwuid(os.geteuid()).pw_name, '--datadir='+env['MARIADB_TEST_DATADIR'],
                       '--bind-address=127.0.0.1', '--port='+str(ports['db']), '--socket=',
                       '--pid-file='+str(p/'maria.pid'), '--skip-grant-tables'], 'maria')
        ready(ports['db'])
        php('$d=new PDO("mysql:host=127.0.0.1;port='+str(ports['db'])+'","root","");$d->exec("DROP DATABASE IF EXISTS ecollab_media_test;CREATE DATABASE ecollab_media_test");')
        sql((ROOT/'tests/fixtures/livekit-admission.sql').read_text()); sql(restore)
        (p/'metrics.jsonl').touch(); (p/'api-mode').write_text('normal')
        (p/'prepend.php').write_text('<?php require_once '+repr(str(ROOT/'database/config/db.php'))+';if(PHP_SAPI==="cli")ini_set("error_log","/dev/stderr");'
            +'class MediaFixturePDO extends PDO { public function prepare(string $q,array $o=[]):PDOStatement|false{file_put_contents('+repr(str(p/'metrics.jsonl'))+',"query\\n",FILE_APPEND|LOCK_EX);return parent::prepare($q,$o);}}'
            +'(new ReflectionProperty(Database::class,"instance"))->setValue(null,new MediaFixturePDO('+repr(dsn)+',"root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]));')
        (p/'livekit.yaml').write_text(f'''port: {ports['livekit']}
bind_addresses: ["127.0.0.1"]
rtc:
  tcp_port: {ports['rtc']}
  use_external_ip: false
  node_ip: 127.0.0.1
  enable_loopback_candidate: true
keys:
  fixture-key: {'s'*64}
logging:
  level: warn
''')
        livekit = start([env['LIVEKIT_BIN'], '--config', str(p/'livekit.yaml'), '--dev'], 'livekit'); ready(ports['livekit'])
        (p/'fpm.conf').write_text(f'''[global]
pid = {p}/fpm.pid
error_log = {p}/fpm.log
daemonize = no
[fixture]
user = root
group = root
listen = 127.0.0.1:{ports['fpm']}
pm = static
pm.max_children = 10
clear_env = no
php_admin_value[auto_prepend_file] = {p}/prepend.php
php_admin_flag[display_errors] = off
''')
        (p/'fastcgi_params').write_text('fastcgi_param REQUEST_METHOD $request_method;\nfastcgi_param SERVER_PROTOCOL $server_protocol;\nfastcgi_param REDIRECT_STATUS 200;\n')
        template = (ROOT/'deploy/phase2/livekit-admission.conf.example').read_text().replace('/var/www/ecollab', str(ROOT)).replace('unix:/run/php/php8.3-fpm.sock', f"127.0.0.1:{ports['fpm']}").replace('127.0.0.1:7880', f"127.0.0.1:{ports['livekit']}")
        # Fail only RoomService traffic on command; signaling/media continue through the real server.
        template = template.replace('access_log off;\n    proxy_pass', f'if (-f {p}/api-down) {{ return 503; }}\n    access_log off;\n    proxy_pass')
        (p/'nginx.conf').write_text(f'''daemon off; master_process off; user root root; pid {p}/nginx.pid; error_log {p}/nginx.log;
events {{worker_connections 256;}}
http {{access_log off; client_body_temp_path {p}/body; fastcgi_temp_path {p}/fcgi; proxy_temp_path {p}/proxy; uwsgi_temp_path {p}/uwsgi; scgi_temp_path {p}/scgi;
server {{listen 127.0.0.1:{ports['ingress']}; {template}
location = /sdk.js {{alias {sdk}; default_type application/javascript;}}
location = /fixture {{default_type text/html; return 200 '<html><body>synthetic media fixture</body></html>';}}
}}}}
''')
        fpm = start([env['PHP_FPM_BIN'], '-R', '-F', '-y', str(p/'fpm.conf')], 'fpm'); ready(ports['fpm'])
        nginx = start([env['NGINX_BIN'], '-p', folder+'/', '-c', str(p/'nginx.conf')], 'nginx'); ready(ports['ingress'])
        browser_env = env | {'TEST_MEDIA_CONTROL': f'http://127.0.0.1:{control.server_port}',
                             'TEST_MEDIA_URL': env['LIVEKIT_URL'], 'TEST_MEDIA_RESULT': str(p/'result.json')}
        # Sample only owned fixture processes, never unrelated host processes.
        resource_rows = {}; sampling = threading.Event(); started = time.monotonic()
        def sample():
            while not sampling.wait(.25):
                for label, proc in [('LiveKit', livekit), ('MariaDB', maria), ('Nginx', nginx), ('FPM', fpm)]:
                    ids = [proc.pid]
                    try: ids += [int(n) for n in pathlib.Path(f'/proc/{proc.pid}/task/{proc.pid}/children').read_text().split()]
                    except OSError: pass
                    pss = 0
                    for pid in ids:
                        try:
                            row = pathlib.Path(f'/proc/{pid}/stat').read_text().split(') ', 1)[1].split()
                            cpu = (int(row[11])+int(row[12]))/os.sysconf('SC_CLK_TCK')
                            item = resource_rows.setdefault((label, pid), {'first_cpu_seconds': cpu, 'last_cpu_seconds': cpu})
                            item['last_cpu_seconds'] = cpu
                            for line in pathlib.Path(f'/proc/{pid}/smaps_rollup').read_text().splitlines():
                                if line.startswith('Pss:'): pss += int(line.split()[1])
                        except (OSError, ValueError): pass
                    key = (label, 'aggregate')
                    resource_rows[key] = {'peak_sampled_pss_kib': max(pss, resource_rows.get(key, {}).get('peak_sampled_pss_kib', 0))}
        sampler = threading.Thread(target=sample, daemon=True); sampler.start()
        try:
            r = subprocess.run(['node', str(ROOT/'tests/livekit-real-browser.cjs')], env=browser_env, text=True, timeout=240)
        finally:
            sampling.set(); sampler.join()
        assert r.returncode == 0, 'Real browser suite failed'
        result = json.loads((p/'result.json').read_text())
        result['database_prepared_queries'] = len((p/'metrics.jsonl').read_text().splitlines())
        result['fixture_resources'] = {label: {
            'sampled_cpu_core_seconds': sum(v['last_cpu_seconds']-v['first_cpu_seconds'] for (name, pid), v in resource_rows.items() if name == label and isinstance(pid, int)),
            **resource_rows.get((label, 'aggregate'), {}), 'sample_interval_seconds': .25,
            'observation_seconds': time.monotonic()-started
        } for label in ['LiveKit', 'MariaDB', 'Nginx', 'FPM']}
        result['versions'] = {'LiveKit': '1.13.7', 'SDK': '2.22.3', 'MariaDB': php('$d=new PDO('+repr(dsn)+',"root","");echo $d->query("SELECT VERSION()")->fetchColumn();')}
        print(json.dumps(result, indent=2))
        if env.get('TEST_MEDIA_EVIDENCE'): pathlib.Path(env['TEST_MEDIA_EVIDENCE']).write_text(json.dumps(result, indent=2)+'\n')
    except Exception:
        for name in ['livekit', 'fpm', 'nginx', 'maria']:
            f=p/(name+'.log')
            if f.exists(): print(name, f.read_text(errors='replace')[-1500:])
        raise
    finally:
        control.shutdown(); control.server_close()
        for proc in reversed(processes):
            if proc.poll() is None:
                proc.terminate()
                try: proc.wait(timeout=15)
                except subprocess.TimeoutExpired: proc.kill(); proc.wait(timeout=5)
        for log in logs: log.close()
