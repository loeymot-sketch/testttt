# PHASE A — Governance Before Code

Status: READY_FOR_HUMAN_GOVERNANCE
Owner: human-led, Codex may generate reports only.
Product code writes: forbidden.

## Goal

Make the repository trustworthy before any new implementation. The current problem is not just red tests; it is that closed work, untracked artifacts, active-cycle state, and migration ownership are not yet folded into a reproducible git state.

## Tasks

### A.1 `CV1-FIX-R0-PERSISTENCE-AUDIT-SIGN`

Objective: classify every modified/untracked artifact into commit/discard/move/ignore.

Allowed writes:
- `reports/audit/UNTRACKED_AUDIT_*.txt`
- `reports/audit/PERSISTENCE_BUCKET_DECISION_*.md`
- `reports/audit/MISSIONS_CLOSED_VS_GIT_*.md`

Required checks:
- `git status --porcelain=v1 --untracked-files=all`
- `git status --porcelain=v1`
- compare directory-collapsed vs full-file count

Exit criteria:
- Every bucket has one decision: `COMMIT`, `DISCARD`, `MOVE`, `IGNORE_WITH_RULE`, or `BLOCKED_HUMAN`.
- No `UNKNOWN` bucket remains.
- Human signature line exists.

Stop conditions:
- product/runtime file is untracked but marked discard without human approval
- gate document is untracked and not explicitly signed/ignored

### A.2 `CV1-GOV-ATOMIC-PERSISTENCE-COMMITS`

Objective: persist accepted work with semantic atomic commits, no `git add -A`.

Allowed writes:
- none by Codex unless human explicitly asks for staging/commit.

Commit buckets suggested:
- gates
- missions
- reports/audit
- memory episodes
- product app/config/database/resources/tests
- scripts/CI

Exit criteria:
- `git status --porcelain` clean for V1 product surfaces, or explicit residual list signed.

### A.3 `CV1-GOV-MEMORY-EPISODES-VERSIONING`

Objective: decide whether `memory/episodes/*.jsonl` are tracked release artifacts.

Allowed writes:
- `docs/orchestration/MEMORY_MATRIX.md`
- `memory/INDEX.md`
- `reports/audit/MEMORY_VERSIONING_DECISION_2026-04-26.md`
- `.gitignore` only if human chooses ignore policy.

Exit criteria:
- either `git ls-files memory/episodes` covers required files, or `.gitignore` policy is documented.
- `memory/episodes/12_decisions_log.jsonl` policy explicit.

### A.4 `CV1-INFRA-CLOSED-VS-GIT-AUDIT`

Objective: regenerate the closed mission persistence proof after A.2.

Allowed writes:
- `reports/audit/MISSIONS_CLOSED_VS_GIT_2026-04-26.md`

Exit criteria:
- every CLOSED mission in `plans/masterplay/MASTERPLAY_QUEUE.md` is `OK_PERSISTED`, or downgraded to `REWORK_NOT_PERSISTED`.

### A.5 `CV1-GOV-SINGLE-ACTIVE-PRIMARY`

Objective: choose one active cycle between W10 closeout and Caisse V1.

Allowed writes:
- `.cursor/ACTIVE_CYCLE.md`
- `.cursor/ACTIVE_CYCLE_ARCHIVE.md`
- `reports/audit/ACTIVE_PRIMARY_DECISION_2026-04-26.md`

Exit criteria:
- one and only one `ACTIVE_PRIMARY`.
- archived cycle is read-only and searchable.

### A.6 `CV1-GATE-M13-ORDER-QUOTES-MIGRATION-DECISION`

Objective: decide what to do with untracked migration `database/migrations/2026_04_25_190000_create_order_quotes_table.php`.

Allowed writes:
- `docs/gates/GATE_SCHEMA_MIGRATIONS_CAISSE_V1_2026-04-25.md`
- `docs/gates/GATE_LOG.md`
- migration file only after human gate.

Exit criteria:
- migration is tracked and gate-approved, or rollback/discard is signed.
- no schema task starts before this is resolved.

## Phase A Exit Gate

Phase A closes only when A.1-A.6 have explicit outcomes. Any skipped item must be `BLOCKED_HUMAN_GATE` with path and owner.
