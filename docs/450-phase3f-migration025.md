# Phase 3F — migration025 compatibility repair

Decision: focused repair ready for independent review, subject to exact-head CI
and deployment-specific schema review. No production access or changes. R7 remains
REQUEST CHANGES; 450-user capacity remains UNVERIFIED.

## Source and independent base

PR73 was reverified open/draft/unmerged at
`19a8845665dc72d8bf4d062f634b25410fad9961`. The clean integration branch
`ecollab-collabs` was fetched at `c838359711a14e9b6eb3f345c4386d184a178b25`
(merge of PR65). This repair is based directly there, not on unresolved R7 or its
experimental broker fork. The migration files and runner at that base match the
reviewed versions. Coworkspace authorization differs in the R7 stack; this repair
does not replace its service or permission changes. Review integration separately.

## Reproduced root cause

A disposable native MariaDB10.11.14 instance applies the real023 and024 migrations
to synthetic prerequisite tables. Unchanged025 fails:

```
SQLSTATE HY000 / error1901
Function or expression 'server_id' cannot be used in the GENERATED ALWAYS AS
clause of `active_server_id`
```

024 creates `fk_cw_server`: `collab_workspaces.server_id -> servers.id`, with
ON DELETE CASCADE and ON UPDATE CASCADE.025 makes a STORED generated field depend
on that cascading base column. This is the incompatibility, not IF syntax or a
MariaDB inability to index generated columns generally. The same original025
succeeds with an ON UPDATE RESTRICT fixture. The alternative below works with
the actual024 cascading FK, including an actual parent primary-key update.

Primary reference: MariaDB generated columns documentation and MDEV-18114 /
MDEV-31322 release notes explain restrictions when FK actions change generated
base columns:
https://mariadb.com/docs/server/reference/sql-statements/data-definition/create/generated-columns
https://mariadb.com/docs/release-notes/enterprise-server/10.6/10.6.15-10
Runtime reproduction on10.11.14, rather than the release-note version alone,
establishes the compatibility result here.

## Chosen schema

`025_coworkspace_one_active_per_server_mariadb.sql` adds:

```sql
active_slot TINYINT UNSIGNED
  GENERATED ALWAYS AS (IF(archived=0,1,NULL)) STORED
UNIQUE KEY uk_coworkspace_active_server (server_id,active_slot)
```

The generated expression depends only on `archived`. The ordinary indexed
`server_id` follows existing FK actions independently. All active rows in one
server compete for `(server_id,1)`; archived rows carry NULL, allowing multiple
archived workspaces. The database enforces creation, archival, reactivation and
server reassignment regardless of which application writer is used. Concurrent
transactions contend on the same unique key. No new field needs to be supplied
by application inserts.

An ordinary nullable `active_server_id` maintained in PHP was rejected: direct
SQL, another create path and reactivation could bypass it. Trigger maintenance
adds write-path complexity, and FK cascades require particular care because they
do not invoke normal table triggers. A generated marker avoids those failure
modes while retaining the original cascading FK. Changing the FK to RESTRICT
was rejected because it changes existing reference-update behavior. No new
workspace ownership/host-transfer policy is introduced.

The replacement validates known generated-column/index shapes, non-null base
columns and InnoDB. It refuses duplicates and unknown partial/custom shapes with
SQLSTATE45000; it never chooses a winner, archives, merges or deletes records.
It accepts absent025 state, the original generated column with or without its
index, the new marker without its index, and a completed replacement. A
connection-owned database-specific GET_LOCK serializes this repair, with a
10-second acquisition timeout. On failure, the migration CLI exits and its
connection releases the lock. Reusing a failed migration connection manually
requires closing it/releasing its lock before another connection retries.

One ALTER drops the known legacy generated column/index and adds the replacement
marker/index as needed. The old unique guard is not dropped in a separately
committed DDL statement. MariaDB10.11 InnoDB atomic ALTER failure leaves its prior
schema, and unique-index construction rechecks concurrent data even if duplicates
appear after the preflight. A completed rerun executes no ALTER. No workspace or
relationship DML is part of this migration. Custom dependencies on the removed
computed column still require deployment inventory; unknown additional indexes
on it are rejected, not silently dropped.

## Immutable migration and registry strategy

Historical `025_coworkspace_one_active_per_server.sql` is byte-for-byte unchanged:
SHA256 `a94f59170a94ae8d17f4bac6c27c01549b60644dd61cb825406984b5aee8ff59`.

`database/migrate.php` explicitly supersedes that filename with the newly named
025 replacement. Lexical ordering puts it after024 and before026. The runner
never fabricates a successful execution of the historical file:

- No old025 ledger row: keep it absent; apply and record the replacement's own
  filename, checksum, timestamp and result.
- Old025 failure row: preserve its error, checksum, status and timestamp intact;
  independently record the successful replacement.
- Successful old025: preserve that row intact; independently apply the forward
  conversion and record the replacement.
- DDL completed but registry ACK lost: pending replacement reruns idempotently,
  then records success. Its prior failure row may be updated normally; the
  historical025 row is never rewritten.
- Historical source hash changed or either related ledger checksum mismatches:
  stop for manual review. Do not silently accept edited migration history.
- `--force=025_coworkspace_one_active_per_server.sql` is rejected. Explicitly
  forcing the named replacement remains supported for reviewed schema repair.
- `--status` identifies supersession/retained history. Successful ledger counts
  exclude failure rows. A historical failure remains available for audit; tools
  that count any success0 row as a current failure must understand supersession.

The new runner must accompany the new SQL file. An old runner or manual glob
import will still attempt the unchanged historical025 and fail; that is not a
supported deployment path for this repair. Do not delete ledger rows, mark the
old file artificially successful, or rewrite the historical file in place.

## Application paths reviewed

`CoworkspaceService` reads/access checks do not maintain active uniqueness.
`API/collaboration/server-coworkspaces.php::ensureServerCoworkspace` and its POST
create path already supply server_id; the automatic path handles unique-key
conflicts by finding the winning workspace. Existing archive/delete operations
are in `API/collaboration/workspaces.php`; archival directly updates archived.
No application reactivation endpoint was found; database reactivation is tested.
Search found no coworkspace application dependency on the removed computed
column; DM `active_server_id` input is unrelated and unchanged.

A confirmed companion bug: legacy `API/collaboration/workspaces.php` create
omitted server_id, although024 makes it NOT NULL without a default. The minimal
insert fix resolves the origin channel's current server_id with a native SQL
subquery, preserving existing channel authorization, transaction and membership
insertion. Both create APIs remain subject to their existing permission policy;
this PR does not implement R7 revocation fencing. Existing explicit-create
conflict HTTP presentation is not redesigned.

## Tests and precise scope

`tests/coworkspace-migration-integration.py` owns a freshly initialized local
MariaDB, loopback random port and temporary datadir. It never discovers a host
DB or uses application connection credentials. PHP requires the fixed disposable
database name. Only this owned daemon is stopped/restarted. No untrusted code
execution or stress/load test is involved.

`tests/coworkspace-migration-regression.php` runs the real migration SQL through
the actual runner's statement functions, plus the actual CLI in a disposable
migration-directory fixture. Scenarios:

| Case | Verified local result |
|---|---|
| Real023/024 then unchanged025 | Expected1901 reproduced |
| Fresh target schema | Replacement succeeds; same workspace/member/document rows and FK actions |
| Previously successful original025 | Conversion succeeds with preserved RESTRICT FK and immutable ledger |
| Partial old computed column; partial new marker; completed state | Repair and repeated execution succeed without changing stored records |
| Duplicate active rows before repair | Explicit1644 rejection; no automatic archive/delete/merge |
| Unknown ordinary partial marker | Explicit1644 rejection; data preserved |
| One active plus several archived | Unique active enforced, archived coexist |
| Archive then create; conflicting/successful reactivation | Correct uniqueness outcomes |
| Two concurrent creates, winner commits | Loser waits then1062; exactly one active row |
| Two concurrent creates, winner rolls back | Contender succeeds; exactly one active row |
| Parent ID update, owner edit, direct workspace server move | CASCADE maintained, existing host preserved, duplicate/invalid destination rejected |
| Parent deletion | Existing ON DELETE CASCADE behavior retained |
| Metadata-lock failure then retry |1205, then successful retry with same records |
| Legacy create SQL and authorized CoworkspaceService read | Native prepared insert supplies server_id; existing service read succeeds |
| CLI absent/failed/successful old ledgers | Supersession, dry-run, continuation, rerun, lost-ACK recovery and checksum mismatch rejection tested |
| Real database daemon stop/restart | Old connection fails; new connection retries repair; stored records preserved |

The CLI-directory fixture includes a later synthetic migration proving execution
continues past025. This is not a certification of every unrelated historical
migration in the full application chain. The legacy create test executes the
actual SQL extracted from the endpoint, not a complete authenticated HTTP test.
The legacy successful025 fixture uses RESTRICT because current10.11 correctly
refuses creating the historical CASCADE/generated combination. No old production
MariaDB datadir was manufactured or claimed inspected.

Local PHPUnit Unit also passes25tests/56assertions; the existing Collabora and
whiteboard database suites pass against a separate guarded schema on the same
disposable daemon. The standalone whiteboard socket regression also passes.
An initial Unit invocation using a symlinked vendor directory failed because
Composer loaded configuration from the other checkout; using a local dependency
copy resolves that test-environment issue without changing application code.
An initial direct whiteboard test without its dedicated DSN correctly refused
to run; it subsequently passes inside the owned database fixture.

The first local harness attempts failed on a disallowed Unix socket and a fixture
path, before migration execution. A legacy-fixture FK rename and saved insert ID
were subsequently corrected. Final tests rerun the complete matrix; no failures
were hidden or tests removed. PHP syntax and workflow/Python parsing are checked.
See `450-phase3f-evidence.json` for final local transcript and source hashes.
New workflow `Coworkspace migration025` runs on Ubuntu24.04 with native MariaDB
10.11 and PHP8.3; exact-head outcomes belong in the PR handoff. Existing relevant
repository CI is retained. MySQL8/other MariaDB series are not certified here.

## Deployment and rollback risks — proposal only

Do not run this against production from this review. Before separately authorized
rollout, inventory actual SHOW CREATE TABLE, FK actions, column/index definitions,
triggers/views/routines and ledger hashes. Confirm backups and restore capability
through the normal approved process; this task takes no production backup.
Resolve duplicate actives through an explicit business decision, preserving
records/relationships. Confirm024 is complete and InnoDB. Unknown/custom states
must stop for review. Test this exact app/runner/SQL release against a sanitized
schema clone, then review its integration with the unmerged optimization stack.

Adding a STORED column/index can rebuild the table, consume temporary disk and
wait on metadata locks. No zero-downtime guarantee or duration estimate is made.
Plan a controlled migration window and quiesce workspace writes for rollout;
size from actual staging table/disk/locking measurements. Atomic DDL does not
make the whole application migration chain transactional. Connection loss can
leave a committed DDL change with a failed/missing registry completion; rerun
using a new connection and the same immutable replacement. Inspect ledger and
schema before retrying unfamiliar errors. Do not disable foreign-key checks in
production; tests do so only when replacing their synthetic fixture tables.

No automatic down migration is supplied: returning to the historical stored
expression reintroduces1901, and dropping the unique guard permits invalid data.
Application rollback should retain this database invariant and use a version
that supplies server_id. Rolling back the migration runner requires retaining
its supersession handling; an older runner would revisit pending historical025.
If a schema rollback is required, prepare and test a separate preserving plan
from the actual schema/backup, rather than dropping records or integrity checks.
No production migration, reset, backup, restart, provisioning, merge or deployment
was performed. Code Lab isolation remains the next independent security workstream.
