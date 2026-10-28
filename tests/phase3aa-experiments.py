"""Isolated CLI-only UTC trial; original FPM application and all security/config files unchanged."""
import importlib.util,json,os,pathlib,subprocess,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[1];BASE='bc1f1bc3cff91ce61cf531853e7220f1cfca590a'
spec=importlib.util.spec_from_file_location('phase3y_owned',ROOT/'tests/phase3y-http.py');h=importlib.util.module_from_spec(spec);spec.loader.exec_module(h)
COHORTS={'utc':('UTC','+00:00'),'mismatch':('UTC','+06:00'),'manila':('Asia/Manila','+08:00'),'reverse':('Asia/Manila','+00:00'),'configured-120':('UTC','+00:00')}
class Owned(h.Owned):
 def experiment(self,script,**args):
  cmd=[self.php,'-d','date.timezone='+self.zone,'-d','session.save_path='+str(self.p/'sessions'),str(ROOT/'tests'/script)]
  r=subprocess.run(cmd,input=json.dumps(args),env=self.env,capture_output=True,text=True,timeout=180)
  self.controls.append({'script':script,'command':cmd,'private_input':'omitted','exit_code':r.returncode,'stderr':self.scrub_text(r.stderr)})
  h.check(r.returncode==0,'Owned experiment failed: '+script);return json.loads(r.stdout)
 def run_trials(self,concurrency):
  c,d=self.registered();self.trials={}
  for variant in ['original','utc_trial']:self.trials[variant]=self.experiment('phase3aa-clock.php',user_id=c.uid,variant=variant)
  self.transition=self.experiment('phase3aa-transition.php',user_id=c.uid)if concurrency else None
  self.race=self.experiment('phase3aa-race.php',user_id=c.uid)if concurrency else None
  # Deterministic real transport refusal; existing provider URL remains owned loopback.
  self.mail.shutdown();self.mail.server_close()
  self.contracts=self.experiment('phase3aa-contracts.php',user_id=c.uid,delivery_offline=True)
 def result(self):
  x=super().result();x.update({'trials':self.trials,'transition':self.transition,'race':self.race,'contracts':self.contracts,'configured_ttl':int(self.env['OTP_EXPIRY']),'trial_only':True});return x

def main():
 target=pathlib.Path(os.environ.get('PHASE3AA_EVIDENCE','phase3aa-raw/experiments.json'));target.parent.mkdir(parents=True,exist_ok=True)
 diff=h.git('diff',BASE,'--','services','API','security','database','assets','config.php','modules','websocket');h.check(not diff,'Application/config/security changes forbidden')
 names=['tests/phase3aa-experiments.py','tests/phase3aa-clock.php','tests/phase3aa-race.php','tests/phase3aa-race-worker.php','tests/phase3aa-transition.php','tests/phase3aa-contracts.php','tests/experiments/phase3aa/UtcOtpTrial.php','tests/phase3y-http.py','tests/phase3y-bootstrap.php','tests/phase3y-control.php','services/AuthService.php','security/forgot-password/OtpService.php','database/config/db.php','config.php']
 out={'base':BASE,'head':h.git('rev-parse','HEAD'),'tree':h.git('rev-parse','HEAD^{tree}'),'intended_head':os.environ.get('PHASE3AA_EXPECTED_HEAD','UNPUBLISHED'),'harness_diff':h.git('diff','HEAD'),'harness_status':h.git('status','--porcelain'),'application_diff':diff,'sha256':{p:h.digest((ROOT/p).read_bytes())for p in names},'scope':'CLI-only test trial; no production-facing application correction; FPM bootstrap and APIs use original service','cohorts':[]}
 for name,(zone,dbzone)in COHORTS.items():
  with tempfile.TemporaryDirectory(prefix='phase3aa-')as t:
   owner=Owned(pathlib.Path(t),zone,dbzone)
   if name=='configured-120':owner.env['OTP_EXPIRY']='120'
   try:
    owner.start();owner.run_trials(concurrency=name=='utc');owner.cleanup=owner.control('cleanup');out['cohorts'].append(owner.result())
   except Exception as e:out['cohorts'].append({'cohort':name,'status':'FAIL','error':owner.scrub_text(str(e)),'controls':owner.controls,'requests':owner.records,'logs':{p.name:owner.scrub_text(p.read_text())for p in [owner.p/'php-errors.log',owner.p/'database.log']if p.exists()}})
   finally:owner.stop();target.write_text(json.dumps(out,indent=2)+'\n')
 h.check(all(c['status']=='PASS'for c in out['cohorts']),'Experiment failure retained')
 print([(c['cohort'],c['configured_ttl'],len(c['trials']['utc_trial']['clock_records']))for c in out['cohorts']])
if __name__=='__main__':main()
