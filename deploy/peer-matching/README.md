# Collaboration / learning rollout

This branch starts at `ecollab-collabs` (`c838359`); it does not contain the separate older audit PR. Back up the database and retain the current Git SHA before deploying. No live deployment or real LiveKit provider test was performed by this change.

## Pull and migrate on the VPS

Run from `/home/ecollabadmin/ecollab-inspect` as the deployment user. Stop if tracked local changes are present; preserve them before switching branches. Never reset or clean the working tree to work around a conflict.

```bash
cd /home/ecollabadmin/ecollab-inspect
umask 0022
git status --short
git rev-parse HEAD
git fetch origin refs/heads/feat/collaboration-assistance-matching:refs/remotes/origin/feat/collaboration-assistance-matching
git switch feat/collaboration-assistance-matching
git pull --ff-only origin feat/collaboration-assistance-matching
php database/migrate.php --status
php database/migrate.php
php tests/ai-learning-policy.php
php tests/peer-behavior-regression.php
sudo systemctl restart php8.3-fpm
```

Restart the existing Ratchet/WebSocket service after migrations; otherwise its long-running worker still runs the old room cleanup. Run `bash scripts/find-websocket-service.sh` to discover matching systemd workers and print their restart commands without restarting anything. If discovery cannot match a wrapper/process manager, inspect `systemctl list-unit-files --type=service` and its `ExecStart` using `systemctl cat SERVICE`. Restart the unit whose command runs this checkout's `websocket/ChatServer.php` or `websocket/bin/server.php`. The project does not declare a universal existing unit name. Existing active voice sessions may disconnect during this restart.

Git stores executable bits, not PHP read permissions. Ensure the FPM/WS service accounts can read the checkout and traverse its parent directories. Do not use recursive `777`, make secrets public, or change `.env` ownership blindly. `sudo -u www-data test -r API/auth/login.php` checks the previously observed login permission issue.

## Weekly matching

Review the two unit files here: defaults target deployment user `ecollabadmin`, `/home/ecollabadmin/ecollab-inspect`, and `/usr/bin/php`. Adapt them if the installed checkout/user differ. Collection follows **AI Suggested Matches** in account settings. Public-channel topic participation, reviewed Code Lab results and offered academic book interactions contribute to weekly snapshots. No private message body, DM, source code, search text or arbitrary browsing URL is collected. UI clicks are categorized and limited to one signal per category/day. Historical participation/results are read only for currently eligible, opted-in users. Summaries expire after 14 days if the job stops; event window is 90 days. Sparse histories use existing matching. Behavior contributes at most 20%, and is not a validated personality or accuracy guarantee.

The existing recommended-book click source is optional. Set `BOOK_RECOMMENDATION_EVENTS_ENABLED=true` in the server environment to include future book clicks; migration `049_book_recommendation_events.sql` must be applied. This does not track arbitrary books or browsing outside eCollab. Turning matching off clears stored page/book click history and snapshots; underlying class messages and grades remain ordinary application records.

```bash
cd /home/ecollabadmin/ecollab-inspect
php scripts/refresh-peer-behavior.php
sudo install -m 644 deploy/peer-matching/ecollab-peer-refresh.service /etc/systemd/system/
sudo install -m 644 deploy/peer-matching/ecollab-peer-refresh.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ecollab-peer-refresh.timer
systemctl list-timers ecollab-peer-refresh.timer
journalctl -u ecollab-peer-refresh.service -n 30 --no-pager
```

The timer refreshes Mondays at 03:00 Asia/Manila, with up to ten minutes of jitter, and catches up after downtime. It runs under the deployment user, shares a nonblocking app-specific lock with manual invocations and replaces snapshots transactionally. Installing the timer does not create external training data or retrain an ML model.

## Acceptance before production use

- Open two authorized browsers in the same voice room: each must watch/expand the other's stream, including publication after joining and reconnection.
- Temporary room: two different users join; first leave preserves it, last leave/disconnect deletes it from sidebars. Multiple tabs in the same room preserve presence. Moving the same account to a different room explicitly disconnects its old media tab (one room per account).
- Facilitator who joined a foreign server cannot publish there by UI or forged API request. Owner server publishing still works; platform admins retain existing authority.
- Start a student task: Code Lab, Jarred DM, AI sessions, chat assist and document suggestions return workflow guidance. Submit/expire: normal help resumes. Clipboard restrictions remain separate; this is not external cheating detection.
- Disable matching: no new clicks recorded, snapshots/history cleared, no behavioral scoring. Run refresh twice: stable evidence, no accumulation from job reruns.
- For rollback, stop/disable the new timer and restore the saved source revision. Keep additive tables intact until a separate reviewed data migration; do not roll back user data to undo source code.

## Verification evidence

Focused PHP/DOM suites and PHPUnit unit suite pass locally; SQLite covers task state, ownership, opt-out, dedup, retention and lifecycle. MariaDB 10.11 runs the additive migrations twice and exercises real transactional voice cleanup and weekly snapshots. The new CI workflow repeats the SQL fixture on MySQL 8 and MariaDB 10.11. Live provider media, true concurrent leave/join load and VPS integration remain deployment acceptance checks.

Follow-up: full PHPStan now passes. The legacy OTP mail branch rejects a missing/invalid sender with JSON/503; grading persists the score from its completed result; PHPStan discovers the isolated Web Push package. CI installs that package before static analysis. No real mail was sent by the regression test.
