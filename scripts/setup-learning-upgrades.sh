#!/usr/bin/env bash
# Run after applying 056/057. Does not alter compiler, cgroups, or realtime settings.
set -euo pipefail
[[ "$(id -u)" == 0 ]] || { echo 'Run with sudo.' >&2; exit 1; }
project="$(cd "$(dirname "$0")/.." && pwd)"
[[ -f "$project/.env" ]] || { echo 'Existing .env is required.' >&2; exit 1; }
command -v php >/dev/null;command -v curl >/dev/null;command -v python3 >/dev/null;docker compose version >/dev/null
php -r 'foreach(["curl","mbstring","openssl"] as $e)if(!extension_loaded($e)){fwrite(STDERR,"Missing PHP extension: $e\n");exit(1);}'
php -r 'require $argv[1]."/config.php";require ROOT_PATH."/database/config/db.php";$db=Database::getInstance();foreach(["code_lab_similarity_jobs","push_subscriptions","push_deliveries","code_lab_deadline_notices"]as$t)$db->query("SELECT 1 FROM ".$t." LIMIT 1");' "$project"
umask 077
state=/var/lib/ecollab-learning-upgrades
mkdir -p "$state" /var/lib/ecollab-similarity
chmod 700 "$state" /var/lib/ecollab-similarity
# Use the operator's installed Composer, or the pinned fallback.
if command -v composer >/dev/null;then composer_command=(composer);else
 curl --fail --location --silent --show-error https://getcomposer.org/download/2.8.12/composer.phar -o "$state/composer.phar"
 echo 'f446ea719708bb85fcbf4ef18def5d0515f1f9b4d703f6d820c9c1656e10a2f2  /var/lib/ecollab-learning-upgrades/composer.phar' | sha256sum -c - >/dev/null
 composer_command=(php "$state/composer.phar")
fi
(umask 0022;COMPOSER_ALLOW_SUPERUSER=1 "${composer_command[@]}" install --working-dir="$project/tools/web-push" --no-dev --no-interaction --no-plugins --prefer-dist)
if [[ ! -f "$state/push-keys.json" ]];then php "$project/scripts/generate-push-keys.php" > "$state/push-keys.json";fi
# Reuse a configured application token on reruns so bridge and PHP remain aligned.
python3 - "$project/.env" <<'PYTOKEN'
import pathlib,re,sys
match=re.search(r'^CODE_LAB_SIMILARITY_TOKEN=(.+)$',pathlib.Path(sys.argv[1]).read_text(),re.M)
if match:
 value=match.group(1).strip().strip('\"\'')
 if len(value)<32:raise SystemExit('Configured similarity token is too short.')
 pathlib.Path('/var/lib/ecollab-similarity/token').write_text(value+'\n')
PYTOKEN
if [[ ! -f /var/lib/ecollab-similarity/token ]];then python3 -c 'import secrets;print(secrets.token_hex(32))' > /var/lib/ecollab-similarity/token;fi
chown 10001:10001 /var/lib/ecollab-similarity/token;chmod 400 /var/lib/ecollab-similarity/token
jar="$project/deploy/code-lab/similarity/jplag.jar"
trap 'rm -f "$jar"' EXIT
curl --fail --location --silent --show-error https://github.com/jplag/JPlag/releases/download/v6.3.0/jplag-6.3.0-jar-with-dependencies.jar -o "$jar"
echo "5f2c21e8b88ed77134effcb3a5a3ab13d188f6a3e16d401387f7479e92db9aa2  $jar" | sha256sum -c - >/dev/null
chmod 644 "$jar"
docker compose -f "$project/deploy/code-lab/similarity/compose.yml" up -d --build
# Authenticated readiness check, without source code or credentials in logs.
python3 - <<'PY'
import json,pathlib,time,urllib.request,urllib.error
key=pathlib.Path('/var/lib/ecollab-similarity/token').read_text().strip()
for attempt in range(30):
 try:
  request=urllib.request.Request('http://127.0.0.1:2359/compare',data=b'{}',headers={'X-Auth-Token':key,'Content-Type':'application/json'})
  urllib.request.urlopen(request,timeout=2)
 except urllib.error.HTTPError as e:
  if e.code==422:break
 except Exception:pass
 time.sleep(1)
else:raise SystemExit('Similarity service did not become ready. Existing settings are backed up; worker units were not started.')
PY
# Real four-language parser smoke check before changing application configuration.
SIMILARITY_URL=http://127.0.0.1:2359/compare SIMILARITY_TOKEN_FILE=/var/lib/ecollab-similarity/token python3 -B "$project/tests/code-lab-similarity-live.py"
# Preserve existing keys; generate them only for the first install. Never print secrets.
python3 - "$project/.env" "$state" <<'PY'
import datetime,json,os,pathlib,re,shutil,stat,sys,tempfile
path=pathlib.Path(sys.argv[1]);state=pathlib.Path(sys.argv[2]);original=path.read_text();info=path.stat();keys=json.loads((state/'push-keys.json').read_text())
values={'WEB_PUSH_PUBLIC_KEY':keys['publicKey'],'WEB_PUSH_PRIVATE_KEY':keys['privateKey'],'WEB_PUSH_SUBJECT':'https://ecollab.tech','CODE_LAB_SIMILARITY_TOKEN':pathlib.Path('/var/lib/ecollab-similarity/token').read_text().strip()}
updated=original
for name,value in values.items():
 pattern=r'^'+re.escape(name)+r'=(.*)$';existing=re.search(pattern,updated,re.M)
 if existing and existing.group(1).strip():continue
 if existing:updated=re.sub(pattern,name+'='+value,updated,flags=re.M)
 else:updated+='\n'+name+'='+value+'\n'
backup=state/('environment-before-upgrades-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%S%f'));shutil.copyfile(path,backup);backup.chmod(0o600)
fd,temp=tempfile.mkstemp(dir=path.parent,prefix='.learning-env-')
try:
 with os.fdopen(fd,'w')as out:out.write(updated)
 os.chmod(temp,stat.S_IMODE(info.st_mode));os.chown(temp,info.st_uid,info.st_gid);os.replace(temp,path)
finally:
 if os.path.exists(temp):os.unlink(temp)
PY
for worker in similarity push;do
 install -m644 "$project/deploy/learning-upgrades/ecollab-$worker.service" "/etc/systemd/system/ecollab-$worker.service"
done
systemctl daemon-reload
systemctl enable --now ecollab-similarity ecollab-push
systemctl restart ecollab-similarity ecollab-push
printf '%s\n' 'Learning upgrades configured. Enable browser push explicitly in Settings > Notifications.' 'The compiler and realtime transport settings were not changed.'
