"""Recompute native trial clock and competing-session evidence; unsafe outcomes remain FAIL."""
import datetime,hashlib,json,pathlib,sys
from zoneinfo import ZoneInfo
p=pathlib.Path(sys.argv[1]);x=json.loads(p.read_text());assert x['base']=='bc1f1bc3cff91ce61cf531853e7220f1cfca590a'and not x['application_diff']
def epoch(w,z):return int(datetime.datetime.strptime(w,'%Y-%m-%d %H:%M:%S').replace(tzinfo=z).timestamp())
def offset(v):
 s=1 if v[0]=='+'else -1;h,m=map(int,v[1:].split(':'));return datetime.timezone(datetime.timedelta(seconds=s*(3600*h+60*m)))
rows=[];races=[];schemas=set();seen=set()
for c in x['cohorts']:
 assert c['status']=='PASS'and c['trial_only']is True
 key=(c['cohort']['php_zone'],c['cohort']['database_zone'],c['configured_ttl']);assert key not in seen;seen.add(key)
 assert all(a['exit_code']==0 for a in c['controls'])and c['migration']['exit_code']==0
 assert c['cleanup']['remaining_synthetic_users']==c['cleanup']['orphan_otp']==0
 assert len(c['inventory']['ledger'])==68 and len(c['inventory']['schema'])==160 and all(a['success']in[1,'1']for a in c['inventory']['ledger']);schemas.add(c['inventory']['schema_sha256'])
 for variant,t in c['trials'].items():
  assert t['configured_ttl']==key[2]and len(t['clock_records'])==36
  identities=set();stable=set();reset_checks=0
  for r in t['clock_records']:
   sc=r['scenario'];ident=(sc['name'],r['action'],r['point']);assert ident not in identities;identities.add(ident)
   trial=variant=='utc_trial';ttl=t['configured_ttl'];exp=r['before']['expires_at']
   gen=epoch(exp,datetime.timezone.utc if trial else ZoneInfo(sc['php_generate']))-ttl
   end=epoch(exp,datetime.timezone.utc if trial else offset(sc['db_verify']))
   now=int(r['verification']['database']['epoch']);assert now==epoch(r['verification']['database']['wall'],offset(sc['db_verify']))
   assert gen==r['generation_epoch_from_stored_php_wall']and end==int(r['before']['interpreted_expiry_epoch'])
   assert end-gen==r['effective_database_ttl_seconds']and now-gen==r['elapsed_database_seconds']and end-now==int(r['before']['remaining_seconds'])
   valid=now<end;assert r['service_success']is valid and r['valid_count']==int(valid)and bool(r['before']['valid_by_database'])is valid and (r['after']['used_at']is not None)is valid
   assert r['simulated_database_clock']is True and r['php_clock_advanced']is False
   if r['point']=='ttl_plus_one':assert now-gen==ttl+1
   else:assert now-end=={'expiry_minus_one':-1,'expiry_exact':0,'expiry_plus_one':1}[r['point']]
   if trial:assert end-gen==ttl and (not valid if r['point']=='ttl_plus_one'else True)
   if sc['name']=='stable':stable.add(end-gen)
   if r['reset_service']is not None:
    a=r['reset_service'];assert a['success']is valid and a['reset_token_present']is valid and not a['authenticated']and not a['pending_login_present'];reset_checks+=1
  assert len(identities)==36 and reset_checks==3
  expected=ttl if trial else ttl+int(datetime.datetime.now(ZoneInfo(key[0])).utcoffset().total_seconds())-int(offset(key[1]).utcoffset(None).total_seconds())
  assert stable=={expected}
  assert t['regeneration']['old_code_success']is False and t['regeneration']['new_code_success']is True and t['regeneration']['expiry_fixture_override']is(variant=='original')
  assert len(t['purge'])==6
  for a in t['purge']:assert a['row_exists']is(a['seconds_relative_24h_cutoff']>=0)and a['removed']==int(a['seconds_relative_24h_cutoff']<0)
  rows.append({'cohort':c['cohort'],'configured_ttl':ttl,'variant':variant,'clock_records':36,'stable_effective_seconds':expected,'strict_expiry_boundary':'PASS','reset_checks':reset_checks})
 for a in c['contracts']['records']:
  assert a['state_preserved']and a['count_before']==1 and a['count_after']==0 and a['trimmed_code_success']and not a['replay']['success']
  assert all(not v['success']for v in a['negative_results'].values());d=a['delivery_failure'];assert not d['transport']['success']and d['forgot_result_success']and not d['debug_present']
 if c['race']:
  assert len(c['race']['records'])==10
  for a in c['race']['records']:
   assert a['independent_connections']and a['independent_sessions']and len(a['workers'])==2
   assert len({w['connection_id']for w in a['workers']})==2
   overlap=max(w['start_monotonic_ns']for w in a['workers'])<min(w['end_monotonic_ns']for w in a['workers']);assert overlap==a['request_intervals_overlap']
   successes=sum(w['success']for w in a['workers']);assert successes==a['successful_consumptions']and all(w['exit_code']==0 and w['success']==w['reset_token_present']and not w['authenticated']for w in a['workers'])
   races.append({'variant':a['variant'],'round':a['round'],'overlap':overlap,'successful_consumptions':successes,'single_use_safety':'FAIL'if successes>1 else'NOT VIOLATED in this round'})
  tr=c['transition'];assert tr['legacy_codes_still_valid_at_simulated_ttl_plus_one']and tr['rollback_restored_unsafe_rows']and tr['old_writer_after_cutover_reintroduces_valid_legacy_row']and not tr['production_transition_approved']
  assert all(not r['success']for r in tr['old_code_verification_after_invalidation'].values())and all(not r['success']for r in tr['fresh_trial_verification_at_ttl_plus_one'].values())
assert seen=={('UTC','+00:00',600),('UTC','+06:00',600),('Asia/Manila','+08:00',600),('Asia/Manila','+00:00',600),('UTC','+00:00',120)}and len(schemas)==1 and len(races)==10
out={'scope':'PASS for test-only characterization; security safety failures preserved','base':x['base'],'head':x['head'],'tree':x['tree'],'intended_head':x['intended_head'],'harness_diff':x['harness_diff'],'harness_status':x['harness_status'],'sha256':x['sha256'],'raw_sha256':hashlib.sha256(p.read_bytes()).hexdigest(),'clock_records':sum(a['clock_records']for a in rows),'trial_clock_records':180,'cohorts':rows,'race_rounds':races,'double_consumption_rounds':sum(r['successful_consumptions']>1 for r in races),'production_correction':'NOT AUTHORIZED / NOT IMPLEMENTED','trial_http_browser':'NOT RUN; native CLI injection only, unchanged application HTTP/browser tested separately'}
pathlib.Path(sys.argv[2]).write_text(json.dumps(out,indent=2)+'\n');print('Recomputed',out['clock_records'],'clock records;',out['double_consumption_rounds'],'double-consumption rounds (security FAIL)')
