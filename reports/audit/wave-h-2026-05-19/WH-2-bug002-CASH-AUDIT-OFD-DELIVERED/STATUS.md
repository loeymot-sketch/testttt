# WH-2 — bug_002 Cash Audit Lost on Canonical 2-Step Driver Flow

**Date** : 2026-05-19  
**Branch** : `heal/cms-pr1-quickwins-2026-05-18`  
**Wave** : H (Heal-from-Ultra-Review)  
**Owner** : autonomous master sub-agent

---

## 1. Bug Recap (root cause analysis)

**File** : `app/Services/OrderService.php` — `deliveryBoyOrderChangeStatus()` (lines 1574–1588 pre-heal).

**Pre-heal symptom** : the canonical driver workflow consists of TWO HTTP `POST /api/frontend/delivery-boy-order/change-status/{id}` calls:

```
Call 1 :  status: 8   (PREPARED)      ->  status: 10  (OUT_FOR_DELIVERY)
Call 2 :  status: 10  (OUT_FOR_DELIVERY) ->  status: 13  (DELIVERED)
```

Pre-heal control flow per call:

| Call | `$wasUnpaidCash` | PAID flip (line 1574) | Inner audit gate (line 1575) | Escrow row written |
|---|---|---|---|---|
| 1 (PREPARED → OFD)   | **true** (was UNPAID + no Transaction row) | **fires** (newStatus=10, but flip unconditional within `wasUnpaidCash`) | **fails** (newStatus≠DELIVERED) | **NO** |
| 2 (OFD → DELIVERED) | **false** (already PAID from call 1) | block skipped | block skipped | **NO** |

**Net** : every CASH_ON_DELIVERY order routed through the canonical 2-step driver flow leaves **ZERO** `delivery.cash_collected_escrow` rows in `audit_logs`. NF525 escrow-trail violated.

**Secondary semantic bug** : `payment_status` flipped to PAID at OFD (pickup at kitchen counter), BEFORE the driver actually collected cash at the doorstep. Legally wrong — the PAID flip is the formal anchor of cash receipt under NF525.

**Why the legacy sentinel didn't catch it** : `DeliveryBoyHardeningSentinelTest::test_p0_liv_02_cash_delivery_writes_escrow_audit_log` seeds the order DIRECTLY at `OUT_FOR_DELIVERY` and exercises a single OFD → DELIVERED jump. On that single-call path, the PAID flip and the audit gate both fire in the same transaction, so the bug never manifests in the test.

## 2. Heal Strategy — Option A (ultra-review pick) refined

Approach :

1. **CASH_ON_DELIVERY** : gate BOTH the `payment_status → PAID` flip AND the escrow audit row capture on `newStatus === DELIVERED`. Cash is collected at the doorstep — never at pickup.
2. **Non-COD methods (E_WALLET / PAYPAL / CARD / TICKET_RESTAURANT)** : preserve the prior flip-at-OFD-or-DELIVERED behaviour verbatim, to avoid regressing the unusual but observed edge case of a non-COD order reaching the driver still UNPAID (late card capture). These methods never wrote escrow rows anyway, so no audit semantics change.

This option is **semantically correct** (PAID == cash truly collected) **and** closes the audit gap on both the 2-step and the 1-step driver paths.

Alternatives rejected :

- **Option B** (write audit at OFD too) — less correct: cash NOT yet collected at pickup; emits a row that contradicts NF525 ledger semantics.
- **Option C** (capture pre-flip `payment_status` into a separate boolean) — preserves the wrong-anchor flip and adds dead state. Option A is cleaner.

## 3. Implementation

**Diff scope** : single function, single locked transaction block. Zero frozen-zone touch. Patch is +66/-15 lines in `app/Services/OrderService.php`. The new logic re-uses the existing `$isCod` discriminator instead of adding new state.

**File path** : `app/Services/OrderService.php` lines 1570–1622 (post-heal numbering).

```php
$isCod = ((int) $pm === (int) \App\Enums\PaymentGateway::CASH_ON_DELIVERY);
$atDelivered = ((int) $newStatus === (int) OrderStatus::DELIVERED);

if ($isCod) {
    if ($atDelivered) {
        $locked->payment_status = PaymentStatus::PAID;
        // P0-LIV-02 escrow capture (atomic with PAID flip).
        $cashEscrowWritten = true;
        $cashEscrowMeta = [...];
    }
    // CASH_ON_DELIVERY at OFD: leave UNPAID — flip + audit on next call.
} else {
    // Non-COD: preserve legacy flip-at-OFD-or-DELIVERED semantics.
    $locked->payment_status = PaymentStatus::PAID;
}
```

## 4. Sentinel (TDD)

**New file** : `tests/Feature/Sentinels/CashAudit2StepDriverFlowSentinelTest.php` (4 tests).

| # | Test | Asserts |
|---|---|---|
| 1 | `test_canonical_2step_prepared_ofd_delivered_writes_exactly_one_escrow_audit_row` | PREPARED→OFD→DELIVERED writes exactly 1 escrow row; UNPAID at OFD; PAID at DELIVERED; correct payload (amount, driver, method, branch). |
| 2 | `test_single_jump_ofd_to_delivered_still_writes_escrow_row` | Regression guard — direct OFD→DELIVERED still emits exactly 1 escrow row + flips to PAID. |
| 3 | `test_non_cash_methods_never_emit_an_escrow_row_on_2step_flow` | Iterates CARD, E_WALLET, PAYPAL, TICKET_RESTAURANT — none emit `delivery.cash_collected_escrow`. |
| 4 | `test_cash_on_delivery_payment_status_flips_at_delivered_not_at_ofd` | Semantic anchor — for CASH_ON_DELIVERY, PAID flip happens AT DELIVERED, not at OFD. Exactly 1 row per order. |

### RED → GREEN

```
PRE-HEAL  : 2 failed (tests 1 + 4), 2 passed (tests 2 + 3 — regression guards).
POST-HEAL : 4 passed (1.04s).
```

The 2 failing assertions before the heal both targeted line `assertSame(PaymentStatus::UNPAID, ...)` after PREPARED→OFD — the exact semantic anchor of the bug.

## 5. Regression Suite

```
$ php artisan test --filter "Refund|Delivery|Order|CashTrail"
Tests: 690 passed, 1 incomplete (pre-existing Owner-finalize on RushMidi S72),
       1 skipped (pre-existing), 87.59s
```

`DeliveryBoyHardeningSentinelTest` (11 tests, including the legacy `test_p0_liv_02_*`) → 11/11 passed. No regression.

## 6. NF525 Chain Attest

Real DB (production audit_logs chain) :

| Phase | `count` | `last_hash` (first 16) | Verify |
|---|---|---|---|
| PRE-heal  | 97 | `af02d7895d412654` | `CHAIN OK (audit_logs + z_reports) (branch=1)` |
| POST-heal | 97 | `af02d7895d412654` | `CHAIN OK (audit_logs + z_reports) (branch=1)` |

**Bit-identical** — the heal is pure-code-only, no production chain row written. APPENDED-ONLY invariant preserved. Per-delivery the chain will advance by exactly one row when a real driver completes a COD delivery (single-call OR 2-call path).

## 7. Frozen-Zone & Scope

- `app/Services/OrderService.php` — NOT in the frozen-zone list (only fiscal services, BranchScope, PricingService, OrderStateMachine, IdempotencyKeyMiddleware are frozen).
- Zero touch to `FiscalSequenceService`, `ZReportService`, `AuditLogService`, `BranchScope`, `PricingService`, `IdempotencyKeyMiddleware`, `OrderStateMachine`.
- Zero touch to wizard / kiosk / POS Vanilla JS.
- Zero migrations.

## 8. Commit

```
fix(orderservice-bug002): NF525 cash audit row written on canonical 2-step driver flow + PAID flip at DELIVERED
```

Files touched in commit :
- `app/Services/OrderService.php` (+66 / -15)
- `tests/Feature/Sentinels/CashAudit2StepDriverFlowSentinelTest.php` (NEW, +318)
- `reports/audit/wave-h-2026-05-19/WH-2-bug002-CASH-AUDIT-OFD-DELIVERED/STATUS.md` (NEW, this file)

## 9. Decision

**continue** — production-ready.

- TDD RED → GREEN demonstrated.
- 690 regression tests green.
- NF525 chain bit-identical.
- Frozen-zone diff = 0.
- Semantic correctness: PAID flip now at the legal anchor of cash receipt.
- Audit invariant: exactly one `delivery.cash_collected_escrow` row per COD delivery on BOTH single-jump and 2-step driver paths.
