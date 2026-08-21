# Windows Setup — ChatGPT ↔ Claude eCollab Loop

## 1. Requirements

- Git
- PowerShell 5.1+ or PowerShell 7+
- Claude Code CLI available as `claude`
- An OpenAI API key stored only in your local environment

The OpenAI API uses the Responses API endpoint. The provider API is separate from a normal ChatGPT web subscription; API usage is billed according to the OpenAI API account/model selected.

## 2. Set the OpenAI key for the current PowerShell session

```powershell
$env:OPENAI_API_KEY = "YOUR_KEY"
$env:OPENAI_MODEL = "gpt-5.6-luna"
```

Do not put the key in a repository file, `.ps1` script, GitHub issue, prompt artifact, or commit.

## 3. Verify Claude Code

```powershell
claude --version
```

If the command is unavailable, install/configure Claude Code separately. The orchestrator intentionally does not embed an Anthropic credential in source control.

## 4. Start the autonomous loop

From the repository root:

```powershell
.\tools\ai-orchestrator\eCollab-AI-Orchestrator.ps1
```

The default task is `ECOLLAB-P1-WS-001`.

## 5. Select another task

```powershell
.\tools\ai-orchestrator\eCollab-AI-Orchestrator.ps1 -TaskId "ECOLLAB-XXXX-YYY-001"
```

The task must exist under `docs/ai-orchestration/tasks/`.

## 6. Dry run

```powershell
.\tools\ai-orchestrator\eCollab-AI-Orchestrator.ps1 -DryRun
```

Dry run performs no provider calls and no repository edits.

## 7. What happens automatically

1. Read the stable task from GitHub/repository state.
2. Ask OpenAI for an independent audit and implementation contract.
3. Persist the audit/contract as repository artifacts.
4. Give the contract to Claude Code.
5. Claude inspects and edits the working tree and runs targeted checks.
6. The runner captures the implementation report.
7. The runner runs deterministic validation available in the checkout.
8. OpenAI reviews the actual diff and test evidence.
9. If review fails, the findings are appended to the contract and Claude gets another iteration.
10. The loop stops at `VERIFIED` or `BLOCKED`.

## 8. Human gates

The runner does not automatically merge to `main`. Destructive migrations, production credentials, irreversible data operations, security-control removal, and final main-branch merge remain human-controlled.

## 9. Troubleshooting

- If the working tree is dirty, commit/stash unrelated work first. This prevents the agent loop from mixing your manual changes with AI changes.
- If OpenAI authentication fails, verify `OPENAI_API_KEY` in the current PowerShell session.
- If Claude fails, run `claude --version` and test Claude Code independently.
- If validation fails, inspect `docs/ai-orchestration/reviews/<TASK-ID>-openai.md` and the implementation report before restarting.
