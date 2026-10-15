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
export CODE_LAB_RUNNER_STATE=/var/lib/ecollab-code-lab
umask 077
mkdir -p "$CODE_LAB_RUNNER_STATE"
chmod 700 "$CODE_LAB_RUNNER_STATE"
compose=(docker compose -f "$project_path/deploy/code-lab/piston/compose.yml")
# Bind mount must be a regular file before the adapter starts.
if [[ ! -e "$CODE_LAB_RUNNER_STATE/profiles.json" ]]; then printf '{}\n' > "$CODE_LAB_RUNNER_STATE/profiles.json"; fi
[[ -f "$CODE_LAB_RUNNER_STATE/profiles.json" ]] || { echo 'Invalid profile file; preserved existing state.' >&2; exit 1; }
"${compose[@]}" up -d piston
# Freeze the installed versions. A repeat deploy never silently changes old task runtimes.
if ! python3 -c 'import json,sys;c=json.load(open(sys.argv[1]));sys.exit(0 if len(c.get("profiles",[]))==5 and len(c.get("token",""))>=32 else 1)' "$CODE_LAB_RUNNER_STATE/profiles.json"; then
 "${compose[@]}" run --rm --no-deps adapter python -B /runner/prepare.py > "$CODE_LAB_RUNNER_STATE/profiles.new.json"
 python3 -c 'import json,sys;c=json.load(open(sys.argv[1]));assert len(c["profiles"])==5 and len(c["token"])>=32' "$CODE_LAB_RUNNER_STATE/profiles.new.json"
 mv "$CODE_LAB_RUNNER_STATE/profiles.new.json" "$CODE_LAB_RUNNER_STATE/profiles.json"
fi
"${compose[@]}" up -d --force-recreate adapter
# Only these three Code Lab keys change. Preserve ownership/mode and all other .env entries.
python3 - "$project_path/.env" "$CODE_LAB_RUNNER_STATE" <<'PY'
import datetime,json,os,pathlib,re,shutil,stat,sys,tempfile
path=pathlib.Path(sys.argv[1]);state=pathlib.Path(sys.argv[2]);conf=json.loads((state/'profiles.json').read_text());original=path.read_text();info=path.stat()
settings={'CODE_LAB_JUDGE0_URL':'http://127.0.0.1:2358','CODE_LAB_JUDGE0_TOKEN':conf['token'],'CODE_LAB_LANGUAGE_IDS':json.dumps(dict(zip(['c','cpp','python','java','javascript'],[p['id'] for p in conf['profiles']])),separators=(',',':'))}
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
# Known programs only; enabling the classroom worker depends on all checks passing.
for attempt in $(seq 1 30); do
 if php "$project_path/scripts/check-code-lab-runner.php" --languages >/dev/null 2>&1; then break; fi
 sleep 1
done
php "$project_path/scripts/check-code-lab-runner.php" --languages
php "$project_path/scripts/check-code-lab-runner.php"
install -m 644 "$project_path/deploy/code-lab/ecollab-code-lab.service" /etc/systemd/system/ecollab-code-lab.service
systemctl daemon-reload
systemctl enable ecollab-code-lab
systemctl restart ecollab-code-lab
printf '%s\n' 'Piston runtimes passed smoke checks. Code Lab worker started. No cgroup/GRUB changes or reboot were needed.' 'The API is bound to 127.0.0.1:2358 only. Private configuration/backup: /var/lib/ecollab-code-lab.'
