#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
[[ "$(git branch --show-current)" == "ecollab-collabs" ]] || { echo 'Use the ecollab-collabs deployment checkout.' >&2; exit 1; }
umask 0022
for file in modules/code-lab/index.php API/code-lab/index.php services/CodeLabService.php services/CodeLabRunner.php services/CodeLabExecutionService.php scripts/code-lab-worker.php scripts/check-code-lab-runner.php modules/chat/chat.php includes/layout/learning-workspace.php assets/css/code-lab.css assets/js/code-lab.js assets/js/chat/chat.js; do
  chmod 644 "$file"
  if [[ "$file" == *.php ]]; then php -l "$file"; fi
 done
 echo 'Code Lab public files checked. Apply migrations 054 and 055 with the migration runner and configure/start the execution worker before running code.'
