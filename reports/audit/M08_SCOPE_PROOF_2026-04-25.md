# M08_SCOPE_PROOF — CV1-M08-FISCAL-Z-NF525

FOODKING_GPT_ONLY: 1
AUDIT_CHANNEL: gpt-codex

## Scope

M08 rework was limited to:

- `app/Services/Fiscal/FiscalSealingService.php`
- `tests/Feature/Fiscal/FiscalSealingHmacTest.php`
- `tests/Feature/Sentinels/FiscalZBranchExactnessSentinelTest.php`
- `missions/CV1-M08-FISCAL-Z-NF525/input.json`
- `missions/CV1-M08-FISCAL-Z-NF525/plan_excerpt.md`
- `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md`
- `reports/post_execute_latest.log`
- `reports/audit/GPT_SELF_AUDIT_CV1-M08-FISCAL-Z-NF525.md`
- `reports/audit/GPT_AUDIT_CV1-M08-FISCAL-Z-NF525_REWORK_FIX_2026-04-25.md`
- `plans/masterplay/MASTERPLAY_QUEUE.md`
- `reports/masterplay/status.json`

## Non-M08 Worktree Noise

The repository already contains many modified and untracked files from prior Caisse V1 missions and generated public assets. Those files were not reverted and are not claimed as part of this M08 rework.

The M08 scoped whitespace check passed:

`git diff --check -- app/Services/Fiscal/ZReportService.php app/Services/Fiscal/FiscalSealingService.php app/Services/FrontendOrderService.php tests/Feature/Fiscal/ZAggregationKioskRoutingTest.php tests/Feature/Fiscal/RefundPreZTest.php tests/Feature/Fiscal/RefundPostZTest.php tests/Feature/Fiscal/VoidPreZTest.php tests/Feature/Fiscal/FiscalSealingHmacTest.php tests/Feature/Fiscal/FiscalArchiveTtlTest.php tests/Feature/Sentinels/FiscalZBranchExactnessSentinelTest.php reports/post_execute_latest.log reports/audit/M08_SCOPE_PROOF_2026-04-25.md reports/audit/GPT_AUDIT_CV1-M08-FISCAL-Z-NF525_REWORK_FIX_2026-04-25.md reports/audit/GPT_SELF_AUDIT_CV1-M08-FISCAL-Z-NF525.md plans/masterplay/MASTERPLAY_QUEUE.md reports/masterplay/status.json`

After the scope rework, the same scoped check also included `missions/CV1-M08-FISCAL-Z-NF525/input.json`, `missions/CV1-M08-FISCAL-Z-NF525/plan_excerpt.md`, and `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md`; it passed.

## Scope Rework

The first M08 final audit correctly rejected the sentinel fixture change because the mission required `FiscalZBranchExactnessSentinelTest` to pass but omitted the file from `input.json.allowlist` and the master plan M08 allowlist. The mission and plan were updated to authorize this mandatory sentinel fixture alignment explicitly.

## Invariants

- `pricing_ssot`: OK, no frontend pricing logic changed.
- `order_status`: OK, fiscal tests and service code use `OrderStatus::*`.
- `branch_id`: OK, Z aggregation remains `branch_id` exact and sentinel now validates only sequenced fiscal rows.
- `commit_before_dispatch`: N/A, no dispatch path changed in this rework.
- `frozen_zones`: OK, frozen gate was approved before M08 ran.
- `order_service_symmetry`: OK, `FrontendOrderService` M08 touch is kiosk fiscal Option B documentation/guard only; `OrderService` remains the POS fiscal sequencing path and was reviewed.
