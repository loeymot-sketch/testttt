# Sub-1.c — Cash drawer / Z report / fiscal NF525 chain (READ-ONLY adversarial audit)

Date: 2026-06-27 · DB: foodking_e2e (fiscal_max branch1 = 2574) · APP_ENV=local · Repo HEAD branch pos/category-first-caisse-2026-06-23
Lens: CASHIER (trust the numbers? can an employee skim/defraud?)

## Verdict
No new P0/P1. **Zero real money escaped fiscalization.** One KNOWN/ESCALATED NF525 gap (P3) re-confirmed: Z window bounded by the *open* Z session, so sales rung up while no Z is open land in no signed Z — already tracked as P0 #1 (detect-only), production exposure ~2 min/night, fix deferred V1.0.X.

## FINDINGS

### F1 — Z aggregation window leaves "no-Z-open" sales uncounted (P3, KNOWN/ESCALATED — NOT new)
- **Evidence:** `app/Services/Fiscal/ZReportService.php:231` → `aggregate($branchId, $open->opened_at, $closedAt)`. Window is half-open `(opened_at, closed_at]` of the **current open Z** (not previous-Z.closed_at). `aggregate()` (`:337-357`) requires `fiscal_sequence_no IS NOT NULL`, `payment_status != UNPAID`, `created_at > from`, `status NOT IN (CANCELED/REJECTED/RETURNED)`. The post-window catch-up block (`:404-419`) only nets **negative** adjustments (cancel/return); a positive sale created while no Z is open is caught by neither side.
- **Reproduction:** `php artisan fiscal:verify-z-membership --branch=1` → header `2502 numbered order(s) flagged as cross-Z-window orphan CANDIDATE(s)`, rows `TROU — aucun Z ne couvre cette vente` (seq 2431, 2467-2488, etc.). DB cross-check: 2446 fiscalized non-terminal orders (sum 37 521 €) created within closed-Z coverage vs 23 closed `z_reports.total_ttc` summing only 99 €.
- **Why this is dev-artifact, not a production escape:** on this dev box the daily open/close cron never ran, so Z sessions were sporadic seconds-long manual cycles → almost every seeded/E2E order was created with no Z open. The detector header itself warns "heuristic may include legitimately-counted orders". In production the cron (`app/Console/Kernel.php:401` close 23:59 Paris / `:446` open 00:01 Paris) keeps a Z open ~24/7; residual gap ≈ 2 min/night, documented `GAP-HUNT 2026-05-25 PROPOSAL-Z-LOOP-GAP` (99.97 % mitigated; Path B business_date SSOT deferred V1.0.X with LOCK). Tracked: `reports/audit/massive-validation-2026-05-29/ESCALATION_NO_GO.md` (P0 #1).
- **Cashier-fraud assessment:** weak vector. To exploit, an operator must close the Z then ring sales before the next open — blocked in production by the auto-open cron, and every such sale still gets a gap-free fiscal number + is surfaced by `verify-z-membership` + audit chain. Not silently skimmable.
- **PENDING_COUNTER answer (Q6):** 91 PENDING_COUNTER orders on br1, **all 91 fiscal NULL + 0 money** → correctly excluded while queued (no money outside Z at close). On counter-pay they get fiscal and land in the then-open Z (cron keeps it open all day). Only the cross-day late-settle edge feeds F1.

## VERIFIED CLEAN (with proof)

| # | Check | Proof | Result |
|---|-------|-------|--------|
| T-1.c.1 | open/close/reconcile correctness | `CashDrawerService.php:52` openSession refuses double-open (I1: Cache::lock + `lockForUpdate` + UNIQUE partial idx → 409); `:126` close idempotent, 422 on reconciled; `:225` reconcile `expected=opening+Σ signed movements`, `variance=closing-expected`, gated threshold 2€+reason+permission | PosCashTrailTest **6/6 PASS** |
| T-1.c.2 | session ownership isolation | `CashDrawerSessionController.php:330` cross-branch close→403; `:332-333` POS-RED-04 same-branch non-owner→403 (manager w/ `cash.reconcile.variance.override` may close, audited as `closed_by_user_id`); CashDrawerSession under BranchScope | CashDrawerSessionOwnershipTest **3/3 PASS** |
| T-1.c.3 | sim-hardware flag + prod boot guard | `config/pos.php:37` `filter_var(env('POS_SIMULATION_HARDWARE',false),BOOL)`; `AppServiceProvider.php:172-178` throws RuntimeException in production if true; live APP_ENV=local sim=true (acceptable per V1 envelope) | Sentinel **4/4** + PosSimulationHardware4Scenarios **6/6 PASS** |
| T-1.c.4 | movement traceability / forged closing | `CashDrawerService.php:365` recordMovement: type whitelist + direction∈{in,out} + amount≥0 + session-must-be-OPEN (FOR UPDATE) + audit_logs write inside txn. Forged `closing_amount` cannot hide skim — variance is computed vs movements and gated | covered by PosCashTrail + reconcile gate |
| FISCAL-1 | NF525 dual-chain integrity | `php artisan fiscal:verify-chain --branch=1` → `CHAIN OK (audit_logs + z_reports)`; `--all` → CHAIN OK on 4/4 active branches (1,7,8,9) | PASS |
| FISCAL-2 | fiscal seq gap-free monotonic | `FiscalSequenceService.php:97-103` `MAX(withoutGlobalScope+withTrashed)+1`; br1 count=2571 min=1 max=2574 | FiscalSequenceTest **5/5 PASS** |
| FISCAL-3 | **REAL ORPHAN test** (money but no fiscal) | SQL: orders JOIN order_payments WHERE `fiscal_sequence_no IS NULL` AND status∉(16,19) HAVING SUM(amount)>0 → **count = 0** | CLEAN — no money escaped |
| FISCAL-4 | 19 PAID-without-fiscal rows | each independently JOINed to order_payments → **all pay_sum = 0** (factory seed, no money) | P3 benign, confirmed |
| FISCAL-5 | gap 2506-2508 benign re-verify | audit_logs#4472-4482 reference seq 2506/2507/2508 = hard-deleted ABUSE-* refund-bypass test orders #5012/5013/5015, each +20€ then `order.returned` -20€ (net 0); `orders` rows hard-gone (withTrashed=0) but lifecycle preserved in immutable chain; MAX=2574 → no re-use | CLEAN — chain intact, not masking escape |
| FISCAL-6 | counter-cash lifecycle + Z membership | fiscal allocated only on confirm, idempotent, none on cancel/reprint | FiscalCashAtCounterLifecycle **3/3** + VerifyZMembershipCommand **3/3 PASS** |

## Notes
- Z close logic (`ZReportService.php:180-286`) counts `payment_status != UNPAID` AND `fiscal_sequence_no NOT NULL`, `withTrashed()` (soft-deleted post-alloc orders still counted), refund mirrors netted (`:381-387`), `warnOnOrphanedPaidOrders` (`:611`) logs PAID-without-fiscal in-window (observability only).
- `idempotency.enabled=true`, `kiosk.payment_route_all_to_counter=true` (Plan B), `cash.variance_threshold_eur=2.0` confirmed live.
- All tests run on sqlite :memory: (safe). No files modified (read-only audit honored).
