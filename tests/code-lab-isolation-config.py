"""Verify effective Compose AND actual disposable Docker network/mount boundaries.
This proves CI topology only, not a separate production kernel or an escape-proof VM.
"""
import json,pathlib,subprocess
ROOT=pathlib.Path(__file__).resolve().parents[1]
compose=['docker','compose','-f',str(ROOT/'deploy/code-lab/piston/compose.yml'),'-f',str(ROOT/'deploy/code-lab/piston/compose.isolated.yml')]
def read(args):return json.loads(subprocess.check_output(args,text=True))
config=read(compose+['config','--format','json'])
for name in ['piston','adapter']:
 service=config['services'][name]
 assert set(service['networks'])=={'execution'},'Unexpected service network'
 assert config['networks']['execution']['internal'] is True
 ids=subprocess.check_output(compose+['ps','-q',name],text=True).strip().splitlines();assert len(ids)==1
 live=read(['docker','inspect',ids[0]])[0]
 assert '@sha256:' in service['image'] and live['Config']['Image']==service['image'],'Unpinned execution image'
 networks=live['NetworkSettings']['Networks'];assert len(networks)==1
 for network in networks:assert read(['docker','network','inspect',network])[0]['Internal'] is True
 assert live['HostConfig']['NetworkMode']!='host'
 for mount in live['Mounts']:
  source=mount.get('Source','')
  assert not any(x in source for x in ['docker.sock','/.ssh','/.env','/var/lib/mysql']), 'Forbidden host secret/storage mount'
 if name=='piston':
  assert service['privileged'] is True,'Do not claim Piston supports unprivileged mode without proof'
  assert not live['HostConfig'].get('PortBindings'),'Piston management listener published'
  assert live['HostConfig']['Memory']==1536*1024*1024
  assert live['HostConfig']['NanoCpus']==1000000000
  assert live['HostConfig']['PidsLimit']==256
  assert '/var/local/lib/isolate' in live['HostConfig']['Tmpfs'],'Unbounded sandbox scratch path'
  assert service['environment']['PISTON_DISABLE_NETWORKING']=='true'
 else:
  for ports in (live['HostConfig'].get('PortBindings') or {}).values():
   assert all(p['HostIp']=='127.0.0.1' for p in ports),'Adapter exposed beyond loopback'
  assert live['HostConfig']['ReadonlyRootfs'] is True
print('PASS actual CI internal-only Docker networks, private management, retained quotas, bounded isolate scratch and no application/DB/admin mounts; separate-host proof remains BLOCKED')
