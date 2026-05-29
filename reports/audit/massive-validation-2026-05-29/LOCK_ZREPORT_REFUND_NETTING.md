# 🔒 LOCK — ZReportService refund-netting (P0 #2)

**LOCK ID** `LOCK_ZREPORT_REFUND_NETTING` · **Cycle** massive-validation-2026-05-29
**Frozen file** `app/Services/Fiscal/ZReportService.php` (CLAUDE.md §7, NF525)
**Scope** `surgical` · **Status** ✅ OWNER-SIGNED (chat decision 2026-05-29: "Aggregate-side netting" + "Authorize me under lock-plan")

## 1. Why frozen / why a LOCK
`ZReportService` produces the HMAC-signed, 6-year-retained NF525 daily Z. Any change to the aggregation alters legally-signed totals → §7 frozen + §10 human gate. Owner authorized under lock-plan discipline (sequential, mandatory PHPUnit, 0 regression, chain re-verify, no push, owner reviews diff after).

## 2. The defect (P0 #2 — grounded in code, not assumption)
- The real refund flow `app/Services/Order/RefundWithCounterEntryService.php:108-130` creates a **separate mirror** Order: `status=RETURNED`, `payment_status=REFUNDED`, `total = -1×parent.total` (pre-negated), `fiscal_sequence_no` = fresh current-window seq, `parent_order_id` set, **`created_at = now` (current window)**, + negated `order_payments` (182-207).
- In `ZReportService::aggregate`:
  - `$orders` (line 355-357) excludes `RETURNED` (in `$terminalStatuses`) → mirror not in positive revenue. ✓
  - `$preZRefundCount` (380-382) **counts** the mirror (refund_count) but does NOT apply its total. 
  - post-Z adjustment block (386-402) requires **`created_at <= $from`** — the mirror's `created_at` is **in-window (`> $from`)** → **missed**.
- ⇒ the mirror's negative `total` reaches the signed `total_ttc/total_ht/total_tva` **nowhere**. Every post-Z counter-entry refund **overstates** the signed daily Z by the refund amount. Empirical repro (campaign): `total_ttc=0` vs expected `-55`.
- **Why the existing `tests/Feature/Fiscal/RefundPostZTest.php` did not catch it:** it tests the *status-flip-in-place* shape (`created_at` in a PRIOR window, positive total, **no `parent_order_id`**), which the post-Z block DOES catch. The counter-entry-mirror shape is uncovered.

## 3. The fix (aggregate-side netting — owner's choice)
Apply in-window **counter-entry mirrors** (`status=RETURNED` AND `parent_order_id IS NOT NULL`, within `$windowQuery`) via `applyOrderToTotals($m, +1, …)` — their `total` is already negated, so `+1×(-55) = -55` lands in the signed `total_ttc/total_ht/total_tva/byMethod`.

```php
// after the positive $orders loop (≈ line 369), before/near the post-Z block:
// [LOCK_ZREPORT_REFUND_NETTING / P0 #2] Counter-entry refund mirrors
// (RefundWithCounterEntryService) are created IN the current window
// (created_at=now) with a pre-NEGATED total + parent_order_id, so they miss BOTH
// the positive $orders loop (RETURNED excluded) AND the post-Z block (which needs
// created_at<=$from). Apply each here with its already-negated total so the refund
// nets into the signed Z. Status-flip-in-place refunds (parent_order_id NULL) stay
// evidence-only per M-08 (they net via $orders exclusion) — untouched.
$counterEntryMirrors = (clone $windowQuery)
    ->where('status', OrderStatus::RETURNED)
    ->whereNotNull('parent_order_id')
    ->get();
foreach ($counterEntryMirrors as $m) {
    $this->applyOrderToTotals($m, 1, $totalTtc, $totalHt, $totalTva, $byMethod);
}
```
**No double-count:** post-Z block needs `created_at<=$from` (mirror is in-window); `$preZRefundCount` is count-only. **refund_count** already includes the mirror (no change). **Same-window sale+refund** now nets to 0 (parent +55 in `$orders`, mirror −55 here) — also a correctness gain.

## 4. Scope / blast radius
- ONE added block in `aggregate()`. No signature/HMAC logic touched (sealing untouched — totals feed the same `signZReport`). `applyOrderToTotals` unchanged.
- `tax_breakdown` (`taxBreakdownForOrders`) — the mirror's negated `order_items.tax_amount` are already in the DB; if the mirror's order_ids should feed the per-rate breakdown, that is a SEPARATE consideration (V1 = 0% VAT → tax breakdown empty → no effect now). Noted, not changed.

## 5. TDD test (write FIRST — must be RED before the fix)
`tests/Feature/Fiscal/RefundCounterEntryNettedInZTest.php`: create a parent (prior window, +55, sealed) + a counter-entry mirror via the real `RefundWithCounterEntryService` (or a faithful mirror row: created_at in-window, total=-55, parent_order_id set, RETURNED, seq set, payment_status REFUNDED); `aggregate()` over the current window; assert `total_ttc ≈ -55` and `refund_count = 1`. Must FAIL pre-fix (asserts the bug), PASS post-fix.

## 6. Verification protocol (all must pass before commit)
1. New test RED → GREEN.
2. `php artisan test --filter=ZReport` + `--filter=Fiscal` + `RefundPostZTest` (the status-flip test MUST still pass = 0 regression).
3. `php artisan fiscal:verify-chain --all` → CHAIN OK (the fix changes future aggregation, not existing signed rows).
4. Frozen-zone diff: ONLY ZReportService.php changed, ONLY the §3 block.
5. advisor review of the diff (mandatory — my fiscal reasoning was wrong 3× this session).

## 7. Rollback
`git revert <patch-sha>`. No data migration; the change only affects how FUTURE Z aggregations sum already-persisted mirror rows. Existing signed Z rows are immutable + unaffected (their signatures stay valid). If a regression appears, revert restores the prior (overstating-but-stable) behavior — no data corruption either way.

## 8. Sub-agent
Orchestrator applies directly (surgical, single block) — NOT delegated.

## 9. NF525 attestation
Pre + post: `php artisan fiscal:verify-chain --all` must report CHAIN OK. The patch does NOT delete/mutate any existing `z_reports`/`audit_logs` row.

## 10. Human gate — OWNER SIGN-OFF
✅ **SIGNED 2026-05-29** via chat AskUserQuestion: owner selected "Aggregate-side netting" for P0 #2 and "Authorize me under lock-plan" for the frozen-zone gate. Implementation authorized under the §6 protocol; owner reviews the committed diff post-hoc (no push).
