# Read-only chat SQL profiler

Run `php scripts/profile-chat-reads.php CHANNEL_ID [USER_ID [SAMPLES]]` from the application directory. The default viewer is the first undeleted synthetic student in that server whose username matches the k6 fixture format. For a private channel, that viewer must also have access. An explicit viewer is checked against current channel permissions. The default is three samples, with a hard maximum of ten.

The profiler uses a read-only transaction and rolls it back. It does not call runtime table-creation helpers, mark messages read, create tokens, publish events or change messages. The output contains IDs, row counts, SQL durations and EXPLAIN plans. It excludes message text, profiles, attachments, credentials and tokens.

Measurements cover the newest 50-message page with author/parent joins, full server member roster, non-admin channel-list unread counts, and the page's reactions and attachments. Roster measurement omits avatar URL normalization; channel-list measurement omits some returned metadata fields. SQL timings include PDO execution and fetching but exclude HTTP/network transport, PHP-FPM queues, session locks, PHP bootstrap/rendering, and read-marker writes. They are diagnostic samples, not HTTP p95 measurements or capacity proof. Poll-specific queries are not included.

First run without a load test. If SQL timings are low while HTTP latency is high, examine PHP-FPM queueing, session locks, endpoint overhead and CPU before adding indexes or caching. `EXPLAIN rows` is an estimate, not actual rows scanned. Timing changes from repeated samples may reflect buffer warming.

Use a new report filename for each capture. Do not start several profilers at once during a saturated load test. No deployment restart or migration is required for this diagnostic.
