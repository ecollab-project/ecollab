"""Recompute redacted HTTP/session, timezone and cleanup evidence; never treat NOT RUN as PASS."""
import collections,datetime,hashlib,json,pathlib,re,sys
src=pathlib.Path(sys.argv[1]);data=json.loads(src.read_text());assert data['base']=='928a3b8912ef60ab374270c1efd3a24c0433c8aa' and not data['application_diff']
expected={('UTC','+00:00'):True,('UTC','+06:00'):False,('Asia/Manila','+08:00'):True};rows=[];seen=set()
for c in data['cohorts']:
 assert c['status']=='PASS' and isinstance(c['cohort'],dict),'Incomplete/failed HTTP cohort; inspect raw failure diagnostics'
 key=(c['cohort']['php_zone'],c['cohort']['database_zone']);assert key in expected and key not in seen;seen.add(key)
 assert c['status']=='PASS';assert all(x['status']in ['PASS','NOT RUN']for x in c['cases']);assert all(x['status']!='NOT RUN'or x['name']=='browser_journey'for x in c['cases'])
 assert all(x['exit_code']==0 for x in c['controls']);assert c['migration']['exit_code']==0;assert len(c['inventory']['ledger'])==68 and all(x['success']in [1,'1']for x in c['inventory']['ledger']);assert len(c['inventory']['schema'])==160
 assert c['cleanup']['remaining_synthetic_users']==c['cleanup']['orphan_otp']==0
 assert c['fpm_runtime'] and all(x['sapi']=='fpm-fcgi' and x['php'].startswith('8.3.') and x['app_debug']is False and x['app_env']=='testing' and x['delivery_is_owned_loopback']is True and x['php_zone']==key[0]for x in c['fpm_runtime'])
 q=c['timezone'];assert q['fresh_code_accepted']is expected[key];assert q['policy_changed']is False
 clock=q['before']['clock'];db=clock['database'];assert clock['php_zone']==key[0] and db['zone']==key[1];assert abs(clock['php_epoch']-int(db['epoch']))<=2
 remaining=q['before']['otp'][-1]['remaining_seconds'];assert (0<remaining+21600<=600)if not expected[key]else(0<remaining<=600)
 rejects=0;counts=collections.Counter()
 for r in c['requests']:
  assert re.fullmatch('[a-f0-9]{64}',r['response_sha256']);counts[str(r['status'])]+=1
  for k in ['session_before','session_after']:
   assert r[k]is None or re.fullmatch(r'session-\d+',r[k])
  for obj in [r['request'],r['response']]:
   def private(v,k=''):
    if isinstance(v,dict):
     for a,b in v.items():
      if a not in ['otp_required','email_verified']and any(x in a.lower()for x in ['password','otp','token','cookie','identifier','email','full_name','username','remember_token']):assert b in ['<redacted>',None]
      else:private(b,a)
    elif isinstance(v,list):
     for b in v:private(b,k)
   private(obj)
  if isinstance(r['response'],dict)and r['response'].get('success')is False and r['state_before']is not None:
   a,b=r['state_before'],r['state_after'];assert a['business']==b['business'] and a['otp_checksum']==b['otp_checksum'];assert not b['session']['authenticated']or a['session']['user_id']==b['session']['user_id']>0;rejects+=1
 if c['concurrent']:
  x=c['concurrent'];assert sorted(x['statuses'])==[200,422]and x['overlap'];assert x['after']['users']['rows']==x['before']['users']['rows']+1
 if key[1]in ['+00:00','+08:00']:
  for label in ['database_otp_expired_intent_valid','intent_expired_database_otp_valid']:
   r=next(r for r in c['requests']if r['case']==label and r['path']=='/API/auth/verify-otp.php');s=r['state_before'];valid=any(x['valid_by_database']for x in s['otp'])
   assert s['session']['login_intent_valid']is(label=='database_otp_expired_intent_valid');assert valid is(label=='intent_expired_database_otp_valid');assert r['status']==200 and r['response']['success']is False
 browser=c['browser'];assert browser['status']in ['PASS','NOT RUN']
 if browser['status']=='PASS':assert browser['exit_code']==0 and not browser['page_errors']and all(x['status']=='PASS'for x in browser['steps'])and all(x['status']<400 for x in browser['navigations'])
 rows.append({'cohort':c['cohort'],'status':'PASS for scoped HTTP controls','http_cases_pass':sum(x['status']=='PASS'and x['name']!='browser_journey'for x in c['cases']),'requests':len(c['requests']),'http_status_counts':dict(counts),'independently_checked_rejections':rejects,'fresh_persisted_otp_outcome':'PASS'if expected[key]else'FAIL: reproduced timezone-dependent rejection','persisted_remaining_seconds':remaining,'clock':clock,'browser':browser['status'],'cleanup':c['cleanup'],'schema_sha256':c['inventory']['schema_sha256']})
assert seen==set(expected)
assert len({r['schema_sha256']for r in rows})==1
out={'base':data['base'],'harness_head':data['head'],'harness_tree':data['tree'],'intended_head':data['intended_head'],'harness_diff':data['harness_diff'],'harness_status':data['harness_status'],'sha256':data['sha256'],'raw_sha256':hashlib.sha256(src.read_bytes()).hexdigest(),'direct_http_requests':sum(r['requests']for r in rows),'cohorts':rows,'limitations':['Semantic redacted request/response records and original response hashes; no unredacted wire archive','Controlled loopback HTTP, not TLS/production parity or capacity','Misaligned fresh OTP deliberately remains FAIL; browser NOT RUN remains NOT RUN']}
pathlib.Path(sys.argv[2]).write_text(json.dumps(out,indent=2)+'\n');print('Recomputed',out['direct_http_requests'],'direct HTTP requests',[(r['cohort'],r['browser'],r['fresh_persisted_otp_outcome'])for r in rows])
