# Wave W2 — T-1.2.5 Fiscal Audit (Round 3)
## Refund post-Z + Void pre-Z + Sealed-order — NF525 Compliance

**Mission:** `goal-ultra-central-mgmt-sync-2026-05-18` | Round 3 / W2 / T-1.2.5
**Role:** FISCAL — DGFiP / expert-comptable mindset (read-only Read + Bash grep)
**Anchors verified line-by-line:**
- `app/Services/Fiscal/FiscalSealingService.php:1-116` (HMAC canonicaliser, prod-secret gate)
- `app/Services/Order/RefundWithCounterEntryService.php:37-234` (mirror counter-entry)
- `app/Services/Fiscal/AuditLogService.php:1-375` (frozen — HMAC chain, per-branch lock)
- `app/Services/Order/SealedOrderGuard.php:1-120` (sealed-Z predicate, dual assertMutable/assertSealed)
- `app/Services/Fiscal/ZReportService.php:1-727` (aggregate at line 297-435)
- `app/Services/OrderService.php:1743-1834` (changeStatus → RETURNED, guard wiring)
- `tests/Feature/Fiscal/{RefundPostZ, RefundPreZ, VoidPreZ, SealedOrderMutationGuard}Test.php`
- `tests/Feature/Refund/RefundMirrorSplitPaymentTest.php`
- `app/Console/Commands/FiscalArchiveCommand.php:46-355` (6-year archive)
- `database/migrations/2026_04_22_000002_create_audit_logs_table.php:85-141` (BEFORE DELETE trigger)
- `config/fiscal.php:117-148` (`sealed_z_guard_enabled`, `archive_retention_years=6`)
**Cross-ref Round 2:** none on T-1.2.5; closest is Round 2/W3 ARCH F1 flagging AuditLog+ZReport unscoped (latent V2-SaaS, not blocking V1).
**Date:** 2026-05-18

---

## Verdict — **NO-GO V1 — CRITICAL NF525 P0**

The mirror counter-entry post-Z refund path produces an Order row whose **NEGATIVE total is silently dropped from `ZReportService::aggregate()`**. Operational reality (cash drawer drained, card refund, customer reimbursed) is recorded in `order_payments` and `audit_logs`, but the SIGNED daily Z **over-reports gross revenue by the refunded amount** and under-reports `refund_count`'s monetary impact. Sub-finding chain: 2 P0 (aggregate silent drop / TVA breakdown silent drop) + 3 P1 (refund-document-type indicator absent / partial-refund unsupported / `RefundChainNumberingTest` declared in plan but missing on disk) + verified-OK (6y retention / DELETE triggers / actor traceability / chain crossing Z / `ZReportCashEnrichmentService` does not touch signed columns). DGFiP audit would catch the P0 chain on the first cross-check of `total_by_method[card]` against the acquirer settlement.

---

## §1 — Evidence Trail

### §1.1 — Mirror counter-entry mechanics (verified)
`RefundWithCounterEntryService::execute()` (lines 52-233):
- `parent.fiscal_sequence_no` required (line 54) — pre-condition.
- `SealedOrderGuard::assertSealed($parent)` (line 70) — refuses pre-Z parents → mirror is for post-Z ONLY.
- `FiscalSequenceService::next($branchId)` allocates a fresh, strictly monotonic number (line 90). Shared series with sales — see §2 design note.
- Mirror Order created with `status=RETURNED, payment_status=REFUNDED, total/subtotal/total_tax × -1, parent_order_id=parent.id, order_serial_no='RTN-'.parent.serial, order_datetime=now()` (lines 95-117). `created_at` is implicit `now()`.
- Items cloned with `quantity × -1, tax_amount × -1, tax_rate/tax_name/tax_type/composition_snapshot preserved verbatim` (lines 121-143).
- OrderPayment tranches cloned with `amount × -1, reference || '-REFUND', terminal_id carried` (lines 163-192) — H2-fix P1-Z7-01 + iter15-P0-10.
- Audit row written with `action='order.refund.counter_entry', payload={parent_order_id, parent_serial_no, parent_fiscal_sequence_no, mirror_fiscal_sequence_no, mirror_total, reason}` (lines 195-210). `user_id` resolved from `Auth::id()` (line 85). Stamped via `AuditLogService::write` → HMAC chained to the LAST audit row of that branch, **independent of any Z boundary** (verified `AuditLogService::lastHashFor($branchId)` line 245-254 — pure tail, no Z filter).

### §1.2 — Sealed-Z guard wiring (verified)
- `OrderService::changeStatus → RETURNED` (line 1754-1776) calls `SealedOrderGuard::assertMutable` → throws `OrderSealedException` (HTTP 409) → operator MUST re-route to `POST /api/admin/pos-order/{order}/refund-with-counter-entry`.
- `OrderService::changePaymentStatus → REFUNDED` (line 1947-1948) — same guard.
- Feature flag `fiscal.sealed_z_guard_enabled` default `true` (`config/fiscal.php:117`, env `SEALED_Z_GUARD_ENABLED`). When `false`, legacy in-place mutation path opens. Production = guard ON by default → **mirror path is the only sanctioned post-Z refund** in V1.

### §1.3 — Z aggregator (verified disqualifying trace)
`ZReportService::aggregate($branchId, $from, $to)` (lines 297-435), the SOLE algorithm fed to `close()` line 231 then `sign()` line 239:
1. `$baseQuery` = `Order::withoutGlobalScope(BranchScope)->withTrashed()->where('branch_id', $branchId)->whereNotNull('fiscal_sequence_no')->where('payment_status', '!=', UNPAID)` — mirror has `fiscal_sequence_no` + `payment_status=REFUNDED`: **PASS**.
2. `$windowQuery = $baseQuery->where('created_at', '<=', $to)` and `->where('created_at', '>', $from)` (line 343-347) — mirror's `created_at = now()` IS in current Z: **PASS**.
3. `$orders = $windowQuery->whereNotIn('status', [CANCELED, REJECTED, RETURNED])` (line 355-357) — mirror has `status=RETURNED`: **EXCLUDED**. Only path that feeds `applyOrderToTotals($o, +1, totalTtc, ...)` (line 366-369) — mirror's negative total is NOT applied here.
4. `$preZRefundCount = $windowQuery->where('status', RETURNED)->count()` (line 380-382) — mirror is **COUNTED** here, but `count()` does not consume `total`. The counter increments; the EUR sum does not.
5. `$postZAdjustmentQuery = $baseQuery->where('created_at', '<=', $from)->where('updated_at', '>', $from)->where('updated_at', '<=', $to)` (line 387-390) — mirror's `created_at = now()` is `> $from`, NOT `<= $from`: **EXCLUDED**. This is the only path that calls `applyOrderToTotals($o, -1, ...)` (line 399-401) for refunds.
6. `taxBreakdownForOrders($orders->pluck('id'), 1, ...)` (line 415) and `taxBreakdownForOrders($adjustmentOrderIds, -1, ...)` (line 417) — mirror's id is in NEITHER set. The per-line `tax_amount = -X` (correctly set at line 132 of RefundWithCounterEntryService) is silently dropped from `total_by_tax_rate`.

**Discriminating grep** on `ZReportService.php` for `parent_order_id|counter_entry|mirror|RTN-` → **0 hits**. There is no alternative aggregation path. Conclusion confirmed.

### §1.4 — Test coverage gap (verified)
- `RefundPostZTest::test_returned_order_after_previous_z_is_counted_as_negative_adjustment` (line 17-53) exercises the **LEGACY** path: parent.status forced to RETURNED, parent.created_at=$from->subDay, parent.updated_at=$from->addHour. This is the `$postZReturned` branch — works because parent is mutated in place. It does NOT exercise the new mirror counter-entry contract.
- `SealedOrderMutationGuardTest::test_refund_with_counter_entry_creates_mirror_order_with_negated_total` (line 117-140) asserts mirror.total=-50 but never opens a Z, closes it, and checks `total_ttc`. The Z signature step is never run against a mirror.
- `RefundMirrorSplitPaymentTest::test_refund_z_reconciliation_under_credits_zero_after_fix` (line 141-165) checks per-mode payment NET = 0 by summing `order_payments.amount` directly — bypasses `ZReportService::aggregate()` entirely. So even this test does NOT detect the aggregate bug.
- `plans/GOAL_ULTRA_CENTRAL_MGMT_SYNC_2026-05-18.md` line 182 declares `RefundChainNumberingTest.php` "TO BE CREATED" — **does not exist on disk** (`find tests -name 'RefundChain*'` returns 0).

### §1.5 — Compliance scaffolding (verified OK)
- 6-year retention: `config/fiscal.php:148 archive_retention_years=6`; `FiscalArchiveCommand` produces a `manifest.json` with `retention_years:6` (line 237) and includes z_reports + orders + audit_logs JSONL (line 228-230). `PruneOutboxCommand:25-27` and `PruneWebhookEventsCommand:37` explicitly document "audit_logs + z_reports NEVER touched". No prune command targets audit_logs.
- DELETE/UPDATE triggers on audit_logs (`migration 2026_04_22_000002` line 85-141) — MySQL/SQLite covered. z_reports DELETE trigger `migration 2026_05_09_160000`. cash_movements + cash_drawer_sessions + order_payments DELETE triggers `migration 2026_05_10_010000`.
- Actor traceability: `RefundWithCounterEntryService:85` → `$userId ?? Auth::id()`; audit payload preserves `parent_serial_no` + `parent_fiscal_sequence_no` + `reason`.
- `ZReportCashEnrichmentService.php:225-263` (cross-checked): pure additive decorator on cash columns post-signature. Comment line 228-229 confirms "ne touche PAS aux champs signés (total_*, *_count). Ne touche PAS la colonne signature." → not an alternate aggregation path. Cannot mask F1/F2.

---

## §2 — Findings (2 P0 / 3 P1 / 1 design note)

### F1 — Mirror counter-entry NOT aggregated in Z totals (P0 — fiscal P0)

```yaml
finding_id: T-1.2.5-FISCAL-F1
severity: P0
classification: COMPLIANCE + TECHNICAL
trigger:
  - app/Services/Order/RefundWithCounterEntryService.php:107 mirror.order_datetime=now(); implicit created_at=now()
  - app/Services/Fiscal/ZReportService.php:355-357 $orders excludes status=RETURNED
  - app/Services/Fiscal/ZReportService.php:387-390 postZAdjustmentQuery requires created_at <= $from, mirror does not satisfy
  - app/Services/Fiscal/ZReportService.php:380-382 preZRefundCount increments but does not consume total
  - app/Services/Fiscal/ZReportService.php aggregator has 0 references to parent_order_id / mirror / counter_entry
  - tests/Feature/Fiscal/RefundPostZTest exercises LEGACY path only, never the mirror through aggregate()
failure_mode:
  v1: |
    Le Cayenne, single-tenant. Day N close at 23:59 signs Z with
    total_ttc=1000€. Day N+1 09:00 a 50€ post-Z refund is processed
    via /api/admin/pos-order/{id}/refund-with-counter-entry. Mirror
    Order created with total=-50, status=RETURNED, fiscal_sequence_no=X+1,
    audit row 'order.refund.counter_entry' chained. Cash drawer drained
    50€ (cashier physically refunds). Day N+1 23:59 Z close runs
    aggregate(opened_at_N+1, closed_at_N+1):
      orders set: mirror EXCLUDED (status=RETURNED)
      postZ adjustment set: mirror EXCLUDED (created_at > opened_at_N+1)
      preZRefundCount = 1, BUT applyOrderToTotals never called for mirror
    Signed Z_{N+1} reports total_ttc=2000€ when reality is 1950€.
    refund_count=1 but EUR delta = 0. The 50€ cash gap is recorded in
    cash_movements but NOT explained in the fiscal aggregate.
  v2_saas: Identical, multiplied per tenant. Every post-Z refund silently inflates SaaS-wide GMV metric.
v2_saas_impact: BLOCKER
cost_of_delay:
  fiscal: |
    Art. 1729 D CGI — défaut de présentation / inexactitude des
    données fiscales = 7500€/exercice/branche + 5%/montant éludé.
    Art. 1743 CGI (escroquerie à la TVA) si répété et conscient =
    pénal (jusqu'à 500k€ + 5 ans). DGFiP cross-check against acquirer
    settlements detects the gap on first audit cycle.
  business: |
    Signed Z chain is the LEGAL evidence; once HMAC'd the over-report
    is immutable. Any retroactive correction breaks chain (z_reports
    DELETE trigger + signature gap_free invariant). Only remediation
    is a "correctif" Z report — operationally heavy.
  customer: Cash drawer reconciliation drift visible to operator day-of, undermines POS trust.
  cross_tenant: NO (per-branch isolation holds; bug is intra-branch).
recommendation:
  - Patch aggregate() to add a NEW collection: $mirrorsThisWindow = (clone $windowQuery)->where('status', RETURNED)->whereNotNull('parent_order_id')->get(); foreach apply -1 (already negated total handled via sign=+1) OR sign=+1 because total IS already negative on the mirror row.
  - Mathematical care: mirror.total = -X (already negated). If using sign=+1: totalTtc += +1 * (-X) = -X (correct). DO NOT pass sign=-1 (would double-negate to +X).
  - taxBreakdownForOrders for the same id set with sign=+1 (mirror tax_amount already -X per item).
  - Distinguish mirror in refund_count vs preZRefund vs postZAdjustment so aggregator does not double-count.
  - Sentinel test tests/Feature/Fiscal/RefundMirrorAggregatedInCurrentZTest.php — covers: opens Z, makes parent in sealed Z_{N}, closes Z_{N}, opens Z_{N+1}, calls RefundWithCounterEntryService::execute, closes Z_{N+1}, asserts aggregate.total_ttc == parent_orders_in_window - mirror_total_abs and total_by_tax_rate decremented correctly.
  - Cross-reference plans/GOAL line 182 — promised RefundChainNumberingTest.php absent.
owner_gate: Y (fiscal correctness — touches signed-Z math)
heal_effort: 4h (10-line patch + 2 sentinel tests + 1 backfill plan for already-signed Z reports if any in prod)
LOCK_required: N (ZReportService is critical but not frozen-zone per CLAUDE.md §7; FiscalSequenceService + AuditLogService + FiscalSealingService ARE frozen — patch lives outside them)
sentinel_test: tests/Feature/Fiscal/RefundMirrorAggregatedInCurrentZTest.php (NEW) + tests/Feature/Fiscal/RefundChainNumberingTest.php (NEW, plan-promised)
```

### F2 — TVA breakdown silently drops mirror tax rows (P0 — compliance)

```yaml
finding_id: T-1.2.5-FISCAL-F2
severity: P0
classification: COMPLIANCE
trigger:
  - app/Services/Order/RefundWithCounterEntryService.php:132 OrderItem cloned with tax_amount × -1 + tax_rate preserved
  - app/Services/Fiscal/ZReportService.php:415-417 taxBreakdownForOrders called on $orders + $adjustmentOrderIds — mirror in NEITHER
  - DGFiP NF525 requirement: `total_by_tax_rate` per-rate signed in Z. Mirror's -X TVA at rate 10% silently absent.
failure_mode:
  v1: |
    Per F1, mirror tax_amount per item is correctly NEGATIVE at row
    level (RefundWithCounterEntryService:132), but never summed.
    Z signed payload total_by_tax_rate = {"10": +T_positive} when
    reality is {"10": +T_positive - T_refund}. TVA collected
    declared = inflated by the refunded TVA. CA3 / CA12 declarations
    derived from Z chain inherit the over-statement → TVA over-paid
    to Trésor on the surface, but reconciliation against acquirer
    settlements eventually surfaces the discrepancy.
  v2_saas: same multiplied.
v2_saas_impact: BLOCKER (TVA per tenant)
cost_of_delay:
  fiscal: |
    Art. 1729 CGI — inexactitude de TVA = 40% intentionnelle / 80%
    manoeuvres frauduleuses. DGFiP cross-check via CA3 / CA12 vs
    Z aggregates is automatic since 2018 (anti-fraude TVA loi).
  business: Forced CA3 corrective + 5-year backfill if scope > 3 closed exercises.
  customer: N/A (invisible to customer until DGFiP fines back-billed).
  cross_tenant: NO (intra-branch TVA).
recommendation:
  - Single combined patch with F1 — same code site (aggregate() body). Add mirror id set to taxBreakdownForOrders call. Watch sign — mirror tax_amount is already negative, so use sign=+1 (NOT -1).
  - Sentinel: tests/Feature/Fiscal/RefundMirrorTaxBreakdownTest.php — multi-rate parent (10% + 5.5%), refund, assert total_by_tax_rate["10"] and ["5.5"] both decremented.
owner_gate: Y (fiscal)
heal_effort: included in F1 4h
LOCK_required: N
sentinel_test: tests/Feature/Fiscal/RefundMirrorTaxBreakdownTest.php (NEW)
```

### F3 — Refund document-type indicator absent on receipt (P1 — compliance, customer-visible)

```yaml
finding_id: T-1.2.5-FISCAL-F3
severity: P1
classification: COMPLIANCE
trigger:
  - resources/js/components/admin/pos/ReceiptComponent.vue:332-344 imports ReceiptDuplicataMarker only
  - resources/js/components/admin/pos/ReceiptDuplicataMarker.vue:25 isDuplicata = printCount >= 2 (re-print marker, not refund marker)
  - grep "AVOIR|REMBOURSEMENT|TICKET D'AVOIR|refund.*marker" → 0 hits in ReceiptComponent / resources/lang
  - Mirror receipt distinguished only by negative totals + 'RTN-' prefix in order_serial_no — NOT a customer-facing semantic marker
law:
  - NF525 referentiel LNE (Art. 286-I-3 bis CGI) — every fiscal document must self-identify its TYPE.
  - DGFiP doctrine BOI-TVA-DECLA-30-20-10 §200 — note de crédit / avoir distinct from facture / ticket de caisse.
  - LNE NF525 §3.4 — "le document de remboursement doit être clairement identifié comme tel".
failure_mode:
  v1: |
    Cashier prints refund ticket post-counter-entry. Customer
    receives a ticket that looks like a -50€ sale ticket with
    'RTN-XXX' in fine print. Auditor reading the ticket cannot
    distinguish from a void (CANCELED) — both look like negative
    sale tickets. DGFiP control on-site: ambiguous. Customer
    dispute: ambiguous evidence trail.
  v2_saas: Same; multiplied across tenants. Brand exposure heightened.
v2_saas_impact: HIGH (not blocker, but compliance smell on day-1)
cost_of_delay:
  fiscal: DGFiP advertising fine grade — not penal but documented non-conformity = NF525 attestation lost.
  business: Lost competitive certification badge (LNE-NF525) — material to enterprise sales pipeline.
  customer: Customer-side confusion, dispute volume up.
  cross_tenant: N/A.
recommendation:
  - Add ReceiptRefundMarker.vue analogous to ReceiptDuplicataMarker.vue. Trigger via order.parent_order_id != null OR order.status === RETURNED && order.total < 0. Display "TICKET D'AVOIR / REMBOURSEMENT NF525" with reference to parent serial.
  - i18n keys label.refund_ticket / label.refund_reference (fr / ar / en).
  - Cite parent_fiscal_sequence_no on the refund receipt — required NF525 §3.4 for traceability.
owner_gate: N (additive UI only)
heal_effort: 2h (Vue component + i18n keys + 1 visual test capture)
LOCK_required: N
sentinel_test: tests/Feature/Pos/ReceiptRefundMarkerTest.php (assert resource exposes parent_order_id + payload visibility); Playwright capture refund-receipt screenshot.
```

### F4 — Partial refund unsupported (P1 — design)

```yaml
finding_id: T-1.2.5-FISCAL-F4
severity: P1
classification: DESIGN
trigger:
  - app/Services/Order/RefundWithCounterEntryService.php:121-143 foreach $parent->orderItems — no item-subset parameter
  - PosOrderController::refundWithCounterEntry validate() only requires `reason` — no `item_ids` / `quantity_per_item` payload
  - No service method execute(Order, array $partialItems, string $reason)
failure_mode:
  v1: |
    Customer disputes 1 item from a 5-item ticket. Cashier has no
    partial-refund tool. Options today:
    (a) Refund full ticket then re-sell the other 4 items → double work + tax on re-sale.
    (b) Out-of-band cash gesture not in fiscal chain → NF525 violation harder than F1.
    Real-world cashier behavior is (b).
  v2_saas: same.
v2_saas_impact: MEDIUM (operational friction; not fiscal blocker if cashiers use (a)).
recommendation:
  - V1.0.2 backlog: extend RefundWithCounterEntryService::execute(Order, array $partialQuantities, string $reason); composition_snapshot stays FULL on the parent line, mirror line uses partial qty + recompute partial tax_amount; cite NF525 traceability rule §3.4 (refund document mirrors only refunded portion).
owner_gate: Y (fiscal — pricing math on partial)
heal_effort: 1d (service signature change + frontend dialog + tests)
LOCK_required: N
sentinel_test: tests/Feature/Refund/PartialRefundTest.php (V1.0.2)
```

### F5 — Promised `RefundChainNumberingTest.php` absent on disk (P1 — coverage)

```yaml
finding_id: T-1.2.5-FISCAL-F5
severity: P1
classification: TEST GAP
trigger:
  - plans/GOAL_ULTRA_CENTRAL_MGMT_SYNC_2026-05-18.md:182 declares (test TO BE CREATED at tests/Feature/Fiscal/RefundChainNumberingTest.php)
  - find tests -name 'RefundChain*' → 0 hits
recommendation:
  - Create test: parent fiscal_seq=N, mirror fiscal_seq=N+1, second mirror on different parent = N+2 (proves shared monotonic series across sales+refunds). Asserts FiscalSequenceService::next() called within RefundWithCounterEntryService::execute() transaction and gap-free per branch.
owner_gate: N
heal_effort: 30min
LOCK_required: N
sentinel_test: tests/Feature/Fiscal/RefundChainNumberingTest.php (NEW)
```

### Design note — sequence model is NF525-acceptable, not a gap

NF525 (LNE) and Art. 286-I-3 bis CGI mandate an inalterable, monotonic, gap-free sequence per caisse, NOT a separate refund series. The codebase choice (single `fiscal_sequence_no` shared across sales + refunds + voids, distinguished by `status` + `parent_order_id` + audit `action`) matches Cegid / JDC / Tiller and is fully compliant **provided every fiscal document self-identifies its type on the printed ticket** (see F3). The aggregator must aggregate ALL fiscal_sequence_no entries (see F1+F2). The design is sound; the implementation gates are incomplete.

---

## §3 — DGFiP Audit Readiness (specific questions an expert-comptable will ask)

1. **"Show me the refund chain for branch B, period [date_from, date_to]."** → `AuditLog::where('branch_id', B)->where('action', 'order.refund.counter_entry')->whereBetween('created_at', [...])` returns rows with `payload.parent_serial_no + parent_fiscal_sequence_no + mirror_fiscal_sequence_no + reason + user_id`. **OK.** Chain integrity verifiable via `AuditLogService::verifyChain($branchId)`.
2. **"Prove the Z aggregate reflects this refund."** → **FAILS** (F1+F2). Mirror's -X is in audit_logs but NOT in z_reports.total_ttc / total_by_tax_rate.
3. **"Show me the customer's refund ticket."** → only `RTN-` prefix + negative totals; no explicit "AVOIR" marker (F3). **WEAK.**
4. **"6-year archive of refund chain?"** → `FiscalArchiveCommand` includes `audit_logs.json` JSONL streamed by branch+window with retention manifest line; DELETE trigger on audit_logs (MySQL/SQLite). **OK.**
5. **"Who refunded what when?"** → `audit_logs.user_id` + payload reason + mirror_fiscal_sequence_no + parent_order_id traceable. **OK.**
6. **"Partial refund traceability?"** → Not supported; (F4) backlog.

---

## §4 — Heal Sequencing (recommend Heal-Implementer prioritisation)

1. **F1+F2 (combined patch, 4h)** — single hotspot in `ZReportService::aggregate()`. Must land BEFORE any V1 production launch that accepts the post-Z refund path. Includes 2 sentinels.
2. **F5 (30min)** — closes plan-promise gap; lands same PR as F1+F2.
3. **F3 (2h)** — customer-visible compliance marker; standalone PR; visual test. P1 — recommended for V1 but not blocker.
4. **F4 (V1.0.2)** — partial refund design + impl.
5. If F1+F2 cannot land before V1, mitigation: feature-flag `fiscal.post_z_refund_enabled=false` until patched; cashiers refund pre-Z only.

---

## §5 — Frozen-Zone & Anti-Drift

- FROZEN files NOT touched by recommendations: `FiscalSequenceService.php`, `AuditLogService.php`, `FiscalSealingService.php` per CLAUDE.md §7.
- Patch site `ZReportService.php` — not declared frozen but is NF525-critical; LOCK doc NOT strictly required, but Round 3 synthesis recommends an owner countersign on the F1+F2 patch because it changes signed aggregate math.
- Sentinel tests are additive (no existing assertion weakened).

---

**End of T-1.2.5 FISCAL Round 3 audit.** Verdict carried into FINAL_ROUND_1_2_3_VERDICT.md: **add P0-FISCAL-F1 + P0-FISCAL-F2 to the GO-FOR-V1 blocker list; P1-FISCAL-F3/F4/F5 to the V1 heal-light backlog.** Cross-system impact: none (intra-branch); orthogonal to CENTRAL Round 2 BranchScope findings.
