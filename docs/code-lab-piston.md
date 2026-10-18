# Code Lab on Ubuntu 24.04 / cgroup v2

The original Judge0 CE installer requires a cgroup v1 host. Use this Piston-based adapter on cgroup v2 instead; no GRUB change or reboot is required. Code Lab's existing private attempts, deadlines, grading queue and weighted checks remain unchanged. This is an intentionally small Judge0-compatible API surface, not a full Judge0 implementation.

## VPS deployment

From the existing deployment checkout:

```bash
cd ~/ecollab-inspect
umask 0022
git pull --ff-only origin ecollab-collabs
bash scripts/deploy-code-lab.sh
sudo bash scripts/setup-code-lab-piston.sh
```

No new migration is needed beyond migration 055, which you already applied. The setup requires Docker Compose, Python 3 and PHP cURL. It downloads Piston and approved language packages, which may take several minutes. It does not restart Nginx, PHP-FPM, LiveKit or Centrifugo.

The setup:

1. Starts a digest-pinned Piston image on a private Docker network, with a persistent packages volume. Its package-management API is not published to the host/internet.
2. Installs the newest stable versions *available in Piston's package index* for GCC, Python, Java and Node. This does not imply the newest upstream compiler or every third-party library is available.
3. Freezes exact runtime versions into `/var/lib/ecollab-code-lab/profiles.json`, with a generated private API token. Repeat deployments preserve these profiles.
4. Starts a bounded adapter at **127.0.0.1:2358**. It does not expose Piston’s package-management API.
5. Runs the five-language smoke checker with temporary child-process settings. Only after every program succeeds does it privately back up `.env`, update its three Code Lab settings, and install/restart `ecollab-code-lab.service`. Database, LiveKit, Centrifugo and other settings are preserved, including `.env` ownership and mode.

If anything fails, paste the error output, not `.env` or the private profile/backup contents. A failed smoke check does not enable the classroom worker. If an older worker was already enabled, its existing state should be inspected before retrying. No secret values are printed by the setup/checker.

After setup:

```bash
php scripts/check-code-lab-runner.php --languages
php scripts/check-code-lab-runner.php
sudo systemctl status ecollab-code-lab --no-pager
sudo journalctl -u ecollab-code-lab -n 30 --no-pager
```

Hard-refresh Code Lab, open Playground, choose Python, enter `print(42)` and Run. Then publish a small automatically graded task with a sample check and submit as a student. Existing manual tasks stay manual; newly published automatic tasks use the frozen runtime IDs.

## Isolation and resource limits

Piston uses isolated namespaces, unprivileged users and cgroup v2 for submitted programs. It requires a privileged service container; that is not the same boundary as a separate VM. A dedicated execution host remains the stronger deployment for hostile or public workloads. This local configuration caps Piston at 1 CPU, 1.5 GiB memory and 256 processes, and the adapter at 0.25 CPU / 128 MiB. These limits reduce contention on the existing 2-vCPU / 8-GB VPS but do not establish a student capacity figure.

Each program has no outgoing network access, 2s CPU / 8s wall execution time and 256 MiB memory; compilation gets 10s CPU / 30s wall and 512 MiB. One execution runs at a time. Output is capped at 16 KiB per stream and results are base64-encoded to the existing app. Piston appends a final newline to stdin if absent; author test inputs accordingly. Java uses `public class Main`.

No application directory, `.env`, Docker socket or adapter credentials are mounted into Piston. The adapter holds a separate private token, owns the runtime mapping, and rejects callback URLs, arbitrary file bundles and legacy runtime IDs. It never executes source locally. Piston provides language execution; eCollab continues to enforce membership, private results, immutable submission snapshots, deadlines, quotas and hidden-check privacy.

Adapter results are bounded in memory and disappear on restart. eCollab's durable queue retains submitted source and can retry failed grading. Runtime IDs 10001–10005 are reserved for these frozen profiles. Older automatically graded Judge0 tasks keep their original IDs and are **not silently reinterpreted** with another compiler; recreate such a task for Piston or keep its original Judge0 backend. Web checks/previews are unchanged.

Language packages live in the Docker volume. Do not delete it or change profile versions while tasks depend on them. An image/runtime update needs deliberate validation and a new runtime mapping for existing assessment consistency. HTML/CSS visual design and browser JavaScript behavior still use previews/manual review; automatic web checks assess HTML structure and text.

## Validation

`tests/code-lab-piston-test.py` verifies private authentication, async API compatibility, runtime pinning, fixed limits, schema rejection and failure translation. The `Code Lab cgroup v2 sandbox` workflow starts the actual Piston container on Ubuntu 24.04, installs all five language runtimes, runs the PHP compiler checker, and verifies stdin, timeout, blocked network, output cap and that adapter credentials are absent inside the sandbox. Actual VPS smoke checks still need to pass before activation.

Upstream reference: https://github.com/engineer-man/piston


## Faster result delivery (2026-10-18)

The adapter now advertises a bounded result-wait capability. PHP can wait up to
one second for a result; a completion notification wakes it immediately. This
removes the fixed 250ms polling interval that could delay queue advancement after
every fast program. Pending jobs still return their pending state at timeout.
Old adapters and ordinary Judge0 retain the previous compatible polling behavior.
The capability does not change program time/memory limits, output caps, network
isolation, CPU quotas, one-at-a-time sandbox execution or grading privacy.

Deploy after the current load test ends and the execution queue drains:

```bash
cd ~/ecollab-inspect
git pull --ff-only origin ecollab-collabs
php scripts/check-code-lab-runner.php --queue-idle
sudo bash scripts/setup-code-lab-piston.sh
```

The installer reuses frozen runtimes and the private token, checks queue state
before restarting the adapter, smoke-tests the compilers and restarts the PHP
worker so it loads the new client. Run this during a quiet period: the idle check
is a preflight check, not a maintenance lock preventing new submissions.
No migration or Windows runner changes are required. Repeat the same 50-user test,
then the 100-user test with the 30-second p95 threshold unchanged. Actual VPS
improvement must come from those results; loopback timing tests are not capacity
claims. The scripts do not add more worker processes or sandbox CPU capacity.


## Worker overhead and measurement (2026-10-18)

The PHP worker now reuses prepared statements (not authorization results), and
performs expired-run cleanup and abandoned-claim recovery at startup and once
per minute between jobs instead of before every job. Idle polling remains one
second. Recovery still uses the existing ten-minute stale-claim threshold;
completed grading records are never removed by run retention. A long grading
job can defer maintenance until the next work cycle.

Every 30 seconds, after the current job returns, the worker emits a bounded
`code_lab_worker_summary` to its journal. Groups contain language, run/grade,
job/failure counts, average/maximum runner and total work durations, and average
non-runner overhead. No account/job IDs, source, test inputs, outputs, hidden
cases or credentials are emitted. Empty windows are silent. `runner_ms` includes
private HTTP, adapter and actual compiler/execution waits; it is not CPU time.
`work_ms` includes membership checks, SQL, maintenance when due and notification
attempts. Neither metric includes time waiting in the application queue before
claiming. These summaries are not substitutes for end-to-end test p95.

Apply during a quiet period after jobs have drained; no migration, adapter
restart, runtime update or Windows script download is needed:

```bash
cd ~/ecollab-inspect
git pull --ff-only origin ecollab-collabs
php scripts/check-code-lab-runner.php --queue-idle && sudo systemctl restart ecollab-code-lab
sudo systemctl is-active ecollab-code-lab
```

In one VPS terminal, collect host observations while testing from Windows:

```bash
cd ~/ecollab-inspect
php scripts/observe-feature-capacity.php 240
```

Then inspect worker summaries after the test:

```bash
sudo journalctl -u ecollab-code-lab --since "10 minutes ago" --no-pager | grep code_lab_worker_summary
```

Use the same 50-user test first and 100-user test next, with the 30-second p95
threshold unchanged. A serial execution queue remains: this change does not
establish a new capacity or add CPU, sandbox concurrency or PHP processes. If
runner time dominates, investigate the sandbox/CPU measurements before changing
backend frameworks or worker limits. The idle check remains a preflight, not a
lock against new submissions; do not restart during an active assessment.
