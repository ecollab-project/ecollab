"""Append a compiled Java profile without reassigning immutable legacy IDs."""
import copy
import json
import pathlib
import time
from bridge import piston_request


def upgrade(config, runtimes):
    result=copy.deepcopy(config)
    ids=[p['id'] for p in result['profiles']]
    if len(ids)!=len(set(ids)) or not set(range(10001,10006)).issubset(ids):
        raise ValueError('Existing frozen profiles required')
    original=next(p for p in result['profiles'] if p['id']==10004)
    if original['language']!='java' or original['version']!='15.0.2':
        raise ValueError('Expected frozen legacy Java 15.0.2')
    profile={'id':10006,'name':'Java (compiled, JDK 15.0.2)','language':'ecollab-java-compiled','version':'1.0.0','filename':'Main.java'}
    if not any(r['language']==profile['language'] and r['version']==profile['version'] for r in runtimes):
        raise ValueError('Compiled Java package not loaded')
    if 10006 in ids:
        if next(p for p in result['profiles'] if p['id']==10006)!=profile:
            raise ValueError('Compiled Java profile conflicts with frozen mapping')
    else:
        result['profiles'].append(profile)
    return result


if __name__=='__main__':
    for attempt in range(60):
        try:
            runtimes=piston_request('/runtimes',timeout=3)
            break
        except Exception:
            time.sleep(1)
    else:
        raise RuntimeError('Piston did not become ready')
    config=json.loads(pathlib.Path('/runner/profiles.json').read_text())
    print(json.dumps(upgrade(config,runtimes)))
