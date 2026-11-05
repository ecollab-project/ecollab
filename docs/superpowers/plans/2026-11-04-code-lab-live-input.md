# Live program input

User clarified that a normal IDE starts a program, displays prompts and accepts answers while that process runs. The earlier unified UI still supplied fixed stdin and did not satisfy this behavior.

Source: deployment branch at effcd160. Realtime/integration lead with backend ownership/deadline checks, sandbox operations and QA gates. Use existing self-hosted Piston's WebSocket capability behind the private adapter; a PTY helper runs inside isolate to preserve normal prompt flushing. Browser → authenticated PHP session API → private adapter session → Piston sandbox → streaming output snapshot → browser. Each answer has a monotonic sequence, one source initialization and no program replay.

Change includes additive migration 068, bounded live sessions sharing the original execution slots, input/EOF/stop, expired/revoked permission cancellation, same-process prompts, deployment preflight and rollback. Preserve module generation, Jarred workspace removal, immutable graded submissions and the original batch grading time envelope. Candidate code is verified on a separate draft branch before promotion to the user's deployment branch.

Verification: local PHP/Python/Node tests; real Python/C/C++/compiled-Java sandbox prompts and persistent state in hosted CI; existing isolation/grade regressions and browser flows. No production source, student answers, secrets or live messages are inspected. Deploy on the VPS only through the commands the user chooses to execute.
