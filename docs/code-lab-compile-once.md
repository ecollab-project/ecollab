# Compile once per automatic grading job

Automatic grading submits all case inputs in one authenticated, private adapter job.
C, C++ and the compiled Java runtime compile once. Python has no compile stage.
Each case executes in a new Piston isolate box/cgroup with a fresh stdin pipe and a
pristine copy of the compiler artifacts. Expected outputs remain in the application.
Each case releases its files and cgroup before the next case starts.
No source or artifacts are cached across jobs. A case cannot persist files or
processes into another case. The original per-case comparison, weights and public
result redaction remain unchanged.

The extension is installed only against the reviewed source hashes inside the
pinned Piston image. A source mismatch fails startup. It uses the existing Piston
slot for the whole job, including all cases. Both shared worker slots, per-server
FIFO rotation, Docker quotas, network isolation and per-case CPU/wall/memory/output
limits remain unchanged. Compiling once reduces repeated work; it does not imply
100 parallel compilers or establish a new capacity.

Artifacts are private in-memory snapshots, bounded to 8 MiB, 256 entries and eight
directory levels per active job. Symlinks, hardlinks and special files are refused.
Infrastructure failures and incomplete batch responses do not become zero grades.
The adapter result cache is capped at 16 MiB plus active jobs. Grading supports up
to ten case inputs, each at most 2048 bytes, under the existing assignment limits.
Case runs have independent time limits. The bounded full-batch HTTP wait covers
one compilation and ten case wall deadlines.

The adapter advertises `ecollab_grade_v1` only for IDs 10001, 10002, 10003 and 10006.
Standard Judge0, older adapters and the frozen legacy Java ID 10004 use the existing
per-case API. An advertised batch failure is never retried as separate executions.

## Deployment

Let the Code Lab queue drain before setup. Setup preserves the existing private
configuration backup, adds no public Piston port, and smoke-tests batch grading
before updating the application runner configuration. No migration, quota change,
cgroup change or reboot is required.

```bash
cd ~/ecollab-inspect
git pull --ff-only origin ecollab-collabs
php scripts/check-code-lab-runner.php --queue-idle
sudo bash scripts/setup-code-lab-piston.sh
php scripts/check-code-lab-runner.php --grading
php scripts/check-code-lab-runner.php --workers
sudo systemctl reload php8.3-fpm
```

Stop if queue-idle or setup fails. Review the failure before running load tests.
Setup already stops and restores the worker around container changes.

## Capacity comparison

Prepare a NEW private `code-grade` target using the same verified synthetic fixture
manifest and copy its target/accounts files to the Windows generator. Grading
attempts are immutable: prepare a new target for each independent repeat. Keep the
two-case program, four-language mix, two execution slots, quotas, 120-second ramp,
180-second deadline and 60-second grading p95 threshold unchanged.

Run 50 first, inspect the report, then 100. Require exit 0, full workload completion,
correct results and no failed thresholds. The previous 100-user two-case result
had all 100 correct but p95 74.844 seconds; it did not establish 100-user capacity.
Compilation reuse must be measured on the deployed VPS before claiming improvement.
