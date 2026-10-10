"""Add the broker WebSocket location to one existing HTTPS vhost, then validate."""
import os, re, sys, shutil, subprocess, time
from pathlib import Path
if os.geteuid() != 0:
    sys.exit('Run with sudo python3 scripts/install-centrifugo-nginx.py')
def mask(text):
    # Ignore quoted strings and comments when matching block braces/directives.
    return re.sub(r'''"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|\#[^\n]*''',lambda m:' '*len(m[0]),text)
candidates=[]
seen=set()
for link in [*Path('/etc/nginx/sites-enabled').glob('*'),*Path('/etc/nginx/conf.d').glob('*.conf')]:
    path=link.resolve()
    if path in seen or not path.is_file(): continue
    seen.add(path)
    text=path.read_text();clean=mask(text)
    for match in re.finditer(r'\bserver\s*\{',clean):
        start=match.end();depth=1;end=start
        while depth and end<len(clean):
            depth += (clean[end]=='{')-(clean[end]=='}');end+=1
        if depth: sys.exit('Unbalanced Nginx configuration; no change made')
        body=clean[start:end-1]
        names=re.findall(r'\bserver_name\s+([^;]+);',body)
        if any('ecollab.tech' in names.split() for names in names) and re.search(r'\blisten\s+[^;]*\b443\b',body):
            candidates.append((path,text,start,end))
if len(candidates)!=1: sys.exit('Could not identify exactly one ecollab.tech HTTPS server block. Add deploy/centrifugo/nginx-location.conf manually inside that block.')
path,text,start,end=candidates[0]
snippet=Path('/etc/nginx/snippets/ecollab-centrifugo.conf')
source=Path(__file__).resolve().parent.parent/'deploy/centrifugo/nginx-location.conf'
expected=source.read_text()
if snippet.exists() and snippet.read_text()!=expected: sys.exit('An existing Centrifugo snippet differs; review it before replacing')
if 'include /etc/nginx/snippets/ecollab-centrifugo.conf;' in text[start:end]:
    subprocess.run(['nginx','-t'],check=True);print('Nginx broker location already configured');sys.exit()
if '/realtime/connection/websocket' in text[start:end]: sys.exit('Broker location already exists; review the existing definition')
backup=Path(str(path)+'.before-centrifugo-'+time.strftime('%Y%m%d-%H%M%S'))
shutil.copy2(path,backup);backup.chmod(0o600)
snippet.parent.mkdir(exist_ok=True);snippet.write_text(expected);snippet.chmod(0o644)
try:
    path.write_text(text[:start]+'\n    include /etc/nginx/snippets/ecollab-centrifugo.conf;\n'+text[start:])
    subprocess.run(['nginx','-t'],check=True)
except Exception:
    path.write_text(text)
    sys.exit('Nginx validation failed; original vhost restored')
print('Nginx validated. Vhost backup: '+str(backup))
