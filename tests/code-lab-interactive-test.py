import base64
import importlib.util
import json
import pathlib
import queue
import selectors
import subprocess
import sys
import threading
import time
import unittest

ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('interactive',ROOT/'deploy/code-lab/piston/interactive.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)

class Socket:
    def __init__(self):self.messages=queue.Queue();self.sent=[];self.closed=False
    def send(self,raw):
        data=json.loads(raw);self.sent.append(data)
        if data['type']=='init':
            self.messages.put(json.dumps({'type':'stage','stage':'run'}))
            self.messages.put(json.dumps({'type':'data','stream':'stdout','data':'First? '}))
        else:self.messages.put(json.dumps({'type':'data','stream':'stdout','data':'Received '+data['data']}))
    def recv(self):
        try:return self.messages.get(timeout=.02)
        except queue.Empty:raise TimeoutError()
    def close(self,**kwargs):self.closed=True

class LiveTests(unittest.TestCase):
    def setUp(self):
        self.sockets=[]
        def connector():
            s=Socket();self.sockets.append(s);return s
        self.gate=threading.BoundedSemaphore(2)
        self.manager=module.LiveManager({10003:{'language':'python','version':'3.10','filename':'main.py'}},self.gate,connector)
    def tearDown(self):
        for token in list(self.manager.jobs):self.manager.stop(token)
        time.sleep(.05)
    def start(self):return self.manager.start({'language_id':10003,'source_code':base64.b64encode(b'known program').decode(),'enable_network':False})
    def wait(self,token,state):
        for _ in range(100):
            result=self.manager.get(token)
            if result['state']==state:return result
            time.sleep(.01)
        self.fail('State not reached: '+state)
    def test_live_input_and_idempotent_delivery(self):
        token=self.start();self.assertIn('First?',self.wait(token,'running')['output'])
        self.manager.input(token,1,'21\n');self.manager.input(token,1,'21\n')
        self.assertEqual(len([m for m in self.sockets[0].sent if m['type']=='init']),1)
        self.assertEqual(len([m for m in self.sockets[0].sent if m['type']=='data']),1)
        with self.assertRaises(ValueError):self.manager.input(token,1,'different\n')
        self.manager.input(token,2,'22\n',True)
        self.assertEqual(self.sockets[0].sent[-1]['data'],'22\n\x04')
        self.sockets[0].messages.put(json.dumps({'type':'done','reason':'Finished'}))
        self.assertEqual(self.wait(token,'finished')['input_seq'],2)
    def test_capacity_stop_and_shared_batch_gate(self):
        first=self.start();self.wait(first,'running');second=self.start();self.wait(second,'running')
        with self.assertRaises(OverflowError):self.start()
        self.assertFalse(self.gate.acquire(blocking=False),'Live and batch share the same slots')
        self.manager.stop(first);self.wait(first,'finished');self.assertTrue(self.sockets[0].closed)
        self.assertTrue(self.gate.acquire(timeout=.2));self.gate.release()
    def test_fixed_limits_and_output_bound(self):
        token=self.start();self.wait(token,'running');payload=self.sockets[0].sent[0]
        self.assertEqual(payload['run_cpu_time'],2000);self.assertEqual(payload['run_timeout'],120000)
        self.assertNotIn('stdin',payload)
        self.sockets[0].messages.put(json.dumps({'type':'data','stream':'stdout','data':'x'*32769}))
        result=self.wait(token,'finished');self.assertEqual(result['reason'],'Output limit exceeded');self.assertEqual(result['output'],'First? ')
    def test_private_profile_and_initial_input_rejected(self):
        for changes in [{'stdin':'preset'},{'language_id':71},{'enable_network':True},{'source_code':'!'}]:
            body={'language_id':10003,'source_code':'eA==','enable_network':False,**changes}
            with self.assertRaises((ValueError,TypeError)):self.manager.start(body)
    def test_pty_prompts_before_input_and_preserves_process_state(self):
        program='import os; a=input("First? "); b=input("Second? "); print(a+":"+b)'
        p=subprocess.Popen([sys.executable,str(ROOT/'deploy/code-lab/piston/grading/terminal.py'),sys.executable,'-c',program],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        selector=selectors.DefaultSelector();selector.register(p.stdout,selectors.EVENT_READ);output=b''
        def until(text):
            nonlocal output
            deadline=time.monotonic()+3
            while text not in output and time.monotonic()<deadline:
                if selector.select(.1):output+=p.stdout.read1(4096)
            self.assertIn(text,output)
        try:
            until(b'First? ');p.stdin.write(b'one\n');p.stdin.flush();until(b'Second? ')
            p.stdin.write(b'two\n');p.stdin.flush();until(b'one:two');self.assertEqual(p.wait(timeout=2),0)
        finally:
            if p.poll() is None:p.kill()
            p.communicate();selector.close()

if __name__=='__main__':unittest.main()
