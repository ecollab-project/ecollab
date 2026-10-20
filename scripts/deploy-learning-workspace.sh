#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
[[ "$(git branch --show-current)" == ecollab-collabs ]] || { echo 'Run this from ecollab-collabs.' >&2; exit 1; }
[[ -z "$(git status --porcelain --untracked-files=no)" ]] || { echo 'Preserve tracked local changes before deployment.' >&2; exit 1; }
umask 0022
git pull --ff-only origin ecollab-collabs
public_files=(modules/student/dashboard.php modules/facilitator/dashboard.php modules/student/tools.php modules/facilitator/tools.php includes/layout/learning-workspace.php services/LearningWorkspaceService.php services/LearningToolsService.php API/student/workspace.php API/student/tools.php API/chat/create-server.php modules/admin/server-monitor.php assets/js/learning/workspace.js assets/css/desktop/learning-workspace.css)
for file in "${public_files[@]}"; do
  [[ -f "$file" ]] || { echo "Missing file: $file" >&2; exit 1; }
  chmod 644 "$file"
  if [[ "$file" == *.php ]]; then php -l "$file"; fi
done
# This is a newly added public asset directory, not a recursive permissions change.
chmod 755 assets/js/learning
echo 'Student and facilitator learning workspaces installed. No new migration is required.'
echo 'Open both dashboards and verify data, permissions, and mobile layout.'
