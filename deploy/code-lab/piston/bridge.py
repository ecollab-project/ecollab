"""Private, bounded Judge0-subset adapter for the cgroup-v2 Piston service.
No submitted source is executed in this process. Only Piston sandbox execution endpoints run submitted code.
"""
import base64
import hmac
import json
import os
import queue
import threading
import time
import urllib.request
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit, parse_qs


def piston_request(path, body=None, timeout=45):
    data=None if body is None else json.dumps(body).encode()
    req=urllib.request.Request(os.environ.get('PISTON_URL','http://piston:2000')+'/api/v2'+path,data=data,headers={'Content-Type':'application/json'})
    with urllib.request.urlopen(req,timeout=125 if path=='/ecollab-grade' else timeout) as response:
        raw=response.read(1048577)
    if len(raw)>1048576:
        raise ValueError('Oversize execution response')
    return json.loads(raw)


def b64(value):
    return base64.b64encode(str(value or '').encode('utf-8',errors='replace')[:16384]).decode()


def translate(result):
    run=result.get('run')
    compile_result=result.get('compile')
    if compile_result and compile_result.get('status')=='XX':
        raise ValueError('Compiler sandbox internal error')
    if compile_result and (compile_result.get('code')!=0 or compile_result.get('signal') or compile_result.get('status')):
        return {'status':{'id':6},'stdout':'','stderr':'','compile_output':b64(compile_result.get('stderr')),'time':None,'memory':None,'exit_code':compile_result.get('code')}
    if not isinstance(run,dict):
        raise ValueError('Missing execution result')
    if run.get('status')=='TO':
        status=5
    elif run.get('code')==0 and not run.get('signal') and not run.get('status'):
        status=3
    elif run.get('status') in (None,'RE','SG','OL','EL','XX'):
        if run.get('status')=='XX':
            raise ValueError('Sandbox internal error')
        status=11
    else:
        raise ValueError('Unknown execution status')
    return {'status':{'id':status},'stdout':b64(run.get('stdout')),'stderr':b64(run.get('stderr')),
            'compile_output':b64((compile_result or {}).get('stderr')),
            'time':(run.get('cpu_time') or 0)/1000,'memory':None,'exit_code':run.get('code')}


def execution_slots(value):
    if str(value) not in ('1','2'):
        raise ValueError('Execution slots must be one or two')
    return int(value)


class Adapter:
    def __init__(self,config,request=piston_request,slots=2):
        if not isinstance(config.get('token'),str) or len(config['token'])<32:
            raise ValueError('Private API token required')
        self.token=config['token']
        self.profiles={int(p['id']):p for p in config['profiles']}
        if set(self.profiles) not in (set(range(10001,10006)),set(range(10001,10007))) or not all(p['id']>=10000 and p.get('language') and p.get('version') and p.get('filename') for p in self.profiles.values()):
            raise ValueError('Five legacy profiles and optional compiled Java profile required')
        self.request=request
        self.lock=threading.Lock()
        self.completed=threading.Condition(self.lock)
        self.jobs={}
        self.cached_bytes=0
        self.pending=queue.Queue(maxsize=32)
        for _ in range(execution_slots(slots)):
            threading.Thread(target=self.worker,daemon=True).start()

    def submit(self,body,batch=False):
        profile=self.profiles.get(body.get('language_id'))
        if profile is None or body.get('enable_network') is not False:
            raise ValueError('Unsupported runtime or network settings')
        if any(body.get(k) for k in ('callback_url','additional_files','compiler_options','command_line_arguments','expected_output')):
            raise ValueError('Unsupported submission feature')
        if not batch and 'test_inputs' in body:
            raise ValueError('Batch inputs require the grading endpoint')
        source=base64.b64decode(body.get('source_code',''),validate=True).decode('utf-8')
        stdin=base64.b64decode(body.get('stdin',''),validate=True).decode('utf-8')
        inputs=None
        if batch:
            if profile['id'] not in (10001,10002,10003,10006):
                raise ValueError('Runtime has no batch grading support')
            values=body.get('test_inputs')
            if not isinstance(values,list) or not 1<=len(values)<=10 or any(not isinstance(v,str) for v in values):
                raise ValueError('Invalid grading input list')
            inputs=[base64.b64decode(v,validate=True).decode('utf-8') for v in values]
            if any(len(v.encode())>2048 for v in inputs):
                raise ValueError('Grading input limit exceeded')
            if stdin:
                raise ValueError('Batch stdin must be empty')
        if len(source.encode())>100000 or len(stdin.encode())>4096:
            raise ValueError('Source/input limit exceeded')
        # Fixed limits cannot be raised by an API caller; profile/version are server-owned.
        payload={'language':profile['language'],'version':profile['version'],
                 'files':[{'name':profile['filename'],'content':source}], 'stdin':stdin,
                 'compile_timeout':30000,'run_timeout':8000,'compile_cpu_time':10000,'run_cpu_time':2000,
                 'compile_memory_limit':536870912,'run_memory_limit':268435456}
        if batch:
            payload.pop('stdin')
            payload['inputs']=inputs
        token=str(uuid.uuid4())
        with self.lock:
            cutoff=time.monotonic()-900
            for key in list(self.jobs):
                job=self.jobs[key]
                if job['result']['status']['id']>2 and job['at']<cutoff:
                    self.cached_bytes-=job.get('bytes',0)
                    del self.jobs[key]
            if len(self.jobs)>=256:
                completed=sorted((j['at'],k) for k,j in self.jobs.items() if j['result']['status']['id']>2)
                if not completed:
                    raise queue.Full()
                removed=self.jobs.pop(completed[0][1])
                self.cached_bytes-=removed.get('bytes',0)
            self.jobs[token]={'result':{'status':{'id':1}},'at':time.monotonic()}
            try:
                self.pending.put_nowait((token,payload,batch))
            except queue.Full:
                del self.jobs[token]
                raise
        return token

    def worker(self):
        while True:
            token,payload,batch=self.pending.get()
            with self.lock:
                self.jobs[token]['result']={'status':{'id':2}}
            try:
                if batch:
                    response=self.request('/ecollab-grade',payload)
                    compile_result=response.get('compile')
                    if compile_result and (compile_result.get('code')!=0 or compile_result.get('signal') or compile_result.get('status')):
                        results=[translate({'compile':compile_result}) for _ in payload['inputs']]
                    else:
                        runs=response.get('runs')
                        if not isinstance(runs,list) or len(runs)!=len(payload['inputs']):
                            raise ValueError('Incomplete grading execution')
                        results=[translate({'compile':compile_result,'run':run}) for run in runs]
                    expected=0 if payload['language']=='python' else 1
                    if response.get('compile_count')!=expected:
                        raise ValueError('Unexpected compilation count')
                    result={'status':{'id':3},'results':results,'compile_count':expected}
                else:
                    result=translate(self.request('/execute',payload))
            except Exception:
                result={'status':{'id':13}}  # Infrastructure error, never a zero grade.
            size=len(json.dumps(result).encode())
            with self.lock:
                while self.cached_bytes+size>16777216:
                    completed=sorted((j['at'],k) for k,j in self.jobs.items() if j['result']['status']['id']>2)
                    if not completed:break
                    removed=self.jobs.pop(completed[0][1]);self.cached_bytes-=removed.get('bytes',0)
                self.jobs[token]={'result':result,'at':time.monotonic(),'bytes':size}
                self.cached_bytes+=size
                self.completed.notify_all()
            self.pending.task_done()

    def get(self,token,wait_ms=0):
        if not isinstance(wait_ms,int) or not 0<=wait_ms<=1000:
            raise ValueError('Result wait must be 0–1000ms')
        with self.completed:
            if wait_ms:
                self.completed.wait_for(lambda: token not in self.jobs or self.jobs[token]['result']['status']['id']>2,timeout=wait_ms/1000)
            job=self.jobs.get(token)
            return job['result'].copy() if job else None


class Handler(BaseHTTPRequestHandler):
    def log_message(self,*args):
        pass  # Do not log source, authentication headers or tokens.
    def send_json(self,status,value):
        data=json.dumps(value).encode()
        self.send_response(status)
        self.send_header('Content-Type','application/json')
        self.send_header('Cache-Control','no-store')
        self.send_header('Content-Length',str(len(data)))
        self.end_headers()
        self.wfile.write(data)
    def authorized(self):
        return hmac.compare_digest(self.headers.get('X-Auth-Token','').encode(),self.server.adapter.token.encode())
    def do_GET(self):
        if not self.authorized():
            return self.send_json(401,{'error':'Authentication required'})
        path=urlsplit(self.path).path
        if path=='/languages':
            return self.send_json(200,[{'id':p['id'],'name':p['name']+' ('+p['language']+' '+p['version']+', Piston)', 'ecollab_grade_v1':p['id'] in (10001,10002,10003,10006)} for p in self.server.adapter.profiles.values()])
        if path.startswith('/submissions/'):
            try:
                wait_ms=int(parse_qs(urlsplit(self.path).query).get('wait_ms',['0'])[0])
                result=self.server.adapter.get(path.split('/')[-1],wait_ms)
            except (ValueError,TypeError):
                return self.send_json(400,{'error':'Invalid result wait'})
            return self.send_json(200,result) if result else self.send_json(404,{'error':'Execution not found'})
        return self.send_json(404,{'error':'Not found'})
    def do_POST(self):
        if not self.authorized():
            return self.send_json(401,{'error':'Authentication required'})
        path=urlsplit(self.path).path
        if path not in ('/submissions','/grade-submissions'):
            return self.send_json(404,{'error':'Not found'})
        try:
            length=int(self.headers.get('Content-Length','0'))
            if length<1 or length>262144:
                return self.send_json(413,{'error':'Request size limit'})
            body=json.loads(self.rfile.read(length))
            if not isinstance(body,dict):
                raise ValueError('Invalid request')
            token=self.server.adapter.submit(body,batch=path=='/grade-submissions')
            return self.send_json(201,{'token':token,'poll_wait_ms':1000})
        except queue.Full:
            return self.send_json(429,{'error':'Execution queue full'})
        except (ValueError,TypeError,KeyError,AttributeError,UnicodeError):
            return self.send_json(400,{'error':'Invalid submission'})


class Server(ThreadingHTTPServer):
    daemon_threads=True
    def __init__(self,address,adapter):
        self.adapter=adapter
        self.slots=threading.BoundedSemaphore(16)
        super().__init__(address,Handler)
    def process_request(self,request,address):
        if not self.slots.acquire(blocking=False):
            request.close()
            return
        super().process_request(request,address)
    def process_request_thread(self,request,address):
        request.settimeout(15)
        try:
            super().process_request_thread(request,address)
        finally:
            self.slots.release()


if __name__=='__main__':
    with open('/runner/profiles.json') as file:
        adapter=Adapter(json.load(file),slots=execution_slots(os.environ.get('CODE_LAB_EXECUTION_SLOTS','2')))
    Server(('0.0.0.0',2358),adapter).serve_forever()

