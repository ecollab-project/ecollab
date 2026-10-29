"""Owner-approved A-AD-1 scope; hashes are frozen separately before final tests."""
import hashlib,json,pathlib,subprocess
R=pathlib.Path(__file__).resolve().parents[1]
def validate():
 def git(*a):return subprocess.check_output(['git',*a],cwd=R,text=True).strip()
 manifest=json.loads((R/'docs/450-phase3ad-identity-scope.json').read_text());base=manifest['base'];allowed=set(manifest['runtime_allowed'])
 assert git('merge-base',base,'HEAD')==base
 changed=git('diff','--name-only',base).splitlines();runtime=[p for p in changed if not p.startswith(('tests/','docs/','.github/'))]
 assert set(runtime)==allowed,repr(runtime)
 pinned=json.loads((R/'docs/450-phase3ad-identity-hashes.json').read_text())
 for p,v in pinned.items():assert hashlib.sha256((R/p).read_bytes()).hexdigest()==v,'Unexpected bytes '+p
 for p in manifest['preserve']:assert (R/p).read_bytes()==subprocess.check_output(['git','show',base+':'+p],cwd=R),'Accepted source changed '+p
 return {'status':'PASS','base':base,'head':git('rev-parse','HEAD'),'tree':git('rev-parse','HEAD^{tree}'),'allowed_runtime':sorted(allowed),'actual_runtime':runtime,'hashes':pinned,'otp_blob':git('rev-parse',base+':security/forgot-password/OtpService.php'),'working_diff':git('diff','HEAD')}
if __name__=='__main__':print(json.dumps(validate()))
