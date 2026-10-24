"""Append immutable JavaScript Node 24 to the frozen legacy profiles; never renumber IDs."""
import copy
import json
import pathlib
import time

from bridge import piston_request

NODE24 = {
    'id': 10007,
    'name': 'JavaScript (Node.js 24.22.1)',
    'language': 'ecollab-node24',
    'version': '24.22.1',
    'filename': 'main.js',
}


def upgrade(config, runtimes):
    result = copy.deepcopy(config)
    profiles = result.get('profiles', [])
    ids = [p['id'] for p in profiles]
    required = set(range(10001, 10007))
    if not isinstance(result.get('token'), str) or len(result['token']) < 32:
        raise ValueError('Existing private token required')
    if len(ids) != len(set(ids)) or not required.issubset(ids) or set(ids) - {10007} != required:
        raise ValueError('Six frozen runtime IDs 10001-10006 required')
    legacy_js = next(p for p in profiles if p['id'] == 10005)
    if legacy_js['language'] not in ('node', 'javascript') or legacy_js['version'] != '20.11.1':
        raise ValueError('Expected immutable legacy JavaScript 20.11.1 profile')
    java = next(p for p in profiles if p['id'] == 10006)
    if java['language'] != 'ecollab-java-compiled' or java['version'] != '1.0.0':
        raise ValueError('Expected frozen compiled Java profile')
    if not any(r.get('language') == NODE24['language'] and r.get('version') == NODE24['version'] for r in runtimes):
        raise ValueError('Verified Node 24 runtime not loaded by Piston')
    if 10007 in ids:
        if next(p for p in profiles if p['id'] == 10007) != NODE24:
            raise ValueError('Node 24 profile conflicts with frozen mapping')
    else:
        profiles.append(copy.deepcopy(NODE24))
    return result


if __name__ == '__main__':
    for attempt in range(60):
        try:
            available = piston_request('/runtimes', timeout=3)
            break
        except Exception:
            time.sleep(1)
    else:
        raise RuntimeError('Piston runtimes unavailable')
    config = json.loads(pathlib.Path('/runner/profiles.json').read_text())
    print(json.dumps(upgrade(config, available)))
