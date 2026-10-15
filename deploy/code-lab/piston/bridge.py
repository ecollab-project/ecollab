"""Private, bounded Judge0-subset adapter for the cgroup-v2 Piston service.
No submitted source is executed in this process. Only /execute on Piston runs code.
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
from urllib.parse import urlsplit


def piston_request(path, body=None, timeout=45):
    data=None if body is None else json.dumps(body).encode()
    req=urllib.request.Request(os.environ.get('PISTON_URL','http://piston:2000')+'/api/v2'+path,data=data,headers={'Content-Type':'application/json'})
    with urllib.request.urlopen(req,timeout=timeout) as response:
        raw=response.read(1048577)
    if len(raw)>1048576:
        raise ValueError('Oversize execution response')
    return json.loads(raw)


def b64(value):
    return base64.b64encode(str(value or '').encode('utf-8',errors='replace')[:16384]).decode()


def translate(result):
    run=result.get('run')
    compile_result=result.get('compile')
    if not isinstance(run,dict):
        raise ValueError('Missing execution result')
    if compile_result and compile_result.get('status')=='XX':
        raise ValueError('Compiler sandbox internal error')
    if compile_result and (compile_result.get('code')!=0 or compile_result.get('signal') or compile_result.get('status')):
        status=6
    elif run.get('status')=='TO':
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


class Adapter:
    def __init__(self,config,request=piston_request):
        if not isinstance(config.get('token'),str) or len(config['token'])<32:
            raise ValueError('Private API token required')
        self.token=config['token']
        self.profiles={int(p['id']):p for p in config['profiles']}
        if len(self.profiles)!=5 or not all(p['id']>=10000 and p.get('language') and p.get('version') and p.get('filename') for p in self.profiles.values()):
            raise ValueError('Five pinned runtime profiles required')
        self.request=request
        self.lock=threading.Lock()
        self.jobs={}
        self.pending=queue.Queue(maxsize=32)
        threading.Thread(target=self.worker,daemon=True).start()

    def submit(self,body):
        profile=self.profiles.get(body.get('language_id'))
        if profile is None or body.get('enable_network') is not False:
            raise ValueError('Unsupported runtime or network settings')
        if any(body.get(k) for k in ('callback_url','additional_files','compiler_options','command_line_arguments','expected_output')):
            raise ValueError('Unsupported submission feature')
        source=base64.b64decode(body.get('source_code',''),validate=True).decode('utf-8')
        stdin=base64.b64decode(body.get('stdin',''),validate=True).decode('utf-8')
        if len(source.encode())>100000 or len(stdin.encode())>4096:
            raise ValueError('Source/input limit exceeded')
        # Fixed limits cannot be raised by an API caller; profile/version are server-owned.
        payload={'language':profile['language'],'version':profile['version'],
                 'files':[{'name':profile['filename'],'content':source}], 'stdin':stdin,
                 'compile_timeout':30000,'run_timeout':8000,'compile_cpu_time':10000,'run_cpu_time':2000,
                 'compile_memory_limit':536870912,'run_memory_limit':268435456}
        token=str(uuid.uuid4())
        with self.lock:
            cutoff=time.monotonic()-900
            for key in list(self.jobs):
                job=self.jobs[key]
                if job['result']['status']['id']>2 and job['at']<cutoff:
                    del self.jobs[key]
            if len(self.jobs)>=256:
                completed=sorted((j['at'],k) for k,j in self.jobs.items() if j['result']['status']['id']>2)
                if not completed:
                    raise queue.Full()
                del self.jobs[completed[0][1]]
            self.jobs[token]={'result':{'status':{'id':1}},'at':time.monotonic()}
            try:
                self.pending.put_nowait((token,payload))
            except queue.Full:
                del self.jobs[token]
                raise
        return token

    def worker(self):
        while True:
            token,payload=self.pending.get()
            with self.lock:
                self.jobs[token]['result']={'status':{'id':2}}
            try:
                result=translate(self.request('/execute',payload))
            except Exception:
                result={'status':{'id':13}}  # Infrastructure error, never a zero grade.
            with self.lock:
                self.jobs[token]={'result':result,'at':time.monotonic()}
            self.pending.task_done()

    def get(self,token):
        with self.lock:
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
            return self.send_json(200,[{'id':p['id'],'name':p['name']+' ('+p['language']+' '+p['version']+', Piston)'} for p in self.server.adapter.profiles.values()])
        if path.startswith('/submissions/'):
            result=self.server.adapter.get(path.split('/')[-1])
            return self.send_json(200,result) if result else self.send_json(404,{'error':'Execution not found'})
        return self.send_json(404,{'error':'Not found'})
    def do_POST(self):
        if not self.authorized():
            return self.send_json(401,{'error':'Authentication required'})
        if urlsplit(self.path).path!='/submissions':
            return self.send_json(404,{'error':'Not found'})
        try:
            length=int(self.headers.get('Content-Length','0'))
            if length<1 or length>262144:
                return self.send_json(413,{'error':'Request size limit'})
            body=json.loads(self.rfile.read(length))
            if not isinstance(body,dict):
                raise ValueError('Invalid request')
            token=self.server.adapter.submit(body)
            return self.send_json(201,{'token':token})
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
        adapter=Adapter(json.load(file))
    Server(('0.0.0.0',2358),adapter).serve_forever()
