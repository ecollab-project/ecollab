"""Private live sessions. Submitted code executes only inside Piston."""
import base64
import hashlib
import json
import os
import threading
import time
import uuid
from urllib.parse import urlsplit


def connect():
    import websocket
    base=urlsplit(os.environ.get('PISTON_URL','http://piston:2000'))
    if base.scheme!='http' or base.hostname not in ('piston','localhost','127.0.0.1'):
        raise ValueError('Interactive runner must remain private')
    ws=websocket.create_connection('ws://'+base.netloc+'/api/v2/ecollab-live',timeout=3,
        enable_multithread=True,suppress_origin=True,http_no_proxy=['piston','localhost','127.0.0.1'])
    ws.settimeout(.25)
    return ws


class LiveManager:
    def __init__(self,profiles,execution,connector=connect):
        self.profiles=profiles
        self.execution=execution
        self.connector=connector
        self.lock=threading.RLock()
        self.jobs={}

    def start(self,body):
        profile=self.profiles.get(body.get('language_id'))
        if not profile or body.get('enable_network') is not False:
            raise ValueError('Unsupported interactive runtime')
        if any(body.get(k) for k in ('stdin','args','callback_url','additional_files','compiler_options','command_line_arguments')):
            raise ValueError('Input must arrive after start')
        source=base64.b64decode(body.get('source_code',''),validate=True).decode('utf-8')
        if len(source.encode())>100000:raise ValueError('Source limit')
        payload={'type':'init','language':profile['language'],'version':profile['version'],
            'files':[{'name':profile['filename'],'content':source}],
            'compile_timeout':30000,'run_timeout':120000,'compile_cpu_time':10000,
            'run_cpu_time':2000,'compile_memory_limit':536870912,'run_memory_limit':268435456}
        with self.lock:
            now=time.monotonic()
            for token in list(self.jobs):
                if self.jobs[token]['state'] in ('finished','failed') and now-self.jobs[token]['at']>180:
                    del self.jobs[token]
            if sum(j['state'] not in ('finished','failed') for j in self.jobs.values())>=2:
                raise OverflowError('Interactive consoles busy')
            if len(self.jobs)>=64:
                done=[k for k,j in self.jobs.items() if j['state'] in ('finished','failed')]
                if not done:raise OverflowError('Interactive consoles busy')
                del self.jobs[done[0]]
            token=str(uuid.uuid4())
            self.jobs[token]={'state':'queued','output':'','bytes':0,'input_seq':0,'input_hash':'',
                'input_bytes':0,'at':now,'poll':now,'io':now,'stop':False,'socket':None,
                'exit_code':None,'reason':''}
        threading.Thread(target=self.worker,args=(token,payload),daemon=True).start()
        return token

    def snapshot(self,job):
        return {k:job[k] for k in ('state','output','input_seq','exit_code','reason')}

    def get(self,token):
        with self.lock:
            job=self.jobs.get(token)
            if not job:return None
            job['poll']=time.monotonic()
            return self.snapshot(job)

    def input(self,token,sequence,data,eof=False):
        if type(sequence) is not int or sequence<1 or not isinstance(data,str) or type(eof) is not bool:
            raise ValueError('Invalid input')
        if len(data.encode())>4096 or '\0' in data:raise ValueError('Input limit')
        wire=data+('\x04' if eof else '')
        digest=hashlib.sha256(wire.encode()).hexdigest()
        with self.lock:
            job=self.jobs.get(token)
            if not job:raise KeyError(token)
            if sequence==job['input_seq'] and digest==job['input_hash']:
                return self.snapshot(job)
            if sequence!=job['input_seq']+1 or job['state']!='running' or not job['socket']:
                raise ValueError('Input sequence or execution state changed')
            if job['input_bytes']+len(wire.encode())>16384:raise ValueError('Session input limit')
            try:job['socket'].send(json.dumps({'type':'data','stream':'stdin','data':wire}))
            except Exception:
                job['stop']=True
                raise ValueError('Input delivery unavailable')
            job['input_bytes']+=len(wire.encode())
            job['input_seq']=sequence
            job['input_hash']=digest
            job['io']=time.monotonic()
            return self.snapshot(job)

    def stop(self,token):
        with self.lock:
            job=self.jobs.get(token)
            if not job:return None
            if job['state'] not in ('finished','failed'):
                job['stop']=True
                job['reason']='Stopped'
            return self.snapshot(job)

    def worker(self,token,payload):
        ws=None
        acquired=False
        job=self.jobs[token]
        try:
            acquired=self.execution.acquire(timeout=10)
            if not acquired:raise RuntimeError('Runner busy')
            with self.lock:
                if job['stop']:return
                job['state']='starting'
            ws=self.connector()
            with self.lock:job['socket']=ws
            ws.send(json.dumps(payload))
            while True:
                with self.lock:
                    now=time.monotonic()
                    if job['stop']:break
                    if now-job['at']>165 or now-job['poll']>20 or (job['state']=='running' and now-job['io']>45):
                        job['reason']='Session timed out'
                        break
                try:raw=ws.recv()
                except TimeoutError:continue
                except Exception as e:
                    if type(e).__name__=='WebSocketTimeoutException':continue
                    raise
                if not raw:break
                if len(raw)>262144:raise RuntimeError('Frame limit')
                msg=json.loads(raw)
                with self.lock:
                    kind=msg.get('type')
                    if kind=='stage':
                        job['state']='compiling' if msg.get('stage')=='compile' else 'running'
                        job['io']=time.monotonic()
                    elif kind=='data' and msg.get('stream') in ('stdout','stderr'):
                        text=str(msg.get('data',''));size=len(text.encode())
                        if job['bytes']+size>32768:
                            job['reason']='Output limit exceeded'
                            break
                        job['output']+=text.replace('\r\n','\n')
                        job['bytes']+=size;job['io']=time.monotonic()
                    elif kind=='exit':job['exit_code']=msg.get('code')
                    elif kind=='done':
                        job['reason']=msg.get('reason','Finished')
                        break
                    elif kind=='error':raise RuntimeError('Sandbox unavailable')
        except Exception:
            with self.lock:
                job['state']='failed';job['reason']='Interactive sandbox unavailable'
        finally:
            if ws:
                try:ws.close(timeout=.2)
                except Exception:pass
            with self.lock:
                job['socket']=None
                if job['state']!='failed':job['state']='finished'
                if not job['reason']:job['reason']='Execution ended'
                job['at']=time.monotonic()
            if acquired:self.execution.release()
