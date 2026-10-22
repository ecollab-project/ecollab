"""Configuration migration in a temporary copy only; never opens the deployed .env."""
import os,pathlib,re,shutil,subprocess,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='broker-config-') as folder:
 p=pathlib.Path(folder)/'app';(p/'scripts').mkdir(parents=True);(p/'deploy/centrifugo').mkdir(parents=True)
 shutil.copy(ROOT/'config.php',p/'config.php');shutil.copy(ROOT/'scripts/configure-centrifugo.php',p/'scripts/configure-centrifugo.php')
 legacy='CENTRIFUGO_TOKEN_SECRET='+'a'*64+'\nCENTRIFUGO_API_KEY='+'b'*64+'\nCENTRIFUGO_ENABLED=true\n'
 (p/'.env').write_text(legacy)
 env={k:v for k,v in os.environ.items() if not k.startswith('CENTRIFUGO_')}
 def run(mode):subprocess.run([os.environ.get('PHP_BIN','php'),str(p/'scripts/configure-centrifugo.php'),mode],env=env,check=True,capture_output=True)
 run('disable');assert 'CENTRIFUGO_ENABLED=false' in (p/'.env').read_text();assert 'CENTRIFUGO_PROXY_SECRET' not in (p/'.env').read_text()
 run('prepare');first=(p/'.env').read_text();broker=(p/'deploy/centrifugo/centrifugo.env').read_text()
 assert 'CENTRIFUGO_TOKEN_SECRET='+'a'*64 in first and 'CENTRIFUGO_API_KEY='+'b'*64 in first
 secret=re.search(r'^CENTRIFUGO_PROXY_SECRET=([a-f0-9]{64})$',first,re.M).group(1)
 assert 'CENTRIFUGO_PROXY_SECRET='+secret in broker and 'TOKEN' not in broker
 assert (p/'deploy/centrifugo/centrifugo.env').stat().st_mode&0o777==0o600
 run('prepare');assert (p/'.env').read_text()==first
 print('PASS isolated configuration: no production/API/legacy-key rotation, separate proxy secret retained, no broker JWT key, private file mode, disable works before migration')
