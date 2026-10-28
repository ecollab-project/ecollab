"""Independently recompute OTP clock characterization from raw walls/epochs and outcomes."""
import datetime,hashlib,json,pathlib,sys
from zoneinfo import ZoneInfo
src=pathlib.Path(sys.argv[1]);data=json.loads(src.read_text());assert data['base']=='b09e66c3fcba5066c63da8bece245ae5f4068410'and not data['application_diff']
expected={('UTC','+00:00'):600,('UTC','+06:00'):-21000,('Asia/Manila','+08:00'):600,('Asia/Manila','+00:00'):29400};seen=set();out=[]
def dbtz(s):
 sign=1 if s[0]=='+'else -1;hours,minutes=map(int,s[1:].split(':'));return datetime.timezone(datetime.timedelta(seconds=sign*(hours*3600+minutes*60)))
for c in data['cohorts']:
 assert c['status']=='PASS'and isinstance(c['cohort'],dict);key=tuple(c['cohort'][k]for k in ['php_zone','database_zone']);assert key in expected and key not in seen;seen.add(key)
 assert all(x['exit_code']==0 for x in c['controls'])and c['migration']['exit_code']==0
 assert c['cleanup']['remaining_synthetic_users']==c['cleanup']['orphan_otp']==0
 assert len(c['inventory']['ledger'])==68 and len(c['inventory']['schema'])==160 and all(x['success']in[1,'1']for x in c['inventory']['ledger'])
 assert all(x['sapi']=='fpm-fcgi'and x['app_debug']is False for x in c['fpm_runtime'])
 assert all(x['status']=='PASS'for x in c['cases'])and c['debug_response_checks']==len(c['requests'])
 stable=[];resets=[];identities=set()
 for r in c['native_clock']['clock_records']:
  sc=r['scenario'];identifier=(sc['name'],r['action'],r['point']);assert identifier not in identities;identities.add(identifier)
  exp=r['before']['expires_at'];gen=int(datetime.datetime.strptime(exp,'%Y-%m-%d %H:%M:%S').replace(tzinfo=ZoneInfo(sc['php_generate'])).timestamp())-600
  interpreted=int(datetime.datetime.strptime(exp,'%Y-%m-%d %H:%M:%S').replace(tzinfo=dbtz(sc['db_verify'])).timestamp())
  v=int(r['verification']['database']['epoch']);assert v==int(datetime.datetime.strptime(r['verification']['database']['wall'],'%Y-%m-%d %H:%M:%S').replace(tzinfo=dbtz(sc['db_verify'])).timestamp())
  assert r['generation_epoch_from_stored_php_wall']==gen and int(r['before']['interpreted_expiry_epoch'])==interpreted
  assert r['effective_database_ttl_seconds']==interpreted-gen and r['elapsed_database_seconds']==v-gen
  assert int(r['before']['remaining_seconds'])==interpreted-v and bool(r['before']['valid_by_database'])is(v<interpreted)
  assert r['service_success']is(v<interpreted)and r['valid_count']==int(v<interpreted)and (r['after']['used_at']is not None)is(v<interpreted)
  assert r['simulated_database_clock']is True and r['php_clock_advanced']is False
  if r['point']=='ttl_plus_one':assert v-gen==601
  else:assert v-interpreted=={'expiry_minus_one':-1,'expiry_exact':0,'expiry_plus_one':1}[r['point']]
  if sc['name']=='stable':assert interpreted-gen==expected[key];stable.append(r)
  if r['reset_service']is not None:
   reset=r['reset_service'];assert reset['success']is(v<interpreted)and reset['reset_token_present']is(v<interpreted)and not reset['authenticated']and not reset['pending_login_present'];resets.append({'scenario':sc['name'],'success_at_simulated_601_seconds':reset['success']})
 assert len(identities)==36 and len(stable)==12 and len(resets)==3
 assert c['native_clock']['regeneration']['old_code_success']is False and c['native_clock']['regeneration']['new_code_success']is True
 assert len(c['native_clock']['purge'])==6
 for r in c['native_clock']['purge']:assert r['row_exists']is(r['seconds_relative_24h_cutoff']>=0)and r['removed']==int(r['seconds_relative_24h_cutoff']<0)
 con=c['otp_concurrent']
 if key==('UTC','+00:00'):assert sum(con['successes'])==1 and con['overlap']and con['before']['business']['users']['rows']==con['after']['business']['users']['rows']and con['after']['session']['authenticated']
 marker=c['verified_marker']
 if key==('UTC','+00:00'):assert marker['registration_success']and marker['before']['session']['signup_verified']and 'pending_signup_email_expires'not in marker['before']['session']['keys']and not marker['before_registration']['session']['signup_intent_valid']
 rejects=0
 for r in c['requests']:
  if isinstance(r['response'],dict)and r['response'].get('success')is False:
   a,b=r['state_before'],r['state_after'];assert a['business']==b['business']and a['otp_checksum']==b['otp_checksum'];assert not b['session']['authenticated']or a['session']['user_id']==b['session']['user_id']>0;rejects+=1
 out.append({'cohort':c['cohort'],'http_requests':len(c['requests']),'http_cases':len(c['cases']),'checked_rejections':rejects,'native_clock_records':36,'stable_effective_database_ttl_seconds':expected[key],'fresh_optional_http_success':c['timezone']['fresh_code_accepted'],'reset_service_controls':resets,'debug_response_checks':c['debug_response_checks'],'cleanup':c['cleanup'],'schema_sha256':c['inventory']['schema_sha256']})
assert seen==set(expected)and len({c['schema_sha256']for c in out})==1
summary={'status':'PASS for characterization, known adverse timezone behavior retained','base':data['base'],'head':data['head'],'tree':data['tree'],'intended_head':data['intended_head'],'sha256':data['sha256'],'harness_diff':data['harness_diff'],'harness_status':data['harness_status'],'raw_sha256':hashlib.sha256(src.read_bytes()).hexdigest(),'cohorts':out,'limitations':['Database verification time advanced with SET SESSION timestamp; PHP time not advanced; no real 601-second waiting claim','OtpService row predicate differs from independently enforced login/signup intent deadlines','No production timezone, TLS, proxy, capacity or new marker TTL policy validation']}
pathlib.Path(sys.argv[2]).write_text(json.dumps(summary,indent=2)+'\n');print([(r['cohort'],r['stable_effective_database_ttl_seconds'],r['http_requests'])for r in out])
