# Phase3AD completion decision: system-user schema compatibility

Observed on actual fresh runner-derived MariaDB10.11 schema:users.is_system is absent;matching get_matches returns500/error1054. This field is present in minimal performance fixtures butnot created by the active migration chain. Existing roles.is_system is a different concept and mustnot be reused for user identity.

A bounded correction proposal must preserve the existing system-user exclusion and Jarred recipient restriction (is_system=1 andusername=ecollab_ai). Do not drop filters to make tests green,assume every existing row is human,mark an arbitrary account as system byusername,or promote separatePR66–73/85 without review.

Reviewable options:

1. **Additive users flag with authoritative lifecycle/transition.** Specify TINYINT flag semantics/default for newly registered human accounts,system-account provisioning/reserved-name policy,and explicit verified system IDs for any existing data. Preserve already-present flags and fail closed on incompatible column/default or ambiguous identity. Define owned fixture andexisting-data upgrade tests. New schema/backfill implementation and real target execution are separate decisions;no such migration is active in this PR.
2. **Compatible reader contract.** Only if identity can be proven through an existing authoritative source,review a source-level compatibility path that enforces equivalent system-user exclusion and Jarred checks. No proven alternative identity source is established in this checkpoint;COALESCE does not handle an absent SQL column.
3. **Keep affected features blocked for the defense.** Retain actual error evidence andexclude matching/DM/system-account dependent flows. This avoids claiming unsupported functions work,but is not full integration acceptance. No auth bypass orUI feature-disable is implemented.

The omitted message content_type error is independently retained. A focused default-handling/error-contract patch can be reviewed separately within Phase3AD completion,with omitted/explicit/invalid types,real UI payload,rejection/data preservation andsource-base comparisons. It is not fixed by a synthetic fixture column orby silently weakening message validation.

Before an implementation decision,confirm whether the nominated defense environment will be a fresh isolated fixture or an existing database. No production/schema inventory orproduction operation is authorized here. Preserve Phase3AC OTP blob7013114a3a7bc0d183c01fc92e0687f06202d3a7 and its legacy-history/cutover restrictions throughout.
