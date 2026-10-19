#!/usr/bin/env bash
# cgroup-v2 deployment; source remains in an isolated Piston namespace.
set -euo pipefail
[[ "$(id -u)" == 0 ]] || { echo 'Run: sudo bash scripts/setup-code-lab-piston.sh' >&2; exit 1; }
[[ -f /sys/fs/cgroup/cgroup.controllers ]] || { echo 'This setup requires cgroup v2.' >&2; exit 1; }
project_path="$(cd "$(dirname "$0")/.." && pwd)"
[[ -f "$project_path/.env" ]] || { echo 'Existing application .env required. No configuration was replaced.' >&2; exit 1; }
command -v python3 >/dev/null
docker compose version >/dev/null
docker info >/dev/null
export CODE_LAB_EXECUTION_SLOTS="${CODE_LAB_EXECUTION_SLOTS:-2}"
[[ "$CODE_LAB_EXECUTION_SLOTS" == 1 || "$CODE_LAB_EXECUTION_SLOTS" == 2 ]] || { echo 'Use one or two execution slots.' >&2; exit 1; }
php -r 'exit(function_exists("pcntl_fork") && function_exists("posix_kill") ? 0 : 1);' || { echo 'PHP CLI pcntl and posix are required.' >&2; exit 1; }
export CODE_LAB_RUNNER_STATE=/var/lib/ecollab-code-lab
umask 077
mkdir -p "$CODE_LAB_RUNNER_STATE"
chmod 700 "$CODE_LAB_RUNNER_STATE"
compose=(docker compose -f "$project_path/deploy/code-lab/piston/compose.yml")
# Bind mount must be a regular file before the adapter starts.
if [[ ! -e "$CODE_LAB_RUNNER_STATE/profiles.json" ]]; then printf '{}\n' > "$CODE_LAB_RUNNER_STATE/profiles.json"; fi
[[ -f "$CODE_LAB_RUNNER_STATE/profiles.json" ]] || { echo 'Invalid profile file; preserved existing state.' >&2; exit 1; }
# Stop new worker claims before recreating any in-memory runner service.
php "$project_path/scripts/check-code-lab-runner.php" --queue-idle
worker_was_active=0
if systemctl is-active --quiet ecollab-code-lab; then worker_was_active=1; fi
restore_worker() { if [[ "$worker_was_active" == 1 ]]; then systemctl start ecollab-code-lab || true; fi; }
trap restore_worker EXIT
systemctl stop ecollab-code-lab 2>/dev/null || [[ "$worker_was_active" == 0 ]]
php "$project_path/scripts/check-code-lab-runner.php" --queue-idle
"${compose[@]}" up -d piston
# Freeze the installed versions. A repeat deploy never silently changes old task runtimes.
if ! python3 -c 'import json,sys;c=json.load(open(sys.argv[1]));sys.exit(0 if {p["id"] for p in c.get("profiles",[])} in (set(range(10001,10006)),set(range(10001,10007))) and len(c.get("token",""))>=32 else 1)' "$CODE_LAB_RUNNER_STATE/profiles.json"; then
 "${compose[@]}" run --rm --no-deps adapter python -B /runner/prepare.py > "$CODE_LAB_RUNNER_STATE/profiles.new.json"
 python3 -c 'import json,sys;c=json.load(open(sys.argv[1]));assert len(c["profiles"])==5 and len(c["token"])>=32' "$CODE_LAB_RUNNER_STATE/profiles.new.json"
 mv "$CODE_LAB_RUNNER_STATE/profiles.new.json" "$CODE_LAB_RUNNER_STATE/profiles.json"
fi
# Keep legacy Java untouched; add a versioned package with a real compile stage.
java_install_status="$("${compose[@]}" exec -T piston bash /ecollab-java-runtime/install.sh)"
if [[ "$java_install_status" == CHANGED ]]; then "${compose[@]}" restart piston; fi
"${compose[@]}" run --rm --no-deps adapter python -B /runner/java_profile.py > "$CODE_LAB_RUNNER_STATE/profiles.compiled.json"
cp -p "$CODE_LAB_RUNNER_STATE/profiles.json" "$CODE_LAB_RUNNER_STATE/profiles-before-compiled-java-$(date -u +%Y%m%dT%H%M%S%N).json"
mv "$CODE_LAB_RUNNER_STATE/profiles.compiled.json" "$CODE_LAB_RUNNER_STATE/profiles.json"
# In-flight adapter results are in memory; never intentionally restart a busy queue.
php "$project_path/scripts/check-code-lab-runner.php" --queue-idle
"${compose[@]}" up -d --force-recreate adapter
# Smoke-test with child-process settings before changing the application .env.
python3 - "$project_path" "$CODE_LAB_RUNNER_STATE" <<'PYSMOKE'
import json,os,pathlib,subprocess,sys,time,urllib.request
project=pathlib.Path(sys.argv[1]);conf=json.loads((pathlib.Path(sys.argv[2])/'profiles.json').read_text())
env=dict(os.environ,CODE_LAB_JUDGE0_URL='http://127.0.0.1:2358',CODE_LAB_JUDGE0_TOKEN=conf['token'],CODE_LAB_LANGUAGE_IDS=json.dumps((dict(zip(['c','cpp','python','java','javascript'],[p['id'] for p in conf['profiles']])) | ({'java':10006} if any(p['id']==10006 for p in conf['profiles']) else {}))))
for attempt in range(30):
 try:
  request=urllib.request.Request('http://127.0.0.1:2358/languages',headers={'X-Auth-Token':conf['token']})
  with urllib.request.urlopen(request,timeout=2) as response:json.load(response)
  break
 except Exception:time.sleep(1)
else:raise SystemExit('Adapter did not become ready; application configuration unchanged.')
subprocess.run(['php',str(project/'scripts/check-code-lab-runner.php'),'--languages'],env=env,check=True,timeout=30)
subprocess.run(['php',str(project/'scripts/check-code-lab-runner.php')],env=env,check=True,timeout=240)
PYSMOKE
# Only these three Code Lab keys change. Preserve ownership/mode and all other .env entries.
python3 - "$project_path/.env" "$CODE_LAB_RUNNER_STATE" <<'PY'
import datetime,json,os,pathlib,re,shutil,stat,sys,tempfile
path=pathlib.Path(sys.argv[1]);state=pathlib.Path(sys.argv[2]);conf=json.loads((state/'profiles.json').read_text());original=path.read_text();info=path.stat()
settings={'CODE_LAB_JUDGE0_URL':'http://127.0.0.1:2358','CODE_LAB_JUDGE0_TOKEN':conf['token'],'CODE_LAB_LANGUAGE_IDS':json.dumps((dict(zip(['c','cpp','python','java','javascript'],[p['id'] for p in conf['profiles']])) | ({'java':10006} if any(p['id']==10006 for p in conf['profiles']) else {})),separators=(',',':'))}
updated=original
for key,value in settings.items():
 pattern=r'^'+re.escape(key)+r'=.*$'
 if re.search(pattern,updated,re.M):updated=re.sub(pattern,key+'='+value,updated,flags=re.M)
 else:updated+='\n'+key+'='+value+'\n'
backup=state/('application-env-before-piston-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S%f'))
shutil.copyfile(path,backup);backup.chmod(0o600)
fd,temp=tempfile.mkstemp(dir=path.parent,prefix='.code-lab-env-')
try:
 with os.fdopen(fd,'w')as file:file.write(updated)
 os.chmod(temp,stat.S_IMODE(info.st_mode));os.chown(temp,info.st_uid,info.st_gid);os.replace(temp,path)
finally:
 if os.path.exists(temp):os.unlink(temp)
PY
if [[ -f "$CODE_LAB_RUNNER_STATE/worker.env" ]]; then cp -p "$CODE_LAB_RUNNER_STATE/worker.env" "$CODE_LAB_RUNNER_STATE/worker-env-before-pool-$(date -u +%Y%m%dT%H%M%S%N)"; fi
printf 'CODE_LAB_EXECUTION_SLOTS=%s\n' "$CODE_LAB_EXECUTION_SLOTS" > "$CODE_LAB_RUNNER_STATE/worker.env.new"
mv "$CODE_LAB_RUNNER_STATE/worker.env.new" "$CODE_LAB_RUNNER_STATE/worker.env"
install -m 644 "$project_path/deploy/code-lab/ecollab-code-lab.service" /etc/systemd/system/ecollab-code-lab.service
systemctl daemon-reload
systemctl enable ecollab-code-lab
systemctl restart ecollab-code-lab
php "$project_path/scripts/check-code-lab-runner.php" --workers
worker_was_active=0
trap - EXIT
printf '%s\n' 'Piston runtimes passed smoke checks. Code Lab worker started. No cgroup/GRUB changes or reboot were needed.' 'The API is bound to 127.0.0.1:2358 only. Private configuration/backup: /var/lib/ecollab-code-lab.'
