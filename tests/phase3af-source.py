"""Freeze accepted runtime;Phase3AF may add only bounded verification artifacts."""
import hashlib,json,pathlib,subprocess,sys
R=pathlib.Path(__file__).resolve().parents[1];BASE='f319fe16ca30b3157a0ca567fa879bec89c75f19';APP='c65d2f994b10dfa7890f6509a2342e58a064571e'
def git(*a):return subprocess.check_output(['git',*a],cwd=R,text=True).strip()
def runtime(p):return p.split('/')[0]in ['API','services','security','database','assets','modules','websocket','scripts','deploy']or p in ['config.php','composer.json','composer.lock','.env.example']
def main():
 assert git('merge-base',BASE,'HEAD')==BASE
 paths=git('diff','--name-only',BASE).splitlines();assert all(p.startswith(('tests/phase3af-','docs/450-phase3af','.github/workflows/phase3af-'))for p in paths),paths
 assert not git('diff',APP,'--','API','services','security','database','assets','modules','websocket','scripts','deploy','config.php','composer.json','composer.lock','.env.example')
 assert not[p for p in git('ls-files','--others','--exclude-standard').splitlines()if runtime(p)]
 out={'status':'PASS','accepted_phase3ae':BASE,'accepted_application':APP,'head':git('rev-parse','HEAD'),'tree':git('rev-parse','HEAD^{tree}'),'runtime_change':'NONE','working_diff':git('diff','HEAD'),'allowed_changes':paths,'otp_blob':git('rev-parse','HEAD:security/forgot-password/OtpService.php'),'harness_sha256':{str(p.relative_to(R)):hashlib.sha256(p.read_bytes()).hexdigest()for p in (R/'tests').glob('phase3af-*')if p.is_file()}}
 p=pathlib.Path(sys.argv[1]if len(sys.argv)>1 else'phase3ad-raw/phase3af-source.json');p.parent.mkdir(exist_ok=True,parents=True);p.write_text(json.dumps(out,indent=2)+'\n');print('PASS accepted application unchanged')
if __name__=='__main__':main()
