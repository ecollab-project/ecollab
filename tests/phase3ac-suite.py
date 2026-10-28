"""Bounded application sampler. Owns every DB, FPM pool, HTTP port and synthetic account.

This is not an external-target load generator or a VPS capacity certification.
"""
import concurrent.futures, hashlib, http.client, http.server, json, os, pathlib, pwd
import signal, socket, statistics, struct, subprocess, tempfile, threading, time

ROOT=pathlib.Path(__file__).resolve().parents[1]
BASE=os.environ.get('CLASSROOM_BASE','54228eb0f662bd8272dea2f32ab446ca6cc207c3')
PHP=os.environ.get('PHP_BIN','php8.3')
PEER_COUNTS={};PEER_LOCK=threading.Lock();DB_PID=None
READS=['dashboard','workspace','navigation','history','dm','threads','notifications','settings','peer','facilitator']
MIXES={'read':READS,'write':['note','setting_write','read_mark','notification_write'],
       'mixed':READS+['history','dm','note','setting_write']}

def percentile(values,q):
    values=sorted(values)
    return values[min(len(values)-1,max(0,__import__('math').ceil(len(values)*q)-1))] if values else None

def read_limit(name):
    try:return pathlib.Path('/sys/fs/cgroup',name).read_text().strip()
    except OSError:return 'NOT VERIFIED'

def freeport():
    with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]

def rec(kind,data=b''):
    return struct.pack('!BBHHBB',1,kind,1,len(data),0,0)+data

def fcgi(path,params,body=b''):
    def size(n):return bytes([n]) if n<128 else struct.pack('!I',n|0x80000000)
    data=b''
    for k,v in params.items():
        k,v=k.encode(),str(v).encode();data+=size(len(k))+size(len(v))+k+v
    with socket.socket(socket.AF_INET) as s:
        s.settimeout(8);s.connect(('127.0.0.1',path));s.sendall(rec(1,struct.pack('!HB5x',1,0))+rec(4,data)+rec(4)+rec(5,body)+rec(5))
        output=b'';errors=b''
        def exact(n):
            out=b''
            while len(out)<n:
                part=s.recv(n-len(out))
                if not part:raise RuntimeError('FPM closed a response')
                out+=part
            return out
        while True:
            _,kind,_,length,pad,_=struct.unpack('!BBHHBB',exact(8));data=exact(length);exact(pad)
            if kind==6:output+=data
            if kind==7:errors+=data
            if kind==3:break
        return output,errors

def request(port,path,cookie='',method='GET',body=None,rid='probe'):
    c=http.client.HTTPConnection('127.0.0.1',port,timeout=8);raw=json.dumps(body).encode() if body is not None else None
    headers={'Cookie':cookie,'X-Fixture-Request':rid,'X-CSRF-Token':'classroom-fixture','Content-Type':'application/json'}
    start=time.monotonic()
    try:
        c.request(method,path,raw,headers);r=c.getresponse();data=r.read()
        return {'status':r.status,'body':data,'cookie':r.getheader('Set-Cookie'),'ms':(time.monotonic()-start)*1000}
    finally:c.close()

def operation(name,uid):
    channel=uid%3 or 3
    if 21<=uid<=40:channel=1+uid%3
    if 41<=uid<=60:channel=101
    if 61<=uid<=80:channel=102
    # Empty-membership actors perform history against another read-only surface instead.
    if name in ['history','read_mark'] and uid>80:name='dm'
    paths={'dashboard':'/API/dashboard/live.php','workspace':'/API/student/workspace.php',
      'navigation':'/API/student/workspace.php?server_id='+str(channel if channel<100 else channel-80),
      'history':f'/API/chat/get-messages.php?channel_id={channel}&read=0',
      'read_mark':f'/API/chat/get-messages.php?channel_id={channel}&read=1',
      'dm':'/API/dm/get-conversations.php','threads':'/API/threads/index.php?scope=public&limit=10',
      'notifications':'/API/notifications/get.php','settings':'/API/profile/settings.php',
      'peer':'/API/chat/peer-match.php?action=get_matches&limit=10',
      'facilitator':'/API/student/workspace.php?mode=facilitator&server_id=1'}
    if name=='note':return '/API/student/workspace.php?action=save_note','POST',{'title':'Synthetic benchmark note','content':'Fixture only'}
    if name=='setting_write':return '/API/profile/settings.php','POST',{'theme':'dark'}
    if name=='notification_write':return '/API/student/workspace.php?action=read_notifications','POST',{}
    return paths[name],'GET',None

def process_usage(pid,expected):
    try:
        path=pathlib.Path('/proc')/str(pid)
        if not (path/'comm').read_text().strip().startswith(expected):return {'unavailable':'PID namespace mismatch'}
        fields=(path/'stat').read_text().split();result={'cpu_seconds':(int(fields[13])+int(fields[14]))/os.sysconf('SC_CLK_TCK'),'pss_kib':None}
        try:
            for line in (path/'smaps_rollup').read_text().splitlines():
                if line.startswith('Pss:'):result['pss_kib']=int(line.split()[1])
        except OSError:pass
        return result
    except OSError:return {'unavailable':'process counters inaccessible'}

def sample(port,cookies,folder,label,users,mix,seconds=8,rate=8):
    """At most ten outstanding requests and eight scheduled operations/s; no catch-up bursts."""
    results=[];status=[];started=time.monotonic();before_lines=len((folder/'requests.jsonl').read_text().splitlines())
    stop=threading.Event()
    def monitor():
        while not stop.wait(.5):
            try:
                current=json.loads(request(port,'/fpm-status?json&full')['body']);current['observed_at']=time.monotonic();current['database_process']=process_usage(DB_PID,'mariadbd');current['worker_processes']={str(item['pid']):process_usage(item['pid'],'php-fpm') for item in current.get('processes',[])};status.append(current)
            except Exception as e:status.append({'unavailable':type(e).__name__})
    watcher=threading.Thread(target=monitor,daemon=True);watcher.start()
    def call(i,warm):
        actors=[1,21,41,61,81,2,22,42,62,82][:users];uid=actors[i%users];name=MIXES[mix][(i//users+i%users)%len(MIXES[mix])];actor=900 if name=='facilitator' else uid
        path,method,body=operation(name,actor);rid=label+'-'+str(i)
        if name=='peer':
            with PEER_LOCK:
                n=PEER_COUNTS.get(actor,0);PEER_COUNTS[actor]=n+1
            if n>=8:name='peer_profile';path='/API/chat/peer-match.php?action=get_profile'
        try:
            r=request(port,path,cookies[actor],method,body,rid);payload=json.loads(r.pop('body'))
            r|={'name':name,'uid':actor,'warmup':warm,'id':rid,'success':r['status']==200 and (payload.get('success',payload.get('ok')) is True)}
            if name in ['workspace','facilitator','navigation'] and r['success']:
                r['success']=all(v['available'] for k,v in payload['data'].items() if k!='limits')
            if not r['success']:r['error']=payload
            return r
        except Exception as e:return {'success':False,'name':name,'id':rid,'warmup':warm,'error':type(e).__name__,'ms':8000}
    # One warm-up cycle covers every surface. Recorded separately; not discarded silently.
    warm=[call(i,True) for i in range(len(MIXES[mix]))]
    if not all(r['success'] for r in warm):
        (folder.parent/'failed-run.json').write_text(json.dumps({'label':label,'warmup':warm}));raise AssertionError(warm)
    futures=[];begin=time.monotonic();cap_stopped=False
    try:
        with concurrent.futures.ThreadPoolExecutor(max_workers=min(users,10)) as pool:
            i=0
            while time.monotonic()-begin<seconds:
                if sum(not f.done() for f in futures)>=10:cap_stopped=True;break
                futures.append(pool.submit(call,i+len(warm),False));i+=1
                time.sleep(1/rate)
            scheduled_seconds=time.monotonic()-begin
            results=[f.result() for f in futures]
    finally:stop.set();watcher.join(timeout=2)
    elapsed=time.monotonic()-begin
    assert len(results)>0
    errors=[r for r in results if not r['success']]
    records=[json.loads(line) for line in (folder/'requests.jsonl').read_text().splitlines()[before_lines:]]
    lookup={r['id']:r for r in records};metrics=[lookup[r['id']] for r in results if r['id'] in lookup]
    assert len(metrics)==len(results),'Missing instrumented requests'
    assert all(q['success'] for r in metrics for q in r['queries']),'Fixture SQL failure'
    peak_active=max([r.get('active processes',0) for r in status]+[0]);peak_queue=max([r.get('listen queue',0) for r in status]+[0])
    times=[r['ms'] for r in results];latency={k:percentile(times,q) for k,q in [('p50',.5),('p95',.95),('p99',.99)]}
    per_route={}
    for name in sorted(set(r['name'] for r in results)):
        rr=[r for r in results if r['name']==name];mm=[lookup[r['id']] for r in rr]
        if rr:per_route[name]={'n':len(rr),'p95_ms':percentile([r['ms'] for r in rr],.95),'queries_median':statistics.median(len(r['queries']) for r in mm),'sql_ms_median':statistics.median(sum(q['ms'] for q in r['queries']) for r in mm)}
    sql={}
    for m in metrics:
        for q in m['queries']:
            item=sql.setdefault(q['sql'],{'calls':0,'ms':0});item['calls']+=1;item['ms']+=q['ms']
    dashboard=[r for r in metrics if r['recent_probe']]
    query_times=[q['ms'] for r in metrics for q in r['queries']]
    result={'sql_distribution_ms':{'n':len(query_times),'p50':percentile(query_times,.5),'p95':percentile(query_times,.95),'p99':percentile(query_times,.99),'at_least_20ms':sum(t>=20 for t in query_times)},'label':label,'scheduled_seconds':scheduled_seconds,'stop_reason':'outstanding-cap' if cap_stopped else 'duration','synthetic_accounts':users,'seconds':elapsed,'total_sample_seconds_including_warmup':time.monotonic()-started,'actual_actor_ids':sorted(set(r['uid'] for r in results)),'offered_rate_cap':rate,'outstanding_cap':min(users,10),'operations':len(results),'successful_rps':(len(results)-len(errors))/elapsed,'errors':errors,'latency_ms':latency,'php_cpu_total_ms':sum(r['cpu_ms'] for r in metrics),'php_peak_bytes_max':max(r['peak_bytes'] for r in metrics),'response_bytes_total':sum(r['payload_bytes'] for r in metrics),'fpm_active_peak_including_status':peak_active,'fpm_listen_queue_peak':peak_queue,'status_samples':status,'recent_probes':len(dashboard),'recent_fallbacks':sum(r['recent_fallback'] for r in dashboard),'routes':per_route,'top_sql':sorted([dict(sql=k,**v) for k,v in sql.items()],key=lambda x:-x['ms'])[:8],'warmup':warm,'requests':results}
    if errors or latency['p99']>=5000:
        (folder.parent/'failed-run.json').write_text(json.dumps(result));raise AssertionError(result)
    # Stop criteria for bounded local previews; no latency assertion used to claim staging acceptance.
    assert latency['p99']<5000,'Stop: five-second tail latency'
    return result

def main():
    global DB_PID
    target=pathlib.Path(os.environ['CLASSROOM_EVIDENCE']);target.parent.mkdir(parents=True,exist_ok=True)
    evidence={'base':BASE,'head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip(),'tree':subprocess.check_output(['git','rev-parse','HEAD^{tree}'],cwd=ROOT,text=True).strip(),'scope':'Owned loopback FPM/MariaDB sampler, not authorized representative external two-tier staging','staging':'BLOCKED: no authorized external staging identified','load_limits':{'levels':[1,10],'outstanding':10,'scheduled_rps':8,'steady_seconds':8,'warmup':'one complete cycle, separately recorded','recovery_seconds':1,'request_timeout_seconds':8,'stop':'SQL/error, worker exit, pending-cap exhaustion or p99>=5000ms'},'runs':[]}
    if os.environ.get('CLASSROOM_PHASE3X')=='1':
        names=['tests/classroom-performance.py','tests/phase3x-suite.php','tests/phase3w-schema.php','tests/phase3w-phpunit-bootstrap.php','tests/Integration/AuthServiceTest.php','tests/Support/LocalOtpTransport.php','services/AuthService.php','security/forgot-password/OtpService.php','config.php']
        difference=subprocess.check_output(['git','diff',BASE,'--','services','API','database','assets','security','websocket','config.php'],cwd=ROOT,text=True)
        assert not subprocess.check_output(['git','diff',BASE,'--','services','API','database','assets','websocket','config.php'],cwd=ROOT,text=True), 'Unapproved application change'
        changed=subprocess.check_output(['git','diff','--name-only',BASE,'--','security'],cwd=ROOT,text=True).splitlines()
        assert changed==['security/forgot-password/OtpService.php'], changed
        evidence['scope']='Disposable full migrated-schema service integration; no HTTP/FPM workload, browser or external mail'
        evidence['load_limits']={'status':'NOT RUN: authentication contract phase, not a load measurement'}
        evidence['phase3x_source']={'intended_base':BASE,'intended_head':os.environ.get('PHASE3X_EXPECTED_HEAD','UNPUBLISHED'),'harness_commit':evidence['head'],'harness_tree':evidence['tree'],'harness_diff':subprocess.check_output(['git','diff','HEAD'],cwd=ROOT,text=True),'application_diff':difference,'sha256':{name:hashlib.sha256((ROOT/name).read_bytes()).hexdigest() for name in names}}
    if any(os.environ.get(k)=='1' for k in ['CLASSROOM_PHASE3V','CLASSROOM_PHASE3V_PROFILE','CLASSROOM_PHASE3V_REGRESSION_ONLY']):
        names=['tests/classroom-performance.py','tests/classroom-bootstrap.php','tests/classroom-fixture.sql','tests/fixtures/application-performance.sql','tests/phase3v-compare.py','tests/phase3v-sample.py','tests/phase3v-profile.php','tests/phase3v-regression.php','database/migrations/067_dashboard_unread_covering.sql']
        evidence['phase3v_driver_source']={'application_ref':BASE,'driver_commit':evidence['head'],'driver_tree':evidence['tree'],'driver_diff':subprocess.check_output(['git','diff','HEAD'],cwd=ROOT,text=True),'sha256':{name:hashlib.sha256((ROOT/name).read_bytes()).hexdigest() for name in names},'application_directory_diff':subprocess.check_output(['git','diff',BASE,'--','services','API','database','assets'],cwd=ROOT,text=True)}
        assert not evidence['phase3v_driver_source']['application_directory_diff'],'Phase3V is an investigation with accepted application source'
    if any(os.environ.get(k)=='1' for k in ['CLASSROOM_PHASE3W','CLASSROOM_PHASE3W_SCHEMA','CLASSROOM_PHASE3W_TELEMETRY']):
        names=['tests/dashboard-profile.php','tests/classroom-bootstrap.php','tests/classroom-performance.py','tests/classroom-fixture.sql','tests/fixtures/application-performance.sql','database/migrations/067_dashboard_unread_covering.sql']+[str(p.relative_to(ROOT)) for p in sorted((ROOT/'tests').glob('phase3w-*')) if p.is_file()]
        evidence['phase3w_driver_source']={'driver_commit':evidence['head'],'driver_tree':evidence['tree'],'driver_diff':subprocess.check_output(['git','diff','HEAD'],cwd=ROOT,text=True),'sha256':{name:hashlib.sha256((ROOT/name).read_bytes()).hexdigest() for name in names},'application_ref':BASE,'application_directory_diff':subprocess.check_output(['git','diff',BASE,'--','services','API','database','assets','security','websocket'],cwd=ROOT,text=True)}
        assert not subprocess.check_output(['git','diff',BASE,'--','services','API','database','assets','websocket'],cwd=ROOT,text=True), 'Unapproved application change'
        assert subprocess.check_output(['git','diff','--name-only',BASE,'--','security'],cwd=ROOT,text=True).splitlines()==['security/forgot-password/OtpService.php']
    with tempfile.TemporaryDirectory(prefix='ecollab-classroom-') as tmp:
        p=pathlib.Path(tmp);dbport=freeport();env=os.environ|{'APP_ENV':'testing','APP_DEBUG':'false','PEER_ML_ENABLED':'false','CENTRIFUGO_ENABLED':'false'}
        def sql(statement,fetch=False,params=None,bindings=None):
            code='$d=new PDO('+json.dumps(f'mysql:host=127.0.0.1;port={dbport};dbname=classroom_perf_test')+',"root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);'
            if env.get('CLASSROOM_FIXED_CLOCK'):code+='$d->exec("SET timestamp='+str(int(env['CLASSROOM_FIXED_CLOCK']))+'");'
            code+=('echo json_encode($d->query($argv[1])->fetchAll(PDO::FETCH_ASSOC));' if fetch else '$d->exec($argv[1]);')
            if params is not None:code=code[:code.index('echo json_encode')]+'$s=$d->prepare($argv[1]);$s->execute(json_decode($argv[2],true));echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));'
            if bindings is not None:
                code=code[:code.index('echo json_encode')]+"$s=$d->prepare($argv[1]);foreach(json_decode($argv[2],true)as$b){if(!($b['captured']??false))throw new RuntimeException('Uncaptured binding');$s->bindValue($b['parameter'],$b['value'],$b['pdo_type']);}$s->execute();echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));"
            r=subprocess.run([PHP,'-r',code,'--',statement]+([json.dumps(bindings)] if bindings is not None else ([json.dumps(params)] if params is not None else [])),env=env,capture_output=True,text=True,timeout=45);assert r.returncode==0,r.stderr+r.stdout
            return json.loads(r.stdout) if fetch else None
        args=[env.get('MARIADB_INSTALL_DB_BIN','mariadb-install-db'),'--no-defaults','--datadir='+str(p/'data'),'--auth-root-authentication-method=normal','--skip-test-db']
        if env.get('MARIADB_BASEDIR'):args+=['--basedir='+env['MARIADB_BASEDIR']]
        log=open(p/'database.log','w');subprocess.run(args,env=env,stdout=log,stderr=log,check=True,timeout=60)
        daemon_args=[env.get('MARIADBD_BIN','mariadbd'),'--no-defaults','--user='+pwd.getpwuid(os.geteuid()).pw_name,'--datadir='+str(p/'data'),'--bind-address=127.0.0.1','--port='+str(dbport),'--socket=','--pid-file='+str(p/'db.pid'),'--innodb-buffer-pool-size=134217728','--max-connections=32']
        if env.get('CLASSROOM_PHASE3X')=='1' or env.get('CLASSROOM_PHASE3W_SCHEMA')=='1':daemon_args+=['--default-time-zone=+00:00']
        daemon=subprocess.Popen(daemon_args,env=env,stdout=log,stderr=log)
        DB_PID=daemon.pid
        evidence['visible_resources']={'affinity_cpu_ids':sorted(os.sched_getaffinity(0)),'cgroup_cpu_max':read_limit('cpu.max'),'cgroup_memory_max':read_limit('memory.max')}
        fpm=None;server=None;app=ROOT;baseline_app=None
        try:
            for _ in range(100):
                assert daemon.poll() is None,(p/'database.log').read_text()
                r=subprocess.run([PHP,'-r',f'$d=new PDO("mysql:host=127.0.0.1;port={dbport}","root","");$d->exec("CREATE DATABASE classroom_perf_test");'],env=env,capture_output=True)
                if r.returncode==0:break
                time.sleep(.1)
            else:raise AssertionError('Database startup')
            if env.get('CLASSROOM_PHASE3X')=='1':
                import shutil
                baseline_app=p/'suite-base'
                subprocess.run(['git','worktree','add','--detach',str(baseline_app),BASE],cwd=ROOT,check=True,capture_output=True)
                shutil.copytree(ROOT/'vendor',baseline_app/'vendor')
                suite_env=env|{'CLASSROOM_DSN':f'mysql:host=127.0.0.1;port={dbport};dbname=classroom_perf_test','PHASE3X_BASE_SUITE':str(baseline_app)}
                r=subprocess.run([PHP,'-d','date.timezone=UTC',str(ROOT/'tests/phase3x-suite.php')],env=suite_env,capture_output=True,text=True,timeout=180)
                evidence['phase3x_suite']={'exit_code':r.returncode,'stdout':r.stdout,'stderr':r.stderr}
                assert r.returncode==0,'Phase3X suite failed; inspect retained evidence'
                return
            if env.get('CLASSROOM_PHASE3W_SCHEMA')=='1':
                suite_env={}
                if env.get('PHASE3W_FULL_PHPUNIT')=='1':
                    import shutil
                    baseline_app=p/'suite-base';subprocess.run(['git','worktree','add','--detach',str(baseline_app),BASE],cwd=ROOT,check=True,capture_output=True)
                    shutil.copytree(ROOT/'vendor',baseline_app/'vendor')
                    composer=env.get('COMPOSER_BIN') or shutil.which('composer');assert composer
                    subprocess.run([PHP,composer,'dump-autoload','--no-interaction'],cwd=baseline_app,env=env,check=True,capture_output=True)
                    suite_env['PHASE3W_SUITE_BASE']=str(baseline_app)
                r=subprocess.run([PHP,str(ROOT/'tests/phase3w-schema.php')],env=env|suite_env|{'CLASSROOM_DSN':f'mysql:host=127.0.0.1;port={dbport};dbname=classroom_perf_test'},capture_output=True,text=True,timeout=180)
                evidence['phase3w_schema']={'exit_code':r.returncode,'stdout':r.stdout,'stderr':r.stderr}
                assert r.returncode==0,r.stderr+r.stdout
                return
            if env.get('CLASSROOM_PHASE3W')=='1' and env.get('PHASE3W_SCHEMA')=='full':
                r=subprocess.run([PHP,str(ROOT/'tests/phase3w-full-fixture.php')],env=env|{'CLASSROOM_DSN':f'mysql:host=127.0.0.1;port={dbport};dbname=classroom_perf_test'},capture_output=True,text=True,timeout=180)
                evidence['phase3w_full_fixture']={'exit_code':r.returncode,'stdout':r.stdout,'stderr':r.stderr}
                assert r.returncode==0,r.stderr+r.stdout
            else:
                for name in ['tests/fixtures/application-performance.sql','database/migrations/013_peer_matching.sql','tests/classroom-fixture.sql','database/migrations/058_chat_read_cursors.sql']:sql((ROOT/name).read_text())
            evidence['database_version']=sql('SELECT VERSION() version',True)
            if env.get('CLASSROOM_PHASE3W_TELEMETRY')=='1':
                r=subprocess.run([PHP,str(ROOT/'tests/phase3w-telemetry-regression.php')],env=env|{'CLASSROOM_DSN':f'mysql:host=127.0.0.1;port={dbport};dbname=classroom_perf_test'},capture_output=True,text=True,timeout=90)
                evidence['phase3w_telemetry']={'exit_code':r.returncode,'stdout':r.stdout,'stderr':r.stderr}
                assert r.returncode==0,r.stderr+r.stdout
                return
            if env.get('CLASSROOM_PHASE3V_REGRESSION_ONLY')=='1':
                old_service=p/'student-base.php';old_service.write_bytes(subprocess.check_output(['git','show',BASE+':services/StudentDashboardService.php'],cwd=ROOT))
                r=subprocess.run([PHP,str(ROOT/'tests/phase3v-regression.php')],env=env|{'CLASSROOM_DSN':f'mysql:host=127.0.0.1;port={dbport};dbname=classroom_perf_test','PHASE3V_BASELINE_SERVICE':str(old_service)},capture_output=True,text=True,timeout=120)
                evidence['regression']={'exit_code':r.returncode,'stdout':r.stdout,'stderr':r.stderr}
                assert r.returncode==0,r.stderr+r.stdout
                return
            if env.get('CLASSROOM_PHASE3V_PROFILE')=='1':
                r=subprocess.run([PHP,str(ROOT/'tests/phase3v-profile.php')],env=env|{'CLASSROOM_DSN':f'mysql:host=127.0.0.1;port={dbport};dbname=classroom_perf_test'},capture_output=True,text=True,timeout=180)
                evidence['phase3v_profile']={'exit_code':r.returncode,'stdout':r.stdout,'stderr':r.stderr}
                assert r.returncode==0,r.stderr+r.stdout
                return
            if env.get('CLASSROOM_PHASE3U_PLAN_CONTROL')=='1':
                old_service=p/'workspace-control-base.php';old_service.write_bytes(subprocess.check_output(['git','show',BASE+':services/LearningWorkspaceService.php'],cwd=ROOT))
                trial_service=p/'workspace-rejected-trial.php';trial_service.write_bytes(subprocess.check_output(['git','show','5366f48f1a128aa40077bf01e938157202759911:services/LearningWorkspaceService.php'],cwd=ROOT))
                r=subprocess.run([PHP,str(ROOT/'tests/phase3u-plan-control.php')],env=env|{'CLASSROOM_DSN':f'mysql:host=127.0.0.1;port={dbport};dbname=classroom_perf_test','PHASE3U_BASELINE_SERVICE':str(old_service),'PHASE3U_TRIAL_SERVICE':str(trial_service)},capture_output=True,text=True,timeout=180)
                evidence['plan_control']={'exit_code':r.returncode,'stdout':r.stdout,'stderr':r.stderr}
                assert r.returncode==0,r.stderr+r.stdout
                return
            if env.get('CLASSROOM_PHASE3U_REGRESSION_ONLY')=='1':
                old_service=p/'workspace-base.php';old_service.write_bytes(subprocess.check_output(['git','show',BASE+':services/LearningWorkspaceService.php'],cwd=ROOT))
                r=subprocess.run([PHP,str(ROOT/'tests/phase3u-regression.php')],env=env|{'CLASSROOM_DSN':f'mysql:host=127.0.0.1;port={dbport};dbname=classroom_perf_test','PHASE3U_BASELINE_SERVICE':str(old_service)},capture_output=True,text=True,timeout=120)
                evidence['regression']={'exit_code':r.returncode,'stdout':r.stdout,'stderr':r.stderr}
                assert r.returncode==0,r.stderr+r.stdout
                return
            if env.get('CLASSROOM_PHASE3U_DISCOVERY')=='1':
                old_service=p/'workspace-discovery-base.php';old_service.write_bytes(subprocess.check_output(['git','show',BASE+':services/LearningWorkspaceService.php'],cwd=ROOT))
                r=subprocess.run([PHP,str(ROOT/'tests/phase3u-discovery.php')],env=env|{'CLASSROOM_DSN':f'mysql:host=127.0.0.1;port={dbport};dbname=classroom_perf_test','PHASE3U_BASELINE_SERVICE':str(old_service)},capture_output=True,text=True,timeout=120)
                evidence['discovery']={'exit_code':r.returncode,'stdout':r.stdout,'stderr':r.stderr}
                assert r.returncode==0,r.stderr+r.stdout
                return
            if env.get('CLASSROOM_PHASE3T_REGRESSION_ONLY')=='1':
                old_service=p/'primary-base.php';old_service.write_bytes(subprocess.check_output(['git','show',BASE+':services/FacilitatorDashboardService.php'],cwd=ROOT))
                r=subprocess.run([PHP,str(ROOT/'tests/phase3t-regression.php')],env=env|{'CLASSROOM_DSN':f'mysql:host=127.0.0.1;port={dbport};dbname=classroom_perf_test','PHASE3T_BASELINE_SERVICE':str(old_service)},capture_output=True,text=True,timeout=120)
                evidence['regression']={'exit_code':r.returncode,'stdout':r.stdout,'stderr':r.stderr}
                assert r.returncode==0,r.stderr+r.stdout
                return
            if env.get('CLASSROOM_PHASE3T_DISCOVERY')=='1':
                discovery_base=p/'discovery-base.php';discovery_base.write_bytes(subprocess.check_output(['git','show',BASE+':services/FacilitatorDashboardService.php'],cwd=ROOT))
                r=subprocess.run([PHP,str(ROOT/'tests/phase3t-discovery.php')],env=env|{'CLASSROOM_DSN':f'mysql:host=127.0.0.1;port={dbport};dbname=classroom_perf_test','PHASE3T_BASELINE_SERVICE':str(discovery_base)},capture_output=True,text=True,timeout=90)
                evidence['discovery']={'exit_code':r.returncode,'stdout':r.stdout,'stderr':r.stderr}
                assert r.returncode==0,r.stderr
                return
            if env.get('CLASSROOM_PROFILE','1')=='1':
                baseline=p/'baseline-workspace.php';source=subprocess.check_output(['git','show',BASE+':services/LearningWorkspaceService.php'],cwd=ROOT,text=True).replace('class LearningWorkspaceService','class BaselineLearningWorkspaceService').replace("__DIR__.'/../database/config/db.php'",repr(str(ROOT/'database/config/db.php'))).replace("__DIR__.'/RealtimeOutbox.php'",repr(str(ROOT/'services/RealtimeOutbox.php')));baseline.write_text(source)
                profile_env=env|{'CLASSROOM_DSN':f'mysql:host=127.0.0.1;port={dbport};dbname=classroom_perf_test','CLASSROOM_BASELINE_SERVICE':str(baseline)}
                with open(target.parent/'profile-progress.log','w') as progress:
                    prof=subprocess.run([PHP,str(ROOT/'tests/classroom-facilitator-profile.php')],env=profile_env,cwd=ROOT,stdout=subprocess.PIPE,stderr=progress,text=True,timeout=180)
                assert prof.returncode==0,(target.parent/'profile-progress.log').read_text()
                evidence['facilitator_equivalence']=json.loads(prof.stdout)
                print('FACILITATOR_PROFILE_SUMMARY '+json.dumps({name:{side:statistics.median(r['ms'] for r in runs) for side,runs in case['runs'].items()} for name,case in evidence['facilitator_equivalence'].items()}),flush=True)
                # Discard only the harness-created schema so comparison mutations/MVCC do not seed HTTP samples.
                sql('DROP DATABASE classroom_perf_test;CREATE DATABASE classroom_perf_test')
                for name in ['tests/fixtures/application-performance.sql','database/migrations/013_peer_matching.sql','tests/classroom-fixture.sql','database/migrations/058_chat_read_cursors.sql']:sql((ROOT/name).read_text())
            evidence['php_cli_version']=subprocess.check_output([PHP,'-v'],env=env,text=True).splitlines()[0]
            selected_ref=env.get('CLASSROOM_APP_REF') or (BASE if env.get('CLASSROOM_VARIANT')=='baseline' else None)
            if selected_ref:
                baseline_app=p/'application-worktree';subprocess.run(['git','worktree','add','--detach',str(baseline_app),selected_ref],cwd=ROOT,check=True,stdout=subprocess.DEVNULL);app=baseline_app
            evidence['variant']=env.get('CLASSROOM_VARIANT','candidate')
            evidence['source_provenance']={
                'intended_base':BASE,'intended_head':env.get('CLASSROOM_INTENDED_HEAD'),
                'application_commit':subprocess.check_output(['git','rev-parse','HEAD'],cwd=app,text=True).strip(),
                'application_tree':subprocess.check_output(['git','rev-parse','HEAD^{tree}'],cwd=app,text=True).strip(),
                'application_diff':subprocess.check_output(['git','diff','HEAD'],cwd=app,text=True),
                'harness_diff':subprocess.check_output(['git','diff','HEAD'],cwd=ROOT,text=True),
                'harness_commit':subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip(),
                'harness_tree':subprocess.check_output(['git','rev-parse','HEAD^{tree}'],cwd=ROOT,text=True).strip(),
                'harness_sha256':{name:hashlib.sha256((ROOT/name).read_bytes()).hexdigest() for name in ['tests/classroom-performance.py','tests/classroom-bootstrap.php']+(['tests/phase3t-sample.py','tests/phase3t-compare.py','tests/phase3t-regression.php'] if (env.get('CLASSROOM_PHASE3T')=='1' or env.get('CLASSROOM_PHASE3U')=='1' or env.get('CLASSROOM_PHASE3V')=='1') else [])},
                'uncommitted_substitutions':[]}
            if env.get('CLASSROOM_PHASE3U')=='1' or env.get('CLASSROOM_PHASE3V')=='1':
                evidence['source_provenance']['harness_sha256'].update({name:hashlib.sha256((ROOT/name).read_bytes()).hexdigest() for name in (['tests/phase3v-compare.py','tests/phase3v-regression.php','tests/phase3v-sample.py'] if env.get('CLASSROOM_PHASE3V')=='1' else ['tests/phase3u-compare.py','tests/phase3u-regression.php'])})
            if selected_ref:assert not evidence['source_provenance']['application_diff'],'Application overlay forbidden for immutable comparisons'
            if (env.get('CLASSROOM_PHASE3T')=='1' or env.get('CLASSROOM_PHASE3U')=='1' or env.get('CLASSROOM_PHASE3V')=='1'):
                evidence['head']=evidence['source_provenance']['application_commit'];evidence['tree']=evidence['source_provenance']['application_tree']
            if (env.get('CLASSROOM_PHASE3T')=='1' or env.get('CLASSROOM_PHASE3U')=='1' or env.get('CLASSROOM_PHASE3V')=='1'):evidence['load_limits']={'type':'bounded mixed read bursts and fixed-arrival reads','levels':[25,100],'steady_seconds':int(env.get('PHASE3V_STEADY_SECONDS' if env.get('CLASSROOM_PHASE3V')=='1' else 'PHASE3T_STEADY_SECONDS','10')),'maximum_inflight':100,'request_timeout_seconds':8,'stop':'SQL/error, missing metric, worker exit or individual request >=7.5s'}
            evidence['phase3s_application_sha256']={name:hashlib.sha256((app/name).read_bytes()).hexdigest() for name in ['services/FacilitatorDashboardService.php','services/LearningWorkspaceService.php','API/chat/peer-match.php']}
            evidence['working_tree_diff_sha256']=hashlib.sha256(subprocess.check_output(['git','diff','HEAD'],cwd=ROOT)).hexdigest()
            evidence['application_file_sha256']=hashlib.sha256((app/('services/StudentDashboardService.php' if env.get('CLASSROOM_PHASE3V')=='1' or env.get('CLASSROOM_PHASE3W')=='1' else 'services/LearningWorkspaceService.php')).read_bytes()).hexdigest()
            sock=freeport();statussock=freeport();port=freeport();folder=p/'http';folder.mkdir();(folder/'requests.jsonl').touch()
            fenv=env|{'CLASSROOM_ROOT':str(app),'CLASSROOM_FOLDER':str(folder),'CLASSROOM_DSN':f'mysql:host=127.0.0.1;port={dbport};dbname=classroom_perf_test','APP_URL':f'http://127.0.0.1:{port}'}
            config=p/'fpm.conf';config.write_text(f'''[global]
error_log = {p}/fpm.log
daemonize = no
[fixture]
listen = 127.0.0.1:{sock}
user = root
group = root
pm = dynamic
pm.max_children = 10
pm.start_servers = 6
pm.min_spare_servers = 4
pm.max_spare_servers = 6
pm.status_path = /fpm-status
pm.status_listen = 127.0.0.1:{statussock}
clear_env = no
catch_workers_output = yes
php_admin_value[session.save_path] = {folder}
php_admin_value[auto_prepend_file] = {ROOT}/tests/classroom-bootstrap.php
php_admin_value[display_errors] = 0
php_admin_value[log_errors] = 1
php_admin_flag[opcache.enable] = {'on' if env.get('CLASSROOM_OPCACHE','on')=='on' else 'off'}
php_admin_value[opcache.file_update_protection] = 0
''')
            evidence['fpm_config']=config.read_text();evidence['fpm_config_sha256']=hashlib.sha256(config.read_bytes()).hexdigest()
            flog=open(p/'fpm-stdio.log','w');fpm_args=[env.get('PHP_FPM_BIN','php-fpm8.3'),'-F','-R','-y',str(config),'-d','opcache.enable='+('1' if env.get('CLASSROOM_OPCACHE','on')=='on' else '0')]
            if env.get('PHASE3T_OPCACHE_EXTENSION'):fpm_args+=['-d','zend_extension='+env['PHASE3T_OPCACHE_EXTENSION']]
            fpm=subprocess.Popen(fpm_args,env=fenv,stdout=flog,stderr=flog)
            for _ in range(100):
                assert fpm.poll() is None,(p/'fpm-stdio.log').read_text()+(p/'fpm.log').read_text()
                try:
                    with socket.create_connection(('127.0.0.1',sock),timeout=.1):break
                except OSError:pass
                time.sleep(.05)
            class Gateway(http.server.BaseHTTPRequestHandler):
                def do_GET(self):self.handle_request()
                def do_POST(self):self.handle_request()
                def handle_request(self):
                    route=self.path.split('?')[0]
                    if route.startswith('/assets/'):
                        file=(app/route.lstrip('/')).resolve()
                        if not file.is_relative_to(app/'assets') or not file.is_file():self.send_error(404);return
                        import mimetypes
                        data=file.read_bytes();self.send_response(200);self.send_header('Content-Type',mimetypes.guess_type(str(file))[0] or 'application/octet-stream');self.end_headers();self.wfile.write(data);return
                    file=(app/route.lstrip('/')).resolve()
                    if route in ['/fixture/seed','/fpm-status']:file=ROOT/'tests/classroom-bootstrap.php'
                    if not file.is_relative_to(app) and route not in ['/fixture/seed','/fpm-status']:self.send_error(404);return
                    if not (route.startswith('/API/') or route in ['/fixture/seed','/fpm-status','/modules/student/dashboard.php','/modules/facilitator/dashboard.php']) or not file.is_file():self.send_error(404);return
                    body=self.rfile.read(int(self.headers.get('Content-Length','0')))
                    params={'SCRIPT_FILENAME':str(file),'SCRIPT_NAME':route,'REQUEST_URI':self.path,'QUERY_STRING':self.path.partition('?')[2],'REQUEST_METHOD':self.command,'SERVER_PROTOCOL':'HTTP/1.1','SERVER_NAME':'127.0.0.1','SERVER_PORT':port,'HTTP_HOST':f'127.0.0.1:{port}','REMOTE_ADDR':'127.0.0.1','DOCUMENT_ROOT':str(app),'CONTENT_TYPE':self.headers.get('Content-Type',''),'CONTENT_LENGTH':len(body),'HTTP_COOKIE':self.headers.get('Cookie',''),'HTTP_X_CSRF_TOKEN':self.headers.get('X-CSRF-Token',''),'HTTP_X_FIXTURE_REQUEST':self.headers.get('X-Fixture-Request','browser')}
                    try:
                        raw,err=fcgi(statussock if route=='/fpm-status' else sock,params,body);head,_,data=raw.partition(b'\r\n\r\n');headers=[line.decode().split(':',1) for line in head.split(b'\r\n') if b':' in line];code=next((int(v.strip().split()[0]) for k,v in headers if k.lower()=='status'),200)
                        self.send_response(code)
                        for k,v in headers:
                            if k.lower()!='status':self.send_header(k,v.strip())
                        self.end_headers();self.wfile.write(data)
                    except Exception:self.send_error(502)
                def log_message(self,*a):pass
            class OwnedHTTPServer(http.server.ThreadingHTTPServer):
                request_queue_size=128
            server=OwnedHTTPServer(('127.0.0.1',port),Gateway);threading.Thread(target=server.serve_forever,daemon=True).start()
            cookies={}
            for uid in list(range(1,101))+[900]:
                r=request(port,'/fixture/seed?uid='+str(uid));assert r['status']==200,(r['status'],r['body']);cookies[uid]=r['cookie'].split(';')[0];evidence['runtime']=json.loads(r['body'])
            evidence['fpm_status_initial']=json.loads(request(port,'/fpm-status?json&full')['body'])
            if env.get('CLASSROOM_PHASE3W')=='1':
                import importlib.util
                assert evidence['runtime']['opcache_enabled']==(env.get('CLASSROOM_OPCACHE')=='on')
                spec=importlib.util.spec_from_file_location('write_sample',ROOT/'tests/phase3w-sample.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
                evidence['phase3w']=module.run(port,cookies,folder,sql,request,process_usage,DB_PID,fenv,target,operation)
                evidence['fpm_status_final']=json.loads(request(port,'/fpm-status?json&full')['body'])
                return
            if (env.get('CLASSROOM_PHASE3T')=='1' or env.get('CLASSROOM_PHASE3U')=='1' or env.get('CLASSROOM_PHASE3V')=='1'):
                assert evidence['runtime']['opcache_enabled']==(env.get('CLASSROOM_OPCACHE')=='on'),'OPcache state not as requested'
                import importlib.util
                spec=importlib.util.spec_from_file_location('read_sample',ROOT/('tests/phase3v-sample.py' if env.get('CLASSROOM_PHASE3V')=='1' else 'tests/phase3t-sample.py'));module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
                evidence['phase3v' if env.get('CLASSROOM_PHASE3V')=='1' else ('phase3u' if env.get('CLASSROOM_PHASE3U')=='1' else 'phase3t')]=module.run(port,cookies,folder,sql,request,process_usage,DB_PID,env,target,operation)
                if env.get('CLASSROOM_PHASE3V')=='1':
                    old_service=p/'student-base.php';old_service.write_bytes(subprocess.check_output(['git','show',BASE+':services/StudentDashboardService.php'],cwd=ROOT))
                    regression=subprocess.run([PHP,str(ROOT/'tests/phase3v-regression.php')],env=fenv|{'PHASE3V_BASELINE_SERVICE':str(old_service)},capture_output=True,text=True,timeout=120)
                    evidence['phase3v_regression']={'exit_code':regression.returncode,'stdout':regression.stdout,'stderr':regression.stderr}
                    assert regression.returncode==0,regression.stderr+regression.stdout
                elif env.get('CLASSROOM_PHASE3U')=='1':
                    old_service=p/'workspace-base.php';old_service.write_bytes(subprocess.check_output(['git','show',BASE+':services/LearningWorkspaceService.php'],cwd=ROOT))
                    regression=subprocess.run([PHP,str(ROOT/'tests/phase3u-regression.php')],env=fenv|{'PHASE3U_BASELINE_SERVICE':str(old_service)},capture_output=True,text=True,timeout=120)
                    evidence['phase3u_regression']={'exit_code':regression.returncode,'stdout':regression.stdout,'stderr':regression.stderr}
                    assert regression.returncode==0,regression.stderr+regression.stdout
                elif env.get('PHASE3T_HISTORY_CONTROL')!='1':
                    old_service=p/'facilitator-base.php';old_service.write_bytes(subprocess.check_output(['git','show',BASE+':services/FacilitatorDashboardService.php'],cwd=ROOT))
                    regression=subprocess.run([PHP,str(ROOT/'tests/phase3t-regression.php')],env=fenv|{'PHASE3T_BASELINE_SERVICE':str(old_service)},capture_output=True,text=True,timeout=120)
                    evidence['phase3t_regression']={'exit_code':regression.returncode,'stdout':regression.stdout,'stderr':regression.stderr}
                    assert regression.returncode==0,regression.stderr+regression.stdout
                else:evidence['phase3t_regression']={'status':'NOT_RUN','reason':'Historical control validates exact HTTP refs; Phase3T native cases are in the main eight runs'}
            elif env.get('CLASSROOM_PHASE3S')=='1':
                import importlib.util
                spec=importlib.util.spec_from_file_location('phase3s_sample',ROOT/'tests/phase3s-sample.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
                evidence['phase3s']=module.run(port,cookies,folder,sql,request,process_usage,DB_PID,env,target,operation)
                old_service=p/'facilitator-base.php';old_service.write_bytes(subprocess.check_output(['git','show',BASE+':services/FacilitatorDashboardService.php'],cwd=ROOT))
                regression=subprocess.run([PHP,str(ROOT/'tests/phase3s-regression.php')],env=fenv|{'PHASE3S_BASELINE_SERVICE':str(old_service)},capture_output=True,text=True,timeout=90)
                evidence['phase3s_regression']={'exit_code':regression.returncode,'stdout':regression.stdout,'stderr':regression.stderr}
                assert regression.returncode==0,regression.stderr+regression.stdout
            else:
                for index in [False,True]:
                    if index:
                        import importlib.util
                        spec=importlib.util.spec_from_file_location('index_fixture',ROOT/'tests/classroom-index.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
                        evidence['index_cost']=module.measure(ROOT,p,env,PHP,dbport,sql)
                        print('INDEX_COST '+json.dumps(evidence['index_cost']),flush=True)
                    for mix in ['read','write','mixed']:
                        for users in [1,10]:
                            label=('indexed' if index else 'unindexed')+'-'+mix+'-'+str(users)
                            before={r['Variable_name']:int(r['Value']) for r in sql("SHOW GLOBAL STATUS WHERE Variable_name IN ('Questions','Innodb_row_lock_waits','Innodb_row_lock_time','Created_tmp_disk_tables','Innodb_buffer_pool_reads','Innodb_buffer_pool_read_requests','Innodb_buffer_pool_wait_free','Com_insert','Com_update')",True)}
                            run=sample(port,cookies,folder,label,users,mix,seconds=int(env.get('CLASSROOM_SECONDS','8')))
                            after={r['Variable_name']:int(r['Value']) for r in sql("SHOW GLOBAL STATUS WHERE Variable_name IN ('Questions','Innodb_row_lock_waits','Innodb_row_lock_time','Created_tmp_disk_tables','Innodb_buffer_pool_reads','Innodb_buffer_pool_read_requests','Innodb_buffer_pool_wait_free','Com_insert','Com_update')",True)}
                            run['database_counter_delta']={k:after[k]-before[k] for k in before};evidence['runs'].append(run)
                            print('CLASSROOM_RUN '+json.dumps({k:v for k,v in run.items() if k not in ['requests','warmup','status_samples','top_sql']}),flush=True)
                            target.write_text(json.dumps(evidence,indent=2)+'\n');time.sleep(1)
                if env.get('CLASSROOM_RUN_WORKERS')=='1':
                    import importlib.util
                    sql('CREATE DATABASE chat_perf_test')
                    spec=importlib.util.spec_from_file_location('actual_workers',ROOT/'tests/chat-worker-profile.py');worker=importlib.util.module_from_spec(spec);spec.loader.exec_module(worker)
                    with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
                        job=pool.submit(worker.run,ROOT,p,env|{'WORKER_PROFILE_IDLE_SECONDS':'15'},PHP,dbport)
                        evidence['worker_overlap_application']=sample(port,cookies,folder,'worker-overlap',10,'mixed',seconds=30)
                        evidence['workers_with_application']=job.result(timeout=45)
                    print('CLASSROOM_WORKER_OVERLAP '+json.dumps(evidence['workers_with_application']),flush=True)
                    # Global Questions counters include application/observers during overlap: not isolated worker QPS.
                if env.get('CLASSROOM_RUN_BROWSER')=='1':
                    browser_env=env|{'CLASSROOM_BROWSER_URL':f'http://127.0.0.1:{port}','CLASSROOM_BROWSER_EVIDENCE':str(target.parent/'classroom-browser.json')}
                    subprocess.run(['node',str(ROOT/'tests/classroom-browser.cjs')],env=browser_env,cwd=ROOT,check=True,timeout=180)
                    evidence['browser']=json.loads((target.parent/'classroom-browser.json').read_text())
            evidence['fpm_status_final']=json.loads(request(port,'/fpm-status?json&full')['body'])
        finally:
            if server:server.shutdown()
            if fpm:fpm.terminate();fpm.wait(timeout=8)
            daemon.terminate();daemon.wait(timeout=15)
            if (p/'failed-run.json').exists():evidence['failed_run']=json.loads((p/'failed-run.json').read_text())
            evidence['fpm_log']=(p/'fpm.log').read_text() if (p/'fpm.log').exists() else ''
            target.write_text(json.dumps(evidence,indent=2)+'\n')
            if baseline_app:subprocess.run(['git','worktree','remove','--force',str(baseline_app)],cwd=ROOT,check=True)

if __name__=='__main__':main()
