"""Fail closed when required integration evidence is absent or fails."""
import hashlib,json,pathlib,sys
p=pathlib.Path(sys.argv[1])
required=['inventory.json','scope.json','recomputed-summary.json','integration-http.json','message-compatibility.json','identity.json']
missing=[name for name in required if not(p/name).exists()]
required_steps=json.loads((pathlib.Path(__file__).parent/'phase3ad-required-steps.json').read_text())
ledger=[json.loads(line)for line in(p/'required-commands.jsonl').read_text().splitlines()]if(p/'required-commands.jsonl').exists()else[]
by_step={v['step']:v for v in ledger}
step_blockers=[name+(': NOT RUN'if name not in by_step else': exit '+str(by_step[name]['exit_code']))for name in required_steps if name not in by_step or by_step[name]['exit_code']!=0]
out={'readiness':'BLOCKED','missing_required':missing,'required_commands':ledger,'step_blockers':step_blockers,'blockers':[]}
if missing:
 out['blockers']=['Required evidence missing: '+','.join(missing)]+step_blockers
else:
 x=json.loads((p/'integration-http.json').read_text());i=json.loads((p/'inventory.json').read_text());core=json.loads((p/'recomputed-summary.json').read_text());scope=json.loads((p/'scope.json').read_text());m=json.loads((p/'message-compatibility.json').read_text())
 assert i['accepted_source']=='0db2d57a85f33710d5bf46b9c7162e8aa39885f6' and len(i['migrations'])==4
 assert scope['status']=='PASS' and scope['base']=='82b1192a8cb4bc943b57e42cbc3c0f4204389586'
 assert core['candidate']['timezone']=='PASS' and core['candidate']['single_use']=='PASS'
 b=x.get('browser',{'status':'NOT RUN'})
 blockers=list(x.get('integration_failures',[]))+step_blockers
 for name,status in [('Integration HTTP',x['status']),('Actual role browser',b['status']),('Message compatibility',m['status'])]:
  if status!='PASS':blockers.append(name+': '+status)
 if any(c.get('functional_status')=='FAIL' for c in m['cases']):blockers.append('Message compatibility retains a functional failure')
 out.update({'readiness':'BLOCKED'if blockers else'PASS','integration_http_status':x['status'],'matching':x.get('matching'),'selected_workflows':x.get('selected_workflows','NOT COMPLETE'),'role_browser':b,'blockers':blockers,'security_core':core['candidate'],'accepted_runtime_paths':len(i['runtime']),'repair_scope':scope,'message_cases':m['cases'],'excluded_prs':i['excluded_parallel_prs']})
identity=json.loads((p/'identity.json').read_text()) if (p/'identity.json').exists() else {}
out['identity_contract']='A-AD-1 owner approved; isolated implementation'
if identity.get('status')!='PASS' or identity.get('contracts',{}).get('status')!='PASS' or identity.get('migration_refusals',{}).get('status')!='PASS':
 out['blockers'].append('Identity contract evidence incomplete or failed')
 out['readiness']='BLOCKED'
out['identity_validation']=identity.get('claims',[])
out['file_sha256']={f.name:hashlib.sha256(f.read_bytes()).hexdigest()for f in p.glob('*.json')if f.name!='integration-summary.json'}
(p/'integration-summary.json').write_text(json.dumps(out,indent=2)+'\n');print(out['readiness'],out['blockers']);sys.exit(0 if out['readiness']=='PASS'else 1)
