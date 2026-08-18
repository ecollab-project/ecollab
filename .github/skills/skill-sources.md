# E-Collab Skill Sources

Audited sources for this project:

- `github/awesome-copilot` — primary source for orchestration, skill discovery, harness engineering, and quality engineering.
- `anthropics/skills` — reference source for portable Agent Skills patterns, browser testing, frontend quality, and skill authoring.
- `Microsoft/skills` — reference source for skill authoring, frontend quality, and agent/SDK patterns. Azure-specific skills are not installed because E-Collab is currently PHP/MySQL/Ratchet-based rather than Azure-native.
- `agentskills/agentskills` — open Agent Skills format/specification source; used as the format compatibility reference rather than copied as a runtime skill.

## Selection policy

Do not install an entire upstream repository. Select capabilities that materially improve E-Collab. Prefer project-local adaptations so repository architecture, security, PHP, MySQL/MariaDB, Ratchet, and AI requirements remain authoritative.

Upstream skill content is adapted rather than blindly copied when it contains assumptions about another agent runtime, language, cloud, or toolchain.

Review upstream sources again before future major updates; do not silently overwrite local E-Collab adaptations.