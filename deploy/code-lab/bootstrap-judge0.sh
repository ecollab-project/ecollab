#!/usr/bin/env bash
# Run on a dedicated execution host, not the production application host.
set -euo pipefail
[[ "$(id -u)" == 0 ]] || { echo 'Run as root on the execution host.' >&2; exit 1; }
if [[ -f /sys/fs/cgroup/cgroup.controllers ]]; then
 echo 'This Judge0 release requires a compatible cgroup v1 host. Provision a dedicated compatible worker; this script does not change GRUB or reboot.' >&2; exit 1
fi
command -v docker >/dev/null
docker compose version >/dev/null
command -v unzip >/dev/null
umask 077
root=/opt/ecollab-judge0
[[ ! -e "$root" ]] || { echo 'Existing runner preserved. Configure it manually.' >&2; exit 1; }
mkdir -p "$root"
curl --fail --location --proto '=https' --tlsv1.2 https://github.com/judge0/judge0/releases/download/v1.13.1/judge0-v1.13.1.zip --output "$root/release.zip"
unzip -q "$root/release.zip" -d "$root"
cd "$root/judge0-v1.13.1"
python3 - <<'PY'
import secrets,pathlib,re
p=pathlib.Path('judge0.conf');s=p.read_text()
settings={'REDIS_PASSWORD':secrets.token_hex(32),'POSTGRES_PASSWORD':secrets.token_hex(32),'AUTHN_TOKEN':secrets.token_hex(32),'ENABLE_NETWORK':'false','ALLOW_ENABLE_NETWORK':'false','MAX_CPU_TIME_LIMIT':'10','MAX_WALL_TIME_LIMIT':'30','MAX_MEMORY_LIMIT':'524288','MAX_MAX_FILE_SIZE':'10240','MAX_MAX_PROCESSES_AND_OR_THREADS':'64','MAX_STACK_LIMIT':'65536','COUNT':'1','RAILS_MAX_THREADS':'2'}
for key,value in settings.items():
 pattern=r'^'+key+r'=.*$'
 if re.search(pattern,s,re.M):s=re.sub(pattern,key+'='+value,s,flags=re.M)
 else:s+='\n'+key+'='+value+'\n'
p.write_text(s);p.chmod(0o600)
compose=pathlib.Path('docker-compose.yml');c=compose.read_text().replace('judge0/judge0:latest','judge0/judge0:1.13.1').replace('"2358:2358"','"127.0.0.1:2358:2358"');compose.write_text(c)
PY
docker compose up -d db redis
sleep 10
docker compose up -d
printf '%s\n' 'Judge0 prepared on loopback port 2358. Configure a private tunnel or authenticated HTTPS proxy for the eCollab worker; do not publish port 2358.' 'Set CODE_LAB_JUDGE0_TOKEN from AUTHN_TOKEN in the private judge0.conf file. Use the language-list and smoke-check scripts before enabling classroom execution.'
