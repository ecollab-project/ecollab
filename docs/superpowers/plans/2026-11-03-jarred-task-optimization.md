# Jarred task capability optimization

Source: local f7da976, equivalent deployed PR107 head d9253cf. User selected both Code Lab guidance and website actions. Integrations leads; backend, security and QA lenses support.

Acceptance: active student attempts retain deterministic workflow-only help across routes, with distinct planning/debug/testing/review guidance and a selector in Code Lab. Capability help remains available during active attempts. Submitted/expired attempts regain ordinary assistance. No solutions, grades, source-code analysis or new write authority are added.

Website actions: one capability catalog advertises only implemented executors and read tools. Support exact authorized channel names/slugs inside the active server, clarify ambiguous matches, reject private/foreign targets, and use existing document/whiteboard access services. Keep temporary-room confirmation in PHP; models do not receive write authority. Do not silently open a different current resource when a requested name is unavailable.

Verify: regression first, isolated SQLite action/task state, Code Lab DOM selector, existing provider/session/task/voice tests and full PHPStan. Publish to the existing feature branch/PR and provide pull commands. No live VPS changes or messages sent by this work.

Implemented: phase-aware deterministic guidance, capability discovery during active attempts, Code Lab focus selector, shared six-tool/two-executor catalog, exact channel name/#slug navigation with ambiguity handling, and authoritative document/whiteboard checks for navigation. Existing temporary-room confirmation remains first; read requests such as 'show me peer matches' continue to the read-tool path. Named documents are not guessed from another current surface.

Verification: the new phase test failed on the old repeated reply, then passed with staged guidance. New SQLite tests cover named/private/foreign/ambiguous channels, explicit slug selection, forged document/board IDs and normal assistance after submission. Code Lab DOM test verifies focus requests, text-only rendering and no code overwrite/transmission. Existing task/execution/clipboard/provider/session suites and 25 PHPUnit tests / 56 assertions pass. Full PHPStan passes. No model-quality benchmark or live provider/VPS check is claimed.
