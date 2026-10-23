# Shared Ollama inference guard — staging preparation only

No installer, service restart or permission change is executed by this PR.

Admin grouping and ordinary Jarred requests now enter the same
OllamaService::requestChat guard. There are no new database queries or transactions.
The guard coordinates active PHP HTTP requests, not all work inside an Ollama daemon.

Before using separate service namespaces, an authorized staging operator must:

1. Provision one private regular lock file outside service-private temporary
   directories, e.g. `/run/ecollab-ai/ollama-inference.lock`, owned by the common
   approved application worker account. Suggested directory0750/file0600; no
   world permissions or world-writable parent. No symlinks. Do not blindly chmod
   an existing path or let untrusted users replace the file.
2. Configure **all** PHP-FPM/CLI callers with the same absolute
   `OLLAMA_INFERENCE_LOCK_FILE`; verify the effective FPM environment (`clear_env`
   matters), mount visibility, device/inode and access. The variable is a path,
   not a credential; do not copy unrestricted environment dumps.
3. Verify ordinary-to-admin and admin-to-ordinary contention across actual staging
   FPM and CLI services. Missing/inaccessible configured files return503; they
   never fall back to a different temporary file. Never unlink, replace, rotate
   or recreate the file while any caller may hold the old inode.
4. Where the variable is unset, legacy temporary-file coordination remains for
   compatibility. This is **not certified across PrivateTmp/container/host
   boundaries**. One shared mount/file coordinates only that filesystem. Multiple
   hosts without one reliable lock mechanism are a separate architecture gate.
5. Record actual Ollama version and demonstrate `format=json`, completed response
   metadata and non-truncating `truncate=false, shift=false` behavior. Older servers
   may not implement those options. JSON syntax and complete candidate coverage
   are checked locally, but model factual quality/context handling need real
   authorized staging validation. No live-model test is authorized by this PR.
6. Validate timeout/client-crash behavior at the daemon. PHP lock release cannot
   prove model cancellation; daemon-side parallelism and residual work may continue.
   Do not equate an HTTP guard with a guaranteed whole-host concurrency ceiling.

Technical envelopes: grouping request32KiB, serialized model messages8KiB,
existing context4096 and output1024 tokens, at most2 requested model threads,
admin45s/ordinary90s default HTTP deadlines, shared response-body cap1MiB.
They are resource envelopes, not hourly/product quotas or token-count estimates.
All80 short candidates can be sent unchanged; larger evidence is rejected422,
never silently shortened. Truncated/malformed/incomplete output is rejected.
No automatic retry/splitting/fallback inference is introduced.

Secure rollout remains a proposal: retain PR76 opt-out/error protections; review
provider and route changes together; drain in-flight requests before configuring
shared paths; test actual namespaces, JSON capability, longest legitimate cohorts
and daemon cancellation. Keep worker and inference counts unchanged. Monitor only
aggregate outcomes/latency/response-limit failures, never prompts/profiles.

Rollback retains a safe shared provider boundary or disables affected admissions
while keeping all messages/settings/grades. Do not restore raw direct admin cURL
or remove coordination as an emergency fallback. Runtime directory recreation
on reboot needs reviewed service ordering and a drain; no tmpfiles/systemd rule
is installed here. Schema, quota values and retention periods are unchanged.
