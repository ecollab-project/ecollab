#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
[[ "$(git branch --show-current)" == "ecollab-collabs" ]] || { echo 'Use the ecollab-collabs deployment checkout.' >&2; exit 1; }
[[ -z "$(git status --porcelain --untracked-files=no)" ]] || { echo 'Preserve tracked local changes before deploying.' >&2; exit 1; }
umask 0022
git pull --ff-only origin ecollab-collabs
# Only public code is made readable; runtime secrets and writable storage are untouched.
files=(modules/chat/chat.php includes/calls/bootstrap.php assets/css/desktop/chat-taskbar.css assets/css/calls/calls.css assets/js/calls/call-dock.js assets/js/chat/workspace-taskbar.js assets/js/chat/dm-call.js assets/js/chat/voice.js)
for file in "${files[@]}"; do
  [[ -f "$file" ]] || { echo "Missing file: $file" >&2; exit 1; }
  chmod 644 "$file"
  if [[ "$file" == *.php ]]; then php -l "$file"; fi
done
echo 'Chat taskbar installed. Refresh the page, then verify minimize, close video, restore and explicit leave in voice, direct and group calls.'
