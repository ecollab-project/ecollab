# Server Code Lab

**Ubuntu 24.04 / cgroup v2:** use [the Piston setup](code-lab-piston.md). The Judge0 cgroup v1 deployment below remains an alternative for compatible dedicated hosts.

Chat sidebar → Code Lab uses the selected server. Student/facilitator dashboards link to a server picker. Membership in an active server is required on the page and every API request. Facilitators/admins who belong to that server can publish assignments and practice tasks, view submitted work and manually record scores/feedback. Students, including student server owners, cannot publish or grade tasks.

## Classroom foundation (migration 054)

- Tasks for all members of the selected server, not a selected-student roster.
- C, C++, Python, Java, JavaScript, and a combined HTML/CSS/JavaScript web document editor.
- Optional UTC opening/deadline values entered/displayed in the user's local timezone; optional 1–1440 minute personal timer.
- One private attempt per student per task, resumed on repeated starts. Optional task restrictions are fixed at publishing; no task editing or multiple attempts in this release.
- Explicit Save draft, download, immutable submission, manual score and feedback.
- Private playground draft per server/student/language, independent of assignment deadlines.
- HTML/CSS/JS inline preview in an opaque-origin sandbox iframe with a restrictive CSP. External resources are blocked.

Migration 054 alone does not implement compiler execution or automatic grading. Migration 055 below adds execution and checks. Runtime/package installation, real-time collaborative editing, selected-student assignment and plagiarism detection remain outside this release. Run stays disabled until a runner and language are configured. Do not execute submitted code inside PHP or the production application container. Add a separate authenticated execution service with CPU/memory/time/output/network limits in a later integration.

## Isolation and timing

The unique key `(task_id,user_id)` separates student attempts. APIs derive user_id from the authenticated session; request user_id is ignored. Server membership and task/server scope are rechecked for each action. Facilitator review selects only submitted work. Private drafts and playgrounds are not broadcast over the collaboration transport.

The API enforces the earlier of `due_at` and `started_at + duration_minutes`; the browser countdown is only a display. Expired drafts remain readable but cannot be saved/submitted. Expiry does not silently submit a draft. Once submitted, code becomes read-only and available to facilitators. Optimistic versions reject overlapping saves from multiple tabs. All mutations require CSRF.

A future execution worker must create an ephemeral isolated job for each run using only that attempt's files. Private database rows alone are not an execution sandbox.

## Deploy

Use the normal database backup process before migration. On the deployment checkout:

```bash
cd ~/ecollab-inspect
umask 0022
git pull --ff-only origin ecollab-collabs
php database/migrate.php --dry-run
php database/migrate.php
bash scripts/deploy-code-lab.sh
```

No compiler service is required for the classroom foundation. Execution requires the configuration below. The deployment helper only normalizes public Code Lab and changed public page assets. Runtime configuration and secrets are not changed.

## Validation

`tests/code-lab-regression.php` uses SQLite fixtures to verify role restrictions, cross-server access, separate private attempts and playgrounds, stale-save rejection, submission locking, review visibility, deadline/timer boundaries and removal of membership. DOM tests verify the form, task flow, code preservation and unavailable runner labeling. The responsive browser sweep includes Code Lab student and facilitator views at 11 widths (240–3840 CSS pixels), with an open creation dialog and private editor. These use isolated fixtures, not production data. Verify actual signed-in workflows after deployment.

## Compiler execution and automatic grading (migration 055)

The execution release adds an authenticated Judge0 adapter, private database job queue, CLI worker, task test-case authoring and automatic weighted scores. The browser never receives Judge0 credentials or submission tokens. Source is never executed by PHP or a local shell. Each Judge0 submission runs separately with network disabled, 2s CPU / 8s wall limits, 256 MiB memory, process/file limits and bounded output. The prepared runner gives compilation up to 10s CPU / 30s wall, 512 MiB memory and 10 MiB artifacts; execution remains under the tighter per-run limits. Worker failures or Judge0 internal errors record a retryable job error, not a student zero.

Console tasks accept 1–10 input/output checks with integer weights 1–100. Newline conventions and final newlines are normalized; other whitespace remains meaningful. Sample checks are visible to students. Hidden inputs, expected outputs, compiler output and program output never appear in grading results—even malicious code that prints its hidden stdin only produces a pass/fail check summary. Interactive Run output uses only student-provided input. Java source must use `public class Main`.

Web tasks use static HTML checks: a tag, `#id`, or `.class`, with optional expected text. Empty expected text checks element presence. Parsing does not execute scripts or load network resources. These checks do not assess CSS appearance or interactive JavaScript behavior; use manual review for those. Browser preview remains available.

Submission and grading enqueue are one transaction: a full queue or quota failure cannot leave a student accidentally submitted without its grading job. There is one active interactive run per account; submissions can queue grading while a run is pending so it cannot block a deadline. There are 60 requests/hour and a 1000-job queue cap. Retries also count against the quota. Automatic grades store `auto_score` separately and only set the final score when a facilitator has not manually reviewed it. Manual overrides are preserved. One grading job per immutable attempt prevents duplicate grading; failed jobs can be retried. Running jobs abandoned for ten minutes become retryable failures. Completed interactive run source/results are retained for 24 hours, while grade records remain with submissions.

### Configure the execution host

Use a separate compatible execution host for Judge0; do not install its privileged containers alongside eCollab secrets, MariaDB or uploaded documents. `deploy/code-lab/bootstrap-judge0.sh` prepares upstream Judge0 CE 1.13.1, generates private credentials, disables program networking and binds its API to loopback. That release requires a cgroup v1 host; the bootstrap deliberately stops on a cgroup v2 host instead of changing GRUB or rebooting. The production Ubuntu 24.04 VPS normally uses cgroup v2. Provision a compatible dedicated runner or use an existing authenticated Judge0 service.

On that host, with Docker Compose, unzip, curl and Python available:

```bash
sudo bash deploy/code-lab/bootstrap-judge0.sh
```

Connect the application to that private API through an authenticated HTTPS proxy or a private tunnel. Keep port 2358 off the public internet. Credentials live in `/opt/ecollab-judge0/judge0-v1.13.1/judge0.conf`; transfer them privately to application configuration. The bootstrap does not configure your DNS, tunnel or TLS proxy.

### Configure eCollab and start its queue worker

1. Apply migration 055 using the usual migration runner and existing database-backup process.
2. Append the settings from `deploy/code-lab/application.env.example` to the **existing** application `.env`; preserve all existing database, LiveKit and Centrifugo settings. Set the actual private URL and token. The example language IDs are syntax examples, not a promise of installed versions.
3. List your runner's installed versions, then set `CODE_LAB_LANGUAGE_IDS` to the selected C/C++/Python/Java/JavaScript IDs. Automatic tasks pin the chosen ID at publishing. Keep those runtime versions available for existing tasks; updated approved libraries must be provisioned into the runner environment, not downloaded by student programs.
4. Ensure PHP has cURL and DOM extensions and run smoke checks:

```bash
cd ~/ecollab-inspect
php scripts/check-code-lab-runner.php --languages
# Set the selected IDs in the existing .env, then:
php scripts/check-code-lab-runner.php
```

5. Install and start the queue worker after the smoke check passes:

```bash
sudo cp deploy/code-lab/ecollab-code-lab.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ecollab-code-lab
sudo systemctl status ecollab-code-lab --no-pager
```

The service template uses the existing VPS account/path. Adjust those if deploying elsewhere. For one queued job without installing systemd, use `php scripts/code-lab-worker.php --once`. Keep execution disabled until the configured runner passes the smoke test. New automatically graded console tasks require configured language IDs; older manual tasks remain manual and may use Run once a runtime is configured.

### Execution validation

`tests/code-lab-execution-regression.php` exercises private results, immutable job snapshots, queue/submission transactions, limits, weighted scores, hidden-output suppression, retries, quotas, compilation failure and manual overrides through a deterministic Judge0 transport fixture. CI also verifies DOM grading and the updated creation form/editor at 11 viewport widths. These tests do not certify a deployed sandbox: `scripts/check-code-lab-runner.php` compiles and executes known programs in all five languages against your actual runner before activation.

## Advanced verification programs
New task and playground language choices are C, C++, Python and Java.
JavaScript and HTML/CSS/JS are no longer offered; historical data is preserved.
In Playground, **Load test program** loads a graph algorithm and sample stdin
without saving or executing it. See [advanced test programs](../examples/code-lab/advanced/README.md)
for all nine fixtures and the VPS command that verifies all four runtimes.
