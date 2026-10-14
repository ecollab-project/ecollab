# Server Code Lab

Chat sidebar → Code Lab uses the selected server. Student/facilitator dashboards link to a server picker. Membership in an active server is required on the page and every API request. Facilitators/admins who belong to that server can publish assignments and practice tasks, view submitted work and manually record scores/feedback. Students, including student server owners, cannot publish or grade tasks.

## First release scope

- Tasks for all members of the selected server, not a selected-student roster.
- C, C++, Python, Java, JavaScript, and a combined HTML/CSS/JavaScript web document editor.
- Optional UTC opening/deadline values entered/displayed in the user's local timezone; optional 1–1440 minute personal timer.
- One private attempt per student per task, resumed on repeated starts. Optional task restrictions are fixed at publishing; no task editing or multiple attempts in this release.
- Explicit Save draft, download, immutable submission, manual score and feedback.
- Private playground draft per server/student/language, independent of assignment deadlines.
- HTML/CSS/JS inline preview in an opaque-origin sandbox iframe with a restrictive CSP. External resources are blocked.

Compiler execution, automatic grading, test-case authoring, runtime/package installation, real-time collaborative editing, selected-student assignment and plagiarism detection are not implemented. Run is visibly disabled. Do not execute submitted code inside PHP or the production application container. Add a separate authenticated execution service with CPU/memory/time/output/network limits in a later integration.

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

No compiler service or new credentials are required for this UI/storage release. The deployment helper only normalizes public Code Lab and changed public page assets. Runtime configuration and secrets are not changed.

## Validation

`tests/code-lab-regression.php` uses SQLite fixtures to verify role restrictions, cross-server access, separate private attempts and playgrounds, stale-save rejection, submission locking, review visibility, deadline/timer boundaries and removal of membership. DOM tests verify the form, task flow, code preservation and unavailable runner labeling. The responsive browser sweep includes Code Lab student and facilitator views at 11 widths (240–3840 CSS pixels), with an open creation dialog and private editor. These use isolated fixtures, not production data. Verify actual signed-in workflows after deployment.
