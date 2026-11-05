"""Trusted programs against actual Piston. Prompts must precede every answer."""
import base64
import json
import os
import pathlib
import time
import urllib.request

config=json.loads((pathlib.Path(os.environ['CODE_LAB_RUNNER_STATE'])/'profiles.json').read_text())
base='http://127.0.0.1:2358'
headers={'X-Auth-Token':config['token'],'Content-Type':'application/json'}
def request(path,body=None):
    req=urllib.request.Request(base+path,headers=headers,data=None if body is None else json.dumps(body).encode())
    with urllib.request.urlopen(req,timeout=15)as response:return json.load(response)
def start(code,runtime):return request('/interactive',{'language_id':runtime,'source_code':base64.b64encode(code.encode()).decode(),'enable_network':False})['token']
def until(token,text=None,finished=False,timeout=45):
    end=time.monotonic()+timeout
    while time.monotonic()<end:
        result=request('/interactive/'+token)
        if (text is not None and text in result['output']) or (finished and result['state'] in ('finished','failed')):return result
        if result['state'] in ('finished','failed'):raise AssertionError('Exited before prompt: '+str(result))
        time.sleep(.1)
    raise AssertionError('Prompt/exit deadline: '+str(result))
programs=[
 (10003,'a=int(input("First? "));b=int(input("Second? "));print("Total="+str(a+b))'),
 (10001,'#include <stdio.h>\nint main(){int a,b;printf("First? ");if(scanf("%d",&a)!=1)return 1;printf("Second? ");if(scanf("%d",&b)!=1)return 1;printf("Total=%d\\n",a+b);return 0;}'),
 (10002,'#include <iostream>\nint main(){int a,b;std::cout<<"First? ";std::cin>>a;std::cout<<"Second? ";std::cin>>b;std::cout<<"Total="<<a+b<<std::endl;}'),
 (10006,'import java.util.Scanner; public class Main {public static void main(String[] x){Scanner s=new Scanner(System.in);System.out.print("First? ");int a=s.nextInt();System.out.print("Second? ");int b=s.nextInt();System.out.println("Total="+(a+b));}}')]
for runtime,code in programs:
    token=start(code,runtime)
    try:
        first=until(token,'First? ');assert first['input_seq']==0,'Prompt must arrive before input'
        request('/interactive/'+token+'/input',{'sequence':1,'data':'21\n','eof':False})
        # Simulate a lost client acknowledgement. This must not feed 21 to the second scanf/input.
        request('/interactive/'+token+'/input',{'sequence':1,'data':'21\n','eof':False})
        second=until(token,'Second? ');assert second['input_seq']==1
        request('/interactive/'+token+'/input',{'sequence':2,'data':'22\n','eof':False})
        result=until(token,finished=True)
        assert result['state']=='finished' and result['reason']=='Finished' and 'Total=43' in result['output'],str(result)
        assert result['output'].count('First? ')==1 and result['output'].count('Second? ')==1,'Process was replayed'
    finally:request('/interactive/'+token+'/stop',{})
    print('PASS: actual interactive prompts, persistent state, duplicate-input protection and completion for runtime '+str(runtime))
token=start('import sys\nprint("Paste then EOF:",flush=True)\ndata=sys.stdin.read()\nprint("Read="+str(len(data)))',10003)
until(token,'Paste then EOF:');request('/interactive/'+token+'/input',{'sequence':1,'data':'line\n','eof':True});result=until(token,finished=True);assert 'Read=5' in result['output'],str(result)
token=start('input("Waiting: ")',10003);until(token,'Waiting: ');request('/interactive/'+token+'/stop',{});assert until(token,finished=True)['reason']=='Stopped'
# A stopped/finished process frees a shared execution slot.
token=start('print("Next process")',10003);assert 'Next process' in until(token,finished=True)['output']
print('PASS: actual EOF, stop, cleanup and subsequent execution')
