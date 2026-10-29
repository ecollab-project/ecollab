"""A-AD-1 real owned FPM/MariaDB tests. No authentication or identity overlays."""
import importlib.util,json,os,pathlib,subprocess,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('integration',R/'tests/phase3ad-http.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);h=m.h

def main():
 target=pathlib.Path(os.environ.get('PHASE3AD_IDENTITY_EVIDENCE','phase3ad-raw/identity.json'));target.parent.mkdir(parents=True,exist_ok=True)
 out={'status':'FAIL','base':'82b1192a8cb4bc943b57e42cbc3c0f4204389586','head':h.git('rev-parse','HEAD'),'tree':h.git('rev-parse','HEAD^{tree}'),'working_diff':h.git('diff','HEAD'),'claims':[]}
 with tempfile.TemporaryDirectory(prefix='phase3ad-identity-')as t:
  o=h.Owned(pathlib.Path(t),'UTC','+00:00');o.env['PHASE3AD_OWNED_FRESH_IDENTITY']='1'
  try:
   o.start();out['migration']=o.migration
   out['migration_refusals']=m.runphp(o,'phase3ad-identity-migration.php',{})
   # Rerun before adding accounts: provenance/rows/flags must remain identical.
   cmd=[o.php,'-d','auto_prepend_file='+str(R/'tests/phase3w-migration-bootstrap.php'),str(R/'database/migrate.php'),'--force=069_system_identity.sql']
   r=subprocess.run(cmd,env=o.env,capture_output=True,text=True,timeout=120);out['migration_repeat']={'command':cmd,'exit_code':r.returncode,'stdout':r.stdout,'stderr':r.stderr};h.check(r.returncode==0,'identity migration idempotency')
   cmd=[o.php,'-d','auto_prepend_file='+str(R/'tests/phase3y-bootstrap.php'),str(R/'scripts/provision-system-account.php')]
   r=subprocess.run(cmd,env=o.env,capture_output=True,text=True,timeout=30);out['provision']={'exit_code':r.returncode,'stdout':r.stdout,'stderr':o.scrub_text(r.stderr)};h.check(r.returncode==0,'CLI provisioning');machine=json.loads(r.stdout)['created_user_id']
   r=subprocess.run(cmd,env=o.env,capture_output=True,text=True,timeout=30);out['duplicate_provision']={'exit_code':r.returncode,'stderr':o.scrub_text(r.stderr)};h.check(r.returncode!=0,'CLI collision refusal')
   actors=[o.registered()for _ in range(4)];fixture=m.runphp(o,'phase3ad-fixture.php',{'users':[c.uid for c,d in actors]});s,p,f,x=[c for c,d in actors]
   out['contracts']=m.runphp(o,'phase3ad-identity-control.php',{'action':'contracts','system_id':machine,'human_id':s.uid})
   ai=s.req('/API/ai/dm-account.php');h.check(ai['status']==200 and int(ai['json']['ai']['id'])==machine,'authoritative Jarred discovery');out['claims'].append('Jarred discovery resolves provisioned machine')
   match=s.req('/API/chat/peer-match.php?action=get_matches');out['matching']={'status':match['status'],'response':o.redact(match['json'])};h.check(match['status']==200 and match['json'].get('ok'),'real matching migrated schema')
   h.check(any(int(v['id'])==p.uid for v in match['json']['matches']),'registered human is eligible')
   h.check(str(machine) not in [str(v.get('id',v.get('user_id'))) for v in (match['json'].get('matches')or[])],'machine not returned as match')
   out['claims'].append('matching HTTP200; machine excluded')
   human_conv=s.req('/API/dm/open-conversation.php?partner_id='+str(p.uid));h.check(human_conv['status']==200,'human DM open');cid=human_conv['json']['conversation_id']
   sent=s.req('/API/dm/send-message.php','POST',{'conversation_id':cid,'body':'Owned identity human DM'});h.check(sent['status']==200 and sent['json'].get('is_ai') is False,'human DM send')
   history=p.req('/API/dm/open-conversation.php?partner_id='+str(s.uid));h.check('Owned identity human DM' in json.dumps(history['json']),'human DM history')
   denied=x.req('/API/dm/send-message.php','POST',{'conversation_id':cid,'body':'Denied outsider'});h.check(denied['status']==403,'DM outsider denial')
   denied=s.req('/API/dm/send-message.php','POST',{'conversation_id':cid,'body':'Denied CSRF'},csrf='invalid');h.check(denied['status']==403,'DM CSRF denial')
   aiconv=s.req('/API/dm/open-conversation.php?partner_id='+str(machine));h.check(aiconv['status']==200,'Jarred DM open');aid=aiconv['json']['conversation_id']
   denied=s.req('/API/dm/send-message.php','POST',{'conversation_id':aid,'body':'Denied CodeLab','surface':'code_lab'});h.check(denied['status']==403,'Jarred CodeLab denial')
   m.runphp(o,'phase3ad-identity-control.php',{'action':'scalar','sql':'INSERT INTO user_settings(user_id,jarred_enabled) VALUES('+str(s.uid)+',0) ON DUPLICATE KEY UPDATE jarred_enabled=0'})
   denied=s.req('/API/dm/send-message.php','POST',{'conversation_id':aid,'body':'Disabled Jarred'});h.check(denied['status']==403,'Jarred preference denial')
   m.runphp(o,'phase3ad-identity-control.php',{'action':'scalar','sql':'UPDATE user_settings SET jarred_enabled=1 WHERE user_id='+str(s.uid)})
   queued=s.req('/API/dm/send-message.php','POST',{'conversation_id':aid,'body':'Owned queued Jarred request'});h.check(queued['status']==202 and queued['json'].get('is_ai') is True,'Jarred authorized durable queue')
   out['claims'].append('human DM roundtrip; outsider/CSRF denial; Jarred CodeLab/preferences denied; authorized queue202; no inference worker executed')
   # Exercise fourth human writer through actual admin authentication.
   admin=h.Client(o).csrf();r=admin.req('/API/auth/login.php','POST',{'identifier':'admin@fatima.edu.ph','password':'Password123!'});h.check(r['json'].get('success'),'canonical admin actual login');admin.csrf()
   r=admin.req('/API/admin/dashboard-data.php?action=create_user','POST',{'username':'owned_endpoint_human','email':'owned-endpoint@example.test','full_name':'Owned Endpoint','password':'owned-valid-pass-42','role':'student','is_system':1});out['admin_create_status']=r['status'];h.check(r['json'].get('success'),'legacy admin writer')
   flag=m.runphp(o,'phase3ad-identity-control.php',{'action':'scalar','sql':"SELECT is_system FROM users WHERE username='owned_endpoint_human'"});h.check(int(flag['value'])==0,'legacy admin explicit human')
   r=admin.req('/API/admin/dashboard-data.php?action=create_user','POST',{'username':'ECOLLAB_AI','email':'reserved-endpoint@example.test','password':'owned-valid-pass-42'});h.check(r['status']==422,'legacy admin reserved denied')
   out['claims'].append('four human writers persist0; HTTP promotion cannot set1; reserved admin request422')
   out['status']='PASS'
  except Exception as e:out['error']=o.scrub_text(str(e))
  finally:
   out['requests']=o.records;out['controls']=o.controls;out['logs']={p.name:o.scrub_text(p.read_text())for p in [o.p/'php-errors.log',o.p/'fpm.log',o.p/'database.log']if p.exists()};o.stop();target.write_text(json.dumps(out,indent=2)+'\n')
 h.check(out['status']=='PASS',out.get('error','identity failed'));print(out['status'],len(out['requests']),out['claims'])
if __name__=='__main__':main()
