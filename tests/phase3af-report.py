"""Stop full defense certification on essential browser/install/restore failure."""
import hashlib,json,pathlib,sys
p=pathlib.Path(sys.argv[1]);out={'phase':'3AF','defense_certification':'BLOCKED','verification':'INCOMPLETE','merge':'NOT AUTHORIZED','production':'BLOCKED','blockers':[]}
try:
 source=json.loads((p/'phase3af-source.json').read_text());core=json.loads((p/'integration-summary.json').read_text());installed=json.loads((p/'phase3af-installed.json').read_text())
 assert source['status']=='PASS'and source['runtime_change']=='NONE'
 if core['readiness']!='PASS':out['blockers'].append('Previously required security/regression/integration core failed or incomplete')
 if installed['status']!='PASS':out['blockers'].append('Installed candidate validation: '+installed['status']+' '+installed.get('error',''))
 if installed.get('backup_restore',{}).get('status')!='PASS':out['blockers'].append('Complete installed backup/restore not verified')
 for c in installed.get('browser',{}).get('cases',[]):
  if c['status']!='PASS':out['blockers'].append(c['name']+': '+c.get('error','FAIL'))
 if installed.get('browser',{}).get('status')!='PASS':out['blockers'].append('Actual expanded browser journeys failed or NOT RUN')
 out.update({'verification':'COMPLETE WITH BLOCKERS'if out['blockers']else'PASS within isolated scope','defense_certification':'BLOCKED'if out['blockers']else'PENDING independent audit and final stable-environment acceptance','source':source,'installed_status':installed['status'],'browser':installed.get('browser',{}).get('cases',[]),'backup_restore':installed.get('backup_restore',{}).get('status','NOT RUN'),'persistent_environment':installed.get('persistent_environment',{'status':'NOT VERIFIED'}),'composed_upload_download':installed.get('composed_upload_download',{'status':'NOT VERIFIED'}),'retained_gates':['ws_tokens canonical capacity cleanup excluded','external integrations/live inference not certified','existing DB069/production OTP cutover,TLS/proxy/durability not approved','no50–100-user capacity certification','runner disk persistence is not a stable public defense deployment']})
except Exception as e:out['blockers'].append(str(e))
out['raw_sha256']={f.name:hashlib.sha256(f.read_bytes()).hexdigest()for f in p.glob('*.json')if f.name!='phase3af-summary.json'}
(p/'phase3af-summary.json').write_text(json.dumps(out,indent=2)+'\n');print(out['defense_certification'],out['blockers']);sys.exit(1 if out['blockers']else 0)
