"""Called only inside the owned MariaDB runner. Exact baseline service and HTTP snapshots."""
import hashlib,json,os,pathlib,signal,socket,statistics,subprocess,time
from http.client import HTTPConnection

def exercise(root,p,env,php,dbsql):
    baseline=p/'baseline-app'
    subprocess.run(['git','worktree','add','--detach',str(baseline),'87e82284ced2cee4858b673cc5a8619151c019bb'],cwd=root,check=True,stdout=subprocess.DEVNULL)
    servers=[]
    def request(port,path,cookie=''):
        c=HTTPConnection('127.0.0.1',port,timeout=15);start=time.perf_counter();c.request('GET',path,headers={'Cookie':cookie});r=c.getresponse();raw=r.read();result=(r.status,raw,r.getheader('Set-Cookie'),(time.perf_counter()-start)*1000);c.close();return result
    def start(app):
        with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
        folder=p/('http-'+app.name);folder.mkdir();e=env|{'DASHBOARD_APP_ROOT':str(app),'DASHBOARD_PERF_FOLDER':str(folder),'APP_URL':f'http://127.0.0.1:{port}','APP_DEBUG':'false','PEER_ML_ENABLED':'false'}
        log=open(folder/'web.log','w');proc=subprocess.Popen([php,'-d','display_errors=0','-d','log_errors=1','-d','session.save_path='+str(folder),'-S',f'127.0.0.1:{port}','-t',str(app),str(root/'tests/dashboard-http-router.php')],env=e,cwd=app,stdout=log,stderr=log,start_new_session=True);servers.append(proc)
        for _ in range(100):
            assert proc.poll() is None,(folder/'web.log').read_text()
            try:r=request(port,'/fixture/seed');break
            except OSError:time.sleep(.05)
        else:raise AssertionError('Fixture PHP unavailable')
        return {'port':port,'cookie':r[2].split(';')[0],'folder':folder,'url':f'http://127.0.0.1:{port}'}
    def get(app,path,cookie=None):
        r=request(app['port'],path,cookie or app['cookie']);assert r[0]==200,(r[0],r[1]);data=json.loads(r[1]);assert data['success'];metrics=json.loads((app['folder']/'http-metrics.jsonl').read_text().splitlines()[-1]);assert all(q['success'] for q in metrics['queries']),metrics
        metrics|={'http_ms':r[3],'payload_sha256':hashlib.sha256(r[1]).hexdigest()};return data,metrics
    try:
        before=start(baseline);after=start(root);http={'baseline':[],'query_only':[],'indexed':[]};responses={}
        # Query-only comparison before adding the index; then compare identical indexed data.
        for i in range(7):
            for name,app in [('baseline',before),('query_only',after)]:
                data,m=get(app,'/API/dashboard/live.php');http[name].append(m);responses[name]=data['data']
        assert responses['baseline']==responses['query_only'],'Whole HTTP dashboard payload changed'
        migration=(root/'database/migrations/067_dashboard_unread_covering.sql').read_text();dbsql(migration);dbsql(migration)
        for i in range(7):
            data,m=get(after,'/API/dashboard/live.php');http['indexed'].append(m);assert data['data']==responses['baseline']
        # Same authenticated endpoint must reflect subsequent changes and another user's data.
        dbsql('INSERT INTO study_sessions VALUES(10001,1,60,NOW())');fresh,_=get(after,'/API/dashboard/live.php');assert fresh['data']['total_sessions']==responses['baseline']['total_sessions']+1;dbsql('DELETE FROM study_sessions WHERE id=10001')
        seed=request(after['port'],'/fixture/seed?uid=2');other,_=get(after,'/API/dashboard/live.php',seed[2].split(';')[0]);assert other['data']['courses']!=responses['baseline']['courses'];request(after['port'],'/fixture/seed?uid=1')
        workspace={}
        for name,app in [('baseline',before),('indexed',after)]:
            data,m=get(app,'/API/student/workspace.php?mode=student');assert all(v['available'] for k,v in data['data'].items() if k!='limits'),data;workspace[name]=m
        evidence={'http':http,'workspace':workspace,'scope':'Sequential loopback PHP built-in HTTP; actual endpoint copies differ only by json_encode timing wrapper, actual services/auth/queries. Not FPM/concurrency/capacity.'}
        if os.environ.get('DASHBOARD_RUN_BROWSER')=='1':
            browser_env=env|{'DASHBOARD_BEFORE_URL':before['url'],'DASHBOARD_AFTER_URL':after['url'],'DASHBOARD_BROWSER_EVIDENCE':str(p/'browser.json')}
            subprocess.run(['node',str(root/'tests/dashboard-browser.cjs')],env=browser_env,cwd=root,check=True,timeout=120)
            evidence['browser']=json.loads((p/'browser.json').read_text())
        else:evidence['browser']={'status':'BLOCKED locally: Chromium artifact download truncated; CI executes real browser.'}
        return evidence
    finally:
        for proc in servers:
            os.killpg(proc.pid,signal.SIGTERM);proc.wait(timeout=5)
        subprocess.run(['git','worktree','remove','--force',str(baseline)],cwd=root,check=True)
