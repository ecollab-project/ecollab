"""Harmless, bounded resource probes on disposable actual Piston only; never production."""
import base64,json,os,pathlib,subprocess,time,urllib.request
assert os.environ.get('GITHUB_ACTIONS')=='true', 'Resource probes are restricted to disposable CI'
assert os.environ.get('DOCKER_HOST','unix:///var/run/docker.sock')=='unix:///var/run/docker.sock', 'Only owned local CI Docker allowed'
config=json.loads(pathlib.Path(os.environ['CODE_LAB_RUNNER_STATE'],'profiles.json').read_text())
address='http://127.0.0.1:2358';headers={'X-Auth-Token':config['token'],'Content-Type':'application/json'}
def execute(source,language_id=10003):
 body={'language_id':language_id,'source_code':base64.b64encode(source.encode()).decode(),'stdin':'','enable_network':False}
 with urllib.request.urlopen(urllib.request.Request(address+'/submissions',data=json.dumps(body).encode(),headers=headers),timeout=3) as r:token=json.load(r)['token']
 limit=time.monotonic()+50
 while time.monotonic()<limit:
  with urllib.request.urlopen(urllib.request.Request(address+'/submissions/'+token+'?wait_ms=1000',headers=headers),timeout=12) as r:result=json.load(r)
  if result['status']['id']>2:return result
 raise AssertionError('Bounded security probe failed to finish')
subprocess.run(['docker','compose','-f','deploy/code-lab/piston/compose.yml','-f','deploy/code-lab/piston/compose.isolated.yml','exec','-T','piston','sh','-c','printf fixture-only > /ecollab-host-canary; test -f /ecollab-host-canary'],check=True)
r=execute('import os;print("ESCAPED" if os.path.exists("/ecollab-host-canary") else "CONFINED")')
assert r['status']['id']==3 and base64.b64decode(r['stdout']).strip()==b'CONFINED','Sandbox can read a container-host canary'
# Less than one-third of the existing container memory ceiling; never an unbounded allocator.
r=execute('a=bytearray(300*1024*1024);print("MEMORY_LIMIT_MISSING")')
assert r['status']['id'] in (5,11), 'Execution memory limit not enforced or infrastructure error'
# At most80 children, each sleeping1s; no recursive forking or fork bomb.
r=execute('#include <unistd.h>\n#include <stdio.h>\n#include <sys/wait.h>\nint main(){int n=0;for(;n<80;n++){int p=fork();if(p<0)break;if(p==0){sleep(1);_exit(0);}}while(wait(0)>0){}printf("%d\\n",n);}',10001)
assert r['status']['id']==3 and 0<=int(base64.b64decode(r['stdout']).strip())<64,'Process cap not enforced'
# At most12MiB attempted, compared to an explicit10,000,000-byte per-file cap.
r=execute('with open("bounded-file","wb") as f:\n for i in range(12): f.write(b"x"*1048576)\nprint("FILE_LIMIT_MISSING")')
assert r['status']['id'] in (5,11),'File-size limit not enforced or infrastructure error'
print('PASS actual bounded memory, process and per-file resource probes; no host escape or hostile stress test performed')
