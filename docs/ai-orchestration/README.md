# E-Collab AI Engineering Control Plane

This directory documents the repository-level collaboration loop between OpenAI/ChatGPT and Claude.

## Architecture

```text
GitHub task/issue
      |
      v
 Orchestrator
      |
      +--> OpenAI audit / architecture / security
      |          |
      |          v
      |      task contract
      |          |
      +----------+----------+
                 |
                 v
              Claude
          implementation
                 |
                 v
                PR
                 |
                 v
              CI/tests
                 |
                 v
          OpenAI verification
                 |
          +------+------+
          |             |
        FAIL           PASS
          |             |
          v             v
       Claude        approval/merge
        fixes
```

## What is already present

The engineering branch already contains a project-local Orchestrator agent, eight specialist agents, and a broad skill stack. The control plane therefore extends the existing architecture rather than replacing it.

## Activation model

There are two supported modes:

### 1. Local autonomous loop

Run the Windows PowerShell runner from the repository checkout. It can use a local Claude Code installation and an OpenAI API key to exchange artifacts without the user relaying messages.

Required local configuration:

- `OPENAI_API_KEY` — OpenAI API credential; never commit it.
- `ANTHROPIC_API_KEY` — only if the selected Claude runner needs it.
- `claude` — Claude Code CLI available on `PATH`.
- `git` — available on `PATH`.

### 2. GitHub-controlled loop

GitHub Actions can validate task state, CI, and artifacts. Provider credentials must be stored as repository/environment secrets. The workflow must remain approval-gated for implementation/merge until the provider runner is deliberately enabled.

## Safety model

The system is deliberately not an unrestricted two-model auto-merge bot. OpenAI reviews the actual diff, CI results, and acceptance criteria. High-risk changes require a human gate.

## First task

The existing eCollab WebSocket authorization issue is an ideal smoke test:

`ECOLLAB-P1-WS-001`

The task should require channel-membership authorization before a connection is inserted into `channelSubs[channelId]`, preserve the connection on an authorization failure, emit the project's defined error frame, and add regression coverage.

## Important limitation

A ChatGPT web conversation and a Claude web conversation cannot be made to exchange messages directly by this repository. The bridge is an API/CLI or agent runtime plus GitHub artifacts. This repository provides the durable protocol and automation layer; provider credentials/runners are intentionally externalized.
