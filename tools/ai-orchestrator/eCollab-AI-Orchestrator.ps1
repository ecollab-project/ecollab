[CmdletBinding()]
param(
    [string]$TaskId = "ECOLLAB-P1-WS-001",
    [switch]$DryRun,
    [int]$MaxIterations = 5
)

$ErrorActionPreference = "Stop"
$repoRoot = Resolve-Path (Join-Path $PSScriptRoot "../..")
Set-Location $repoRoot

function Require-Command([string]$Name) {
    if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
        throw "Required command '$Name' was not found on PATH."
    }
}

function Read-Text([string]$Path) {
    if (-not (Test-Path $Path)) { throw "Missing artifact: $Path" }
    return Get-Content -Raw -Path $Path
}

function Write-Artifact([string]$Path, [string]$Content) {
    $dir = Split-Path -Parent $Path
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
    Set-Content -Path $Path -Value $Content -Encoding UTF8
}

function Invoke-OpenAI([string]$Prompt) {
    if (-not $env:OPENAI_API_KEY) { throw "OPENAI_API_KEY is not set." }

    $model = if ($env:OPENAI_MODEL) { $env:OPENAI_MODEL } else { "gpt-5.6-luna" }
    $body = @{
        model = $model
        input = $Prompt
    } | ConvertTo-Json -Depth 10

    $response = Invoke-RestMethod `
        -Uri "https://api.openai.com/v1/responses" `
        -Method Post `
        -Headers @{ Authorization = "Bearer $($env:OPENAI_API_KEY)" } `
        -ContentType "application/json" `
        -Body $body

    if ($response.output_text) { return [string]$response.output_text }

    $parts = @()
    foreach ($item in $response.output) {
        foreach ($content in $item.content) {
            if ($content.text) { $parts += [string]$content.text }
        }
    }
    return ($parts -join "`n")
}

function Invoke-Claude([string]$Prompt) {
    Require-Command "claude"
    $result = & claude -p $Prompt --output-format text 2>&1
    if ($LASTEXITCODE -ne 0) { throw "Claude Code failed with exit code $LASTEXITCODE.`n$result" }
    return ($result -join "`n")
}

Require-Command "git"

$taskPath = Join-Path $repoRoot "docs/ai-orchestration/tasks/$TaskId.md"
$statePath = Join-Path $repoRoot "docs/ai-orchestration/state.json"
$task = Read-Text $taskPath
$state = Get-Content -Raw $statePath | ConvertFrom-Json

$status = & git status --porcelain
if ($status) {
    throw "Working tree is not clean. Commit/stash unrelated work before starting the autonomous loop."
}

if ($DryRun) {
    Write-Host "DRY RUN: task=$TaskId state=$($state.state) maxIterations=$MaxIterations"
    Write-Host "No provider calls, edits, commits, or pushes will be performed."
    exit 0
}

$openAiAuditPath = Join-Path $repoRoot "docs/ai-orchestration/audits/$TaskId-openai.md"
$contractPath = Join-Path $repoRoot "docs/ai-orchestration/contracts/$TaskId.md"
$claudeReportPath = Join-Path $repoRoot "docs/ai-orchestration/implementation/$TaskId-claude.md"
$openAiReviewPath = Join-Path $repoRoot "docs/ai-orchestration/reviews/$TaskId-openai.md"
$verificationPath = Join-Path $repoRoot "docs/ai-orchestration/verification/$TaskId.md"

$websocketContext = if ($TaskId -eq "ECOLLAB-P1-WS-001" -and (Test-Path "websocket/ChatServer.php")) {
    Get-Content -Raw "websocket/ChatServer.php"
} else {
    "Use local repository search to identify the smallest relevant evidence set before making decisions."
}

$state.state = "AUDITING"
$state.iteration = 0
Write-Artifact $statePath ($state | ConvertTo-Json -Depth 10)

$auditPrompt = @"
You are the independent OpenAI auditor for E-Collab.

Task:
$task

Repository:
ecollab-project/ecollab

Your job is NOT to implement the task. Audit the current implementation, identify the authoritative code path, state the root cause, derive an exact implementation contract, and list tests that prove the acceptance criteria. Do not trust prior reports without checking the supplied evidence. Flag security and regression risks.

Relevant current source evidence:
$websocketContext

Return a concise engineering audit with:
1. Findings
2. Evidence
3. Root cause/design decision
4. Exact contract for Claude
5. Acceptance tests
6. Risks/blockers
"@

$audit = Invoke-OpenAI $auditPrompt
Write-Artifact $openAiAuditPath $audit

$contract = @"
# $TaskId — Implementation Contract

## Task
$task

## Independent OpenAI audit
$audit

## Implementation rules
- Inspect the live repository before editing.
- Preserve existing architecture and security controls.
- Make the smallest coherent change.
- Add or update regression tests.
- Run targeted validation, then broader validation where practical.
- Do not modify unrelated files.
- Do not commit secrets.
"@
Write-Artifact $contractPath $contract

for ($iteration = 1; $iteration -le $MaxIterations; $iteration++) {
    $state.iteration = $iteration
    $state.state = "IMPLEMENTING"
    Write-Artifact $statePath ($state | ConvertTo-Json -Depth 10)

    $claudePrompt = @"
You are Claude, the implementation engineer for E-Collab.

Read the task and independent OpenAI contract below. Inspect the actual repository yourself. Implement the required change; do not merely describe it.

TASK:
$task

CONTRACT:
$contract

Rules:
- Work only in the current feature branch.
- Do not reset, discard, or overwrite unrelated user work.
- Preserve authentication/authorization and existing protocol behavior.
- Add regression coverage.
- Run relevant tests/checks.
- At the end, report files changed, tests run, failures, and remaining risks.
"@

    $claudeReport = Invoke-Claude $claudePrompt
    Write-Artifact $claudeReportPath $claudeReport

    $state.state = "TESTING"
    Write-Artifact $statePath ($state | ConvertTo-Json -Depth 10)

    $testOutput = @()
    if (Test-Path "composer.json") {
        $testOutput += (& composer validate --no-check-publish 2>&1 | Out-String)
    }
    if (Test-Path "vendor/bin/phpunit") {
        $testOutput += (& vendor/bin/phpunit --testsuite Unit 2>&1 | Out-String)
    }
    if (Test-Path "vendor/bin/phpstan") {
        $testOutput += (& vendor/bin/phpstan analyse --no-progress 2>&1 | Out-String)
    }
    $testEvidence = ($testOutput -join "`n")

    $state.state = "REVIEWING"
    Write-Artifact $statePath ($state | ConvertTo-Json -Depth 10)

    $diff = (& git diff --no-ext-diff -- . | Out-String)
    $reviewPrompt = @"
You are the independent OpenAI verifier for E-Collab.

Task:
$task

Contract:
$contract

Claude implementation report:
$claudeReport

Actual git diff:
$diff

Deterministic validation output:
$testEvidence

Review the actual diff and evidence. Do not assume Claude's report is accurate. Check every acceptance criterion, security boundary, regression risk, and unintended change.

Return exactly:
VERDICT: PASS or FAIL
FINDINGS:
- ...
REQUIRED_FIXES:
- ...
EVIDENCE:
- ...
"@

    $review = Invoke-OpenAI $reviewPrompt
    Write-Artifact $openAiReviewPath $review

    if ($review -match "(?im)^VERDICT:\s*PASS\s*$") {
        $state.state = "VERIFIED"
        Write-Artifact $statePath ($state | ConvertTo-Json -Depth 10)

        $verification = @"
# $TaskId — Verification

## Verdict
PASS

## Iteration
$iteration

## OpenAI review
$review

## Deterministic validation
$testEvidence

## Claude implementation report
$claudeReport
"@
        Write-Artifact $verificationPath $verification
        Write-Host "VERIFIED: $TaskId"
        exit 0
    }

    if ($iteration -lt $MaxIterations) {
        $state.state = "REWORK_REQUIRED"
        Write-Artifact $statePath ($state | ConvertTo-Json -Depth 10)
        $contract += "`n`n## Latest OpenAI review — fix these findings before the next pass`n$review"
        Write-Artifact $contractPath $contract
    }
}

$state.state = "BLOCKED"
Write-Artifact $statePath ($state | ConvertTo-Json -Depth 10)
throw "Automatic verification did not pass within $MaxIterations iterations. Review $openAiReviewPath."
