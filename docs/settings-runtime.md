# Settings, realtime rendering and Jarred runtime

Deploy through the normal application checkout:

```sh
cd ~/ecollab-inspect
git pull --ff-only origin ecollab-collabs
php database/migrate.php
```

Migration 060 makes the 21 columns previously defined only in `database/extend-user-settings.sql` part of the normal migration runner, and adds two opt-in Jarred context fields. Each column is guarded through information_schema: existing preferences are retained and rerunning the migration is safe. No model, Docker, cgroup, firewall or server tuning changes are required. If the push worker is active, restart it to load the notification-category changes:

```sh
if systemctl is-active --quiet ecollab-push; then sudo systemctl restart ecollab-push; fi
```

Hard-refresh open application tabs after deployment.

## Settings behavior

| Area | Runtime behavior |
| --- | --- |
| Account and profile | Existing update/upload/password APIs are retained; saving account text preserves an uploaded avatar. Profile visibility now gates the profile API through self, accepted connection or active shared server checks. |
| Privacy | Activity status is masked in remaining member/match/friend/picker APIs. Invisible users withdraw channel awareness. DM read receipts require both participants to allow receipts and never expose another conversation's cursor. |
| Screenshot alerts | The browser cannot reliably detect operating-system screenshots. The unsupported switch is visibly disabled rather than presenting a false guarantee. |
| Connections | Existing request/DM admission and AI matching preference gates remain in force. |
| Appearance | All 20 palettes remain available. Saved settings use one account load shared with media/settings modules. Chat text/sidebar scale and message density have explicit consumers. |
| Notifications | Group/reply/thread categories are routed correctly. Sound is independent of the desktop-notification switch. Notification refreshes coalesce bursts, retain a trailing refresh and skip hidden backup polls. Persistent notification history is retained even when alerts are off. |
| Chat and DMs | Timestamp and typing switches affect rendering; typing is suppressed at the sender. Native and rich composers honor Enter-to-send and retain Ctrl+Enter as explicit send. Link previews use local URL metadata without external scraping. Media/animated previews can require an explicit reveal; videos do not auto-play. |
| Voice and video | Camera selection now appears in account settings and maps to the existing call-device preferences. Existing output-device, output-volume and processing controls remain. Unsupported microphone gain is disabled with an explanation; use the OS microphone level in those browsers. Device selection applies when capture is created; active-call device changes use the existing call-settings dialog. |
| Jarred | The enable switch is enforced by all four inference entry points. Concise/balanced/detailed styles set response instructions and output budgets. Saved notes are editable, removable and opt-in across conversations; disabling personalization suppresses them. Existing conversation transcripts remain scoped to their owning user/session. |
| Accessibility | Existing reduce-motion/high-contrast behavior remains. Screen-reader mode adds labeled message logs and polite announcements, plus stronger keyboard focus. |

Other open tabs for the same account receive an invalidation signal and fetch the fresh account settings. The signal contains no notes or profile data. Initial account loads do not trigger refresh storms. Settings saves are serialized; a save response cannot discard a draft in the saved-context field.

## Realtime changes and limits

Live append uses cached date formatters, a message-ID index and tail-only date/unread decoration. Existing separators stay in place; initial history, pagination and deletions still reconcile the full timeline. Existing reconnect/catch-up and unread semantics are preserved. Lazy images and non-autoplay video reduce avoidable media work. This is a focused browser/PHP improvement, not proof of maximum server throughput.

## Jarred conversation handling

DM inference now receives bounded user **and assistant** history, so follow-ups can refer to prior answers. Current application evidence and permission checks remain authoritative; old assistant statements cannot authorize actions. Ordinary greetings skip roster/matching context queries. Same-conversation requests are guarded against overlap. Dashboard turns are persisted atomically after inference, preventing half-saved user/assistant pairs. Slow replies and slow session loads cannot overwrite another selected conversation.

Ollama retains its existing 4096-token context and one-to-two-thread cap. The non-thinking request is retained, temperature is lowered, output length follows the selected style, and a 10-minute model residency window reduces avoidable reloads. Timing fields expose model load, prompt evaluation and generation durations. Gemini fallback waits at most 15 seconds before the local path. The global local-inference guard remains to preserve CPU headroom. See the official [Ollama chat API](https://docs.ollama.com/api/chat) for request options.

No inference results or private transcripts are shared between users. Notes do not automatically import old conversations. Existing Code Lab integrity blocks remain in place. These changes improve context handling; they do not guarantee factual correctness from a small model.

## Verification

Tests cover the original-settings schema upgraded twice on MariaDB, all preference columns, profile/receipt privacy, opt-in context, bounded history, atomic rollback, account/session isolation, concurrent turns, provider request options and timing parsing, stale browser responses, live cross-tab preferences, composer behavior and phone/tablet/desktop layout.

A local DOM test checks 1,000 incremental appends and retained divider nodes. Its elapsed time is browser/test-host specific and is not a VPS capacity result. For two fixed synthetic local-model requests on the VPS, run as the PHP-FPM user:

```sh
sudo -u www-data php scripts/benchmark-jarred.php
```

This reports timings and whether a specified synthetic fact survives a follow-up. It reads no user conversations or saved notes. Run the existing k6 workload again to measure production capacity; no new capacity limit is claimed by this patch.
