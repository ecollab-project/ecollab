# Piston Node.js 24 staging modernization (not deployable)

Scope: branch `security/piston-node24-20261023`. No changes to production
`deploy/code-lab/piston/compose.yml`, `scripts/setup-code-lab-piston.sh`,
application credentials, persistent Docker packages, or task history.

## Current implementation

- The staging-only Compose override builds from the **fixed Piston source SHA**
  `de2b365ac759670a3a0d13ea208a0869a92c7e64` and the existing reviewed
  **Isolate SHA** `af6db68042c3aa0ded80787fbb78bc0846ea2114`.
- The Piston API builds on `node:24.22.1-bookworm-slim`. Its pinned base image
  **tag** still needs a reviewed immutable OCI digest before production use.
  Third-party npm dependencies and native `waitpid` addon require successful
  compilation, vulnerability review and runtime smoke tests before acceptance.
- `grading/install.py` retains its **original SHA-256 allowlist** and fails
  closed on unrecognized Piston source. Do not rewrite hashes automatically or
  bypass them to get a green build. A hash mismatch means the build stops.
- The new package `ecollab-node24/24.22.1` has a separate Piston directory
  and JavaScript runtime ID `10007`. ID `10005` remains Node 20.11.1.
  Frozen C/C++/Python/Java IDs `10001` through `10006` are unchanged.
  Never rewrite past task `runtime_id` values.
- The adapter permits seven profiles but rejects altered Node 24 details.
  JavaScript batch grading behavior is unchanged: only the legacy supported
  grading languages receive the custom compile-once route.
- No production installation path or default language mapping has changed.
  Opt-in use of ID `10007` needs a separately reviewed deployment plan and
  a way for students/facilitators to select new JavaScript versions.

## CI verification (GitHub-hosted isolated VM, not production VPS)

`.github/workflows/code-lab-node24.yml` builds and runs only on the dedicated
development branch or by explicit dispatch. The workflow:

1. Runs offline profile tests, old adapter tests and configuration validation.
2. Builds the Node 24 Piston image, including native Node addon compilation and
   strict grading source-hash check. The build must fail if either is incompatible.
3. Creates disposable *staging* package volumes and private profiles; installs
   legacy compilers, compiled Java and the new Node 24 package.
4. Runs existing real-language and grading tests plus new legacy-versus-modern
   JavaScript, CPU, blocked-network and private-file checks.
5. Deletes staging containers and volumes even on failures.

GitHub Actions run results must be inspected: workflow existence is not evidence
of a successful modern image build. GitHub-hosted tests are **not** a substitute
for independent sandbox escape review or a dedicated hostile-code execution host.

## Explicit blockers before any deployment

- Verify native `waitpid` addon and transitive npm dependencies against Node 24.
- Fix Debian Bookworm vs older Piston packages compatibility *without weakening
  source-integrity validation*; review all patches and update SHA allowlists
  only after independent full-source review.
- Pin the resolved production image by digest; retain SBOM and dependency
  vulnerability review.
- Independently test cgroup v2/Isolate, syscall restrictions, networking,
  cross-case source/artifact isolation, outputs/timeouts and two-slot quotas.
- Confirm the old `10005` runtime still executes exactly its frozen version and
  the new `10007` package passes the same integration and performance matrix.
- Create a dedicated staging environment with no live credentials or data.
- Draft a **separate** opt-in deployment/migration plan with a task publication
  switch for new JavaScript work; never silently change historical assessments.
- Document production backup, rollback and long-term dedicated execution host.

## Staging teardown / rollback

On the *disposable staging host only*:

```bash
export CODE_LAB_RUNNER_STATE=/tmp/ecollab-node24-ci-private
docker compose -f deploy/code-lab/piston/compose.yml \
  -f deploy/code-lab/piston/compose.node24.staging.yml down -v
```

Do not run the existing production installer to test this branch. Production is
unmodified, so its rollback is **no change** at this point. Future deployment
must preserve all prior image digests, private profiles, package volumes,
secrets and assessment records and rehearse a verified rollback separately.
