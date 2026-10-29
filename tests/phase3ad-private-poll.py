"""Private poll vote authorization, real auth and migrated schema."""
import importlib.util,json,os,pathlib,tempfile
R=pathlib.Path(__file__).resolve().parents[1];s=importlib.util.spec_from_file_location('integration',R/'tests/phase3ad-http.py');m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
target=pathlib.Path(os.environ.get('PHASE3AD_PRIVATE_POLL_EVIDENCE','phase3ad-raw/private-poll.json'));target.parent.mkdir(parents=True,exist_ok=True)
out={'head':m.h.git('rev-parse','HEAD'),'tree':m.h.git('rev-parse','HEAD^{tree}'),'working_diff':m.h.git('diff','HEAD'),'vote_source_sha256':m.h.digest((R/'API/chat/vote-poll.php').read_bytes())}
with tempfile.TemporaryDirectory(prefix='phase3ad-private-')as t:
 o=m.h.Owned(pathlib.Path(t),'UTC','+00:00')
 try:
  o.start();actors=[o.registered()for _ in range(4)];fixture=m.runphp(o,'phase3ad-fixture.php',{'users':[c.uid for c,d in actors]});student,peer,fac,outsider=[c for c,d in actors]
  for c,d in actors:c.csrf()
  r=student.req('/API/chat/send-message.php','POST',{'channel_id':fixture['channels']['private'],'content':'Private poll','content_type':'poll','poll_options':['A','B']});m.h.check(r['status']==201,'create private poll');poll=r['json']['message']['poll'];payload={'poll_id':poll['id'],'option_id':poll['options'][0]['id']}
  history=peer.req('/API/chat/get-messages.php?channel_id='+str(fixture['channels']['private']));m.h.check(history['status']==403,'private history denied')
  denied=peer.req('/API/chat/vote-poll.php','POST',payload);out['private_vote_status']=denied['status'];out['history_status']=history['status']
  expected=200 if os.environ.get('PHASE3AD_PRIVATE_UNSAFE_CONTROL')=='1'else 403
  m.h.check(denied['status']==expected,'private vote authorization')
  if expected==403:m.h.check(denied['before']['business']==denied['after']['business'],'denial mutated business data')
  own=student.req('/API/chat/vote-poll.php','POST',payload);m.h.check(own['status']==200,'private member vote')
  out['status']='FAIL (unsafe control reproduced)'if expected==200 else'PASS'
 except Exception as e:out.update({'status':'FAIL','error':o.scrub_text(str(e))})
 finally:o.stop();out.update({'requests':o.records,'controls':o.controls});target.write_text(json.dumps(out,indent=2)+'\n')
print(out['status'],out.get('private_vote_status'),len(out['requests']))
m.h.check(out['status']=='PASS'or out['status']=='FAIL (unsafe control reproduced)','private poll check')
