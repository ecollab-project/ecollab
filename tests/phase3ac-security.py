"""Actual candidate application, owned FPM/MariaDB; no OTP service substitution."""
import importlib.util,json,os,pathlib,subprocess,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('candidate_http',ROOT/'tests/phase3ac-http.py');h=importlib.util.module_from_spec(spec);spec.loader.exec_module(h)
COHORTS={'utc':('UTC','+00:00'),'mismatch':('UTC','+06:00'),'manila':('Asia/Manila','+08:00'),'reverse':('Asia/Manila','+00:00'),'configured-120':('UTC','+00:00')}
class Owned(h.Owned):
 def experiment(self,script,**args):
  cmd=[self.php,'-d','date.timezone='+self.zone,'-d','session.save_path='+str(self.p/'sessions'),str(ROOT/'tests'/script)]
  r=subprocess.run(cmd,input=json.dumps(args),env=self.env,capture_output=True,text=True,timeout=180)
  self.controls.append({'script':script,'command':cmd,'private_input':'omitted','exit_code':r.returncode,'stderr':self.scrub_text(r.stderr)})
  h.check(r.returncode==0,'Candidate experiment failed: '+script);return json.loads(r.stdout)
def main():
 target=pathlib.Path(os.environ.get('PHASE3AC_EVIDENCE','phase3ac-raw/security.json'));target.parent.mkdir(parents=True,exist_ok=True)
 out={'base':'e4999821b16f8d8085a8cde6c5b08de2fc824ae5','actual_head':h.git('rev-parse','HEAD'),'tree':h.git('rev-parse','HEAD^{tree}'),'intended_head':os.environ.get('PHASE3AC_EXPECTED_HEAD','UNPUBLISHED'),'working_diff':h.git('diff','HEAD'),'hashes':{str(p.relative_to(ROOT)):h.digest(p.read_bytes())for p in [ROOT/'security/forgot-password/OtpService.php',*sorted((ROOT/'tests').glob('phase3ac-*'))]if p.is_file()},'cohorts':[]}
 for name,(zone,dbzone)in COHORTS.items():
  with tempfile.TemporaryDirectory(prefix='phase3ac-')as t:
   owner=Owned(pathlib.Path(t),zone,dbzone)
   if name=='configured-120':owner.env['OTP_EXPIRY']='120'
   try:
    owner.start();c,d=owner.registered()
    clock=owner.experiment('phase3ac-clock.php',user_id=c.uid,variant='utc_trial')
    race=owner.experiment('phase3ac-race.php',user_id=c.uid)if name=='utc'else None
    controls=owner.experiment('phase3ac-contracts.php',user_id=c.uid)if name=='utc'else None
    adverse=owner.experiment('phase3ac-adverse.php',user_id=c.uid)if name=='utc'else None
    transition=owner.experiment('phase3ac-transition.php',user_id=c.uid)if name=='utc'else None
    owner.run(full=name=='utc');owner.cleanup=owner.control('cleanup');result=owner.result();result.update({'clock':clock,'race':race,'security_controls':controls,'adverse':adverse,'transition':transition,'ttl':int(owner.env['OTP_EXPIRY'])});out['cohorts'].append(result)
   except Exception as e:out['cohorts'].append({'name':name,'status':'FAIL','error':owner.scrub_text(str(e)),'controls':owner.controls,'cases':owner.cases,'requests':owner.records,'logs':{p.name:owner.scrub_text(p.read_text())for p in [owner.p/'php-errors.log',owner.p/'database.log']if p.exists()}})
   finally:owner.stop();target.write_text(json.dumps(out,indent=2)+'\n')
 h.check(all(c['status']=='PASS'for c in out['cohorts']),'Candidate failures retained')
 print([(c['cohort'],c['ttl'],len(c['clock']['clock_records']),c['status'])for c in out['cohorts']])
if __name__=='__main__':main()
