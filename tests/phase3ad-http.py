"""Actual application integration with real account creation/login; no session seeding."""
import importlib.util,json,os,pathlib,subprocess,tempfile
R=pathlib.Path(__file__).resolve().parents[1];spec=importlib.util.spec_from_file_location('owned',R/'tests/phase3ad-gateway.py');h=importlib.util.module_from_spec(spec);spec.loader.exec_module(h)
def runphp(o,name,data):
 cmd=[o.php,str(R/'tests'/name)];r=subprocess.run(cmd,input=json.dumps(data),env=o.env,capture_output=True,text=True,timeout=60);o.controls.append({'command':cmd,'exit_code':r.returncode,'private_input':'omitted','stderr':o.scrub_text(r.stderr)});h.check(r.returncode==0,'Owned fixture failed');return json.loads(r.stdout)
def main():
 target=pathlib.Path(os.environ.get('PHASE3AD_HTTP_EVIDENCE','phase3ad-raw/integration-http.json'));target.parent.mkdir(exist_ok=True,parents=True)
 out={'accepted_source':'0db2d57a85f33710d5bf46b9c7162e8aa39885f6','head':h.git('rev-parse','HEAD'),'tree':h.git('rev-parse','HEAD^{tree}'),'intended_head':os.environ.get('PHASE3AD_EXPECTED_HEAD','UNPUBLISHED'),'working_diff':h.git('diff','HEAD'),'hashes':{str(p.relative_to(R)):h.digest(p.read_bytes())for p in sorted((R/'tests').glob('phase3ad-*'))if p.is_file()}}
 with tempfile.TemporaryDirectory(prefix='phase3ad-')as temp:
  o=h.Owned(pathlib.Path(temp),'UTC','+00:00')
  try:
   o.start();actors=[o.registered()for _ in range(4)];fixture=runphp(o,'phase3ad-fixture.php',{'users':[c.uid for c,d in actors]});out['fixture']=fixture
   for c,d in actors:
    c.req('/API/auth/logout.php','POST',{});c.csrf();r=c.req('/API/auth/login.php','POST',{'identifier':d['email'],'password':d['password']});h.check(r['json']['success'],'real role login');c.csrf()
   s,p,f,x=[a[0]for a in actors];sid=fixture['server'];cid=fixture['channels']['general'];private=fixture['channels']['private'];tid=fixture['thread']
   def expect(c,path,status=200,method='GET',body=None,csrf='valid'):
    r=c.req(path,method,body,csrf=csrf);h.check(r['status']==status,'HTTP '+path+' got '+str(r['status']));return r['json']
   for c,role in [(s,'student'),(f,'facilitator')]:
    d=expect(c,'/API/dashboard/live.php');h.check(d['success']and d['role']==role,'role dashboard');expect(c,'/modules/'+role+'/dashboard.php')
   for c,mode in [(s,'student'),(f,'facilitator')]:h.check(expect(c,f'/API/student/workspace.php?mode={mode}&server_id={sid}')['success'],'workspace')
   expect(s,f'/API/student/workspace.php?mode=facilitator&server_id={sid}',403);expect(x,f'/API/chat/get-messages.php?channel_id={cid}',403)
   expect(p,f'/API/chat/get-messages.php?channel_id={private}',403);expect(s,f'/API/chat/get-messages.php?channel_id={private}')
   expect(s,'/API/chat/send-message.php',403,'POST',{'channel_id':cid,'content':'CSRF rejected'},csrf='invalid')
   sent=expect(s,'/API/chat/send-message.php',201,'POST',{'channel_id':cid,'content':'Phase3AD actual HTTP message','content_type':'text'});out['sent_message_id']=sent['message']['id']
   history=expect(p,f'/API/chat/get-messages.php?channel_id={cid}');h.check('Phase3AD actual HTTP message'in json.dumps(history),'message history roundtrip')
   thread=expect(s,f'/API/threads/index.php?action=get&id={tid}');h.check('phase3ad.txt'in json.dumps(thread),'thread attachment metadata')
   expect(x,f'/API/threads/index.php?action=get&id={tid}',404)
   created=expect(s,'/API/threads/index.php',201,'POST',{'action':'create','scope':'channel','server_id':sid,'channel_id':cid,'title':'Phase3AD created','body':'Real HTTP thread'});h.check(created['thread_id']>0,'create thread')
   announcement=expect(f,'/API/student/workspace.php?mode=facilitator&action=announcement',200,'POST',{'server_id':sid,'title':'Phase3AD classroom update','message':'Owned facilitator announcement'});h.check(announcement['success'],'owned facilitator classroom write');expect(s,'/API/student/workspace.php?mode=facilitator&action=announcement',403,'POST',{'server_id':sid,'title':'Denied','message':'Denied'});
   notifications=expect(s,'/API/notifications/get.php');h.check(any(int(n['id'])==fixture['notification']for n in notifications['notifications']),'owned notification')
   matched=s.req('/API/chat/peer-match.php?action=get_matches');out['matching']={'status':'PASS'if matched['status']==200 and matched['json'].get('ok')and matched['json'].get('profile_ready')is not False else'FAIL','http_status':matched['status'],'response':o.redact(matched['json'])};out['integration_failures']=[] if out['matching']['status']=='PASS'else['peer matching on real migrated schema fails;users.is_system missing']
   if os.environ.get('PHASE3AD_REQUIRE_BROWSER')=='1':
    env=o.env|{'PHASE3AD_ORIGIN':f'http://127.0.0.1:{o.port}','PHASE3AD_ACTORS':json.dumps([{'email':d['email'],'role':'facilitator'if i==2 else'student'}for i,(c,d)in enumerate(actors)]),'PHASE3AD_BROWSER_OUT':str(o.p/'browser.json'),'PHASE3AD_FIXTURE':json.dumps(fixture)}
    r=subprocess.run(['node',str(R/'tests/phase3ad-browser.cjs')],env=env,capture_output=True,text=True,timeout=120);out['browser']=json.loads((o.p/'browser.json').read_text())if(o.p/'browser.json').exists()else{'status':'FAIL'};h.check(r.returncode==0 and out['browser']['status']=='PASS','actual role browser')
   else:out['browser']={'status':'NOT RUN locally;required hosted'}
   out['status']='FAIL'if out['integration_failures']else'PASS';out['selected_workflows']='PASS:roles,dashboards,workspace,channelmessage/history,facilitatorannouncement,thread/attachmentmetadata,notifications,access/CSRF';out['requests']=o.records;out['controls']=o.controls;out['inventory']=o.inventory;out['migration']=o.migration;out['logs']={p.name:o.scrub_text(p.read_text())for p in [o.p/'php-errors.log',o.p/'fpm.log']if p.exists()};out['external_features']='BLOCKED:broker/media,CodeLab,liveAI/worker,Collabora not exercised'
  except Exception as e:out.update({'status':'FAIL','error':o.scrub_text(str(e)),'requests':o.records,'controls':o.controls,'logs':{p.name:o.scrub_text(p.read_text())for p in [o.p/'php-errors.log',o.p/'database.log',o.p/'fpm.log',o.p/'fpm-stdio.log']if p.exists()}})
  finally:o.stop();target.write_text(json.dumps(out,indent=2)+'\n')
 h.check(out['status']=='PASS','Integration failure retained');print('Actual integration HTTP',len(out['requests']),out['status'])
if __name__=='__main__':main()
