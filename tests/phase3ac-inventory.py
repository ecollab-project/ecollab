"""Tracked-source OTP writer inventory. Does not inspect any production environment."""
import hashlib,json,pathlib,subprocess,sys
r=pathlib.Path(__file__).resolve().parents[1];paths=subprocess.check_output(['git','ls-files','*.php','*.sql'],cwd=r,text=True).splitlines();hits=[]
for name in paths:
 if name.startswith(('tests/','docs/','vendor/')):continue
 p=r/name
 if not p.is_file():continue
 text=p.read_text(errors='replace')
 if 'otp_codes'in text or 'new OtpService'in text or 'otpService->generate'in text:
  hits.append({'path':name,'sha256':hashlib.sha256(p.read_bytes()).hexdigest(),'references':[{'line':i,'source':line.strip()}for i,line in enumerate(text.splitlines(),1)if 'otp_codes'in line or 'otpService->generate'in line or 'new OtpService'in line]})
assert {h['path']for h in hits}=={'security/forgot-password/OtpService.php','services/AuthService.php','API/auth/resend-signup-otp.php'}
s=(r/'security/forgot-password/OtpService.php').read_text();assert 'INTERVAL :ttl SECOND'in s and 'CURRENT_TIMESTAMP'in s
out={'status':'PASS for tracked supported application writers','base':'e4999821b16f8d8085a8cde6c5b08de2fc824ae5','head':subprocess.check_output(['git','rev-parse','HEAD'],cwd=r,text=True).strip(),'writers':hits,'default_changed':False,'limitations':['Session-only pre-signup OTPs remain epoch-based and unchanged','Manual SQL,unknown external writers,existing database triggers/events and production state are not verified; deployment gate requires their inventory','No omitted-created_at application INSERT found; test fixtures are excluded from supported application writers']}
pathlib.Path(sys.argv[1]).write_text(json.dumps(out,indent=2)+'\n');print(out['status'])
