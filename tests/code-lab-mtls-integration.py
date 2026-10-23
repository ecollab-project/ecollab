"""Disposable real Nginx mTLS -> real adapter -> mock backend, or explicit CI Piston.
No student source is executed locally. Private keys never leave this temp directory.
"""
import base64, http.client, importlib.util, ipaddress, json, os, pathlib, pwd, shutil, socket, ssl, subprocess, tempfile, threading, time
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BIN','php');NGINX=os.environ.get('NGINX_BIN') or shutil.which('nginx')
assert NGINX, 'Actual Nginx required; do not silently skip mTLS tests'
spec=importlib.util.spec_from_file_location('bridge',ROOT/'deploy/code-lab/piston/bridge.py')
bridge=importlib.util.module_from_spec(spec);spec.loader.exec_module(bridge)
def free_port():
    with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]
with tempfile.TemporaryDirectory(prefix='code-lab-mtls-') as folder:
    p=pathlib.Path(folder);os.chmod(p,0o700)
    def openssl(*args):
        r=subprocess.run(['openssl',*args],cwd=p,capture_output=True,text=True,timeout=20)
        assert r.returncode==0, 'Disposable certificate generation failed: '+r.stderr[-300:]
    for ca in ['server-ca','client-ca','other-ca']:
        openssl('req','-x509','-newkey','rsa:2048','-nodes','-days','2','-subj','/CN=fixture-'+ca,'-keyout',ca+'.key','-out',ca+'.crt','-addext','basicConstraints=critical,CA:TRUE','-addext','keyUsage=critical,keyCertSign,cRLSign')
    openssl('req','-new','-newkey','rsa:2048','-nodes','-subj','/CN=fixture-server','-keyout','server.key','-out','server.csr')
    (p/'server.ext').write_text('subjectAltName=IP:127.0.0.1\nextendedKeyUsage=serverAuth\nbasicConstraints=CA:FALSE\n')
    openssl('x509','-req','-in','server.csr','-CA','server-ca.crt','-CAkey','server-ca.key','-CAcreateserial','-days','1','-extfile','server.ext','-out','server.crt')
    (p/'index.txt').write_text('');(p/'serial').write_text('1000\n');(p/'crlnumber').write_text('1000\n');(p/'certs').mkdir()
    (p/'ca.conf').write_text(f'''[ca]
default_ca=authority
[authority]
database={p}/index.txt
serial={p}/serial
crlnumber={p}/crlnumber
new_certs_dir={p}/certs
certificate={p}/client-ca.crt
private_key={p}/client-ca.key
default_md=sha256
default_days=1
default_crl_days=1
policy=policy
unique_subject=no
[policy]
commonName=supplied
[client]
basicConstraints=CA:FALSE
extendedKeyUsage=clientAuth
keyUsage=digitalSignature,keyEncipherment
''')
    for name in ['client','rotated']:
        openssl('req','-new','-newkey','rsa:2048','-nodes','-subj','/CN=fixture-'+name,'-keyout',name+'.key','-out',name+'.csr')
        openssl('ca','-batch','-config','ca.conf','-extensions','client','-in',name+'.csr','-out',name+'.crt')
    openssl('ca','-gencrl','-config','ca.conf','-out','client-ca.crl')
    for key in p.glob('*.key'):key.chmod(0o600)
    upstream=os.environ.get('CODE_LAB_TLS_UPSTREAM')
    fixture=None;seen=[]
    if upstream:
        assert os.environ.get('GITHUB_ACTIONS')=='true' and upstream==os.environ['CODE_LAB_ADAPTER_IPV4']+':2358', 'Only explicitly owned CI adapter supported'
        assert ipaddress.ip_address(os.environ['CODE_LAB_ADAPTER_IPV4']).is_private
        config=json.loads(pathlib.Path(os.environ['CODE_LAB_RUNNER_STATE'],'profiles.json').read_text())
    else:
        config={'token':'fixture-bearer-'*5,'profiles':[{'id':10001+i,'name':name,'language':lang,'filename':filename,'version':'1.2.3'} for i,(name,lang,filename) in enumerate([('C','c','main.c'),('C++','c++','main.cpp'),('Python','python','main.py'),('Java','java','Main.java'),('JavaScript','javascript','main.js'),('Compiled Java','ecollab-java-compiled','Main.java')])]}
        def backend(path,body):
            seen.append((path,body))
            if path=='/ecollab-grade':return {'runs':[{'code':0,'stdout':'42'} for _ in body['inputs']],'compile_count':0 if body['language']=='python' else 1}
            return {'run':{'code':0,'stdout':'42','cpu_time':1}}
        fixture=bridge.Server(('127.0.0.1',0),bridge.Adapter(config,backend));threading.Thread(target=fixture.serve_forever,daemon=True).start()
        upstream='127.0.0.1:'+str(fixture.server_port)
    port=free_port();template=(ROOT/'deploy/code-lab/isolated/nginx.conf.example').read_text()
    rendered=template.replace('__PRIVATE_ADDRESS__','127.0.0.1:'+str(port)).replace('__TLS_DIRECTORY__',str(p)).replace('__ADAPTER_ADDRESS__',upstream)
    conf=p/'nginx.conf';conf.write_text(f'user root root; daemon off; master_process off; pid {p}/nginx.pid; error_log {p}/nginx.log; events {{worker_connections 128;}} http {{ client_body_temp_path {p}/body; proxy_temp_path {p}/proxy; fastcgi_temp_path {p}/fastcgi; uwsgi_temp_path {p}/uwsgi; scgi_temp_path {p}/scgi;\n'+rendered+'\n}\n')
    nginx=None;nginxlog=open(p/'nginx-process.log','w')
    def start():
        global nginx
        syntax=subprocess.run([NGINX,'-t','-p',str(p)+'/','-c',str(conf)],capture_output=True,text=True)
        assert syntax.returncode==0,syntax.stderr
        nginx=subprocess.Popen([NGINX,'-p',str(p)+'/','-c',str(conf)],stdout=nginxlog,stderr=nginxlog)
        for _ in range(100):
            assert nginx.poll() is None, 'Fixture gateway exited'
            try:
                with socket.create_connection(('127.0.0.1',port),.1):return
            except OSError:time.sleep(.02)
        raise AssertionError('Fixture gateway unavailable')
    env=os.environ|{'CODE_LAB_RUNNER_TRANSPORT':'mtls','CODE_LAB_JUDGE0_URL':f'https://127.0.0.1:{port}',
        'CODE_LAB_JUDGE0_TOKEN':config['token'],'CODE_LAB_RUNNER_TLS_CA':str(p/'server-ca.crt'),
        'CODE_LAB_RUNNER_TLS_CERT':str(p/'client.crt'),'CODE_LAB_RUNNER_TLS_KEY':str(p/'client.key'),
        'CODE_LAB_LANGUAGE_IDS':json.dumps({'c':10001,'cpp':10002,'python':10003,'java':10006,'javascript':10005})}
    def php(code,changes=None):
        command='require '+json.dumps(str(ROOT/'services/CodeLabRunner.php'))+';'+code
        r=subprocess.run([PHP,'-r',command],env=env|(changes or {}),capture_output=True,text=True,timeout=60)
        assert r.returncode==0, 'Fixture PHP client failed: '+r.stderr[-400:]
        return r.stdout
    rejected='try{(new CodeLabRunner())->request("GET","/languages");exit(9);}catch(RuntimeException $e){echo "denied";}'
    queued=None
    try:
        start()
        context=ssl.create_default_context(cafile=str(p/'server-ca.crt'));context.load_cert_chain(str(p/'client.crt'),str(p/'client.key'))
        persistent=http.client.HTTPSConnection('127.0.0.1',port,context=context,timeout=3)
        persistent.request('GET','/languages',headers={'X-Auth-Token':config['token'],'Connection':'keep-alive'})
        response=persistent.getresponse();assert response.status==200 and response.getheader('Connection').lower()=='close';response.read();persistent.close()
        profiles=json.loads(php('echo json_encode((new CodeLabRunner())->request("GET","/languages"));'))
        assert {v['id'] for v in profiles}=={v['id'] for v in config['profiles']}
        for changes in [
            {'CODE_LAB_JUDGE0_TOKEN':'wrong-'*10},
            {'CODE_LAB_RUNNER_TLS_CA':str(p/'other-ca.crt')},
            {'CODE_LAB_JUDGE0_URL':f'https://localhost:{port}'},
            {'CODE_LAB_JUDGE0_URL':f'http://127.0.0.1:{port}'},
            {'CODE_LAB_RUNNER_TLS_KEY':str(p/'missing.key')},
            {'CODE_LAB_RUNNER_TRANSPORT':'unknown'}]:
            assert php(rejected,changes)=='denied'
        (p/'client.key').chmod(0o644);assert php(rejected)=='denied';(p/'client.key').chmod(0o600)
        # Missing or foreign client certificate is rejected by actual Nginx TLS.
        for cert,key in [(None,None),('other-ca.crt','other-ca.key')]:
            script=p/'probe.py';script.write_text('''import os,ssl,urllib.request,sys
c=ssl.create_default_context(cafile=sys.argv[2])
if sys.argv[3]!='-':c.load_cert_chain(sys.argv[3],sys.argv[4])
try:urllib.request.urlopen(urllib.request.Request(sys.argv[1],headers={'X-Auth-Token':os.environ['FIXTURE_PROBE_BEARER']}),context=c,timeout=3);raise AssertionError('Unauthenticated TLS accepted')
except (ssl.SSLError,urllib.error.URLError):pass
''')
            subprocess.run(['python3',str(script),env['CODE_LAB_JUDGE0_URL']+'/languages',str(p/'server-ca.crt'),str(p/cert) if cert else '-',str(p/key) if key else '-'],env=os.environ|{'FIXTURE_PROBE_BEARER':config['token']},check=True)
        # Management paths never reach the adapter through the gateway.
        assert php('try{(new CodeLabRunner())->request("GET","/api/v2/packages");exit(9);}catch(RuntimeException $e){echo "denied";}')=='denied'
        before=time.monotonic()
        result=json.loads(php('echo json_encode((new CodeLabRunner())->execute(10003,"print(42)",""));'))
        assert result['status']['id']==3 and result['stdout'].strip()=='42'
        execution_ms=(time.monotonic()-before)*1000
        # Actual cURL certificate verification is used on every request; no mock transport.
        latencies=json.loads(php('$r=new CodeLabRunner();$v=[];for($i=0;$i<12;$i++){$t=hrtime(true);$r->request("GET","/languages");$v[]=(hrtime(true)-$t)/1e6;}echo json_encode($v);'))
        if os.environ.get('CODE_LAB_TLS_UPSTREAM'):
            for arguments in [[],['--grading']]:
                subprocess.run([PHP,str(ROOT/'scripts/check-code-lab-runner.php'),*arguments],env=env,cwd=ROOT,check=True,timeout=240)
        queued=subprocess.Popen([PHP,str(ROOT/'tests/code-lab-mtls-queue.php')],cwd=ROOT,env=env,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True)
        for line in queued.stdout:
            print(line,end='',flush=True)
            if line.strip()=='FIXTURE_RUNNER_OUTAGE':
                nginx.terminate();nginx.wait(timeout=5)
                queued.stdin.write('continue\n');queued.stdin.flush()
            elif line.strip()=='FIXTURE_RUNNER_RECOVERY':
                # Restart ONLY the adapter owned by this fixture/CI project.
                # Application records stay in the independent live SQLite fixture.
                if fixture:
                    address=fixture.server_address;fixture.shutdown();fixture.server_close()
                    fixture=bridge.Server(address,bridge.Adapter(config,backend))
                    threading.Thread(target=fixture.serve_forever,daemon=True).start()
                elif os.environ.get('CODE_LAB_TLS_RESTART_ADAPTER')=='1':
                    assert os.environ.get('GITHUB_ACTIONS')=='true', 'Docker restart hook is CI-only'
                    subprocess.run(['docker','compose','-f','deploy/code-lab/piston/compose.yml','-f','deploy/code-lab/piston/compose.isolated.yml','restart','adapter'],cwd=ROOT,check=True,stdout=subprocess.DEVNULL,timeout=30)
                    for _ in range(60):
                        try:
                            request=bridge.urllib.request.Request('http://'+upstream+'/languages',headers={'X-Auth-Token':config['token']})
                            with bridge.urllib.request.urlopen(request,timeout=1) as response:json.load(response)
                            break
                        except Exception:time.sleep(.1)
                    else:raise AssertionError('Restarted fixture adapter unavailable')
                start();queued.stdin.write('continue\n');queued.stdin.flush()
        assert queued.wait(timeout=10)==0,'Authenticated runner queue recovery failed'
        baseline=json.loads(php('$r=new CodeLabRunner();$v=[];for($i=0;$i<12;$i++){$t=hrtime(true);$r->request("GET","/languages");$v[]=(hrtime(true)-$t)/1e6;}echo json_encode($v);',{'CODE_LAB_RUNNER_TRANSPORT':'legacy','CODE_LAB_JUDGE0_URL':'http://'+upstream}))
        openssl('ca','-config','ca.conf','-revoke','client.crt');openssl('ca','-gencrl','-config','ca.conf','-out','client-ca.crl')
        nginx.terminate();nginx.wait(timeout=5);start()
        assert php(rejected)=='denied', 'Revoked client certificate remained usable'
        assert len(json.loads(php('echo json_encode((new CodeLabRunner())->request("GET","/languages"));',{'CODE_LAB_RUNNER_TLS_CERT':str(p/'rotated.crt'),'CODE_LAB_RUNNER_TLS_KEY':str(p/'rotated.key')})))==len(profiles)
        # No network/proxy/bearer fallback when the authenticated service disappears.
        nginx.terminate();nginx.wait(timeout=5);assert php(rejected)=='denied'
        if not os.environ.get('CODE_LAB_TLS_UPSTREAM'):
            assert all('expected_output' not in body and 'enable_network' not in body for _,body in seen)
        report={'fixture':'actual Nginx mTLS + PHP cURL + '+('actual Piston adapter' if os.environ.get('CODE_LAB_TLS_UPSTREAM') else 'real adapter with mocked backend; no source execution'),
            'language_profiles':len(profiles),'transport_samples':len(latencies),'transport_p50_ms':sorted(latencies)[5],
            'transport_p95_ms':max(latencies),'direct_adapter_http_p50_ms':sorted(baseline)[5],'direct_adapter_http_p95_ms':max(baseline),
            'median_gateway_plus_tls_increment_ms':sorted(latencies)[5]-sorted(baseline)[5],'unexpected_positive_probe_errors':0,'known_python_request_end_to_end_ms':execution_ms,'runtime_cpu_seconds':result.get('time') if os.environ.get('CODE_LAB_TLS_UPSTREAM') else None,
            'runtime_memory':result.get('memory'),'queue_wait_ms':None,'host_cpu_memory':None,
            'unauthorized_wrong_ca_hostname_plaintext_revoked_and_unavailable':'PASS','separate_kernel_isolation':'BLOCKED'}
        print('MEASURED '+json.dumps(report))
        print('PASS real mTLS authorization, peer/hostname verification, CRL rotation, route isolation, failure and existing protocol')
    finally:
        if queued is not None and queued.poll() is None:queued.terminate();queued.wait(timeout=5)
        if nginx is not None and nginx.poll() is None:nginx.terminate();nginx.wait(timeout=5)
        nginxlog.close()
        if fixture:fixture.shutdown();fixture.server_close()
