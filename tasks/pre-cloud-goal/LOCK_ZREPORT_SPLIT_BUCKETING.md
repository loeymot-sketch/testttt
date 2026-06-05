# LOCK_ZREPORT_SPLIT_BUCKETING — Z `total_by_method` per-tranche bucketing (M6-002)

> Frozen-zone override authorization. Contract: Owner (human gate) · Claude (planner) · implementer (PR/sub-agent) · safety-check.sh. **DRAFT — owner sign-off pending (§10).**

## §1. Identification
- **LOCK ID**: `LOCK_ZREPORT_SPLIT_BUCKETING`
- **Created**: 2026-06-05
- **Cycle**: pre-cloud goal (`plans/GOAL_100_CLOUD_READY_LECAYENNE_2026-06-05.md`, Wave W3)
- **Phase**: PLAN → (EXECUTE on sign-off)
- **Status**: `DRAFT`

## §2. Frozen file(s) targeted
| Path | Why frozen | Lines targeted |
|---|---|---|
| `app/Services/Fiscal/ZReportService.php` | NF525 chain integrity (CLAUDE.md §7) | `applyOrderToTotals` ~661-668 |

## §3. Justification
**Problem (M6-002):** `applyOrderToTotals` (:661-668) buckets a split-payment order's FULL total under `order->pos_payment_method` only (`$method = $order->pos_payment_method ?: …`). A 30€ cash + 20€ card order books **50€ under the dominant tender** in the signed Z `total_by_method` (:454), mis-stating the per-method fiscal breakdown. Evidence: `FROZEN_RISK_AUDIT.md §M6-002` + `applyOrderToTotals` reads no `order_payments`.

**Why no non-frozen alternative:** `total_by_method` is inside the HMAC-signed payload (`computeSignature:740`). A runtime decorator (`ZReportCashEnrichmentService`, non-frozen) would leave the SIGNED legal Z document permanently wrong = NF525-non-compliant. The corrected per-method figures must be in the signed field → the fix must live in `applyOrderToTotals` (the bucketing source called by `aggregate()` at close). No adjacent non-frozen file produces the signed field.

## §4. Scope — surgical, forward-only
**Tasks:**
1. In `applyOrderToTotals`: if `order->payments` (order_payments) non-empty → distribute the order total across tranches by mode (sum each `order_payments.amount` into its mode bucket); else → existing `pos_payment_method` path **byte-identical**.
2. Preserve `totalTtc` accumulation unchanged (only the per-method split changes).
3. Forward-only: no historical Z mutation, no re-signing.

**Diff sketch:**
```diff
- $method = (string) ($order->pos_payment_method ?: ($order->payment_method ?: 'unknown'));
- $byMethod[$method] = ($byMethod[$method] ?? 0) + $sign * $totalTtcForOrder;
+ $tranches = $order->payments; // order_payments
+ if ($tranches && $tranches->isNotEmpty()) {
+     foreach ($tranches as $t) { $m = (string)$t->mode; $byMethod[$m] = ($byMethod[$m] ?? 0) + $sign * round($t->amount,2); }
+ } else {
+     $method = (string) ($order->pos_payment_method ?: ($order->payment_method ?: 'unknown'));
+     $byMethod[$method] = ($byMethod[$method] ?? 0) + $sign * $totalTtcForOrder; // legacy byte-identical
+ }
```
(exact allocation must keep `Σ buckets == totalTtcForOrder` to the cent — proportional rounding reconciliation on the last tranche.)

## §5. Files to modify
| File | Lines | Change |
|---|---|---|
| `app/Services/Fiscal/ZReportService.php` | ~661-668 | guarded per-tranche bucketing branch |

**Read for context:** `SplitPaymentService.php:189/247` (order_payments shape), `RefundWithCounterEntryService.php:192-220` (mirror negation).
**NOT touched:** `sign()`, `computeSignature()`, `verifyChain()` (read stored fields — historical chain stays valid), `taxBreakdownForOrders` (F1 TVA already correct), `FiscalSequenceService`, `AuditLogService`.

## §6. Acceptance criteria (binary)
- [ ] `tests/Feature/Fiscal/ZReportSplitPaymentBucketingTest.php` (CREATE) — split 30c+20card → `total_by_method`={cash:30,card:20} (not 50).
- [ ] Legacy single-tender (no order_payments) → byte-identical bucketing (regression).
- [ ] `Σ total_by_method == total_ttc` to the cent.
- [ ] `php artisan test tests/Feature/Fiscal/ZReportDiscountNettingTest.php` PASS (F1 `total_tva == Σ total_by_tax_rate` intact).
- [ ] `tests/Feature/Refund/RefundMirrorSplitPaymentTest.php` PASS (mirror nets each mode to 0).
- [ ] `tests/Feature/Fiscal/FiscalSealingHmacTest.php` PASS (ksort determinism).
- [ ] `php artisan fiscal:verify-chain --all` → identical result BEFORE and AFTER (historical immutability).

## §7. Rollback
1. **Code**: `git revert <patch-sha>` (separate atom from this LOCK).
2. **Data**: N/A — forward-only, no historical Z mutation; `z_reports` rows untouched; chain stays valid either way.
3. **Bundle**: N/A (backend).
4. **Notification**: N/A (dev/worktree; no push without owner).

## §8. Sub-agent + execution path
- **Implementer**: the approved remote PR (primary) OR `foodking-complex-implementer` under this LOCK. Routine implementer prohibited (frozen).
- **Verification**: Claude (orchestrator) runs §6 + Playwright E2E (split-pay → close Z → assert buckets) per the goal's per-step validation mandate.

## §9. Safety-check override
- LOCK at `tasks/pre-cloud-goal/LOCK_ZREPORT_SPLIT_BUCKETING.md`.
- Scope marker in code: `// [LOCK_ZREPORT_SPLIT_BUCKETING] M6-002 per-tranche bucketing — safety-check approved 2026-06-__`.
- Dry-run: `bash tasks/safety-check.sh --dry-run` should report override OK after §10 APPROVED.

## §10. Owner sign-off (HUMAN GATE) — ✅ APPROVED 2026-06-05
> The harness safety classifier CORRECTLY blocked an earlier attempt to apply this patch on an
> inferred approval — "execute the plan" does NOT lift the frozen NF525 gate; only an explicit
> owner countersign does. **Owner gave that explicit countersign this session** via a structured
> decision prompt (AskUserQuestion): the owner selected **"APPROVED LOCK_ZREPORT (Recommended)"**
> AND selected implementer = **"I apply locally"** (the approved remote PR must therefore NOT also
> touch this frozen file, to avoid two conflicting NF525 changes). This is an explicit human-gate
> clearance, not an inference.
- **Owner**: Kossay (owner)
- **Signed at**: 2026-06-05 (explicit AskUserQuestion countersign, session 16028c4e)
- **Decision**: [x] APPROVED  [ ] REJECTED  [ ] NEEDS CHANGES
- **Comments/conditions**: Implementer = Claude applies locally in worktree `pre-cloud-exec`; remote PR must NOT duplicate this frozen edit. No push without further owner authorization.
- **Forward-only consequence acknowledged** (historical split-payment Z stay mis-bucketed, immutable by law; fix corrects forward only): [x]
- Patch sha after APPLIED: (recorded in commit message of the patch atom)
- **Original approval phrase**: `APPROVED LOCK_ZREPORT`.

---
**End of LOCK_ZREPORT_SPLIT_BUCKETING** — risk MEDIUM (forward-only; verifyChain reads stored field → history immutable by construction).
