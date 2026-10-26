"""Run four serial, alternating exact-base/candidate owned HTTP fixtures; preserve every run."""
import gzip,hashlib,json,os,pathlib,subprocess,sys
ROOT=pathlib.Path(__file__).resolve().parents[1]
BASE='2716766ab71f27d32d0d435d555f19801d7d0aa7'

def main():
 folder=pathlib.Path(os.environ.get('PHASE3S_OUTPUT',ROOT/'phase3s-raw')).resolve();folder.mkdir(exist_ok=True,parents=True)
 report={'base':BASE,'candidate_head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip(),'runs':[],'response_comparisons':[]}
 previous={};previous_samples={}
 for i,side in enumerate(['baseline','candidate','candidate','baseline']):
  path=folder/(str(i)+'-'+side+'.json');cmd=[sys.executable,'-B','-u',str(ROOT/'tests/classroom-performance.py')]
  env=os.environ|{'CLASSROOM_PHASE3S':'1','CLASSROOM_PROFILE':'0','CLASSROOM_FIXED_CLOCK':'1792800000','CLASSROOM_BASE':BASE,'CLASSROOM_VARIANT':side,'CLASSROOM_EVIDENCE':str(path)}
  with (folder/(str(i)+'-'+side+'.log')).open('w') as log:r=subprocess.run(cmd,cwd=ROOT,env=env,stdout=log,stderr=log,timeout=240)
  report['runs'].append({'side':side,'exit_code':r.returncode,'file':path.name,'command':cmd})
  if not path.exists():raise RuntimeError('Missing fixture evidence '+str(path))
  data=json.loads(path.read_text());scope=data.get('phase3s')
  if r.returncode==0:
   assert scope is not None
   mismatches=[]
   for key,value in scope['responses'].items():
    if key in previous and previous[key]!=value:mismatches.append(key)
    else:previous[key]=value
   hashes={}
   for cell in scope['cells']:
    for request in cell['requests']:
     key=(cell['label'],request['route'],request['uid'])
     hashes.setdefault(key,set()).add(request['response_sha256'])
   hash_mismatches=[str(key) for key,value in hashes.items() if key in previous_samples and previous_samples[key]!=value]
   if not previous_samples:previous_samples=hashes
   mismatches.extend(hash_mismatches)
   report['response_comparisons'].append({'run':i,'equal':not mismatches,'mismatches':mismatches,'complete_payloads':len(scope['responses']),'normalized_fields':['dashboard envelope generated_at only']})
  raw=path.read_bytes();gz=path.with_suffix('.json.gz');gz.write_bytes(gzip.compress(raw,mtime=0))
  report['runs'][-1]|={'raw_sha256':hashlib.sha256(raw).hexdigest(),'gzip_sha256':hashlib.sha256(gz.read_bytes()).hexdigest()}
  (folder/'comparison.json').write_text(json.dumps(report,indent=2))
  print(side,r.returncode,'cells',len(scope['cells']) if scope else None,flush=True)
  assert r.returncode==0,'Inspect retained run logs and partial evidence'
  assert not mismatches,('Complete response mismatch',mismatches,i)
 print('PASS full response comparisons across all four runs',len(previous),flush=True)
if __name__=='__main__':main()
