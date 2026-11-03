# Module-based Code Lab task creation

In an owned server, choose **Create task → From a module**, upload a lesson, select Easy/Medium/Hard and C/C++/Python/Java, then select **Generate task with Jarred**. Generation creates one private, editable draft. The facilitator reviews its source excerpt, title and instructions, then explicitly publishes the task to that server. The default grading method is facilitator review. Starter code, automatic checks and timing remain under Advanced options; Write manually remains available.

The coding workspace has one Console for both typed input and returned output, with no standalone stdin section or inline Jarred guidance panel. Enter adds an input line; Ctrl/Cmd+Enter or Run code sends the console input with the program. The current runner uses per-run input and returns completed output; it is not a live interactive terminal. Copy/save/download utilities and student-attempt restrictions remain. Module-based facilitator task generation is unchanged.

## Files and privacy

- PDF, DOCX, UTF-8 TXT and Markdown; maximum 5 MB per file. PDF must contain readable text; there is no OCR.
- PDF extraction reads at most 40 pages. Up to 8,000 extracted characters enter the local model context. The UI shows coverage and the quoted source. For long modules, upload a specific lesson/chapter.
- Original uploads remain in PHP temporary storage and are never copied into public uploads or the Library. Private extracted text is removed on publication/discard, or after 24 hours when queue housekeeping runs. Ready drafts expire after seven days. Draft status/source excerpt is available only to its authenticated creator while authorized in that server.
- Reader subprocesses have ten-second wall, eight-second CPU and 256-MB address-space bounds. DOCX is streamed from word/document.xml without extracting files or running macros. External XML entities/DTDs are rejected. Parser stderr and module content never enter public API errors or worker logs.
- Module text is untrusted evidence. Ollama receives a structured-output schema and must quote an exact supporting source excerpt; malformed or untraceable output fails instead of publishing. This checks provenance, not pedagogical correctness. The facilitator must review wording, difficulty, input/output requirements and grading criteria.

## Jobs and ownership

Migration 067 creates private module-draft jobs. Upload routes require authentication, CSRF, server publication authority, valid file/difficulty/language, and a shared generation/retry rate limit of six per hour. Facilitators can draft/publish only in servers they own; existing platform-admin authority is unchanged. Read/publish never reveal another creator's draft, even to another server manager.

The two existing Jarred worker processes alternate DM and module work; at most one module generation is leased at a time. No additional model worker or service is created. This leaves one of the two installed processes available for DMs. The existing inference gate/Ollama two-generation profile remains. Module queue cap is ten; one active private draft per facilitator prevents accidental duplicate work. The model runs outside database transactions, with the existing CLI-only 180-second default budget.

Close/reopen Create task to resume generation or review. Failed generation can retry without uploading again while extracted source is retained. Discard invalidates a running lease so a late worker cannot restore it. Queued jobs expire after 15 minutes; running leases expire after six minutes. Account, preferences and current server publication authority are checked again before a draft becomes ready. Publication reuses the normal task validator and atomically records its task ID; concurrent/repeated publish requests create one task and one set of notifications.

Official structured-output API: https://docs.ollama.com/capabilities/structured-outputs . Other AI generation calls retain their previous payload unless a schema is explicitly supplied.

## VPS deployment

Assumes the existing checkout and installed Jarred worker instances @1/@2 from migration 066:

```bash
cd ~/ecollab-inspect &&
umask 0022 &&
git pull --ff-only origin feat/collaboration-assistance-matching &&
sudo apt-get update &&
sudo apt-get install -y --no-install-recommends poppler-utils unzip php8.3-xml &&
php database/migrate.php &&
sudo systemctl restart php8.3-fpm ecollab-jarred-worker@1.service ecollab-jarred-worker@2.service
systemctl is-active php8.3-fpm ecollab-jarred-worker@1.service ecollab-jarred-worker@2.service
```

No new Composer package, model download, Ollama configuration or code-execution worker change is needed. Existing PHP DOM/Fileinfo, proc_open and standard Ubuntu /usr/bin/prlimit must be available. Hard-refresh Code Lab. If the reverse proxy/PHP has lower upload limits, raise its verified site-specific limit to accommodate the chosen file (up to 5 MB plus multipart overhead); this patch does not rewrite unknown Nginx configuration. TXT modules need no PDF/DOCX command but remain bounded by the same text validation.

Acceptance on VPS: owned-server facilitator uploads a short readable programming lesson, selects difficulty/language, closes/reopens while queued, edits the ready draft, publishes once, then verifies student private attempts still run with separate stdin. Students and non-owner facilitators must be denied generation and private-draft access. Test a second Jarred conversation while module generation is active. Actual qwen3 task quality and VPS latency require this trial; fixture outputs do not certify them.

Rollback: stop both Jarred worker instances briefly, revert this module feature (including worker dispatch) together, then restart the previous DM-only workers and PHP-FPM. Retain additive migration 067; do not drop published tasks or job metadata. The existing 066 DM queue remains compatible with its prior worker.

## Verification

Local tests exercise real PDF/DOCX extraction, bounded input validation, module provenance/schema checks, draft privacy/ownership, revoked ownership, cancelled/expired leases, retries, no automatic publication and idempotent publication. JSDOM checks the complete upload/review/publish contract, close/reopen recovery, escaped rendering, advanced options and the unified console, multiline input, keyboard run, literal output and no inline Jarred controls. Hosted CI adds MySQL 8/MariaDB five-process claim/publish races and Chromium module flows at 240/390/1440px alongside existing responsive, PHPStan, PHPUnit and Code Lab regressions. These use synthetic lessons and accounts; no production module or student content was inspected.
