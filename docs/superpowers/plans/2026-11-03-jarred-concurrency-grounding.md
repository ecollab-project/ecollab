# Jarred concurrent requests and grounded replies

TASK: accept a five-request burst from independent conversations, preserve request isolation, bound resource use and reduce unsupported application claims.
SOURCE: feat/collaboration-assistance-matching, prior remote 3de2a4d. Inspected OllamaService, JarredConversation/Tools/AssistancePolicy, DM send, AI sessions and deployment files. Read-only Hostinger inventory identifies srv1987643 as 2 CPUs / 8192 MB RAM; live model/version, current free RAM and FPM worker count are not verified.
PRIMARY DEPARTMENT: DevOps, runtime capacity.
SUPPORT: backend service/concurrency, AI integration/grounding, QA load and isolation. Playbooks executed in this session; no independently spawned agents.

Observed failure: one global nonblocking exclusive inference lock rejects all other site requests. Before-change regression admitted exactly 1 of 5 independent PHP processes. Per-user/conversation turn locks are separate and correctly retained. Session locks are already released before network calls. No cross-user history mixing was demonstrated by inspected queries; the tests exercise transport payload/reply isolation rather than certifying every authenticated application flow.

Flow: authenticated DM or AI session → existing role/resource/Code Lab policy → per-conversation lock → shared five-slot admission for the PHP OS account → loopback-only Ollama endpoint → configured two-generation scheduler with bounded provider queue → original conversation persistence/UI. Slot acquisition waits briefly and counts toward the existing request deadline; exceptions/timeouts free the slot. Warm-up yields to interactive requests. Lock files contain no prompts or replies.

Grounding: source data is bounded as intact JSON, with explicit truncation and omitted-source labels. Live facts are distinguished from historical assistant text and untrusted user preferences/content. DM history is reduced when current evidence is present; the verbose DM persona prompt is shortened. Temperature is lowered to 0.1. No generated text grants backend authority or bypasses active Code Lab restrictions. These measures cannot guarantee correct facts from a small local model; a concrete wrong prompt/reply is needed to diagnose remaining hallucinations.

Verification:
- Before fix: five-provider-request regression fails with 1 accepted.
- After fix: five concurrent PHP/provider requests pass with independent payloads/replies.
- Full pool rejects an excess caller within a bounded wait; it does not send that request to the provider.
- Warmer skips while real work is active; released/error/timeout slots can be acquired again.
- Evidence remains valid bounded JSON; missing context is explicit; same-conversation requests retain 409 protection while different users acquire independently.
- CLI-only synthetic five-worker smoke command reports completion, exact-marker correctness and timings without reading/writing app accounts/messages.
- Existing provider URL/proxy/redirect controls, learning policy, action authority, PHPStan and PHPUnit remain checked.

Deployment: see deploy/jarred/README.md. Install the Ollama systemd profile only for the existing host service, restart Ollama/FPM when current requests finish, then warm and run the synthetic probe as www-data. Inspect FPM worker headroom before a five-user web trial. No new dependency/migration or pool/Nginx rewrite. Five short accepted calls are not evidence of five low-latency full answers, nor a full authenticated FPM capacity certification. Long questions can still exceed the existing 45-second deadline on this shared CPU VPS. This is bounded synchronous admission plus Ollama queueing, not a durable background job system.

Rollback: revert this source change and remove only the supplied Ollama override, daemon-reload and restart the existing services. No user history is rewritten and no database rollback is required.
