"""Pinned integration composition and source-only PR reconciliation; no merge approval."""
import hashlib,json,pathlib,subprocess,sys,os
R=pathlib.Path(__file__).resolve().parents[1];I='c838359711a14e9b6eb3f345c4386d184a178b25';A='0db2d57a85f33710d5bf46b9c7162e8aa39885f6'
def git(*a):return subprocess.check_output(['git',*a],cwd=R,text=True).strip()
def entries(ref):return {l.split('\t')[1]:l.split()[2]for l in git('ls-tree','-r',ref).splitlines()}
def runtime(p):return p.split('/')[0]in ['services','API','security','database','assets','modules','websocket','scripts','deploy']or p in ['config.php','composer.json','composer.lock','.env.example']
a=entries(A);i=entries(I);assert git('merge-base',I,A)==I
prs=json.loads((R/'docs/450-phase3ad-pr-snapshot.json').read_text());rows=[]
for p in prs:
 e=entries(p['head']);files=[l.split('\t')[-1]for l in git('diff','--name-status',p['base'],p['head']).splitlines()];rp=[f for f in files if runtime(f)];ancestor=subprocess.run(['git','merge-base','--is-ancestor',p['head'],A],cwd=R,capture_output=True).returncode;assert ancestor in [0,1]
 rows.append(p|{'ancestor_of_candidate_source':ancestor==0,'runtime_paths':rp,'exact':[f for f in rp if e.get(f)==a.get(f)],'different':[f for f in rp if f in a and e.get(f)!=a.get(f)],'absent':[f for f in rp if f not in a],'disposition':'retained source;acceptance not inferred from ancestry'if ancestor==0 else'excluded parallel head;no new integration approval supplied'})
paths=[l.split('\t')[-1]for l in git('diff','--name-status',I,A).splitlines()];rp=[p for p in paths if runtime(p)];m=[p for p in rp if p.startswith('database/migrations/')];assert len(m)==4
scope=__import__('runpy').run_path(str(R/'tests/phase3ad-scope.py'))['validate']() if os.environ.get('PHASE3AD_REPAIR')=='1' else None
if scope is None: assert git('diff',A,'--','services','API','security','database','assets','modules','websocket','scripts','deploy','config.php','composer.json','composer.lock')==''
out={'integration':I,'accepted_source':A,'accepted_tree':git('rev-parse',A+'^{tree}'),'merge_base':I,'commits_ahead':int(git('rev-list','--count',I+'..'+A)),'net_paths':paths,'runtime':[{'path':p,'integration_blob':i.get(p),'candidate_blob':a.get(p)}for p in rp],'migrations':m,'prs':rows,'otp_blob':a['security/forgot-password/OtpService.php'],'runtime_identity_with_accepted':'CHANGED within explicit repair scope'if scope else'PASS','repair_scope':scope,'excluded_parallel_prs':[p['number']for p in rows if not p['ancestor_of_candidate_source']],'head':git('rev-parse','HEAD'),'tree':git('rev-parse','HEAD^{tree}'),'source_overlay':git('diff','HEAD'),'scope':'isolated accepted descendant composition,no new parallel PR source,no merge into integration'}
pathlib.Path(sys.argv[1]).write_text(json.dumps(out,indent=2)+'\n');print('PRs',len(rows),'runtime paths',len(rp),'migrations',len(m),'excluded',out['excluded_parallel_prs'])
