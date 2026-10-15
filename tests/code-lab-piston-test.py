import base64
import importlib.util
import json
import pathlib
import threading
import time
import unittest
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
    def test_reject_legacy_ids_and_extra_execution_features(self):
        for change in [{'language_id':71},{'enable_network':True},{'callback_url':'https://example.invalid'},{'additional_files':'zip'},{'source_code':'invalid!'}]:
            with self.assertRaises(ValueError):self.submit(**change)
    def test_status_translation(self):
        self.assertEqual(bridge.translate({'run':{'code':None,'signal':'SIGKILL','status':'TO'}})['status']['id'],5)
        self.assertEqual(bridge.translate({'compile':{'code':1,'stderr':'bad'},'run':{'code':1}})['status']['id'],6)
        self.assertEqual(bridge.translate({'run':{'code':None,'signal':'SIGKILL','status':'OL'}})['status']['id'],11)
        for result in [{},{'run':{'status':'XX'}},{'compile':{'status':'XX'},'run':{}}]:
            with self.assertRaises(ValueError):bridge.translate(result)
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
            with urllib.request.urlopen(request)as response:token=json.load(response)['token']
            self.adapter.pending.join()
            request=urllib.request.Request(address+'/submissions/'+token+'?base64_encoded=true&fields=stdout,status',headers=headers)
            with urllib.request.urlopen(request)as response:self.assertEqual(json.load(response)['status']['id'],3)
            request=urllib.request.Request(address+'/submissions/unknown',headers=headers)
            with self.assertRaises(urllib.error.HTTPError)as e:urllib.request.urlopen(request)
            self.assertEqual(e.exception.code,404)
        finally:server.shutdown();server.server_close()

if __name__=='__main__':unittest.main()
