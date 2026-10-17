# Learning upgrades

## Included features

- **CodeMirror 6** replaces the Code Lab source textarea with syntax highlighting, line numbers, bracket matching, indentation and undo/redo for C, C++, Python and Java. Assets and language parsers are self-hosted. Existing private saves, compiler runs, timers and read-only submissions use the same backing field. Workspace changes clear editor undo history. The internal-only clipboard guard also covers CodeMirror; authoring forms remain unrestricted.
- **JPlag 6.3.0** adds a facilitator-only **Similarity report** action on each Code Lab task. It queues a snapshot of submitted attempts, excludes the supplied starter code, compares locally and returns pairs, percentages and matching line ranges. Private drafts and playgrounds are excluded. Students cannot request/read reports. Grades remain unchanged. Similarity is a review signal, never proof of cheating. Parser errors, short programs and common required solutions need human interpretation.
- **Web Push** is an optional device setting under **Settings → Notifications**. Enabling requires a deliberate click and browser permission. Unread notifications generate generic lock-screen alerts, respecting account/category preferences. Code Lab adds publication, grading/review and one-hour deadline notices. Browser endpoints belong to one account; switching ownership clears old deliveries and logout removes that browser's enrollment. Alerts contain no message text, names, code or assignment details. Clicking opens chat notifications without navigating away from a Code Lab window.

Clipboard restrictions are browser-level deterrents, not VM isolation. They cannot prevent screenshots, retyping, extensions, developer tools or another device. Push requires HTTPS and browser/OS support; on supported iOS/iPadOS devices it requires a Home Screen installation. Delivery is best-effort, not a deadline guarantee. Existing in-app notifications remain available.

## VPS deployment

This supplements the existing Piston worker. It needs PHP 8.3 with curl, mbstring and openssl, Docker Compose, curl and Python 3. Apply migrations before installing the workers:

```bash
cd /home/ecollabadmin/ecollab-inspect
umask 0022
git pull --ff-only origin ecollab-collabs
php database/migrate.php --dry-run
php database/migrate.php
sudo bash scripts/setup-learning-upgrades.sh
sudo systemctl status ecollab-similarity ecollab-push --no-pager
```

The setup script verifies/downloads the pinned JPlag JAR, builds a Java 25 JDK parser container, tests actual C/C++/Python/Java comparisons, installs locked PHP push dependencies, generates VAPID keys and a bridge token, backs up `.env` privately and adds missing configuration keys. It preserves existing VAPID keys. An existing similarity token is reused by the bridge. Secrets are not printed. Private setup state is in `/var/lib/ecollab-learning-upgrades`; the bridge token is in `/var/lib/ecollab-similarity`. Back up these directories and `.env` securely. Do not rotate VAPID keys casually: existing browser subscriptions would need enrollment again.

The parser listens only on `127.0.0.1:2359`, requires a private token, parses programs without executing them and deletes its temporary copies after each scan. The container has a read-only filesystem, dropped capabilities, no privileged mode, a 512 MB Java heap, a 768 MB memory limit, one CPU and one concurrent comparison. A scan handles 2–100 submissions, each at most 100 KB, with a 120-second parser timeout, a five-minute task cooldown and up to 1,000 returned pairs. Request separate tasks for larger cohorts. The queue is independent of compilation. Monitor resource use on a shared VPS before running large scans at peak time.

New systemd units are `ecollab-similarity` and `ecollab-push`; deployment does not restart the existing compiler/realtime units. Diagnose with:

```bash
sudo journalctl -u ecollab-similarity -u ecollab-push -n 60 --no-pager
sudo docker compose -f deploy/code-lab/similarity/compose.yml ps
```

Service files assume the existing checkout path and `ecollabadmin` user. Adjust those files if deploying elsewhere. Nginx must serve root `push-sw.js` and the new bundled editor file. The dedicated `/push/` registration has no fetch handler and does not replace an application's root service worker. Browser push uses browser vendors' push infrastructure, not a paid notification aggregator. Only approved HTTPS vendor endpoints are accepted; HTTP redirects and proxies are disabled for delivery.

To pause optional background work:

```bash
sudo systemctl stop ecollab-similarity ecollab-push
sudo docker compose -f deploy/code-lab/similarity/compose.yml stop
```

The CodeMirror editor works without the new workers. Existing queued jobs remain in the database. Do not roll back schema by deleting task/submission data.

## Dependencies and verification

CodeMirror packages are pinned in `tools/code-lab-editor/package-lock.json` (MIT); bundled notices accompany the editor. Rebuild using `npm ci --prefix tools/code-lab-editor` and `npm run build --prefix tools/code-lab-editor`. Minishlink Web Push 11.0.0 (MIT) and Guzzle 7.10.0 are isolated in `tools/web-push/composer.lock`, keeping application dependencies unchanged. JPlag 6.3.0 is GPL-3.0; its unmodified release is downloaded at deployment and checksum verified, not checked into this repository. Upstream source and licensing: https://github.com/jplag/JPlag/tree/v6.3.0 . Preserve its license and corresponding source obligations if distributing the parser image.

Tests cover facilitator/server authorization, immutable submitted snapshots, grade preservation, queue deduplication/rate limits, push endpoint validation, notification privacy/preferences, device ownership, expiry, deadline deduplication, deliberate browser enrollment and real Chromium clipboard behavior. A separate CI job builds the constrained Docker image and runs actual JPlag comparisons for all four supported languages. External push delivery still requires an enrolled real device and production VAPID configuration.
