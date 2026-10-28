"""Test-only reverse timezone and native clock characterization, application unchanged."""
import importlib.util,json,os,pathlib,subprocess,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('phase3y_http',ROOT/'tests/phase3y-http.py');h=importlib.util.module_from_spec(spec);spec.loader.exec_module(h)
class CheckedClient(h.Client):
 def req(self,*args,**kwargs):
  r=super().req(*args,**kwargs)
  def inspect(v):
   if isinstance(v,dict):
    for k,x in v.items():
     if k.lower()in ['otp_debug','debug_otp']:h.check(x is None,'debug-only OTP value must be absent or null')
     inspect(x)
   elif isinstance(v,list):
    for x in v:inspect(x)
  inspect(r['json']);self.o.debug_response_checks=getattr(self.o,'debug_response_checks',0)+1
  return r
h.Client=CheckedClient
BASE='b09e66c3fcba5066c63da8bece245ae5f4068410'
COHORTS={'utc':('UTC','+00:00'),'mismatch':('UTC','+06:00'),'manila':('Asia/Manila','+08:00'),'reverse':('Asia/Manila','+00:00')}
class Owned(h.Owned):
 def clock(self,uid):
  cmd=[self.php,'-d','date.timezone='+self.zone,'-d','session.save_path='+str(self.p/'sessions'),str(ROOT/'tests/phase3z-clock.php')]
  r=subprocess.run(cmd,input=json.dumps({'user_id':uid}),env=self.env,capture_output=True,text=True,timeout=120)
  self.controls.append({'action':'native_clock_matrix','command':cmd,'exit_code':r.returncode,'stderr':self.scrub_text(r.stderr)})
  h.check(r.returncode==0,'native clock helper failed');return json.loads(r.stdout)
 def extended(self):
  self.run(full=False)
  c,d=self.registered();self.native_clock=self.clock(c.uid)
  if self.zone=='UTC'and self.dbzone=='+00:00':
   def verified_marker():
    c=h.Client(self).csrf();d=self.data();self.verify(c,d);before=c.state();h.check(before['session']['signup_verified'] and 'pending_signup_email_expires'not in before['session']['keys'],'verified marker has no remaining pending-code expiry')
    self.control('expire_signup',session_id=c.sid());pre=c.state();h.check(not pre['session']['signup_intent_valid'] and pre['session']['signup_verified'],'old pending timer with verified marker')
    r=c.req('/API/auth/signup.php','POST',d);h.check(r['json']['success'],'current registration ignores pending timer after verification')
    self.marker={'before':before,'before_registration':pre,'registration_success':r['json']['success'],'time_waited_seconds':0,'timer_expired_by_owned_control':True,'new_policy':False}
   self.case('verified_marker_has_no_independent_age_check',verified_marker)
   def reset_journey():
    c,d=self.registered();c.req('/API/auth/logout.php','POST',{});c.csrf()
    for route in ['forgot-password','reset-password']:
     self.reject(c.req('/API/auth/'+route+'.php'),405)
     self.reject(c.req('/API/auth/'+route+'.php','POST',{},csrf='invalid'),403)
    r=c.req('/API/auth/forgot-password.php','POST',{'email':d['email']});h.check(r['json']['success'],'real reset-code request')
    # Existing loopback receiver places non-login subjects in its verification slot.
    code=self.code(d);r=c.req('/API/auth/verify-otp.php','POST',{'user_id':c.uid,'otp':code});h.check(r['json']['success'],'real reset-code verification')
    token=r['json']['reset_token'];self.secrets.add(token);h.check(not c.state()['session']['authenticated'],'reset token does not log in')
    new='owned-reset-password-84';self.secrets.add(new)
    body={'reset_token':token,'new_password':new,'confirm_password':new}
    r=c.req('/API/auth/reset-password.php','POST',body);h.check(r['json']['success'],'real password reset')
    self.reject(c.req('/API/auth/reset-password.php','POST',body),422)
    self.reject(c.req('/API/auth/login.php','POST',{'identifier':d['email'],'password':d['password']}),401)
    r=c.req('/API/auth/login.php','POST',{'identifier':d['email'],'password':new});h.check(r['json']['success'],'new password login')
   self.case('password_reset_http_debug_absence_and_replay',reset_journey)
 def result(self):
  r=super().result();r['native_clock']=self.native_clock;r['debug_response_checks']=self.debug_response_checks;r['verified_marker']=getattr(self,'marker',None);return r

def main():
 target=pathlib.Path(os.environ.get('PHASE3Z_EVIDENCE','phase3z-raw/timezone.json'));target.parent.mkdir(parents=True,exist_ok=True)
 diff=h.git('diff',BASE,'--','services','API','security','database','assets','config.php','websocket','modules');h.check(not diff,'application changes forbidden')
 names=['tests/phase3z-clock.php','tests/phase3z-timezone.py','tests/phase3y-http.py','tests/phase3y-control.php','tests/phase3y-bootstrap.php','tests/classroom-performance.py','services/AuthService.php','security/forgot-password/OtpService.php','database/config/db.php','config.php']
 data={'base':BASE,'head':h.git('rev-parse','HEAD'),'tree':h.git('rev-parse','HEAD^{tree}'),'intended_head':os.environ.get('PHASE3Z_EXPECTED_HEAD','UNPUBLISHED'),'harness_diff':h.git('diff','HEAD'),'harness_status':h.git('status','--porcelain'),'application_diff':diff,'sha256':{p:h.digest((ROOT/p).read_bytes())for p in names},'cohorts':[]}
 for name,(zone,dbzone)in COHORTS.items():
  with tempfile.TemporaryDirectory(prefix='phase3z-')as t:
   owner=Owned(pathlib.Path(t),zone,dbzone)
   try:
    owner.start();owner.extended();owner.cleanup=owner.control('cleanup');data['cohorts'].append(owner.result())
   except Exception as e:data['cohorts'].append({'cohort':name,'status':'FAIL','error':owner.scrub_text(str(e)),'cases':owner.cases,'controls':owner.controls,'requests':owner.records,'runtime_logs':{p.name:owner.scrub_text(p.read_text())for p in [owner.p/'database.log',owner.p/'php-errors.log']if p.exists()}})
   finally:owner.stop();target.write_text(json.dumps(data,indent=2)+'\n')
 h.check(all(c['status']=='PASS'for c in data['cohorts']),'Clock investigation failed; raw retained')
 print([(c['cohort'],len(c['requests']),len(c['native_clock']['clock_records']))for c in data['cohorts']])
if __name__=='__main__':main()
