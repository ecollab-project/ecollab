"""Record actual command exits; absent/skipped/failed required steps block readiness."""
import json
import os
import pathlib
import subprocess
import sys
import time

label, *command = sys.argv[1:]
target = pathlib.Path(os.environ.get('PHASE3AD_STEP_LEDGER', 'phase3ad-raw/required-commands.jsonl'))
target.parent.mkdir(parents=True, exist_ok=True)
started = time.time()
result = subprocess.run(command)
with target.open('a') as handle:
    handle.write(json.dumps({'step': label, 'command': command, 'exit_code': result.returncode,
                             'started_epoch': started, 'finished_epoch': time.time()}) + '\n')
sys.exit(result.returncode)
