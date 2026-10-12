"""Package only the test runner and synthetic credentials, never application secrets."""
from pathlib import Path
import json, os, zipfile
os.umask(0o077)
root=Path(__file__).resolve().parent.parent
folder=root.parent/'ecollab-load-tests'
manifest=Path((folder/'LATEST').read_text().strip())
data=json.loads(manifest.read_text())
if data.get('cleaned_at'): raise SystemExit('Create a fresh fixture; the latest one was already cleaned')
kit=folder/'ecollab-k6-kit.zip'
with zipfile.ZipFile(kit,'w',zipfile.ZIP_DEFLATED) as archive:
    for filename in ['chat-capacity.js','plan.js','README.md']:
        archive.write(root/'tools/load-tests'/filename,filename)
    target={k:data[k] for k in ['fixture_id','base_url','ws_url','server_id','channel_id']}
    archive.writestr('target.json',json.dumps(target,indent=2))
    archive.writestr('accounts.json',json.dumps(data['users'],indent=2))
kit.chmod(0o600)
print('Private PC test kit: '+str(kit))
print('Copy via SCP; extract and run k6 on your PC or a separate machine. Do not publish this ZIP.')
