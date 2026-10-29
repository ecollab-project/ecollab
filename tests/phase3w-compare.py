"""Same-source schema/index controls; no application candidate."""
import gzip,hashlib,json,os,pathlib,subprocess,sys
ROOT=pathlib.Path(__file__).resolve().parents[1];BASE='e818bbd84ae85d67aa71a38460fe154a5128a930'
def main():
 folder=pathlib.Path(os.environ.get('PHASE3W_OUTPUT',ROOT/'phase3w-raw')).resolve();folder.mkdir(exist_ok=True,parents=True);head=subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip();expected=os.environ.get('PHASE3W_EXPECTED_HEAD',head)
 tree=lambda ref:subprocess.check_output(['git','rev-parse',ref+'^{tree}'],cwd=ROOT,text=True).strip()
 assert tree(expected)==tree(head);report={'base':BASE,'application_ref':BASE,'application_change':'NONE','harness_commit':head,'harness_tree':tree(head),'intended_harness_head':expected,'opcache':'on in every pool','runs':[],'response_comparisons':[]};previous={};native_previous={}
 for i,(schema,index)in enumerate((s,index)for s in ['minimal','full']for index in ['absent','present','present','absent']):
  path=folder/(str(i)+'-'+schema+'-'+index+'.json');cmd=[sys.executable,'-B','-u',str(ROOT/('tests/phase3ad-repair-suite.py' if os.environ.get('PHASE3AD_IDENTITY')=='1' else 'tests/classroom-performance.py'))];env=os.environ|{'TZ':'UTC','CLASSROOM_BASE':BASE,'CLASSROOM_APP_REF':BASE,'CLASSROOM_INTENDED_HEAD':BASE,'CLASSROOM_PHASE3W':'1','CLASSROOM_PROFILE':'0','CLASSROOM_FIXED_CLOCK':'1792800000','CLASSROOM_OPCACHE':'on','PHASE3W_SCHEMA':schema,'PHASE3W_INDEX':index,'PHASE3W_SECONDS':'30','CLASSROOM_EVIDENCE':str(path)}
  with (folder/(str(i)+'.log')).open('w')as log:r=subprocess.run(cmd,cwd=ROOT,env=env,stdout=log,stderr=log,timeout=420)
  info={'schema':schema,'index':index,'exit_code':r.returncode,'file':path.name,'command':cmd};report['runs'].append(info)
  if path.exists():
   raw=path.read_bytes();z=gzip.compress(raw,mtime=0);path.with_suffix('.json.gz').write_bytes(z);info|={'raw_sha256':hashlib.sha256(raw).hexdigest(),'gzip_sha256':hashlib.sha256(z).hexdigest()}
  (folder/'comparison.json').write_text(json.dumps(report,indent=2)+'\n');assert r.returncode==0,('Inspect retained failed run and partial evidence',i)
  d=json.loads(raw);source=d['source_provenance'];assert source['application_commit']==BASE and source['application_tree']==tree(BASE)and source['application_diff']==''and source['uncommitted_substitutions']==[];assert source['harness_diff']==''and d['phase3w_driver_source']['driver_diff']=='';assert d['phase3w']['write_verification']['status']=='PASS'
  payloads={key:{k:v for k,v in p.items()if k!='generated_at'}for key,p in d['phase3w']['static_responses'].items()}
  equal=schema not in previous or previous[schema]==payloads;report['response_comparisons'].append({'run':i,'schema':schema,'complete_payloads':len(payloads),'equal':equal,'normalized':['dashboard envelope generated_at only'],'dynamic_read_snapshots':'Retained but not compared across different write interleavings.'});previous.setdefault(schema,payloads)
  native=json.loads(d['phase3w']['native_profile']['stdout']);values={name:{phase:[r['unread']for r in rows]for phase,rows in c['samples'].items()}for name,c in native['cases'].items()};assert schema not in native_previous or native_previous[schema]==values;native_previous.setdefault(schema,values)
  (folder/'comparison.json').write_text(json.dumps(report,indent=2)+'\n');print('PHASE3W CONTROL',i,schema,index,'exit',r.returncode,'responses_equal',equal,flush=True);assert equal,('Complete static response mismatch',schema,i)
 print('PASS eight same-source controls, typed history plans, native counts and operation-log invariants',flush=True)
if __name__=='__main__':main()
