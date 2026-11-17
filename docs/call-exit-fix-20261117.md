# Call exit hit target — 2026-11-17

Base: `31e45053c467e90b485a776db05bd00bdc436566` on `feat/collaboration-assistance-matching`.

The expanded fixed voice panel covered the desktop workspace taskbar. At the center of `.vcb-leave`, live `elementsFromPoint` returned the panel's Audio Settings control before the Leave button. A real Leave-button click repeatedly opened Call settings. The separate panel Leave control worked.

Reserve the desktop server dock height below the expanded voice panel. Keep the existing mobile call taskbar and minimized panel rules. Bump the shared call stylesheet URL so existing caches load the corrected CSS.

Acceptance: pointer Leave opens confirmation in expanded/minimized states; Stay leaves the call connected; keyboard Enter and confirmation disconnect exactly once; settings do not open. The isolated Chromium regression uses production markup/styles and real click hit testing at 390, 860, 861, 1024, 1440 and 1920 pixels. It runs in the responsive CI workflow. Local Chromium launch is blocked by the execution environment's socket restriction, so browser regression results must come from CI and live QA. Existing call taskbar, workspace taskbar and LiveKit voice regressions passed locally.

Rollback: GitHub branch `backup/pre-call-exit-20261117-31e4505`; VPS branch `backup/pre-call-exit-vps-20261117`; code archive `/home/ecollabadmin/ecollab-rollback/pre-call-exit-31e4505.tar.gz`. Verify SHA-256, gzip integrity, readability and archived call CSS before deployment. Preserve all untracked files and private configuration. There are no schema, dependency or background-worker changes.
