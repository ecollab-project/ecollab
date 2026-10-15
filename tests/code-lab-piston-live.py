"""Known test programs only, against the actual cgroup-v2 sandbox in CI."""
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
env=dict(os.environ,CODE_LAB_JUDGE0_URL=address,CODE_LAB_JUDGE0_TOKEN=config['token'],CODE_LAB_LANGUAGE_IDS=json.dumps(dict(zip(['c','cpp','python','java','javascript'],[p['id']for p in config['profiles']]))))
headers={'X-Auth-Token':config['token'],'Content-Type':'application/json'}
for attempt in range(30):
    try:
        with urllib.request.urlopen(urllib.request.Request(address+'/languages',headers=headers),timeout=2)as response:json.load(response)
        break
    except Exception:time.sleep(1)
else:raise RuntimeError('Adapter did not become ready')
subprocess.run(['php','scripts/check-code-lab-runner.php'],env=env,check=True,timeout=240)
subprocess.run(['php','scripts/test-code-lab-languages.php'],env=env,check=True,timeout=600)


def execute(source,stdin=''):
    body={'language_id':10003,'source_code':base64.b64encode(source.encode()).decode(),'stdin':base64.b64encode(stdin.encode()).decode(),'enable_network':False}
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
