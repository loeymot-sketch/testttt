# PLAN EXCERPT — CV1-M09-BRANCH-ISOLATION

Source: `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md`

## M-09 — CAISSE_V1_BRANCH_ISOLATION_2026-04-25

Gate: `GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25` approved on 2026-04-25.

Human decision: Option C — Partial allowlist by method/surface.

Goal: eliminate the `branch_id LIKE` leaks listed by the parent plan and formalize the `branch_id=0` policy.

Precise tasks:

1. `OrderService.php:151,194,230,267` — detect `branch_id` in `$orderFilter` and use strict `where('branch_id', '=', $value)`; keep LIKE for other text fields.
2. `OrderService.php:1920` — `salesReportOverview` else branch must use exact branch equality.
3. `FrontendOrderService.php:99` — exact branch equality.
4. Audit `branch_id=0`: `posOrderStore:610`, `destroy:1793-1795` — formalize policy as admin global only and cover with `OssAdminBranchPolicyTest`.
5. Add a static lint rule blocking `where('branch_id', 'like')`.

Allowlist: `app/Services/OrderService.php`, `app/Services/FrontendOrderService.php`, `tests/Feature/Branch/*`, `scripts/lint-fk-branch-isolation.sh`.

Mandatory tests: sentinels #7-#11 pass green, branch isolation tests pass, branch lint passes.

SYMMETRY_NOTE required: both OrderService and FrontendOrderService are touched.

## Rework clarification — 2026-04-25

GPT self-audit reported `VERDICT: NEEDS_FIX` because sentinels #8, #9, #10 and #11 were still red.

Allowed M-09 rework surfaces are expanded only for sentinels explicitly marked `@fix-mission CV1-M09-BRANCH-ISOLATION`:

- #8 `OrderShowBranchGuardSentinelTest`: `OrderService::show` and `PosOrderController::show`.
- #9 `TransactionBranchExactnessSentinelTest`: `TransactionService::list` and `TransactionController`.
- #11 `OssAdminBranchPolicySentinelTest`: `OrderStatusScreenOrderService::list` and `OrderStatusScreenController`.

Not in M-09 scope:

- #10 `FiscalZBranchExactnessSentinelTest`, marked `@fix-mission CV1-M08-FISCAL-Z-NF525`, remains blocked by fiscal gate and must not be fixed here.
