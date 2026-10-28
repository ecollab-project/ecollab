"""Recompute full-suite acceptance from retained commands, JUnit and schema records."""
import hashlib,json,pathlib,sys,xml.etree.ElementTree as ET
raw=json.loads(pathlib.Path(sys.argv[1]).read_text());run=raw['phase3x_suite'];assert run['exit_code']==0
suite=json.loads(run['stdout']);assert suite['status']=='PASS';source=raw['phase3x_source'];assert not source['application_diff']
expected={'testRegisterCreatesUserAndReturnsSuccess','testRegisterRejectsDuplicateEmail','testRegisterSeedsPmUserStudyPrefs','testRegisterFallsBackToDefaultsWhenStyleGoalUnrecognized','testLoginSucceedsWithCorrectCredentials'}
results={};identities=set()
assert set(suite['runs'])=={'baseline','current','reverse','random-31028'}
for label,r in suite['runs'].items():
 xml=ET.fromstring(r['junit']);top=xml.find('testsuite');counts={k:int(top.attrib[k]) for k in ['tests','assertions','errors','failures','skipped']};assert counts==r['counts']
 cases=list(xml.iter('testcase'));assert len(cases)==counts['tests'];failed={c.attrib['name']for c in cases if c.find('failure') is not None or c.find('error') is not None}
 assert set(r['failed_cases'])==failed;assert counts['errors']==counts['skipped']==0
 if label=='baseline':assert r['exit_code']==1 and counts=={'tests':46,'assertions':114,'errors':0,'failures':5,'skipped':0} and failed==expected
 else:assert r['exit_code']==0 and counts['tests']>=57 and counts['failures']==0 and not failed
 assert r['remaining_synthetic_auth_users']==r['orphan_otp_rows']==0
 schema=json.loads(r['schema']['stdout']);assert r['schema']['exit_code']==0 and schema['full_migration_status']=='PASS' and not schema['failed_migrations'];assert len(schema['migration_ledger'])==68 and len(schema['derived_schema'])==160 and len(schema['inventory'])==70
 assert all(c['exit_code']==0 for c in schema['runner_commands']);identities.add(r['schema_identity'])
 results[label]={'exit_code':r['exit_code'],'counts':counts,'failed_cases':sorted(failed),'remaining_synthetic_auth_users':r['remaining_synthetic_auth_users'],'orphan_otp_rows':r['orphan_otp_rows'],'schema_identity':r['schema_identity']}
assert len(identities)==1
clock=suite['clock_alignment'];assert clock['php_zone']=='UTC' and clock['database']['db_zone']=='+00:00' and clock['database']['db_now']==clock['database']['db_utc'] and abs(clock['php_epoch']-clock['database']['db_epoch'])<=5
out={'status':'PASS','scope':suite['scope'],'base':raw['base'],'source':source,'clock_alignment':clock,'runs':results,'raw_sha256':hashlib.sha256(pathlib.Path(sys.argv[1]).read_bytes()).hexdigest(),'limitations':['Service integration only; HTTP/browser/external mail not executed','UTC-aligned owned fixture; production timezone parity unknown','Inherited base failures remain FAIL; static clean/capacity gates not established']}
pathlib.Path(sys.argv[2]).write_text(json.dumps(out,indent=2)+'\n');print('Verified JUnit, schema, source and cleanup:',[(k,r['exit_code'],r['counts'])for k,r in results.items()])
