"""Run full PHPStan on both trees; retain failures, fail on any additional diagnostic."""
import collections,json,os,pathlib,shutil,subprocess,tempfile
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BIN','php8.3');BASE=os.environ.get('PEER_THREADS_BASE', '051cae2ee1005ab063675e8054c2ff5b1801294b')
def main():
 with tempfile.TemporaryDirectory(prefix='ecollab-static-') as tmp:
  base=pathlib.Path(tmp)/'base'
  subprocess.run(['git','worktree','add','--detach',str(base),BASE],cwd=ROOT,check=True,capture_output=True)
  try:
   shutil.copytree(ROOT/'vendor',base/'vendor')
   composer=os.environ.get('COMPOSER_BIN') or shutil.which('composer');assert composer,'Composer required'
   subprocess.run([PHP,composer,'dump-autoload','--no-interaction'],cwd=base,check=True,capture_output=True)
   results={};normalized={}
   for side,root in [('baseline',base),('candidate',ROOT)]:
    r=subprocess.run([PHP,str(root/'vendor/bin/phpstan'),'analyse','--no-progress','--error-format=json','--memory-limit=768M'],cwd=root,capture_output=True,text=True,timeout=180)
    assert r.returncode in (0,1),(side,r.stderr)
    d=json.loads(r.stdout);assert not d['errors'],d['errors'];results[side]={'exit_code':r.returncode,'report':d}
    normalized[side]=collections.Counter((str(pathlib.Path(f).relative_to(root)),m['message'].replace(str(root),'ROOT')) for f,v in d['files'].items() for m in v['messages'])
   extra=normalized['candidate']-normalized['baseline'];results['additional_diagnostics']=[list(x) for x in extra.elements()]
   pathlib.Path(os.environ.get('PEER_THREADS_STATIC_EVIDENCE','peer-threads-static.json')).write_text(json.dumps(results,indent=2))
   print('PHPStan baseline:',results['baseline']['report']['totals'],'candidate:',results['candidate']['report']['totals'],'additional:',sum(extra.values()))
   assert not extra,'Additional PHPStan diagnostics'
  finally:subprocess.run(['git','worktree','remove','--force',str(base)],cwd=ROOT,capture_output=True)
if __name__=='__main__':main()
