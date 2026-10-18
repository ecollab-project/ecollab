# Code Lab capacity and blocking audit — 2026-10-18

Scope: the authenticated Code Lab submission, durable queue, PHP worker, private
adapter and compiler path, plus session locking in chat/voice-token/upload routes. This is not a claim that every failure in eCollab,
LiveKit, Jarred, the network or the VPS has been eliminated.

## Evidence and changes

The latest measured 100-submission burst completed every program correctly but
failed its 30-second p95 target at 43.70 seconds. Worker overhead averaged about
3–5ms while Java runner waits averaged roughly 1 second. Those observations do
not justify a PHP framework replacement as the next performance change.

| Blocking/failure point | Handling |
|---|---|
| Single PHP process waits for each compilation | Supervisor runs at most two independent child workers; each opens its own PDO connection after fork. |
| Single adapter worker and sandbox slot | Two adapter workers and two Piston slots; adapter queues/results remain bounded. |
| Slow first submission blocks all subsequent work | A second slot can advance other work. Two long programs can still occupy both slots; this is bounded parallelism, not preemptive scheduling. |
| Concurrent database claim | Existing compare-and-set claim permits one winner; a collision loops without executing the same source twice. Real MariaDB process test covers 80 jobs. |
| Accidental second supervisor/manual worker | Database-scoped, connection-held slot locks permit only one worker in each of two slots. One-shot CLI work uses slot 1. |
| Inherited database sockets after fork | Parent opens no PDO/HTTP connections. Each child initializes its own connection and runner. |
| Worker crash | Supervisor detects child exit, drains its sibling and exits nonzero for systemd restart. Expired running jobs retain the existing failed-state recovery; they are not silently regraded or assigned zero. |
| Shutdown during grading | SIGTERM stops new claims and allows current work to finish. Systemd uses KillMode=mixed and a 540-second stop budget, then forcibly bounds a stuck process. |
| Recreating runner with active jobs | Installer checks an empty queue, stops claims, rechecks, then recreates services. New API submissions remain durable/queued. Previously active worker is restarted on installer failure. This is not an atomic maintenance lock. |
| Repeated SQL cleanup/preparation | Minute-based maintenance and prepared-statement reuse remain enabled. Membership checks remain fresh. |
| Session locks in chat, voice-token and uploads | Five routes now close the authenticated session immediately after CSRF verification, before database/file work. Profile-avatar updates still retain their session write. This improves same-account request overlap; it is not a compiler-speed claim. |
| PHP session locks across compilation | API closes the session before database actions. Compilers run in CLI processes, outside PHP-FPM. |
| Database locks held during compilation | Claim is a short compare-and-set; result transaction starts after execution. Grading notifications run after commit. |
| Indefinite private HTTP calls | Existing connect/request, adapter execution and result-wait deadlines remain bounded. An infrastructure failure remains an error, never a student zero. |
| Resource exhaustion | Original shared Piston CPU=1, memory=1536MiB, pids=256; adapter CPU=.25, memory=128MiB, pids=32; PHP service MemoryMax=256MiB. Two jobs share these totals. Limits are not doubled. |
| Cross-student leakage | Private membership/result checks, immutable submissions, per-execution sandbox files, no network, fixed runtimes/output limits and hidden-case privacy remain enforced. |
| Blind performance tuning | Thirty-second timing summaries now identify worker slot. Host observer measures CPU/steal/memory and queue age. |

## Deploy in a quiet period

Wait for existing tests/assessments and jobs to finish. The branch backup
`backup/before-code-lab-pool-20261018` includes tracked Centrifugo configuration.
No migration or Windows runner update is required.

```bash
cd ~/ecollab-inspect
git pull --ff-only origin ecollab-collabs
php scripts/check-code-lab-runner.php --queue-idle
sudo bash scripts/setup-code-lab-piston.sh
php scripts/check-code-lab-runner.php --workers
sudo systemctl status ecollab-code-lab --no-pager
```

The installer validates Linux PHP CLI pcntl/posix and a slot count of 1 or 2,
retains frozen compiler profiles and private credentials, preserves unrelated
application configuration, smoke-tests runtimes, installs the supervisor unit
and verifies that both database slot locks are occupied. It writes only the
nonsecret slot setting to the private `worker.env` used by systemd. It does not
restart PHP-FPM, Nginx, LiveKit, Centrifugo or Ollama.

If host contention or compiler limits worsen results, drain the queue and use
this one-slot rollback across all three layers:

```bash
sudo env CODE_LAB_EXECUTION_SLOTS=1 bash scripts/setup-code-lab-piston.sh
CODE_LAB_EXECUTION_SLOTS=1 php scripts/check-code-lab-runner.php --workers
```

Do not change Piston slots without matching the adapter/PHP settings. Restarting
only PHP does not apply the adapter/container changes. Failed installations
attempt to restart the previously active worker, but do not claim a complete
transactional rollback of Docker or the application checkout; inspect failures
before rerunning. In-memory adapter results disappear on restart.

## Measure

In a separate VPS terminal while running the Windows test:

```bash
cd ~/ecollab-inspect
php scripts/observe-feature-capacity.php 240
```

Windows, preserving the same workload, limits and direct terminal output:

```powershell
Set-Location "$HOME\ecollab-capacity-test\feature-capacity"
.\run-capacity.ps1 -Mode code-run -Users 50
```

After a pass, run 100, then repeat passing stages with independent sample IDs.
Keep the p95 target at 30 seconds. Compare full workload completion, correctness,
rejections, exit code, queue age, host CPU/steal/memory, and worker runner time.
A faster synthetic concurrency test is not measured VPS capacity.

```bash
sudo journalctl -u ecollab-code-lab --since "10 minutes ago" --no-pager | grep code_lab_worker_summary
```

Two slots do not promise twice the throughput: compiler CPU, shared memory,
Docker isolation startup, storage and competing services can dominate. Complex
programs, ten-case grading and repeated submissions differ from one small
prime-sieve execution per user. Queue admission/hourly quotas are unchanged.
The application's global queue-count check is an admission threshold, not an
atomic exact cap under simultaneous enqueue transactions. Browser polling,
PHP-FPM backlog, database outages, model inference contention and media bandwidth
remain separate measurable constraints. Jarred still has its deliberate single
local-inference gate; this pool does not establish simultaneous AI or call
capacity. Offloading execution to another host remains an option if measured
runner CPU dominates after bounded concurrency.

## Regression coverage

- SQLite: private results, immutable submissions, quotas, deadlines, grading,
  hidden checks, failed infrastructure, manual overrides and maintenance.
- MariaDB 10.11: two independent processes, unique job claims/results, duplicate
  worker-slot refusal, and infrastructure failure distinct from a grade.
- Supervisor: graceful active-job completion, unexpected child death, nonzero
  failure propagation and no orphan children.
- Adapter protocol: exactly two active requests, pending third request, unique
  outputs, bounded waits, authentication and unchanged resource payloads.
- Actual Piston in CI: all five installed runtimes, blocked network, output cap,
  timeouts, private credentials absent, overlapping Python jobs with independent
  files and concurrent Java compilation under shared container limits.
