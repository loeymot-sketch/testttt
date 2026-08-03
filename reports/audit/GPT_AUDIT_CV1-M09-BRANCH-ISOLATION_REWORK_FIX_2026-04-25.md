# GPT AUDIT — CV1-M09-BRANCH-ISOLATION REWORK FIX

Date: 2026-04-25
Audit channel: GPT/Codex local only
Claude: not used

## Scope

M-09 branch isolation was reworked after `GPT_SELF_AUDIT_CV1-M09-BRANCH-ISOLATION.md` returned `VERDICT: NEEDS_FIX`.

Applied fixes:

- `branch_id` filters in `OrderService` and `FrontendOrderService` use exact equality.
- `branch_id=0` global authority is restricted to real global Admin (`Admin` role + `branch_id=0`) in M-09 service paths.
- POS order show now returns explicit 403 for cross-branch staff instead of route-model 404.
- Transaction list now force-scopes non-global users to their own branch and rejects foreign `branch_id`.
- OSS order screen now rejects `branch_id=0` for non-global staff and allows it for global Admin.
- Static lint blocks product `branch_id LIKE` filters.

## Invariants

- pricing_ssot: OK — no pricing logic was added to frontend or moved out of backend.
- order_status: OK — no new magic order status strings were introduced.
- branch_id: PASS for M-09 — exact filters and explicit cross-branch guards are now covered.
- commit_before_dispatch: N/A — no dispatch path was added or moved.
- frozen_zones: OK — `GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25` is Approved Option C and the rework is restricted to M-09 branch-guard surfaces.
- order_service_symmetry: OK — `OrderService` and `FrontendOrderService` both handle `branch_id` as exact equality.

## Validation

PASS:

- `php -l app/Services/OrderService.php`
- `php -l app/Http/Controllers/Admin/PosOrderController.php`
- `php -l app/Services/TransactionService.php`
- `php -l app/Http/Controllers/Admin/TransactionController.php`
- `php -l app/Services/OrderStatusScreenOrderService.php`
- `php -l app/Http/Controllers/Admin/OrderStatusScreenController.php`
- `bash scripts/lint-fk-branch-isolation.sh`
- `php artisan test --filter=OrderListBranchExactnessSentinelTest`
- `php artisan test --filter=OrderShowBranchGuardSentinelTest`
- `php artisan test --filter=TransactionBranchExactnessSentinelTest`
- `php artisan test --filter=OssAdminBranchPolicySentinelTest`
- `php artisan test --filter='OrderBranchIsolationTest|OssAdminBranchPolicyTest|OrderListBranchExactnessSentinelTest|OrderShowBranchGuardSentinelTest|TransactionBranchExactnessSentinelTest|OssAdminBranchPolicySentinelTest'` — 7 passed

Expected out-of-scope failures documented:

- `php artisan test --filter=Branch` — fails only on `FiscalZBranchExactnessSentinelTest` (M-08 fiscal), `PaymentConfirmCrossBranchSentinelTest` (M-06), and `QueueNumberUniquenessSentinelTest` (schema gate).
- `php artisan test --filter=Sentinel` — M-09 sentinels pass; remaining failures are M-06 POS/payment-confirm, M-07 KDS, M-08 fiscal, and schema/queue uniqueness.

## Verdict

VERDICT: PASS

M-09 is acceptable to close. Remaining sentinel failures are explicitly outside M-09 and must stay in their gated missions.
