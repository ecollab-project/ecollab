"""Install exact accepted runtime;keep owned state;exercise browser and full cold restore."""
import gzip,hashlib,importlib.util,json,os,pathlib,shutil,subprocess,tarfile,time,sys,traceback
R=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('af_gateway',R/'tests/phase3af-gateway.py');h=importlib.util.module_from_spec(spec);spec.loader.exec_module(h)
A='c65d2f994b10dfa7890f6509a2342e58a064571e'
def sha(p):return hashlib.sha256(pathlib.Path(p).read_bytes()).hexdigest()
def runtime(p):return p.split('/')[0]in ['services','API','security','database','assets','modules','websocket','scripts','deploy']or p in ['config.php','composer.json','composer.lock','.env.example']
def files(folder):return {str(p.relative_to(folder)):sha(p)for p in pathlib.Path(folder).rglob('*')if p.is_file()}
def main():
 target=pathlib.Path(os.environ.get('PHASE3AF_EVIDENCE','phase3ad-raw/phase3af-installed.json')).resolve();target.parent.mkdir(exist_ok=True,parents=True)
 state=pathlib.Path(os.environ.get('PHASE3AF_STATE_DIR',str(R.parent/'phase3af-private-state'))).resolve()
 if state.exists():raise RuntimeError('Refusing to overwrite an existing installed environment;choose a new owned directory')
 state.mkdir(mode=0o700,parents=True);(state/'phase3af-owned.json').write_text(json.dumps({'scope':'fresh synthetic only','created':time.time()}));os.chmod(state/'phase3af-owned.json',0o600)
 app=state/'installed';app.mkdir();dbdir=state/'runtime';dbdir.mkdir();private=state/'backup-private';private.mkdir(mode=0o700)
 out={'status':'FAIL','defense_certification':'BLOCKED','accepted_application':A,'head':h.git('rev-parse','HEAD'),'tree':h.git('rev-parse','HEAD^{tree}'),'intended_head':os.environ.get('PHASE3AF_EXPECTED_HEAD','UNPUBLISHED'),'working_diff':h.git('diff','HEAD'),'scope':'owned installed source+dependencies,FPM,MariaDB,uploads,on-disk state,cold backup/restore;not public deployment','cases':[],'commands':[]}
 o=None
 try:
  archive=state/'source.tar';cmd=['git','archive','--format=tar','--output='+str(archive),'HEAD'];r=subprocess.run(cmd,cwd=R,capture_output=True,text=True);out['commands'].append({'command':cmd,'exit_code':r.returncode});h.check(r.returncode==0,'source archive')
  with tarfile.open(archive)as t:
   for m in t.getmembers():h.check((app/m.name).resolve().is_relative_to(app),'unsafe source archive path');h.check(not m.issym()and not m.islnk(),'unexpected archive link')
   t.extractall(app,filter='data')
  overlays=[]
  for p in (R/'tests').glob('phase3af-*'):
   if p.is_file()and not(app/'tests'/p.name).exists():shutil.copy2(p,app/'tests'/p.name);overlays.append({'path':'tests/'+p.name,'sha256':sha(p),'scope':'test harness only,prepublication local run'})
  out['test_harness_overlays']=overlays
  shutil.copytree(R/'vendor',app/'vendor')
  cmd=[os.environ.get('PHP_BIN','php8.3'),os.environ.get('COMPOSER_BIN','composer'),'dump-autoload','--no-interaction'];r=subprocess.run(cmd,cwd=app,capture_output=True,text=True,timeout=120);out['commands'].append({'command':cmd,'exit_code':r.returncode});h.check(r.returncode==0,'installed dependency autoload')
  names=h.git('ls-tree','-r','--name-only',A).splitlines();runtime_hashes={p:sha(app/p)for p in names if runtime(p)}
  for p,d in runtime_hashes.items():h.check(hashlib.sha256(subprocess.check_output(['git','show',A+':'+p],cwd=R)).hexdigest()==d,'accepted runtime mismatch '+p)
  out['installation']={'status':'PASS','source_archive_sha256':sha(archive),'source_archive_bytes':archive.stat().st_size,'composer_lock_sha256':sha(app/'composer.lock'),'runtime_hashes':runtime_hashes,'vendor_manifest_sha256':hashlib.sha256(json.dumps(files(app/'vendor'),sort_keys=True).encode()).hexdigest(),'application_overlay':'NONE'}
  h.ROOT=app;o=h.Owned(dbdir,'UTC','+00:00');o.env['PHASE3AD_OWNED_FRESH_IDENTITY']='1';o.env['CHAT_RESUMABLE_UPLOADS']='false'
  o.start();out['migration']=o.migration
  def php(script,data):
   cmd=[o.php,str(app/'tests'/script)];r=subprocess.run(cmd,input=json.dumps(data),env=o.env,capture_output=True,text=True,timeout=90);out['commands'].append({'command':cmd,'exit_code':r.returncode,'private_input':'omitted','stderr':o.scrub_text(r.stderr)});h.check(r.returncode==0,'owned control failed '+script);return json.loads(r.stdout)
  cmd=[o.php,'-d','auto_prepend_file='+str(app/'tests/phase3y-bootstrap.php'),str(app/'scripts/provision-system-account.php')];r=subprocess.run(cmd,env=o.env,capture_output=True,text=True,timeout=30);out['commands'].append({'command':cmd,'exit_code':r.returncode,'stderr':o.scrub_text(r.stderr)});h.check(r.returncode==0,'CLI machine provisioning');out['machine']=json.loads(r.stdout)
  actors=[o.registered()for _ in range(4)];fixture=php('phase3ad-fixture.php',{'users':[c.uid for c,d in actors]});out['fixture']=fixture
  # Role changes are owned fixture setup;browser then uses actual password login.
  browser_dir=target.parent/'phase3af-browser';browser_dir.mkdir(exist_ok=True)
  ordered=[actors[0],actors[2],actors[1],actors[3]]
  if os.environ.get('PHASE3AF_REQUIRE_BROWSER')=='1':
   env=o.env|{'PHASE3AF_ORIGIN':f'http://127.0.0.1:{o.port}','PHASE3AF_ACTORS':json.dumps([{'id':c.uid,'email':d['email']}for c,d in ordered]),'PHASE3AF_FIXTURE':json.dumps(fixture),'PHASE3AF_BROWSER_DIR':str(browser_dir)}
   cmd=['node',str(R/'tests/phase3af-browser.cjs')];r=subprocess.run(cmd,env=env,capture_output=True,text=True,timeout=480);out['commands'].append({'command':cmd,'exit_code':r.returncode,'stdout':o.scrub_text(r.stdout),'stderr':o.scrub_text(r.stderr)});out['browser']=json.loads((browser_dir/'browser.json').read_text())if(browser_dir/'browser.json').exists()else{'status':'FAIL','error':'browser record missing'}
  else:out['browser']={'status':'NOT RUN','reason':'actual Chromium required in hosted workflow'}
  # A real multipart native upload is attempted even if browser journeys fail.
  import http.client,uuid
  c=actors[0][0];c.csrf();content=b'Phase3AF owned multipart backup sentinel\n';boundary='af-'+uuid.uuid4().hex
  body=('--'+boundary+'\r\nContent-Disposition: form-data; name="file"; filename="phase3af-owned.txt"\r\nContent-Type: text/plain\r\n\r\n').encode()+content+('\r\n--'+boundary+'--\r\n').encode()
  conn=http.client.HTTPConnection('127.0.0.1',o.port,timeout=20);conn.request('POST','/API/chat/upload-file.php',body,{'Content-Type':'multipart/form-data; boundary='+boundary,'Cookie':c.cookie,'X-CSRF-Token':c.token});res=conn.getresponse();raw=res.read();status=res.status;conn.close();up=json.loads(raw)
  h.check(status==200 and up.get('success'),'native FPM multipart upload');conn=http.client.HTTPConnection('127.0.0.1',o.port,timeout=20);conn.request('GET','/'+up['file_path'],headers={'Cookie':c.cookie});res=conn.getresponse();got=res.read();ds=res.status;conn.close();h.check(ds==200 and got==content,'complete owned byte download')
  out['composed_upload_download']={'status':'PASS','upload_status':status,'download_status':ds,'bytes':len(content),'sha256':hashlib.sha256(got).hexdigest(),'scope':'actual FPM upload and installed static download;not private-URL confidentiality certification'}
  before=php('phase3af-control.php',{'action':'fingerprint'})
  # Configuration is limited to the explicit owned fixture values;never dump inherited process env.
  keys=['APP_ENV','APP_DEBUG','APP_URL','APP_KEY','CLASSROOM_DSN','PHASE3Y_PRIVATE','BREVO_API_URL','BREVO_API_KEY','BREVO_SENDER_EMAIL','SESSION_SECURE','SESSION_LIFETIME','SESSION_SAMESITE','OTP_EXPIRY','OTP_LENGTH','PEER_ML_ENABLED','CENTRIFUGO_ENABLED','PHASE3AD_OWNED_FRESH_IDENTITY','CHAT_RESUMABLE_UPLOADS']
  config={k:o.env[k]for k in keys if k in o.env};cfg=private/'owned-runtime.json';cfg.write_text(json.dumps(config,sort_keys=True));os.chmod(cfg,0o600)
  dump=private/'database.sql';cmd=[os.environ.get('MARIADB_DUMP_BIN','mariadb-dump'),'--no-defaults','--protocol=TCP','--host=127.0.0.1','--port='+str(o.dbport),'--user=root','--single-transaction','--routines','--triggers','--hex-blob','--skip-comments','--databases','classroom_perf_test']
  with dump.open('wb')as f:r=subprocess.run(cmd,env=o.env,stdout=f,stderr=subprocess.PIPE,timeout=120)
  os.chmod(dump,0o600);out['commands'].append({'command':cmd,'exit_code':r.returncode,'stderr':o.scrub_text(r.stderr.decode())});h.check(r.returncode==0,'owned logical backup')
  o.stop();o.gateway=o.mail=o.fpm=o.daemon=None
  installed_files=files(app);(private/'file-manifest.json').write_text(json.dumps(installed_files,sort_keys=True))
  bundle=private/'complete-backup.tar.gz'
  with tarfile.open(bundle,'w:gz')as t:t.add(app,arcname='installed');t.add(dump,arcname='database.sql');t.add(cfg,arcname='owned-runtime.json');t.add(dbdir/'fpm.conf',arcname='fpm.conf')
  os.chmod(bundle,0o600)
  # Preserve original owned state as a separate checkpoint;restore to fresh disk directories.
  original_app=app;original_runtime=dbdir
  # Restore to an independent empty installation and data directory;keep original checkpoint intact.
  restore=private/'restore';restore.mkdir()
  with tarfile.open(bundle)as t:
   for m in t.getmembers():h.check((restore/m.name).resolve().is_relative_to(restore),'unsafe backup member');h.check(not m.issym()and not m.islnk(),'unexpected backup link')
   t.extractall(restore,filter='data')
  app=restore/'installed';h.check(files(app)==installed_files,'source/vendor/uploads restored bytes')
  h.check(json.loads((restore/'owned-runtime.json').read_text())==config,'owned runtime configuration restored')
  dbdir=state/'restored-runtime';dbdir.mkdir(mode=0o700);h.ROOT=app;o.p=dbdir;o.env.update(config);o.env['PHASE3Y_PRIVATE']=str(dbdir)
  o.restore_dump=restore/'database.sql';o.start();after=php('phase3af-control.php',{'action':'fingerprint'});h.check(before==after,'complete restored schema and data equality')
  # Restoring the backup never restores live PHP sessions. Authenticate afresh.
  old=c.req('/API/auth/validate-session.php');h.check(not old['json'].get('authenticated'),'old browser session not resurrected')
  fresh=h.Client(o).csrf();login=fresh.req('/API/auth/login.php','POST',{'identifier':actors[0][1]['email'],'password':actors[0][1]['password']});h.check(login['json'].get('success'),'fresh restored human login');fresh.csrf();history=fresh.req('/API/chat/get-messages.php?channel_id='+str(fixture['channels']['general']));h.check(history['status']==200,'restored owned channel history')
  out['backup_restore']={'status':'PASS','bundle_sha256':sha(bundle),'bundle_bytes':bundle.stat().st_size,'database_dump_sha256':sha(dump),'source_vendor_upload_files':len(installed_files),'file_manifest_sha256':hashlib.sha256(json.dumps(installed_files,sort_keys=True).encode()).hexdigest(),'schema_data_before':before,'schema_data_after':after,'configuration_sha256':sha(cfg),'cold_database_recreation':True,'services_quiesced_before_file_snapshot':True,'old_sessions_restored':False,'original_checkpoint_preserved':True,'configuration_rebase':['PHASE3Y_PRIVATE and FPM filesystem paths rebound to new owned directory;semantic settings/ports unchanged'],'fresh_authentication':'PASS','restore_command':o.restore_result,'private_backup_published':False,'scope':'complete owned installed snapshot+config+seed/database+uploads;not a production rollback procedure'}
  out['persistent_environment']={'status':'PASS across stop,cold disk restore,restart and fresh login','state_retained':True,'services':'stopped after bounded verification;owned disk state retained','durability_boundary':'runner/workspace lifetime only;no stable external/public defense host provisioned','configuration_publication':'hashes only;private fixture config/backup not uploaded'}
  out['requests']=o.records;out['post_restore']=php('phase3af-control.php',{'action':'postbrowser'})
  if out['browser']['status']=='PASS':out['status']='PASS';out['defense_certification']='PENDING independent audit and final environment acceptance'
  else:out['status']='FAIL';out['blockers']=['Essential browser journeys failed or NOT RUN;full defense certification blocked']
 except Exception as e:
  out['error']=o.scrub_text(str(e))if o else str(e);out['diagnostic_traceback']=o.scrub_text(traceback.format_exc())if o else traceback.format_exc()
 finally:
  if o:
   out['controls']=o.controls;out['logs']={p.name:o.scrub_text(p.read_text())for p in [dbdir/'php-errors.log',dbdir/'fpm.log']if p.exists()}
   try:o.stop()
   except Exception:pass
  target.write_text(json.dumps(out,indent=2)+'\n')
 print(out['status'],out.get('error',out.get('blockers',[])));sys.exit(0 if out['status']=='PASS'else 1)
if __name__=='__main__':main()
