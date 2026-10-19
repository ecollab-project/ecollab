import base64
import importlib.util
import json
import pathlib
import threading
import time
import tempfile
import subprocess
import sys
import stat
import unittest
import shutil
import urllib.error
import urllib.request

ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('bridge',ROOT/'deploy/code-lab/piston/bridge.py')
bridge=importlib.util.module_from_spec(spec);spec.loader.exec_module(bridge)

class BridgeTests(unittest.TestCase):
    def setUp(self):
        self.calls=[]
        config={'token':'private-'*8,'profiles':[{'id':10001+i,'name':'Language','language':name,'version':'1.2.3','filename':filename}for i,(name,filename)in enumerate([('c','main.c'),('c++','main.cpp'),('python','main.py'),('java','Main.java'),('javascript','main.js')])]}
        def request(path,body):
            self.calls.append(body)
            return {'run':{'code':0,'signal':None,'status':None,'stdout':'42\n','stderr':'','cpu_time':10}}
        self.adapter=bridge.Adapter(config,request)
    def submit(self,**extra):
        return self.adapter.submit({'language_id':10003,'source_code':base64.b64encode(b'print(42)').decode(),'stdin':'','enable_network':False,**extra})
    def test_batch_compile_once_and_fail_closed(self):
        def request(path, body):
            self.assertEqual(path,'/ecollab-grade')
            self.assertNotIn('stdin',body)
            self.assertEqual(body['inputs'],['41','5'])
            return {'runs':[{'code':0,'stdout':'42'},{'code':0,'stdout':'6'}],'compile_count':0}
        self.adapter.request=request
        body={'language_id':10003,'source_code':base64.b64encode(b'program').decode(),'stdin':'','test_inputs':[base64.b64encode(x).decode() for x in [b'41',b'5']],'enable_network':False}
        token=self.adapter.submit(body,batch=True);self.adapter.pending.join()
        result=self.adapter.get(token)
        self.assertEqual(result['compile_count'],0)
        self.assertEqual([base64.b64decode(r['stdout']) for r in result['results']],[b'42',b'6'])
        for malformed in [{'runs':[{'code':0}],'compile_count':0},{'runs':[{'code':0},{'code':0}],'compile_count':1},{'runs':[{'status':'XX'},{'code':0}],'compile_count':0}]:
            self.adapter.request=lambda path,body:malformed
            token=self.adapter.submit(body,batch=True);self.adapter.pending.join()
            self.assertEqual(self.adapter.get(token)['status']['id'],13)
        for change in [{'test_inputs':[]},{'test_inputs':['%%%']},{'test_inputs':['eA==']*11},{'language_id':10004},{'stdin':'eA=='}]:
            with self.assertRaises(ValueError):self.adapter.submit({**body,**change},batch=True)
        with self.assertRaises(ValueError):self.adapter.submit(body)
        self.adapter.request=lambda path,body:{'compile':{'code':1,'stderr':'bad'},'runs':[],'compile_count':1}
        token=self.adapter.submit({**body,'language_id':10001},batch=True);self.adapter.pending.join()
        self.assertEqual([r['status']['id'] for r in self.adapter.get(token)['results']],[6,6])

    def test_pinned_profile_limits_and_result(self):
        token=self.submit(run_timeout=999999,version='newest',language='bash')
        self.adapter.pending.join()
        result=self.adapter.get(token)
        self.assertEqual(result['status']['id'],3)
        self.assertEqual(base64.b64decode(result['stdout']),b'42\n')
        request=self.calls[0]
        self.assertEqual(request['version'],'1.2.3')
        self.assertEqual(request['language'],'python')
        self.assertEqual(request['run_timeout'],8000)
        self.assertEqual(request['run_memory_limit'],268435456)
        self.assertEqual(request['compile_memory_limit'],536870912)
    def test_bounded_wait_wakes_on_completion_and_releases_lock(self):
        entered=threading.Event();release=threading.Event()
        def request(path,body):
            entered.set();release.wait(3)
            return {'run':{'code':0,'stdout':'42'}}
        self.adapter.request=request
        token=self.submit();self.assertTrue(entered.wait(1))
        result=[]
        waiter=threading.Thread(target=lambda:result.append(self.adapter.get(token,1000)))
        waiter.start();time.sleep(.02)
        # The waiting HTTP reader must not hold the adapter lock/queue hostage.
        self.assertEqual(self.adapter.get(token)['status']['id'],2)
        with self.assertRaises(ValueError):self.adapter.get(token,1001)
        with self.assertRaises(ValueError):self.adapter.get(token,-1)
        release.set();waiter.join(.5)
        self.assertFalse(waiter.is_alive(),'Completion notification should wake the reader before its 1s timeout')
        self.assertEqual(result[0]['status']['id'],3)
        self.assertIsNone(self.adapter.get('unknown',1000))
    def test_pending_wait_times_out_without_a_false_completion(self):
        release=threading.Event()
        def request(path,body):
            release.wait(3)
            return {'run':{'code':0}}
        self.adapter.request=request
        token=self.submit();before=time.monotonic()
        result=self.adapter.get(token,30)
        self.assertLess(time.monotonic()-before,.5)
        self.assertLessEqual(result['status']['id'],2)
        release.set()
    def test_two_slots_progress_independently_without_exceeding_bound(self):
        release=threading.Event();two_started=threading.Event();active=0;peak=0;guard=threading.Lock()
        def request(path,body):
            nonlocal active,peak
            with guard:
                active+=1;peak=max(peak,active)
                if active==2:two_started.set()
            release.wait(2)
            with guard:active-=1
            return {'run':{'code':0,'stdout':body['files'][0]['content']}}
        self.adapter.request=request
        tokens=[self.submit(source_code=base64.b64encode(('source-'+str(i)).encode()).decode())for i in range(3)]
        self.assertTrue(two_started.wait(1),'A slow first job must not block the second slot')
        self.assertEqual(self.adapter.get(tokens[2])['status']['id'],1)
        release.set();self.adapter.pending.join()
        self.assertEqual(peak,2)
        for i,token in enumerate(tokens):self.assertEqual(base64.b64decode(self.adapter.get(token)['stdout']),('source-'+str(i)).encode())
        for value in ['0','3',-1,True]:
            with self.assertRaises(ValueError):bridge.execution_slots(value)

    def test_reject_legacy_ids_and_extra_execution_features(self):
        for change in [{'language_id':71},{'enable_network':True},{'callback_url':'https://example.invalid'},{'additional_files':'zip'},{'source_code':'invalid!'}]:
            with self.assertRaises(ValueError):self.submit(**change)
    def test_status_translation(self):
        self.assertEqual(bridge.translate({'run':{'code':None,'signal':'SIGKILL','status':'TO'}})['status']['id'],5)
        self.assertEqual(bridge.translate({'compile':{'code':1,'stderr':'bad'},'run':{'code':1}})['status']['id'],6)
        self.assertEqual(bridge.translate({'run':{'code':None,'signal':'SIGKILL','status':'OL'}})['status']['id'],11)
        self.assertEqual(bridge.translate({'compile':{'code':1,'stderr':'syntax'}})['status']['id'],6)
        for result in [{},{'run':{'status':'XX'}},{'compile':{'status':'XX'},'run':{}}]:
            with self.assertRaises(ValueError):bridge.translate(result)
    def test_php_client_wait_and_legacy_compatibility(self):
        import os
        php=os.environ.get('PHP_BIN','php')
        if shutil.which(php) is None:
            self.skipTest('PHP cURL is exercised by CI')
        if subprocess.run([php,'-r','exit(function_exists("curl_init")?0:1);'],capture_output=True).returncode:
            self.skipTest('PHP cURL is exercised by CI')
        def request(path,body):
            time.sleep(.04)
            return {'run':{'code':0,'stdout':'42\n','cpu_time':40}}
        self.adapter.request=request
        timings={}
        for legacy in [True,False]:
            reads=[]
            class TestHandler(bridge.Handler):
                def send_json(self,status,value):
                    if legacy and status==201:
                        value={key:val for key,val in value.items() if key!='poll_wait_ms'}
                    super().send_json(status,value)
                def do_GET(self):
                    reads.append(self.path)
                    super().do_GET()
            server=bridge.Server(('127.0.0.1',0),self.adapter);server.RequestHandlerClass=TestHandler
            threading.Thread(target=server.serve_forever,daemon=True).start()
            try:
                result=subprocess.run([php,str(ROOT/'tests/code-lab-runner-wait.php'),'http://127.0.0.1:'+str(server.server_port)],capture_output=True,text=True,check=True,timeout=15)
                report=json.loads(result.stdout);self.assertEqual(report['finished'],8)
                timings[legacy]=report['elapsed_ms']
                self.assertEqual(any('wait_ms=1000' in path for path in reads),not legacy)
            finally:server.shutdown();server.server_close()
        self.assertLess(timings[False],timings[True]*.7,'Fast executions should not pay the fixed 250ms completion polling delay')
        print('Controlled 8-job loopback fixture: legacy %.1fms, completion wait %.1fms (not a VPS capacity result)'%(timings[True],timings[False]))
    def test_auth_and_protocol(self):
        server=bridge.Server(('127.0.0.1',0),self.adapter)
        threading.Thread(target=server.serve_forever,daemon=True).start()
        address='http://127.0.0.1:'+str(server.server_port)
        try:
            with self.assertRaises(urllib.error.HTTPError)as e:urllib.request.urlopen(address+'/languages')
            self.assertEqual(e.exception.code,401)
            headers={'X-Auth-Token':self.adapter.token,'Content-Type':'application/json'}
            request=urllib.request.Request(address+'/languages',headers=headers)
            with urllib.request.urlopen(request)as response:self.assertEqual(len(json.load(response)),5)
            request=urllib.request.Request(address+'/submissions?base64_encoded=true&wait=false',data=json.dumps({'language_id':10003,'source_code':base64.b64encode(b'print(42)').decode(),'stdin':'','enable_network':False}).encode(),headers=headers)
            with urllib.request.urlopen(request)as response:
                created=json.load(response);token=created['token'];self.assertEqual(created['poll_wait_ms'],1000)
            self.adapter.pending.join()
            request=urllib.request.Request(address+'/submissions/'+token+'?base64_encoded=true&fields=stdout,status',headers=headers)
            with urllib.request.urlopen(request)as response:self.assertEqual(json.load(response)['status']['id'],3)
            request=urllib.request.Request(address+'/submissions/'+token+'?wait_ms=1001',headers=headers)
            with self.assertRaises(urllib.error.HTTPError)as e:urllib.request.urlopen(request)
            self.assertEqual(e.exception.code,400)
            request=urllib.request.Request(address+'/submissions/unknown',headers=headers)
            with self.assertRaises(urllib.error.HTTPError)as e:urllib.request.urlopen(request)
            self.assertEqual(e.exception.code,404)
        finally:server.shutdown();server.server_close()

class JavaProfileTests(unittest.TestCase):
    def test_upgrade_preserves_legacy_profiles_and_private_token(self):
        spec=importlib.util.spec_from_file_location('java_profile',ROOT/'deploy/code-lab/piston/java_profile.py')
        module=importlib.util.module_from_spec(spec)
        sys.path.insert(0,str(ROOT/'deploy/code-lab/piston'))
        try:spec.loader.exec_module(module)
        finally:sys.path.pop(0)
        config={'token':'private-'*8,'profiles':[{'id':10001+i,'name':name,'language':name,'version':'15.0.2' if name=='java' else '1.2.3','filename':'Main.java' if name=='java' else 'main'}for i,name in enumerate(['c','cpp','python','java','javascript'])]}
        runtimes=[{'language':'ecollab-java-compiled','version':'1.0.0'}]
        updated=module.upgrade(config,runtimes)
        self.assertEqual(updated['token'],config['token'])
        self.assertEqual(updated['profiles'][:5],config['profiles'])
        self.assertEqual(len(config['profiles']),5,'Upgrade must not mutate existing profiles')
        self.assertEqual(updated['profiles'][5]['id'],10006)
        self.assertEqual(module.upgrade(updated,runtimes),updated,'Repeat install must be idempotent')
        with self.assertRaises(ValueError):module.upgrade(config,[])
        self.assertEqual(len(bridge.Adapter(updated).profiles),6)
        updated['profiles'][5]['version']='unreviewed'
        with self.assertRaises(ValueError):module.upgrade(updated,runtimes)

class InstallerTests(unittest.TestCase):
    def test_configuration_backup_preserves_unrelated_values_and_mode(self):
        script=(ROOT/'scripts/setup-code-lab-piston.sh').read_text()
        block=script.split('python3 - "$project_path/.env" "$CODE_LAB_RUNNER_STATE" <<\'PY\'\n',1)[1].split('\nPY\n',1)[0]
        self.assertLess(script.index('# Smoke-test with child-process settings'),script.index('# Only these three Code Lab keys change.'))
        with tempfile.TemporaryDirectory()as temp:
            folder=pathlib.Path(temp);app=folder/'public';app.mkdir();state=folder/'private';state.mkdir()
            env=app/'.env';original='DB_PASSWORD=keep-database\nCENTRIFUGO_TOKEN_SECRET=keep-centrifugo\nLIVEKIT_API_SECRET=keep-livekit\nCODE_LAB_JUDGE0_URL=http://old\n'
            env.write_text(original);env.chmod(0o640)
            before=env.stat()
            (state/'profiles.json').write_text(json.dumps({'token':'test-token-'*8,'profiles':[{'id':10001+i}for i in range(5)]}))
            subprocess.run([sys.executable,'-c',block,str(env),str(state)],check=True)
            updated=env.read_text()
            for line in original.splitlines()[:3]:self.assertIn(line,updated)
            self.assertIn('CODE_LAB_JUDGE0_URL=http://127.0.0.1:2358',updated)
            self.assertEqual(stat.S_IMODE(env.stat().st_mode),0o640)
            self.assertEqual((env.stat().st_uid,env.stat().st_gid),(before.st_uid,before.st_gid))
            backups=list(state.glob('application-env-before-piston-*'));self.assertEqual(len(backups),1)
            self.assertEqual(backups[0].read_text(),original)
            self.assertEqual(stat.S_IMODE(backups[0].stat().st_mode),0o600)

if __name__=='__main__':unittest.main()

