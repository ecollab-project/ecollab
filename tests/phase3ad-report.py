"""Do not turn a matching-schema failure into integration acceptance."""
import hashlib,json,pathlib,sys
p=pathlib.Path(sys.argv[1]);x=json.loads((p/'integration-http.json').read_text());i=json.loads((p/'inventory.json').read_text());core=json.loads((p/'recomputed-summary.json').read_text())
assert i['accepted_source']=='0db2d57a85f33710d5bf46b9c7162e8aa39885f6' and len(i['migrations'])==4
assert core['candidate']['timezone']=='PASS' and core['candidate']['single_use']=='PASS'
b=x.get('browser',{'status':'NOT RUN'});out={'readiness':'PASS'if x['status']=='PASS'else'BLOCKED','integration_http_status':x['status'],'matching':x.get('matching'),'selected_workflows':x.get('selected_workflows','NOT COMPLETE'),'role_browser':b,'blockers':x.get('integration_failures',[x.get('error','unknown')]),'security_core':core['candidate'],'runtime_paths':len(i['runtime']),'excluded_prs':i['excluded_parallel_prs'],'file_sha256':{f.name:hashlib.sha256(f.read_bytes()).hexdigest()for f in p.glob('*.json')if f.name!='integration-summary.json'}}
(p/'integration-summary.json').write_text(json.dumps(out,indent=2)+'\n');print(out['readiness'],out['blockers']);sys.exit(0 if out['readiness']=='PASS'else 1)
