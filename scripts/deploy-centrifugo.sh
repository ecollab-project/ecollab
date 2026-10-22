#!/usr/bin/env bash
# The old JWT installer cannot perform the coordinated proxy-auth migration safely.
set -euo pipefail
printf '%s\n' 'Legacy JWT installer retired. No changes made. Review docs/450-phase3c-centrifugo-reauthorization.md for the coordinated staging/rollout gates.' >&2
exit 1
