#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
[[ "$(git branch --show-current)" == "ecollab-collabs" ]] || { echo 'Use the ecollab-collabs deployment checkout.' >&2; exit 1; }
umask 0022
for file in modules/code-lab/index.php API/code-lab/index.php services/CodeLabService.php modules/chat/chat.php includes/layout/learning-workspace.php assets/css/code-lab.css assets/js/code-lab.js assets/js/chat/chat.js; do
  chmod 644 "$file"
  if [[ "$file" == *.php ]]; then php -l "$file"; fi
 done
 echo 'Code Lab public files checked. Apply migration 054_code_lab.sql with the migration runner before opening Code Lab.'
