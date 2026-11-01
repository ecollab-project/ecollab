"""Explicit owner-authorized correction manifest; historical guards remain active by default."""
import hashlib,json,pathlib,subprocess
R=pathlib.Path(__file__).resolve().parents[1]
def validate():
 def git(*a):return subprocess.check_output(['git',*a],cwd=R,text=True).strip()
 m=json.loads((R/'docs/450-phase3af-correction/runtime-manifest.json').read_text());base=m['base'];assert git('merge-base',base,'HEAD')==base
 paths=git('diff','--name-only',base).splitlines();runtime=[p for p in paths if not p.startswith(('tests/','docs/','.github/'))];assert set(runtime)==set(m['hashes']),(runtime,list(m['hashes']))
 for p,v in m['hashes'].items():assert hashlib.sha256((R/p).read_bytes()).hexdigest()==v,'Unfrozen correction '+p
 assert not[p for p in git('ls-files','--others','--exclude-standard').splitlines()if p.startswith(('services/','API/','security/','assets/','database/','deploy/','uploads/'))]
 assert not git('diff',base,'--','security','database','config.php','composer.json','composer.lock')
 return {'status':'PASS','base':base,'head':git('rev-parse','HEAD'),'tree':git('rev-parse','HEAD^{tree}'),'allowed_runtime':sorted(m['hashes']),'hashes':m['hashes'],'working_diff':git('diff','HEAD'),'otp_blob':git('rev-parse','HEAD:security/forgot-password/OtpService.php')}
if __name__=='__main__':print(json.dumps(validate(),indent=2))
