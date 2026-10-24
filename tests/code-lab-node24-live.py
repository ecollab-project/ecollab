"""Real Node 24 execution and legacy compatibility checks; isolated staging only."""
import base64
import json
import os
import pathlib
import time
import urllib.request

config = json.loads((pathlib.Path(os.environ['CODE_LAB_RUNNER_STATE']) / 'profiles.json').read_text())
headers = {'X-Auth-Token': config['token'], 'Content-Type': 'application/json'}
address = 'http://127.0.0.1:2358'
profiles = {p['id']: p for p in config['profiles']}
assert set(profiles) == set(range(10001, 10008))
assert profiles[10005]['version'] == '20.11.1'
assert profiles[10007]['language'] == 'ecollab-node24'
assert profiles[10007]['version'] == '24.22.1'


def execute(language_id, source):
    body = {
        'language_id': language_id,
        'source_code': base64.b64encode(source.encode()).decode(),
        'stdin': '',
        'enable_network': False,
    }
    req = urllib.request.Request(address + '/submissions', json.dumps(body).encode(), headers)
    with urllib.request.urlopen(req, timeout=10) as response:
        token = json.load(response)['token']
    deadline = time.monotonic() + 60
    while time.monotonic() < deadline:
        req = urllib.request.Request(address + '/submissions/' + token, headers=headers)
        with urllib.request.urlopen(req, timeout=12) as response:
            result = json.load(response)
        if result['status']['id'] > 2:
            return result, base64.b64decode(result.get('stdout') or '')
        time.sleep(.25)
    raise RuntimeError('Node grading execution timed out')


legacy, output = execute(10005, 'console.log(process.version)')
assert legacy['status']['id'] == 3 and output.strip() == b'v20.11.1', 'Legacy JavaScript mapping changed'
modern, output = execute(10007, 'console.log(process.version);console.log(20+22)')
assert modern['status']['id'] == 3 and output.splitlines() == [b'v24.22.1', b'42'], 'Node 24 execution failed'
network, output = execute(10007, "const net=require('node:net');let s=net.connect(443,'1.1.1.1');s.on('error',()=>console.log('BLOCKED'));s.on('connect',()=>{console.log('NETWORK_OPEN');s.destroy()});s.setTimeout(1500,()=>{console.log('BLOCKED');s.destroy()})")
assert network['status']['id'] == 3 and b'BLOCKED' in output and b'NETWORK_OPEN' not in output, 'Node 24 networking was not blocked'
isolated, output = execute(10007, "const fs=require('fs');console.log(fs.existsSync('/runner/profiles.json')?'LEAK':'ISOLATED')")
assert isolated['status']['id'] == 3 and output.strip() == b'ISOLATED', 'Private runner state leaked'
timeout, output = execute(10007, 'while(true){}')
assert timeout['status']['id'] == 5, 'Node 24 sandbox run CPU limit missing'
print('PASS: legacy ID 10005, Node 24 ID 10007, CPU budget, network blocking, secret isolation')
