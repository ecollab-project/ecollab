# Server queues and feature capacity tests

Code Lab now uses a durable round-robin dispatcher across nonempty server queues.
Within each server it claims the oldest arrival (`created_at`, then ID). Retried
grades rejoin by their new arrival time. Two shared slots remain the maximum;
no process/container is created per server. A single busy server can use both
slots. The dispatch transaction commits before any compiler or grading work.

Fairness is by job, not CPU seconds. Jobs are not preempted: a grade with ten
checks occupies one slot until it finishes. Different servers still share CPU,
memory and the existing global admission/quota policies. A server queue cannot
make 300 programs execute at once or guarantee a deadline during overload.
Queue position is advisory, within the student's server, and returned only for
their own job after membership checks. It excludes currently running jobs and is
not an ETA. Two slots may complete jobs in a different order from claim order.

## Deploy on the VPS

The Git backup branch `backup/before-server-code-queues-20261019` preserves the
prior code including tracked `deploy/centrifugo` configuration. It is not a backup
of private environment files or production data.

```bash
cd ~/ecollab-inspect
git pull --ff-only origin ecollab-collabs
```

Stop the execution supervisor gracefully, apply migration 062, then restart it.
Wait for the stop command to finish; active jobs are allowed to finish first.
If migration fails, resolve it before restarting the worker. Do not reinstall
Piston or increase Docker CPU/worker limits for this change.

```bash
sudo systemctl stop ecollab-code-lab
php database/migrate.php --dry-run
php database/migrate.php
sudo systemctl start ecollab-code-lab
php scripts/check-code-lab-runner.php --workers
sudo systemctl reload php8.3-fpm
```

## Refresh the PC runner

```powershell
Set-Location "$HOME\ecollab-capacity-test\feature-capacity"
scp -r ecollabadmin@187.53.142.72:/home/ecollabadmin/ecollab-inspect/tools/feature-capacity/* .
npm ci
npx playwright install chromium
```

Install browsers before preparing media tokens. `Users` now accepts any integer
2–300 for compiler/media/AI testing, including 75. Grading requires its prepared
task levels: 2,4,50,100,150,200,250,300.

## Prepare the matching private target

For EACH mode, prepare on the VPS, then copy on Windows before running it.
Keep the printed per-run target directory for later cleanup. These examples use
the existing synthetic fixture manifest referenced by `LATEST`; validation
refuses foreign/changed membership or real accounts. It must contain enough
accounts for the requested stage.

```bash
cd ~/ecollab-inspect
fixture_path="$(cat /home/ecollabadmin/ecollab-load-tests/LATEST)"
test -f "$fixture_path" && php scripts/prepare-feature-capacity.php "$fixture_path" code-run
```

On Windows, after each preparation:

```powershell
Set-Location "$HOME\ecollab-capacity-test\feature-capacity"
scp ecollabadmin@187.53.142.72:/home/ecollabadmin/ecollab-load-tests/feature-target.json .\feature-target.json
scp ecollabadmin@187.53.142.72:/home/ecollabadmin/ecollab-load-tests/feature-accounts.json .\accounts.json
```

If the manifest lives outside `/home/ecollabadmin/ecollab-load-tests`, copy the
stable files from its actual parent directory. Never publish accounts or tokens.

## Sustained compiler submissions

Use a `code-run` target. Smoke first, then test one load at a time:

```powershell
Set-Location "$HOME\ecollab-capacity-test\feature-capacity"
.\run-capacity.ps1 -Mode code-sustained -Users 4 -SubmissionsPerUser 2 -ThinkSeconds 5
.\run-capacity.ps1 -Mode code-sustained -Users 50 -SubmissionsPerUser 10 -ThinkSeconds 5
```

This is a closed-loop workload: each student submits, polls until actual compiler
completion, waits five seconds, then submits again. Starts are spread over five
seconds. Fifty users perform 500 real compiler jobs across C/C++/Python/Java.
No user has more than one outstanding job. A slow service lowers arrival rate;
this is not a fixed requests/second capacity claim or a fixed-duration soak.
The run must finish all configured iterations; per-job completion/correctness
must reach 99%, with p95 completion below 30s. Each job's 180s deadline includes
queue wait. Queue timing is polling-observed, not exact server enqueue/start time.

Ten jobs per account consume ten of the existing 60/hour compiler/grading quota.
Count earlier runs and grading submissions before repeating. Do not bypass quotas;
use fresh synthetic accounts or wait for the window. Maximum submissions per user
is 20 per invocation. Inspect completed/attempted counts and elapsed duration.

For comparison the original synchronized burst remains:

```powershell
.\run-capacity.ps1 -Mode code-run -Users 75
```

The existing two 75-user burst passes are not sustained-load results. Start
sustained testing at 50; only advance to 75/100/150/200/250/300 after passing,
with two independent runs at each claimed level. Stop escalating at a failure.

## Automatic grading

Prepare on the VPS using the same verified manifest variable:

```bash
test -f "$fixture_path" && php scripts/prepare-feature-capacity.php "$fixture_path" code-grade
```

Copy both private files with the Windows SCP commands above, then:

```powershell
Set-Location "$HOME\ecollab-capacity-test\feature-capacity"
.\run-capacity.ps1 -Mode code-grade -Users 4
.\run-capacity.ps1 -Mode code-grade -Users 50
```

Each student has an isolated immutable assignment, two real checks (one hidden),
and must receive score 100 with both checks passing. p95 includes queuing and
both checks and must stay below 60s. A finished compiler process alone is not a
grading pass. Each prepared task level runs once; prepare/copy a NEW target for
every repeat. Testing ten-case assignments needs a separate labeled workload;
these two-case results cannot establish that capacity.

## Calls and screen sharing

For paired voice/video media prepare room size 2; for group calls use 10:

```bash
test -f "$fixture_path" && php scripts/prepare-feature-capacity.php "$fixture_path" media 2
```

Copy both files, then smoke:

```powershell
Set-Location "$HOME\ecollab-capacity-test\feature-capacity"
.\run-capacity.ps1 -Mode voice -Users 2 -RampSeconds 5 -HoldSeconds 20
```

Prepare/copy fresh media tokens before EACH of these stages; do not paste/run
all three in a loop with one token set:

```powershell
.\run-capacity.ps1 -Mode voice -Users 50
```

```powershell
.\run-capacity.ps1 -Mode video -Users 50
```

For screen sharing prepare a fresh `media 10` target, copy it, then:

```powershell
.\run-capacity.ps1 -Mode screen -Users 50
```

Fifty paired users means 25 rooms. Fifty users in groups of ten means five rooms.
Screen mode has one animated 1080p/30fps share per room, audio from everyone,
and real remote video decoding. It tests LiveKit media transport, not the app's
invitation/ringing/answer flow. Camera mode uses 720p/30fps. Full peak hold is
300s, with media continuity, loss/jitter/RTT/FPS and generator health checks.
Tokens last ten minutes. Larger loads may need multiple synchronized PCs; use
the sharding/aggregation procedure in the [runner README](../tools/feature-capacity/README.md).
An overloaded laptop is not proof of VPS media capacity.

## Simultaneous AI sessions

```bash
test -f "$fixture_path" && php scripts/prepare-feature-capacity.php "$fixture_path" ai
```

Copy both private files, then:

```powershell
Set-Location "$HOME\ecollab-capacity-test\feature-capacity"
.\run-capacity.ps1 -Mode ai -Users 2
```

Only proceed to 50/100/150/200/250/300 if the smaller stage passes. Each student
makes two turns in a private session, including recalling their synthetic label;
p95 for the two turns must be under 30s. This is not a general accuracy benchmark.
Current local Ollama admission allows ONE inference at a time and rejects others
as busy. This queue change applies to Code Lab, not AI: concurrent local AI may
fail even at two users. Busy, 429 and 503 responses count as failures. The test
does not silently serialize inference or bypass the lock. The report's provider
is the configured route; it does not prove which provider served every turn.
AI's existing 20 messages/hour/account quota still applies.

## Server fairness, observation and acceptance

SQLite regression covers restart/wrap/arrival/retry ordering and privacy. The
MariaDB CI test dispatches 80 jobs across three servers using TWO PHP processes;
a transactional audit verifies round-robin and FIFO claims exactly once. The
compiler callback asserts there is no open transaction during execution.

A load test against one synthetic server cannot demonstrate cross-server
fairness. For live interference testing, create two separate base fixtures and
prepare a target from each. Keep their private kits/targets in separate PC
folders, use disjoint accounts, and start sustained tests in two terminals.
The stable export paths are overwritten at each preparation, so copy/save each
kit before preparing the next. Correlate both reports with worker logs; assess
per-server waiting times and shared total throughput. Never reuse real classes.

In another VPS window during each test:

```bash
cd ~/ecollab-inspect
php scripts/observe-feature-capacity.php 900
sudo journalctl -u ecollab-code-lab --since '20 minutes ago' --no-pager -n 100
```

Require actual process exit 0, full workload/hold, `capacity_pass: true`, no failed
thresholds, and two independent passes of the same profile. The wrapper saves
timestamped JSON automatically and keeps live terminal output without Tee.
Isolated compiler/media/AI passes do not establish their combined mixed capacity.
Cleanup instructions and exact media metrics are in the runner README.
