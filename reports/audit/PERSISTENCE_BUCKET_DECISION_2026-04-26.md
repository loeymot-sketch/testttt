# PERSISTENCE_BUCKET_DECISION_2026-04-26

TASK_ID: CV1-FIX-R0-PERSISTENCE-AUDIT-SIGN
Phase: A.1
Runner: codex-extension
Scope: governance report only, no staging, no commit, no product edit.

## Source Commands

- `git status --porcelain=v1 --untracked-files=all`
- `git status --porcelain=v1`
- bucket classification by path family

## Current Counts

| View | Modified tracked | Untracked | Other |
| --- | ---: | ---: | ---: |
| Full-file view | 111 | 679 | 0 |
| Directory-collapsed view | 111 | 410 | 0 |

The previous `UNTRACKED_AUDIT_2026-04-26.txt` counted 393 collapsed untracked entries. The current collapsed count is 410 and full-file count is 679, because new planning/audit artifacts were added after that audit.

## Bucket Counts

| Bucket | Modified tracked | Untracked full-file | Default decision | Reason |
| --- | ---: | ---: | --- | --- |
| CODE_TEST_CI | 68 | 128 | BLOCKED_HUMAN | Contains app/config/database/resources/routes/tests/scripts/public/package/build artifacts. Must be reviewed and committed by semantic cluster, never `git add -A`. |
| DOCS_ORCHESTRATION | 32 | 21 | COMMIT_AFTER_REVIEW | Contains operating rules and orchestration docs. High impact but intended governance work. |
| GATES | 2 | 8 | BLOCKED_HUMAN | Gate docs and `GATE_LOG.md` cannot be silently persisted or discarded. |
| MEMORY_EPISODES | 0 | 27 | BLOCKED_HUMAN_POLICY | Needs A.3 decision: track JSONL memory or document ignore policy. |
| MISSIONS | 0 | 272 | COMMIT_AFTER_REVIEW | Mission inputs/briefs/output are required evidence for CLOSED claims. |
| PLANS | 2 | 22 | COMMIT_AFTER_REVIEW | Planning artifacts should be persisted if accepted. |
| REPORTS | 7 | 199 | COMMIT_AFTER_REVIEW | Audit/execution/release reports are evidence; generated logs may be selectively retained. |
| OTHER | 0 | 2 | BLOCKED_HUMAN | Paths outside normal buckets need explicit review. |

## Critical Untracked Product/Governance Paths

Examples requiring human review before commit/discard:

- `database/migrations/2026_04_25_190000_create_order_quotes_table.php`
- `app/Services/Order/OrderQuoteService.php`
- `app/Models/OrderQuote.php`
- `app/Domain/Kds/KitchenReleaseRule.php`
- `app/Listeners/DispatchKdsTicket.php`
- `app/Listeners/PersistOrderCreatedToOutbox.php`
- `config/payment.php`
- `config/horizon.php`
- `docs/gates/GATE_*_2026-04-25.md`
- `memory/episodes/caisse_v1_*.jsonl`
- `missions/CV1-M*/` and `missions/CV1-LOT-*/`

## Recommended Commit Buckets

Do not stage all at once. Use these buckets in order:

1. `chore(governance): persist caisse v1 gates`
2. `chore(missions): persist caisse v1 masterplay mission packets`
3. `chore(memory): persist caisse v1 jsonl episodes`
4. `chore(reports): persist caisse v1 audit evidence`
5. `feat(caisse-v1): persist order quote and POS/KDS runtime changes`
6. `test(caisse-v1): persist sentinels and e2e coverage`
7. `chore(ci): persist legacy guards and orchestration scripts`

## Human Signature Status

HUMAN_SIGNATURE: PENDING.

Until signed, Phase A remains open and all B+ work remains blocked.
