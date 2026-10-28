"""Read-only immutable stack inventory; ancestry and source equality are not release acceptance."""
import collections,gzip,hashlib,json,pathlib,re,subprocess,sys
ROOT=pathlib.Path(__file__).resolve().parents[1];INTEGRATION='c838359711a14e9b6eb3f345c4386d184a178b25';ACCEPTED='5a03656b27904498917c16cbfa320187c6da8c84';T='f77506a874c4650ec6c69637461c368876fa178d';commands=[]
def git(*args,allowed=(0,)):
 r=subprocess.run(['git',*args],cwd=ROOT,capture_output=True,text=True);commands.append({'command':['git',*args],'exit_code':r.returncode,'stderr':r.stderr});assert r.returncode in allowed,(args,r.returncode,r.stderr);return r.stdout.strip(),r.returncode
def runtime(p):return p.split('/')[0]in ['services','API','security','database','assets','modules','websocket','deploy','scripts']or p in ['config.php','composer.json','composer.lock','.env.example']
def entries(ref):
 s,_=git('ls-tree','-r',ref);return {line.split('\t',1)[1]:line.split()[2]for line in s.splitlines()}
def diff(a,b):return git('diff','--name-status',a,b)[0].splitlines()
def main():
 target=pathlib.Path(sys.argv[1]);prs=json.loads((ROOT/'docs/450-phase3ab-pr-snapshot.json').read_text());assert {p['number']for p in prs}==set(range(66,97))
 for p in prs:
  for k in ['head','base']:assert re.fullmatch('[a-f0-9]{40}',p[k])
 refs={INTEGRATION,ACCEPTED,T}|{p[k]for p in prs for k in ['head','base']};trees={ref:entries(ref)for ref in sorted(refs)}
 h=trees[ACCEPTED];i=trees[INTEGRATION];base_diff=diff(INTEGRATION,ACCEPTED);paths=[r.split('\t')[-1]for r in base_diff];runtime_paths=[p for p in paths if runtime(p)];outprs=[]
 for p in sorted(prs,key=lambda p:p['number']):
  _,anc=git('merge-base','--is-ancestor',p['head'],ACCEPTED,allowed=(0,1));changes=diff(p['base'],p['head']);rp=[x.split('\t')[-1]for x in changes if runtime(x.split('\t')[-1])]
  exact=[f for f in rp if trees[p['head']].get(f)==h.get(f)];different=[f for f in rp if f in h and trees[p['head']].get(f)!=h.get(f)];absent=[f for f in rp if f not in h]
  outprs.append(p|{'head_is_ancestor_of_accepted':anc==0,'changed_paths':changes,'runtime_changed_paths':rp,'runtime_exact_at_stack':exact,'runtime_different_at_stack':different,'runtime_absent_at_stack':absent,'acceptance':'Source comparison only;independent acceptance and integration approval not inferred'})
 gate_paths=['services','API','security','database','assets','modules','websocket','config.php','composer.json','composer.lock']
 no_app_delta,rc=git('diff','--exit-code',T,ACCEPTED,'--',*gate_paths);assert not no_app_delta
 authentication=['services/AuthService.php','security/forgot-password/OtpService.php','config.php'];auth={p:{'integration_blob':i[p],'stack_blob':h[p],'identical':i[p]==h[p]}for p in authentication};assert all(x['identical']for x in auth.values())
 migration_paths=[p for p in runtime_paths if p.startswith('database/migrations/')];env=[]
 for p in runtime_paths:
  if p.endswith(('.php','.py','.sh','.env.example','.service'))or p.endswith('env.example'):
   text,_=git('show',ACCEPTED+':'+p)
   names=sorted(set(re.findall(r'(?:getenv|env)\(\s*[\'\"]([A-Z][A-Z0-9_]+)',text)))
   if p.endswith('env.example'):names=sorted(set(names)|set(re.findall(r'^([A-Z][A-Z0-9_]+)=',text,re.M)))
   if names:env.append({'path':p,'variable_names_only':names})
 actual,_=git('rev-parse','HEAD');tree,_=git('rev-parse','HEAD^{tree}');overlay,_=git('diff',ACCEPTED,'--',*gate_paths,'deploy','scripts');assert not overlay
 out={'scope':'Read-only pending-stack and baseline inventory; no merge or release approval','integration':INTEGRATION,'accepted':ACCEPTED,'merge_base':git('merge-base',INTEGRATION,ACCEPTED)[0],'harness_head':actual,'harness_tree':tree,'application_overlay':overlay,'integration_tree':git('rev-parse',INTEGRATION+'^{tree}')[0],'accepted_tree':git('rev-parse',ACCEPTED+'^{tree}')[0],'commits_ahead':int(git('rev-list','--count',INTEGRATION+'..'+ACCEPTED)[0]),'net_changed_paths':base_diff,'category_counts':dict(collections.Counter(p.split('/')[0]for p in paths)),'runtime_paths':[{'path':p,'integration_blob':i.get(p),'accepted_blob':h.get(p)}for p in runtime_paths],'migration_paths':migration_paths,'configuration_dependencies':env,'auth_baseline_identity':auth,'phase3u_through3aa_application_delta':'EMPTY versus exact Phase3T runtime paths','prs':outprs,'limitations':['Git ancestry does not prove equivalence for metadata-republished or rehearsed branches','Matching blobs do not prove integration,security or production acceptance','No merge rehearsal,production migration/config inspection or defense build certification','No real secrets/config files read;only tracked source and variable names'],'commands':commands}
 target.parent.mkdir(parents=True,exist_ok=True);target.write_text(json.dumps(out,indent=2)+'\n');print('Stack delta',len(paths),'paths;',len(runtime_paths),'runtime/deployment paths;',len(migration_paths),'migration additions;PRs',len(outprs));print('auth-identical',all(x['identical']for x in auth.values()),'runtime unchanged sincePhase3T');print('non-ancestor PR heads',[p['number']for p in outprs if not p['head_is_ancestor_of_accepted']])
if __name__=='__main__':main()
