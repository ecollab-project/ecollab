#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
[[ "$(git branch --show-current)" == "ecollab-collabs" ]] || { echo 'Use the ecollab-collabs deployment checkout.' >&2; exit 1; }
[[ -z "$(git status --porcelain --untracked-files=no)" ]] || { echo 'Preserve tracked local changes before deploying.' >&2; exit 1; }
umask 0022
git pull --ff-only origin ecollab-collabs
# Public page templates and responsive assets only. Runtime secrets remain untouched.
files=(index.php terms.php includes/layout/learning-workspace.php assets/css/device-layout.css assets/js/device-layout.js assets/js/mobile-viewport.js)
while IFS= read -r -d '' file; do
  if grep -q 'device-layout.css' "$file"; then files+=("$file"); fi
 done < <(find modules -type f -name '*.php' -print0)
for file in "${files[@]}"; do
  chmod 644 "$file"
  if [[ "$file" == *.php ]]; then php -l "$file"; fi
 done
 echo 'Responsive layouts installed. Refresh pages and verify your primary workflows on phone, tablet and desktop. No migration required.'
