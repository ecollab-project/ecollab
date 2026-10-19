"""Known test programs only, against the actual cgroup-v2 sandbox in CI."""
import concurrent.futures
import base64
import json
import os
import pathlib
import subprocess
import time
import urllib.error
import urllib.request

state=pathlib.Path(os.environ['CODE_LAB_RUNNER_STATE'])
config=json.loads((state/'profiles.json').read_text())
address='http://127.0.0.1:2358'
env=dict(os.environ,CODE_LAB_JUDGE0_URL=address,CODE_LAB_JUDGE0_TOKEN=config['token'],CODE_LAB_LANGUAGE_IDS=json.dumps((dict(zip(['c','cpp','python','java','javascript'],[p['id']for p in config['profiles']])) | {'java':10006})))
headers={'X-Auth-Token':config['token'],'Content-Type':'application/json'}
for attempt in range(30):
    try:
        with urllib.request.urlopen(urllib.request.Request(address+'/languages',headers=headers),timeout=2)as response:json.load(response)
        break
    except Exception:time.sleep(1)
else:raise RuntimeError('Adapter did not become ready')
subprocess.run(['php','scripts/check-code-lab-runner.php'],env=env,check=True,timeout=240)
subprocess.run(['php','scripts/test-code-lab-languages.php'],env=env,check=True,timeout=600)


def execute(source,stdin='',language_id=10003):
    body={'language_id':language_id,'source_code':base64.b64encode(source.encode()).decode(),'stdin':base64.b64encode(stdin.encode()).decode(),'enable_network':False}
    request=urllib.request.Request(address+'/submissions?base64_encoded=true&wait=false',data=json.dumps(body).encode(),headers=headers)
    with urllib.request.urlopen(request)as response:token=json.load(response)['token']
    deadline=time.monotonic()+50
    while time.monotonic()<deadline:
        with urllib.request.urlopen(urllib.request.Request(address+'/submissions/'+token,headers=headers))as response:result=json.load(response)
        if result['status']['id']>2:return result
        time.sleep(.25)
    raise RuntimeError('Execution did not finish')

result=execute('a,b=map(int,input().split());print(a+b)','20 22')
assert result['status']['id']==3 and base64.b64decode(result['stdout']).strip()==b'42','Real stdin execution failed'
result=execute('while True: pass')
assert result['status']['id']==5,'CPU/wall timeout not enforced'
result=execute('import socket\ns=socket.socket();s.settimeout(1)\ntry:\n s.connect(("1.1.1.1",443));print("NETWORK_OPEN")\nexcept OSError:\n print("NETWORK_BLOCKED")')
assert result['status']['id']==3 and b'NETWORK_BLOCKED' in base64.b64decode(result['stdout']),'Sandbox network isolation failed'
result=execute('print("x"*1000000)')
assert result['status']['id']!=3,'Output cap not enforced'
result=execute('this is not valid python !')
assert result['status']['id']==11,'Runtime/syntax error not reported'
result=execute('import os;print("PRIVATE_FILE" if os.path.exists("/runner/profiles.json") else "ISOLATED")')
assert result['status']['id']==3 and base64.b64decode(result['stdout']).strip()==b'ISOLATED','Adapter credentials visible inside sandbox'
print('PASS: five real language compilers/runtimes, stdin, timeout, blocked networking, output limits and filesystem isolation')

# Three submissions: at most two active; overlapping jobs cannot share a working file.
def parallel_program(index):
    source='import time,json,pathlib\np=pathlib.Path("same-name.txt");p.write_text("slot-'+str(index)+'")\nstart=time.monotonic();time.sleep(1.5)\nprint(json.dumps({"start":start,"end":time.monotonic(),"value":p.read_text()}))'
    result=execute(source)
    assert result['status']['id']==3,'Concurrent real sandbox execution failed'
    value=json.loads(base64.b64decode(result['stdout']))
    assert value['value']=='slot-'+str(index),'Concurrent sandboxes shared a working file'
    return value
with concurrent.futures.ThreadPoolExecutor(max_workers=3)as pool:
    intervals=list(pool.map(parallel_program,range(3)))
events=sorted([(r['start'],1)for r in intervals]+[(r['end'],-1)for r in intervals])
active=0;peak=0
for _,delta in events:active+=delta;peak=max(peak,active)
assert peak==2,'Expected exactly two overlapping isolated programs, never three'
print('PASS: actual two-slot sandbox overlap, bounded concurrency, independent working files')

def java_program(index):
    source='public class Main { public static void main(String[] args) throws Exception { java.nio.file.Path p=java.nio.file.Path.of("same-java-file.txt"); java.nio.file.Files.writeString(p,"java-'+str(index)+'"); Thread.sleep(1000); System.out.println(java.nio.file.Files.readString(p)); } }'
    result=execute(source,language_id=10006)
    assert result['status']['id']==3 and base64.b64decode(result['stdout']).strip()==('java-'+str(index)).encode(),'Concurrent Java memory/process isolation failed'
with concurrent.futures.ThreadPoolExecutor(max_workers=2)as pool:list(pool.map(java_program,range(2)))
print('PASS: concurrent Java compilation/execution under the unchanged shared memory/process limits')

subprocess.run(['php','tests/code-lab-worker-pool.php'],env=dict(env,CODE_LAB_POOL_HTTP_TEST='1'),check=True,timeout=30)
print('PASS: supervisor SIGTERM preserves both real in-flight compiler HTTP results')

# Compilation diagnostics must be classified even when Piston omits `run`.
result=execute('public class Main { this does not compile }',language_id=10006)
assert result['status']['id']==6 and base64.b64decode(result['compile_output']),'Java compilation failure classification failed'
result=execute('public class Main { public static void main(String[] args) { while(true) {} } }',language_id=10006)
assert result['status']['id']==5,'Compiled Java runtime CPU limit was weakened'
result=execute('public class Main { public static void main(String[] args) { System.out.println("LEGACY_OK"); } }',language_id=10004)
assert result['status']['id']==3 and base64.b64decode(result['stdout']).strip()==b'LEGACY_OK','Frozen legacy Java mapping was changed'
print('PASS: compiled Java phases, compiler diagnostics without run result, unchanged run CPU timeout and legacy Java compatibility')
