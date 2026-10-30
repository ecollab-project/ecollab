"""Separate isolated rehearsal PASS from merge/deployment BLOCKED."""
import json,pathlib,sys,hashlib
p=pathlib.Path(sys.argv[1]);out={'phase':'3AE','rehearsal':'BLOCKED','merge_execution':'NOT AUTHORIZED','production_transition':'BLOCKED','defense_environment_certification':'PENDING'}
try:
 source=json.loads((p/'phase3ae-source.json').read_text());integration=json.loads((p/'integration-summary.json').read_text());identity=json.loads((p/'identity.json').read_text())
 assert source['runtime_identity']=='BYTE-IDENTICAL to accepted3AD' and source['merge_simulation']['exit_code']==0
 assert source['commits_ahead']==70 and len(source['runtime'])==69 and len(source['migration_additions'])==6
 assert integration['readiness']=='PASS' and not integration['blockers']
 assert identity['status']=='PASS' and identity['contracts']['synthetic_fixture_cleanup']['status']=='BLOCKED'
 out.update({'rehearsal':'PASS','source':{k:source[k]for k in ['integration','accepted_head','accepted_tree','rehearsal_head','rehearsal_tree','runtime_identity']},'cumulative_runtime_paths':69,'migration_additions':source['migration_additions'],'excluded_parallel_prs':[v['number']for v in source['prs']if not v['ancestor_of_accepted']],'merge_conflicts':[],'known_blockers':[identity['contracts']['synthetic_fixture_cleanup'],'Existing/production069 identity classification and coordinated OTP transition not approved','External services/full composed upload-download/50-100-user capacity not certified'],'next':'Independent Phase3AE audit;then bounded Phase3AF candidate testing only,no merge/deployment'})
except Exception as e:out['error']=str(e)
out['files']={f.name:hashlib.sha256(f.read_bytes()).hexdigest()for f in p.glob('*')if f.is_file()and f.name!='phase3ae-summary.json'}
(p/'phase3ae-summary.json').write_text(json.dumps(out,indent=2)+'\n');print(out['rehearsal'],out.get('known_blockers',out.get('error')));sys.exit(0 if out['rehearsal']=='PASS'else 1)
