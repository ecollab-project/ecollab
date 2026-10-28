"""Recompute security claims from raw records, independent of CI success labels."""
import hashlib,json,pathlib,sys
folder=pathlib.Path(sys.argv[1]);out={};hashes={p.name:hashlib.sha256(p.read_bytes()).hexdigest()for p in sorted(folder.glob('*.json'))}
def load(n):return json.loads((folder/n).read_text())
s=load('security.json');assert len(s['cohorts'])==5
clocks=[];races=[]
for c in s['cohorts']:
 assert c['status']=='PASS';assert c['cleanup']['remaining_synthetic_users']==0 and c['cleanup']['orphan_otp']==0
 clocks+=c['clock']['clock_records']
 for r in c['clock']['clock_records']:
  assert r['effective_database_ttl_seconds']==c['ttl'];assert r['service_success']==r['expected_predicate']
  if r['point']in ['expiry_exact','expiry_plus_one','ttl_plus_one']:assert not r['service_success']
 if c['race']:
  races+=c['race']['records']
  for r in c['race']['records']:
   assert r['independent_connections']and r['independent_sessions'];assert r['successful_consumptions']==1
   assert sum(w['reset_token_present']for w in r['workers'])==1
  assert c['security_controls']['status']=='PASS';assert c['transition']['legacy_used_rows_preserved_byte_for_byte']
  for r in c['adverse']['records']:assert not r['result']['success']and not r['result']['reset_token_present']
assert len(clocks)==180 and len(races)==8
out['candidate']={'clock_records':len(clocks),'race_rounds':len(races),'two_session_rounds':sum(len(r['workers'])==2 for r in races),'four_session_rounds':sum(len(r['workers'])==4 for r in races),'single_use':'PASS','timezone':'PASS'}
o=load('original-experiments.json');oldr=[r for c in o['cohorts']if c.get('race')for r in c['race']['records']];assert len(oldr)==10;assert all(r['successful_consumptions']==2 for r in oldr);out['original']={'race_rounds':10,'single_use':'FAIL (preserved)','clock_records':sum(len(v['clock_records'])for c in o['cohorts']for v in c['trials'].values())}
f=load('full.json');runs=json.loads(f['phase3x_suite']['stdout'])['runs'];assert runs['baseline']['counts']['failures']==5
for key in ['current','reverse','random-31028']:assert runs[key]['exit_code']==0 and runs[key]['counts']=={'tests':60,'assertions':280,'errors':0,'failures':0,'skipped':0}
out['full_suite']={k:{'exit_code':v['exit_code'],'counts':v['counts']}for k,v in runs.items()}
for name in ['accepted-base.json','accepted-phase3y.json','accepted-phase3z.json','accepted-phase3ab.json']:
 if not(folder/name).exists():continue
 x=json.loads(load(name)['phase3w_schema']['stdout'])['full_phpunit'];assert x['baseline']['exit_code']==0 and x['current']['exit_code']==0;out[name]={k:{'exit_code':x[k]['exit_code'],'tests':x[k]['completed_tests']}for k in ['baseline','current']}
a=load('static.json');assert not a['additional_diagnostics'];out['phpstan']={'added':0,'baseline':a['baseline']['report']['totals'],'candidate':a['candidate']['report']['totals']}
if(folder/'http.json').exists():
 h=load('http.json');assert all(c['status']=='PASS'for c in h['cohorts']);b=h['cohorts'][0]['browser'];assert b['status']=='PASS';assert any('persisted OTP'in r['name']for r in b['steps']);out['http']={'direct_requests':sum(len(c['requests'])for c in h['cohorts']),'browser':b['status'],'browser_version':b['browser_version']}
else:assert '--local'in sys.argv,'Hosted actual-candidate HTTP/Chromium evidence required'
out['raw_sha256']=hashes
(folder/'recomputed-summary.json').write_text(json.dumps(out,indent=2)+'\n');print(json.dumps(out,indent=2))
