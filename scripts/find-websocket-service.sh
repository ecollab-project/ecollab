#!/usr/bin/env bash
# Read-only discovery. Prints restart commands; never restarts a running service.
set -euo pipefail
app_root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
if ! command -v systemctl >/dev/null; then
  echo 'systemctl unavailable. Check the existing Supervisor/PM2/process manager configuration.' >&2
  exit 1
fi
units=$(systemctl list-unit-files --type=service --no-legend --no-pager)
found=0
while read -r unit _; do
  [[ -n "$unit" ]] || continue
  command_line=$(systemctl show "$unit" --property=ExecStart --value 2>/dev/null || true)
  [[ "$command_line" =~ websocket/(ChatServer\.php|bin/server\.php) ]] || continue
  directory=$(systemctl show "$unit" --property=WorkingDirectory --value 2>/dev/null || true)
  if [[ "$command_line" == *"$app_root/"* || "$directory" == "$app_root" ]]; then
    printf 'Detected worker: %s\nRestart after migrations: sudo systemctl restart %q\n' "$unit" "$unit"
    found=1
  fi
done <<< "$units"
if [[ "$found" == 0 ]]; then
  echo 'No matching systemd worker found for this checkout. Check its existing process manager before restarting; do not guess a unit name.' >&2
  exit 1
fi
