# Live Code Lab console

Click Run first. The program starts once inside the sandbox and its output appears as it runs. When it prints a prompt, type an answer in the console and press Enter (or Send on touch devices). Shift+Enter adds another line. Finish input / Ctrl+D supplies EOF for programs that read until end-of-input. Stop cancels the process. Loaded playground examples offer Send test input after the program starts.

The input cursor stays hidden until execution reaches the run stage. Each answer is delivered to the same process, preserving variables and program state. Answers are not implemented by restarting or replaying the program. Inline Jarred guidance remains removed; facilitator module task generation is available as before.

## Architecture and bounds

The browser uses authenticated same-origin start/status/input/stop requests. PHP validates membership, private session ownership, task state/deadlines and the existing 60 starts/hour quota. It never returns the private adapter token. Additive migration 068 stores session identity and expiry, not a second copy of the source or input/output transcript.

The private adapter maintains a WebSocket to a reviewed Piston extension. The run stage forwards through a PTY inside isolate so Python input(), C scanf, C++ cin and Java Scanner show prompts before answers, including unflushed C prompts. Submitted code still runs only inside Piston. There is no new public listener, browser-to-Piston connection or shell execution on the PHP/application host.

Interactive and batch jobs share the same configured one/two execution slots and existing aggregate container resource quotas. Interactive runtime keeps 2 seconds CPU and 256 MiB memory, with up to 120 seconds wall time for human input. Compilation keeps 10 seconds CPU, 30 seconds wall and 512 MiB memory. The adapter caps a session's output at 32 KiB and total input at 16 KiB; a request is at most 4 KiB. Missing browser polling for 20 seconds or no program/input activity for 45 seconds ends the session. A source snapshot starts once; monotonic input sequence IDs make acknowledgement retries idempotent. Completed session buffers expire after three minutes and are bounded globally.

The Piston global wall-time ceiling permits the interactive budget. Batch execution and automatic grading explicitly retain their original 8-second wall limit. Frozen language versions, CPU/memory/network limits, private attempts and hidden grading inputs remain authoritative. Input, prompts and adapter credentials are not logged.

## Deploy on the existing VPS

Apply during a quiet period, after assessments and running programs finish. The idle check includes live sessions as well as queued batch jobs. The setup preserves frozen profiles, privately backs up the three existing Code Lab settings, rebuilds the adapter, applies the hash-checked Piston extension, smoke-tests compilers/graders and restarts the existing Code Lab worker.

```bash
cd ~/ecollab-inspect &&
umask 0022 &&
git pull --ff-only origin feat/collaboration-assistance-matching &&
php database/migrate.php &&
php scripts/check-code-lab-runner.php --queue-idle &&
sudo bash scripts/setup-code-lab-piston.sh &&
sudo systemctl restart php8.3-fpm
```

Docker Compose builds the adapter with pinned websocket-client 1.9.2. No new Nginx/WebSocket proxy route or model configuration is needed. Reload Code Lab, write `name = input("Name: "); print("Hello", name)` in Python, click Run, wait for Name:, then type your name and Enter. Verify another prompt in the same program before treating live input as accepted. An old adapter reports an unavailable live console instead of falling back to preset stdin.

For rollback, let sessions/jobs finish, revert this feature as a unit and run the prior sandbox setup before restarting PHP-FPM. Retain additive migration 068 and existing attempts/grades. Do not delete the packages volume or private runtime profiles. Reverting only the browser/controller while changing the protocol is insufficient. Setup checks are preflight checks, not a maintenance lock; do not restart during an active assessment.

## Verification

Local tests cover the manager's one-init/multiple-input protocol, input retry identity, shared capacity, EOF, output bounds, stopping, actual PTY prompts from a trusted program, service ownership/revocation/expiry and browser controller state. Hosted CI additionally starts the actual digest-pinned Piston container and waits for each real Python/C/C++/compiled-Java prompt before sending two answers. It verifies the second answer uses state from the first, repeated input acknowledgements do not feed bytes twice, EOF completes a reading loop, and stopped sessions release execution capacity. Existing sandbox isolation, batch grading, PHPStan, PHPUnit, private-attempt and responsive browser regressions also run. Live VPS deployment and classroom capacity are separate acceptance checks.

Upstream interactive protocol: https://github.com/engineer-man/piston#interactive-execution-endpoint-not-available-through-the-public-api
