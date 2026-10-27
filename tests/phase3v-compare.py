"""Eight identical-application controls, alternating legacy/existing067 schemas per cache block."""
import gzip,hashlib,json,os,pathlib,subprocess,sys
ROOT=pathlib.Path(__file__).resolve().parents[1]
BASE='e6f864dcda680f8239da089b9b3bc97f0d0c0cde'

def main():
 folder=pathlib.Path(os.environ.get('PHASE3V_OUTPUT',ROOT/'phase3v-raw')).resolve();folder.mkdir(exist_ok=True,parents=True)
 candidate=BASE  # Explicit identical-source controls, not an application candidate.
 harness=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
 subprocess.run(['git','cat-file','-e',candidate+'^{commit}'],cwd=ROOT,check=True)
 expected_harness=os.environ.get('PHASE3V_EXPECTED_HEAD') or harness
 assert subprocess.check_output(['git','rev-parse',expected_harness+'^{tree}'],cwd=ROOT,text=True)==subprocess.check_output(['git','rev-parse','HEAD^{tree}'],cwd=ROOT,text=True)
 report={'intended_harness_head':expected_harness,'application_refs':{'control_a':BASE,'control_b':BASE},'application_change':'NONE','intended_head':candidate,'base':BASE,'harness_head':harness,'cohort_contract':'Both application refs are the exact accepted base. Schema states vary only by existing migration067 in owned fixtures.','runs':[],'response_comparisons':[]}
 previous={};previous_samples={};previous_plans={}
 report['same_schema_pool_plan_comparisons']=[]
 for i,(cache,index,side) in enumerate([(mode,index,'control_a' if j%2==0 else 'control_b') for mode in ['off','on'] for j,index in enumerate(['legacy','existing_067','existing_067','legacy'])]):
  path=folder/(str(i)+'-'+cache+'-'+index+'-'+side+'.json');cmd=[sys.executable,'-B','-u',str(ROOT/'tests/classroom-performance.py')]
  env=os.environ|{'TZ':'UTC','CLASSROOM_APP_REF':BASE if side=='baseline' else candidate,'CLASSROOM_INTENDED_HEAD':candidate,'CLASSROOM_OPCACHE':cache,'CLASSROOM_PHASE3V':'1','PHASE3V_INDEX':index,'CLASSROOM_PROFILE':'0','CLASSROOM_FIXED_CLOCK':'1792800000','CLASSROOM_BASE':BASE,'CLASSROOM_VARIANT':side,'CLASSROOM_EVIDENCE':str(path)}
  with (folder/(str(i)+'-'+side+'.log')).open('w') as log:r=subprocess.run(cmd,cwd=ROOT,env=env,stdout=log,stderr=log,timeout=300)
  report['runs'].append({'side':side,'opcache':cache,'schema_variant':index,'exit_code':r.returncode,'file':path.name,'command':cmd})
  if not path.exists():raise RuntimeError('Missing fixture evidence '+str(path))
  data=json.loads(path.read_text());scope=data.get('phase3v')
  if r.returncode==0:
   assert scope is not None
   provenance=data['source_provenance']
   assert provenance['application_commit']==(BASE if side=='baseline' else candidate)
   assert provenance['intended_head']==candidate and provenance['intended_base']==BASE
   assert provenance['application_diff']=='' and provenance['uncommitted_substitutions']==[]
   assert provenance['harness_diff']==''
   assert not any(p['transitions'] for p in scope['plan_checkpoints'])
   assert len(scope['plan_checkpoints'])>=2
   plans={}
   for checkpoint in scope['plan_checkpoints']:
    dataset=checkpoint['label'].split('-')[0]
    for observation in checkpoint['observations']:plans[(dataset,observation['key'])]=observation['sha256']
   cohort=(cache,index)
   if cohort in previous_plans:
    differences=[str(k) for k,v in plans.items() if previous_plans[cohort].get(k)!=v]
    report['same_schema_pool_plan_comparisons'].append({'cache':cache,'index':index,'equal':not differences,'differences':differences})
   else:previous_plans[cohort]=plans

   mismatches=[]
   for key,value in scope['responses'].items():
    if key in previous and previous[key]!=value:mismatches.append(key)
    else:previous[key]=value
   hashes={}
   for cell in scope['cells']:
    if '-steady-' in cell['label']:continue
    for request in cell['requests']:
     key=(cell['label'],request['route'],request['uid'])
     hashes.setdefault(key,set()).add(request['response_sha256'])
   hash_mismatches=[str(key) for key,value in hashes.items() if key in previous_samples and previous_samples[key]!=value]
   if not previous_samples:previous_samples=hashes
   mismatches.extend(hash_mismatches)
   for cell in scope['cells']:
    if '-steady-' not in cell['label']:continue
    for request in cell['requests']:
     key=cell['label'].split('-')[0]+':'+request['route']+':'+str(request['uid'])
     payload=previous[key]
     digest=hashlib.sha256(json.dumps(payload,sort_keys=True,separators=(',',':'),ensure_ascii=False).encode()).hexdigest()
     assert request['response_sha256']==digest,('Steady response mismatch',key)
   report['response_comparisons'].append({'run':i,'equal':not mismatches,'mismatches':mismatches,'complete_payloads':len(scope['responses']),'normalized_fields':['dashboard envelope generated_at only']})
  raw=path.read_bytes();gz=path.with_suffix('.json.gz');gz.write_bytes(gzip.compress(raw,mtime=0))
  report['runs'][-1]|={'raw_sha256':hashlib.sha256(raw).hexdigest(),'gzip_sha256':hashlib.sha256(gz.read_bytes()).hexdigest()}
  (folder/'comparison.json').write_text(json.dumps(report,indent=2))
  print(side,r.returncode,'cells',len(scope['cells']) if scope else None,flush=True)
  assert r.returncode==0,'Inspect retained run logs and partial evidence'
  assert not mismatches,('Complete response mismatch',mismatches,i)
 print('PASS full response comparisons across all eight runs',len(previous),flush=True)
if __name__=='__main__':main()
