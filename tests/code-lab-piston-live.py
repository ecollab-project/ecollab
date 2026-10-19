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


# Compile once, restore a fresh artifact/filesystem/process for every hidden case.
def grade(source,inputs,language_id):
    body={'language_id':language_id,'source_code':base64.b64encode(source.encode()).decode(),'stdin':'','test_inputs':[base64.b64encode(x.encode()).decode()for x in inputs],'enable_network':False}
    request=urllib.request.Request(address+'/grade-submissions',data=json.dumps(body).encode(),headers=headers)
    with urllib.request.urlopen(request)as response:token=json.load(response)['token']
    deadline=time.monotonic()+135
    while time.monotonic()<deadline:
        with urllib.request.urlopen(urllib.request.Request(address+'/submissions/'+token+'?wait_ms=1000',headers=headers),timeout=12)as response:result=json.load(response)
        if result['status']['id']>2:return result
    raise RuntimeError('Batch grading did not finish')

programs={
 10001:'#include <stdio.h>\nint main(){int n;scanf("%d",&n);printf("%d\\n",n+1);}',
 10002:'#include <iostream>\nint main(){int n;std::cin>>n;std::cout<<n+1<<std::endl;}',
 10003:'print(int(input())+1)',
 10006:'public class Main { public static void main(String[] a) { System.out.println(new java.util.Scanner(System.in).nextInt()+1); } }'
}
for language,source in programs.items():
    result=grade(source,['41','5'],language)
    assert result['status']['id']==3 and result['compile_count']==(0 if language==10003 else 1),'Compile-once protocol failed'
    assert [base64.b64decode(row['stdout']).strip()for row in result['results']]==[b'42',b'6'],'Batch case ordering/stdin failed'
    assert all(row['status']['id']==3 for row in result['results']),'Batch runtime failed'
result=grade('import pathlib\np=pathlib.Path("case-file");print("DIRTY" if p.exists() else "FRESH");p.write_text(input())',['first-secret','second-secret'],10003)
assert [base64.b64decode(row['stdout']).strip()for row in result['results']]==[b'FRESH',b'FRESH'],'Cases shared filesystem state'
result=grade('n=int(input())\nif n==1:\n while True: pass\nprint(42)',['1','2'],10003)
assert [row['status']['id']for row in result['results']]==[5,3],'Per-case CPU budget/timeout isolation failed'
result=grade('public class Main { syntax error }',['a','b'],10006)
assert result['compile_count']==1 and [row['status']['id']for row in result['results']]==[6,6],'Compiler errors must not execute cases'
subprocess.run(['php','scripts/check-code-lab-runner.php','--grading'],env=env,check=True,timeout=240)
print('PASS: real compile-once grading in four runtimes, pristine cases, independent CPU budgets, compilation failures and PHP batch client')
