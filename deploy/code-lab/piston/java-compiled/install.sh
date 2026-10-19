#!/usr/bin/env bash
# Runs inside Piston only, after the durable application queue drains.
set -euo pipefail
umask 022
base=/piston/packages/java/15.0.2
target=/piston/packages/ecollab-java-compiled/1.0.0
hooks=/ecollab-java-runtime
[[ -x "$base/bin/javac" && -x "$base/bin/java" && -f "$base/.ppman-installed" ]]
if [[ -e "$target" ]]; then
 [[ -f "$target/.ppman-installed" && -f "$target/.ecollab-java-1" ]]
 cmp "$hooks/compile" "$target/compile"
 cmp "$hooks/run" "$target/run"
 printf 'UNCHANGED\n'
 exit
fi
mkdir -p /piston/packages/ecollab-java-compiled
stage=$(mktemp -d /piston/packages/ecollab-java-compiled/staging-XXXXXXXX)
trap 'rm -rf "$stage"' EXIT
# Never copy the installation marker before the new package is complete.
tar -C "$base" --exclude=./.ppman-installed -cf - . | tar -C "$stage" -xf -
cp "$hooks/compile" "$stage/compile"
cp "$hooks/run" "$stage/run"
chmod 755 "$stage" "$stage/compile" "$stage/run"
# Runtime environment files contain package paths, not application credentials.
node - "$stage" "$base" "$target" <<'JS'
const fs=require('fs'),path=require('path');
const [stage,base,target]=process.argv.slice(2);
const info=JSON.parse(fs.readFileSync(path.join(stage,'pkg-info.json'),'utf8'));
if(info.language!=='java'||info.version!=='15.0.2')throw Error('Expected frozen Java 15.0.2');
info.language='ecollab-java-compiled';info.version='1.0.0';info.aliases=[];
delete info.provides;
fs.writeFileSync(path.join(stage,'pkg-info.json'),JSON.stringify(info));
fs.writeFileSync(path.join(stage,'metadata.json'),JSON.stringify({language:info.language,version:info.version,aliases:[]}));
const envPath=path.join(stage,'.env');
fs.writeFileSync(envPath,fs.readFileSync(envPath,'utf8').split(base).join(target));
JS
printf 'JDK 15.0.2; compiled profile revision 1\n' > "$stage/.ecollab-java-1"
mv "$stage" "$target"
touch "$target/.ppman-installed"
trap - EXIT
printf 'CHANGED\n'
