#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ "$(git branch --show-current)" != "ecollab-collabs" ]]; then
  echo 'Run this from the ecollab-collabs deployment checkout.' >&2
  exit 1
fi
if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
  echo 'Tracked local changes detected. Preserve and review them before deployment.' >&2
  exit 1
fi
# New public code files must be readable by Nginx/PHP-FPM; secrets are untouched.
umask 0022
git pull --ff-only origin ecollab-collabs
public_files=(
  modules/admin/dashboard.php
  modules/admin/server-monitor.php
  modules/admin/reports.php
  modules/admin/tools.php
  includes/layout/admin-workspace-sidebar.php
  includes/layout/admin-system-capacity.php
  API/admin/workspace.php
  API/admin/reports.php
  API/admin/server-monitor.php
  API/admin/service-status.php
  API/admin/system-health.php
  API/admin/group-recommendations.php
  API/admin/system-capacity.php
  services/AdminWorkspaceService.php
  services/AdminReportService.php
  services/AdminSpaceMonitorService.php
  services/SystemCapacityService.php
  assets/js/admin/workspace.js
  assets/js/admin/system-capacity.js
  assets/css/desktop/admin-workspace.css
  assets/css/desktop/system-capacity.css
)
for file in "${public_files[@]}"; do
  [[ -f "$file" ]] || { echo "Missing expected file: $file" >&2; exit 1; }
  chmod 644 "$file"
  if [[ "$file" == *.php ]]; then php -l "$file"; fi
done
echo 'Admin workspace code installed. Open the dashboard and verify its data sources.'
echo 'Keep the existing system-capacity cron job; no new migration is required.'
