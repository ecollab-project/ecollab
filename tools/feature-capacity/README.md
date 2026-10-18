# eCollab feature capacity: 50–300 active users

These are runners, not capacity results. The tested chat capacity does not establish
media, compiler or inference capacity. Run on another machine, never on the VPS
that hosts the services. No production limits, worker counts or account roles are
changed. All API users must be generated synthetic students in their private server.
The chat runner is unchanged.

## Workloads and initial acceptance targets

| Mode | Work per user | Initial acceptance |
|---|---|---|
| `voice` | Non-silent Opus audio publishing and subscribing to every other participant in its room | Full 300s hold, >=99% continuous media; p95 packet loss <=2%, jitter <=30ms, RTT <=300ms |
| `video` | Audio plus VP8 1280x720 at 30fps, max1.7Mbps per camera; all-to-all room subscriptions | Same media targets; every expected video stream >=15 decoded fps |
| `screen` | Audio from everyone; one animated 1920x1080 share at 30fps, max6Mbps per room | Same targets; every expected share >=15 decoded fps |
| `code-run` | One synchronized compiler submission, four languages distributed across users | >=99% completed AND correct; p95 including queue wait <30s; deadline180s |
| `code-grade` | Read/start own assignment, submit source, finish both automatic checks with score100 | >=99% completed AND correct; p95 including queue wait <60s; deadline180s |
| `ai` | Two turns in each student's own Jarred session; recall a unique synthetic study label | >=99% completed AND recall-correct; p95 for BOTH turns <30s |

API tests are synchronized bursts, not a sustained arrival-rate benchmark. Media
uses a full peak hold. These are explicitly chosen starting SLOs, not measured
network guarantees. Keep SLOs and profiles fixed across comparisons. A busy/429/
503 AI response, accepted-but-unfinished compiler job, wrong answer or early stop
cannot pass. The synthetic recall probe does not measure general AI accuracy.

The default media quality matches the current capture defaults: 720p/30fps camera
and1080p/30fps sharing. `-MediaQuality economy` explicitly selects360p/15fps camera
or720p/5fps sharing; reports group that separately. Audio is a fixed32kbps profile.
Adaptive subscriptions are disabled to measure all expected receivers consistently.

For 1:1-like media use room size2; for groups/voice use10 or50. Total users means
participants, not calls: 300 users in pairs =150 rooms; 300 users in groups of10
=30 rooms. Screen mode at300/group10 =30 publishers and270 viewers. This does NOT
validate 300 people in one call. Every media user publishes audio and actually
receives/decodes the expected remote streams. Synthetic canvas/oscillator sources
avoid physical camera/microphone permissions but still use real Chromium WebRTC
encoding, SRTP, ICE and LiveKit forwarding. Actual capture-device behavior,
subjective call quality and the app's DM/group invite/ringing/answer workflow are
not measured. Those need separate functional tests; media-plane results must not
be relabeled as complete call-workflow capacity.

## Existing resource constraints

`OllamaService` has a nonblocking global inference lock: one LOCAL inference at a
time, with other attempts rejected as busy. Fifty simultaneous successful local
requests are not supported by the current admission policy. The AI test measures
that honestly; it does not bypass the lock, rate limits or model settings. When
Gemini is configured the real API may use it and fall back locally; the report
records the configured route, not proof of the actual provider for every turn.
Default user quota is20 AI messages/hour. Two complete six-stage ladders at two
turns exceed that quota: use fresh synthetic accounts or wait for the quota window,
not a production quota bypass.

One `ecollab-code-lab` worker handles jobs serially. A queue with300 accepted jobs
is not300 parallel executions. This runner waits for actual completion, includes
queue time, tests C/C++/Python/Java, and verifies output/grades. Its sieve workloads
are intentionally bounded; results do not establish capacity for arbitrary
student programs or CPU/time-limit abuse.

## Install the PC tools once

Install Node.js22+ and k6. First copy ONLY the public runner source from the VPS:

```powershell
Set-Location "$HOME\ecollab-capacity-test"
scp -r ecollabadmin@187.53.142.72:/home/ecollabadmin/ecollab-inspect/tools/feature-capacity .
Set-Location .\feature-capacity
npm ci
npx playwright install chromium
```

The public runner folder includes `plan.js`; no helper from the chat kit is needed.
If you downloaded the earlier incomplete folder, copy `..\kit\plan.js` into it
or download the fixed `tools/feature-capacity/plan.js`.

k6 is already installed in the existing chat kit. Media uses Node/Playwright,
not k6. Do not upload a ZIP/kit containing accounts or room tokens to GitHub.

## Prepare a private target on the VPS

Reuse the original private manifest if its accounts/server are still active:

```bash
cd ~/ecollab-inspect
cat /home/ecollabadmin/ecollab-load-tests/LATEST
```

Use the PRINTED manifest path below. `LATEST` may point to a different test; verify
its account count. At least300 synthetic students are required for the full ladder.
If necessary create a separate300-account base fixture with
`php scripts/load-test-fixture.php create 300` (keep older manifests for cleanup).

```bash
php scripts/prepare-feature-capacity.php /home/ecollabadmin/ecollab-load-tests/YOUR-FIXTURE.json code-run
```

Modes: `code-run`, `code-grade`, `ai`, or `media`. Media optionally takes room size:

```bash
php scripts/prepare-feature-capacity.php /home/ecollabadmin/ecollab-load-tests/YOUR-FIXTURE.json media 10
```

The command prints a PRIVATE kit directory. Copy only the new target and account
files to your already installed PC folder; do not overwrite your existing chat kit:

```powershell
scp ecollabadmin@187.53.142.72:/home/ecollabadmin/ecollab-load-tests/feature-target.json .\feature-target.json
scp ecollabadmin@187.53.142.72:/home/ecollabadmin/ecollab-load-tests/feature-accounts.json .\accounts.json
```

Media tokens last10 minutes. Prepare/copy them AFTER installing browser tools, and
prepare a fresh media target before EVERY300-second stage and repeat. The runner
refuses tokens that cannot cover the full ramp/hold plus margin. The SDK receives
only room-scoped participant tokens, never the LiveKit API secret.

Stable current target/account copies are written beside the manifest for easy SCP;
older per-run folders remain available for cleanup.

Code-grade preparation creates separate private assignments for2/4-user smoke
runs and each50/100/150/200/250/300-user stage. Each stage can run ONCE per prepared
target: submissions are immutable. Prepare a NEW target for an independent repeat.
No facilitator role is granted to a test student; CLI provisioning only inserts
known synthetic assignments in the already verified fixture server.

## Smoke, then one level at a time (NO Tee or output piping)

```powershell
.\run-capacity.ps1 -Mode code-run -Users 4
```

For a media target smoke:

```powershell
.\run-capacity.ps1 -Mode voice -Users 2 -RampSeconds 5 -HoldSeconds 20
```

Then, using the matching prepared target:

```powershell
.\run-capacity.ps1 -Mode code-run -Users 50
.\run-capacity.ps1 -Mode code-grade -Users 50
.\run-capacity.ps1 -Mode ai -Users 50
.\run-capacity.ps1 -Mode voice -Users 50
.\run-capacity.ps1 -Mode video -Users 50
.\run-capacity.ps1 -Mode screen -Users 50
```

Run ONLY the mode matching the target; prepare/copy a different target before
switching between API modes. The three media profiles can use a freshly prepared
media target but each takes long enough that tokens normally need renewal.
Increase Users through50,100,150,200,250,300 only after the lower level passes.
Do not automatically escalate failed loads. Keep the terminal output visible.
The wrapper saves timestamped JSON and the actual process exit code. Require
exit0, full completion and no failed thresholds. Run each passing profile twice
with independent run results. Media may be valid at50 while inference fails there.

## Observe the VPS in another SSH window

Start just before the PC runner:

```bash
cd ~/ecollab-inspect
php scripts/observe-feature-capacity.php 480
```

It prints CPU, steal, available memory, aggregate interface traffic and Code Lab
queue depth/age, and saves private JSON outside the web root. Interface totals can
double-count container bridges; use the public NIC/hosting metrics for egress.
Correlate timestamps with the PC report. Check service logs when a workload fails:

```bash
sudo journalctl -u ecollab-code-lab -u ollama -u php8.3-fpm --since '15 minutes ago' --no-pager -n 100
```

## Large media tests: distribute generators when needed

A laptop running300 codecs can fail before the VPS. A generator CPU p95 over85%,
Node event-loop p95 over100ms or free memory below256MiB invalidates its result.
Browser codec starvation also fails media continuity/FPS. Check generator uplink
capacity and actual bitrates; the tool cannot prove your ISP link has spare capacity.

Use the SAME target file and synchronized clocks on all generators. Choose a shared
START_AT_MS sufficiently in the future (not beyond token expiry). Example:300 total
participants spread over six generators,50 on each:

```powershell
# Generate ONCE; send this number to every generator.
[DateTimeOffset]::UtcNow.AddSeconds(45).ToUnixTimeMilliseconds()

# Generator0; change ShardIndex to1..5 on the other five machines.
.\run-capacity.ps1 -Mode screen -Users 300 -ShardCount 6 -ShardIndex 0 -StartAtMs SHARED_NUMBER
```

The ramp starts together; every shard observes the SAME peak hold. A shard result
alone is never a300-user pass. Copy all six result files to one folder and combine:

```powershell
node .\summarize-capacity.mjs --aggregate .\shard0.json .\shard1.json .\shard2.json .\shard3.json .\shard4.json .\shard5.json
```

Aggregation rejects missing/duplicate participants or shards, differing profiles,
non-overlapping hold windows and failed/overloaded generators. Successful shards
must all have exit0. No token appears in result JSON.

## Summarize actual evidence

```powershell
node .\summarize-capacity.mjs .\results-ai-50-RUN1.json .\results-ai-50-RUN2.json
```

Use actual filenames (tab completion helps). The summary groups exact profiles,
requires two independent passes with zero failures, and gives the highest
repeat-validated TESTED load. Reusing a result file is not a second run. A passing
300-user chat test plus a passing50-user media test does not establish350 mixed
users. After isolated capacities are known, a separately labeled mixed-workload
run with disjoint accounts and simultaneous observation is still required.

## Cleanup after jobs drain and all runners stop

Preserve each prepared target file on the VPS so its assignments can be removed:

```bash
php scripts/prepare-feature-capacity.php /home/ecollabadmin/ecollab-load-tests/YOUR-FIXTURE.json cleanup /home/ecollabadmin/ecollab-load-tests/PRINTED-CAPACITY-DIRECTORY/feature-target.json
```

Cleanup verifies fixture ownership, refuses active grading/runs or foreign attempts,
and removes only that target's synthetic grading data, tagged run jobs and AI
sessions. Clean every prepared target BEFORE base fixture cleanup. Ordinary
`load-test-fixture.php cleanup` now refuses to delete a server with Code Lab data,
since those tables do not cascade. Media rooms empty themselves after clients
leave; exported participant tokens expire automatically. Delete private PC/VPS
kit copies when done; keep token-free results.

References: https://docs.livekit.io/reference/client-sdk-js/ and
https://docs.livekit.io/transport/self-hosting/benchmark . The runner SDK is pinned
to2.22.3 to match the current application. No fixed VPS capacity is inferred from
upstream benchmark hardware.
