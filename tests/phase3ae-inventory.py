"""Read-only cumulative integration plan and immutable accepted-source gate."""
import hashlib,json,pathlib,subprocess,sys,gzip
R=pathlib.Path(__file__).resolve().parents[1]
I='c838359711a14e9b6eb3f345c4386d184a178b25';A='c65d2f994b10dfa7890f6509a2342e58a064571e';T='7f6686f3d382f8764e84851411f2f92af840077c'
def git(*a):return subprocess.check_output(['git',*a],cwd=R,text=True).strip()
def runtime(p):return p.split('/')[0]in ['services','API','security','database','assets','modules','websocket','scripts','deploy']or p in ['config.php','composer.json','composer.lock','.env.example']
def entries(ref):return {l.split('\t')[1]:l.split()[2]for l in git('ls-tree','-r',ref).splitlines()}
def category(p):
 if p.startswith('deploy/'):return 'external/staging dependency;not activated'
 if p.startswith('database/'):return 'migration/ledger compatibility;fresh-only069 gate'
 if p.startswith('security/')or p in ['services/AuthService.php','services/OAuthService.php','services/AdminWorkspaceService.php','API/admin/dashboard-data.php','API/auth/resend-signup-otp.php','scripts/provision-system-account.php','services/LoadTestFixture.php']:return 'accepted OTP/identity and explicit human writers;production transition blocked'
 if any(s in p.lower() for s in ['jarred','ollama','gemini','ai/','ai-assist','group-recommendations','dm-notifications','temporaryvoice']):return 'AI/worker/shared-lock/authorization dependency;live service incomplete'
 if any(s in p for s in ['CodeLab','code-lab']):return 'CodeLabmTLS/staging;external execution excluded'
 return 'dashboard/chat/matching/thread/read and API integration;bounded accepted scope'
def main():
 assert git('merge-base',I,A)==I and int(git('rev-list','--count',I+'..'+A))==70
 assert git('rev-parse',A+'^{tree}')==T
 assert git('merge-base',A,'HEAD')==A
 diff=git('diff','--name-only',A).splitlines();assert all(p.startswith(('tests/phase3ae-','docs/450-phase3ae','.github/workflows/phase3ae-'))for p in diff),diff
 untracked=git('ls-files','--others','--exclude-standard').splitlines();assert not [p for p in untracked if runtime(p)]
 base,accepted=entries(I),entries(A);current=entries('HEAD')
 for path in set(accepted)|set(current):
  if runtime(path):assert accepted.get(path)==current.get(path),'Runtime source change '+path
 paths=git('diff','--name-only',I,A).splitlines();rp=[p for p in paths if runtime(p)];assert len(paths)==725 and len(rp)==69
 rows=[]
 for p in rp:
  rows.append({'path':p,'integration_blob':base.get(p),'accepted_blob':accepted.get(p),'sha256':hashlib.sha256((R/p).read_bytes()).hexdigest(),'review_class':category(p)})
 prs=[]
 for p in json.loads((R/'docs/450-phase3ae-pr-snapshot.json').read_text()):
  ancestor=subprocess.run(['git','merge-base','--is-ancestor',p['head'],A],cwd=R,capture_output=True).returncode;assert ancestor in [0,1]
  e=entries(p['head']);own=[l.split('\t')[-1]for l in git('diff','--name-status',p['base'],p['head']).splitlines()];runtime_paths=[f for f in own if runtime(f)]
  if p['number']in list(range(66,74))+[85]:decision='EXCLUDED parallel head;no merge/combined release approval'
  elif p['number']in [90,91]:decision='Retain final reverted/no-net-application-change investigation;reject trial optimization'
  elif p['number']in range(74,85):decision='Inherited source tested by scoped3AD;staging/external/production approval not inferred'
  elif p['number']in [86,87,88,89]:decision='Retained scoped optimization;capacity and production release not certified'
  elif p['number']in [98,100,101,102]:decision='Retain independently accepted OTP/message/identity correction;bounded isolated scope'
  else:decision='Retain validation/decision/evidence ancestor;no independent runtime release approval inferred'
  prs.append(p|{'ancestor_of_accepted':ancestor==0,'disposition':decision,'runtime_paths':runtime_paths,'same_final_blob':[f for f in runtime_paths if e.get(f)==accepted.get(f)],'changed_later':[f for f in runtime_paths if f in accepted and e.get(f)!=accepted[f]],'absent_final':[f for f in runtime_paths if f not in accepted]})
 assert [p['number']for p in prs if not p['ancestor_of_accepted']]==list(range(66,74))+[85]
 for left,right in [('f77506a874c4650ec6c69637461c368876fa178d','e6f864d'),('e6f864d','e818bbd')]:assert git('diff','--name-only',left,right,'--','services','API','security','database','assets')==''
 sim=subprocess.run(['git','merge-tree','--write-tree',I,A],cwd=R,text=True,capture_output=True);assert sim.returncode==0 and sim.stdout.strip()==T
 migrations=[p for p in rp if p.startswith('database/migrations/')];assert len(migrations)==6
 assert (R/'database/migrations/025_coworkspace_one_active_per_server.sql').read_bytes()==subprocess.check_output(['git','show',I+':database/migrations/025_coworkspace_one_active_per_server.sql'],cwd=R)
 out={'status':'PASS for source/merge rehearsal;actual merge unauthorized','integration':I,'accepted_head':A,'accepted_tree':T,'rehearsal_head':git('rev-parse','HEAD'),'rehearsal_tree':git('rev-parse','HEAD^{tree}'),'runtime_identity':'BYTE-IDENTICAL to accepted3AD','working_diff':git('diff','HEAD'),'commits_ahead':70,'cumulative_paths':paths,'runtime':rows,'migration_additions':migrations,'prs':prs,'merge_simulation':{'command':['git','merge-tree','--write-tree',I,A],'exit_code':sim.returncode,'tree':sim.stdout.strip(),'conflicts':[],'target_branch_mutated':False},'merge_strategy':'One future reviewed cumulative PR or explicitly authorized fast-forward from exactI to exact accepted source;do not merge parallel PRs or cherry-pick rejected trial commits','remaining_release_gate':'BLOCKED:069 cannot upgrade existing/production accounts;separate identity/OTP cutover approval and real environment acceptance required'}
 target=pathlib.Path(sys.argv[1]if len(sys.argv)>1 else'phase3ad-raw/phase3ae-source.json');target.parent.mkdir(exist_ok=True,parents=True);target.write_text(json.dumps(out,indent=2)+'\n')
 raw=subprocess.check_output(['git','diff','--binary',I,A,'--',*rp],cwd=R);target.with_name('phase3ae-cumulative-runtime.diff.gz').write_bytes(gzip.compress(raw,mtime=0))
 print('PASS',len(prs),'PRs',len(rp),'runtime paths',len(migrations),'migration additions;merge tree',T)
if __name__=='__main__':main()
