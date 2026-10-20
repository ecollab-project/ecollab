"""Install approved runtime packages and freeze language/version profiles once."""
import json
import os
import re
import secrets
import sys
import time
from bridge import piston_request


def numeric_version(version):
    if not re.fullmatch(r'\d+\.\d+\.\d+',version):
        return (-1,)
    return tuple(map(int,version.split('.')))


def prepare():
    for attempt in range(60):
        try:
            available=piston_request('/packages',timeout=30)
            break
        except Exception:
            time.sleep(1)
    else:
        raise RuntimeError('Piston did not become ready')
    for aliases in [('gcc',),('python',),('java',),('node','javascript')]:
        candidates=[p for p in available if p['language'] in aliases and numeric_version(p['language_version'])>(-1,)]
        if not candidates:
            raise RuntimeError('Required runtime package unavailable: '+str(aliases))
        selected=max(candidates,key=lambda p:numeric_version(p['language_version']))
        if not selected['installed']:
            print('Installing '+selected['language']+' '+selected['language_version'],file=sys.stderr,flush=True)
            piston_request('/packages',{'language':selected['language'],'version':selected['language_version']},timeout=600)
    runtimes=piston_request('/runtimes')
    profiles=[]
    definitions=[(10001,'C',('c',),'main.c'),(10002,'C++',('c++','cpp'),'main.cpp'),(10003,'Python',('python','python3'),'main.py'),(10004,'Java',('java',),'Main.java'),(10005,'JavaScript',('javascript','js','node'),'main.js')]
    for identifier,name,aliases,filename in definitions:
        candidates=[r for r in runtimes if set(aliases)&set([r['language']]+r.get('aliases',[]))]
        if not candidates:
            raise RuntimeError('Installed runtime unavailable: '+name)
        chosen=max(candidates,key=lambda r:numeric_version(r['version']))
        profiles.append({'id':identifier,'name':name,'language':chosen['language'],'version':chosen['version'],'filename':filename})
    return {'token':secrets.token_hex(32),'profiles':profiles}


if __name__=='__main__':
    json.dump(prepare(),sys.stdout)
