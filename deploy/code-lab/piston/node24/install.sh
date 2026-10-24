#!/usr/bin/env bash
# STAGING ONLY. Add a new, immutable Piston runtime without changing legacy node packages.
set -euo pipefail
umask 022
[[ "$(node --version)" == v24.22.1 ]] || { echo 'Expected Node.js v24.22.1 API binary' >&2; exit 1; }
[[ -x /usr/local/bin/node ]] || exit 1
target=/piston/packages/ecollab-node24/24.22.1
if [[ -e "$target" ]]; then
  [[ -f "$target/.ppman-installed" && -f "$target/.ecollab-node24-1" && -x "$target/bin/node" ]]
  [[ "$(stat -c %a "$target")" == 755 && "$(stat -c %a "$target/bin")" == 755 ]]
  [[ "$("$target/bin/node" --version)" == v24.22.1 ]]
  cmp /usr/local/bin/node "$target/bin/node"
  printf 'UNCHANGED\n'
  exit 0
fi
mkdir -p /piston/packages/ecollab-node24
stage=$(mktemp -d /piston/packages/ecollab-node24/staging-XXXXXXXX)
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/bin"
# mktemp creates 0700 directories; unprivileged Isolate must be able to traverse the package.
chmod 755 "$stage" "$stage/bin"
install -m 755 /usr/local/bin/node "$stage/bin/node"
cat > "$stage/run" <<'RUN'
#!/usr/bin/env bash
exec node "$@"
RUN
chmod 755 "$stage/run"
cat > "$stage/metadata.json" <<'JSON'
{"language":"ecollab-node24","version":"24.22.1","aliases":[]}
JSON
cat > "$stage/pkg-info.json" <<'JSON'
{"language":"ecollab-node24","version":"24.22.1","aliases":[],"build_platform":"docker-debian"}
JSON
cat > "$stage/.env" <<'ENVFILE'
PATH=/piston/packages/ecollab-node24/24.22.1/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
ENVFILE
printf 'Node 24.22.1 package revision 1\n' > "$stage/.ecollab-node24-1"
mv "$stage" "$target"
touch "$target/.ppman-installed"
trap - EXIT
printf 'CHANGED\n'
