# PS-4 — POS Client management + Receipts — STATUS.md

- **Round**: 1 (heal/cms-pr1-quickwins-2026-05-18, HEAD d3dc4c2c6)
- **Scope**: 10.10 Client management (walk-in / registered lookup / walk-in delivery fee)
       + 10.11 Receipts (NF525 ticket impression / duplicata / fiscal_sequence_no wire-in)
- **Wall-clock**: 32 min
- **Verdict**: PASS_WITH_HEALS — 1 P2 heal applied + 6 V1.0.2 backlog items + 0 blockers

---

## Synthesis (4 specialists, parallel read-only audit)

The PS-4 zone is **architecturally sound after commit 80fb27c48 (wire-in) and d3dc4c2c6 (BroadcastableOrder typehint widening)**. All four specialists converge: the SSOT contract (OrderDetailsResource → ReceiptDataService → printed ticket via posReceiptBuilder.js) is now sentinel-tested, the walk-in resolver is a clean idempotent firstOrCreate (no spam vector), the receipt UI is accessible and PII-clean, and the kiosk receipt persistence helper guards localStorage against PII leakage.

**Cross-validated findings (2+ specialists agree)**:

1. **PosOrderReceiptComponent (admin/posOrders re-print modal) is NOT NF525-compliant** — confirmed by Architect (ARCH-PS4-002), UX/A11y (UX-PS4-003), RED (RED-PS4-002). It lacks the ReceiptDuplicataMarker, the NF525 footer, the per-rate VAT block, AND does not POST to the print-receipt fiscal endpoint. The day-to-day re-print path (PosOrdersTrackerComponent → ReceiptComponent) IS correct, but this latent admin surface is a real NF525 gap for any manager who re-prints from /admin/posOrders.

2. **audit_emitted=false silently swallowed by UI** — confirmed by UX/A11y (UX-PS4-002), RED (RED-PS4-005). The backend explicitly emits this signal so the UI can warn the manager about a fiscal chain failure, but the JS handler discarded it. **HEALED in this round** (see below).

3. **Print-receipt endpoint has no per-route throttle** — confirmed by Architect (ARCH-PS4-006), RED (RED-PS4-004). The broader `api/admin/pos/*` bucket caps at 120/min per-user, so the practical attack surface is bounded, but a dedicated `throttle:pos-quote` on this route would harden the fiscal chain noise vector. Recommended for V1.0.1.

**Owner-attested OK / KEEP-WHAT-WORKS**:
- Walk-in resolver pattern (single canonical sentinel user)
- ReceiptDuplicataMarker visibility (2px dashed red + print-color-adjust:exact + role=status)
- ReceiptComponent toolbar A11y (aria-busy + tabindex=-1 on hidden buttons + role=document)
- Kiosk receipt persistence (TTL + version gating + anti-PII guard)
- BroadcastableOrder typehint widening (kiosk + POS converge through the same service entry-point)

---

## 4-LIST

### KEEP (working as-attested)

- `app/Services/Pos/WalkInCustomerResolver.php` — DB-first firstOrCreate by stable email, PENDING_WALKIN phone sentinel, idempotent role assignment.
- `app/Services/Receipt/ReceiptDataService.php` — SSOT for six NF525 fields, accepts BroadcastableOrder.
- `app/Http/Resources/OrderDetailsResource.php` — Now delegates to ReceiptDataService for fiscal fields; keeps audit_chain_fingerprint + payments_breakdown + tax_lines locally.
- `app/Http/Controllers/Admin/Pos/PosReceiptPrintController.php` — Atomic branch-scoped UPDATE, audit-chained, operational-continuity-safe.
- `resources/js/components/admin/pos/ReceiptComponent.vue` — Primary cashier-side print modal, NF525-complete, A11y-clean.
- `resources/js/components/admin/pos/ReceiptDuplicataMarker.vue` — Legally-compliant DUPLICATA visibility (print-color-adjust:exact).
- `resources/js/helpers/posReceiptBuilder.js` — Pure helpers (buildNf525Footer / formatPaymentsBreakdown / normalizeReceiptVariations + Extras / receiptBranchHeader).
- All four Vitest specs (posReceiptBuilder, posReceiptPrintFlow, posReceiptDuplicataMarker, kioskReceiptPersistence).
- All three PHP Feature tests (PosWalkInCustomerApiTest, PosWalkInAndDeliveryFeeTest, ReceiptDataServiceWireInTest — locking the SSOT contract + BroadcastableOrder widening).

### HEAL (applied in this audit)

- `resources/js/components/admin/pos/ReceiptComponent.vue` (handlePrintClientClick) — surface `alertService.warning('pos.receipt_audit_chain_warning')` when `audit_emitted === false`. NF525 SIEM signal recovered. (UX-PS4-002 / RED-PS4-005)
- `resources/js/languages/fr.json` + `en.json` + `ar.json` — new i18n key `pos.receipt_audit_chain_warning` (FR canonical, EN/AR localised).
- `tests/js/posReceiptPrintFlow.spec.js` — +2 sentinel tests locking the new behaviour (warn on audit_emitted=false, no-warn on happy path) + `alertService` mock added (de-facto coverage extension for prior 403/404/409 paths too).

**Verification evidence**:
- Vitest: `posReceiptBuilder.spec.js` 17/17 + `posReceiptPrintFlow.spec.js` 8/8 (+2 new) + `posReceiptDuplicataMarker.spec.js` 7/7 + `kioskReceiptPersistence.spec.js` 8/8 = **40/40 PASS**.
- PHPUnit: `PosWalkInCustomerApiTest` 1/1 + `PosWalkInAndDeliveryFeeTest` 1/1 + `ReceiptDataServiceWireInTest` 5/5 = **7/7 PASS**.
- Frozen-zone touch: **NONE** (ReceiptComponent.vue is not in the frozen list; the wizard popup POS is. We touched the *receipt* modal which is post-payment, not the wizard).
- NF525 chain: unchanged (no service/controller modified; only the consumer UX).

### BACKLOG V1.0.2 (deferred — not safe to heal in this audit window)

- **ARCH-PS4-002 / UX-PS4-003 / RED-PS4-002 (P2)** — Bring PosOrderReceiptComponent.vue to NF525 parity OR deprecate it. Needs owner Aurore decision on which surface (admin/posOrders vs PosOrdersTrackerComponent) is the canonical re-print path.
- **ARCH-PS4-006 / RED-PS4-004 (P2)** — Add `throttle:pos-quote` (or dedicated `pos-receipt-print` 30/min) to `Route::post('/orders/{order}/print-receipt')` in routes/api.php L831. Pattern is already established for walk-in-customer + quote.
- **SEC-PS4-001 (INFO)** — Add Feature assertion that 5 calls to /admin/pos/walk-in-customer yield User::where(email=...).count()===1 (lock the no-spam invariant explicitly).
- **SEC-PS4-006 (INFO)** — Add Feature test for cross-tenant 404 on POST /admin/pos/orders/{foreign_order}/print-receipt.
- **UX-PS4-006 / UX-PS4-007 (P3)** — Wrap toolbar emojis in aria-hidden=true (BYPASS marker, print kitchen/client buttons).
- **RED-PS4-007 (P3)** — Add User model boot deleting hook preventing deletion of walkingcustomer@example.com (hostile-admin protection).

### BLOCKERS

**NONE.** PS-4 zone does not block V1 merge. All P0 issues are resolved (the F1+F2+F3 kiosk-checkout 500 was already fixed in d3dc4c2c6 before this audit). The P2 heals applied are net-positive for production readiness.

---

## Deliverables

- `architect.json` — 7 findings (1 P2, 6 INFO)
- `security.json` — 7 findings (0 P-rated, 7 INFO including 1 reassuring rebuttal of the random-phone-email RED hypothesis)
- `ux_a11y.json` — 8 findings (2 P2, 2 P3, 4 INFO; WCAG violations: 0)
- `red.json` — 7 attacks probed (3 blocked, 4 reproduced); 2 net-new findings (RED-PS4-004 throttle, RED-PS4-007 walk-in user delete)
- `STATUS.md` (this file)

## Files touched (heal — non-frozen)

- `resources/js/components/admin/pos/ReceiptComponent.vue` (+10 lines, 1 conditional alert)
- `resources/js/languages/fr.json` (+1 key, between L171 and L172)
- `resources/js/languages/en.json` (+1 key, between L171 and L172)
- `resources/js/languages/ar.json` (+1 key, between L140 and L141)
- `tests/js/posReceiptPrintFlow.spec.js` (+~50 lines: 2 new tests + alertService mock)

## Files DELIBERATELY NOT touched (per mandates)

- `app/Services/OrderService.php` (session-A WIP — dirty-list)
- `public/js/pos-wizard.js` + `public/css/pos-wizard.css` (frozen zone)
- Any backend in `app/Services/Pos/` other than the read-only audit of WalkInCustomerResolver
- Any NF525-critical backend service or migration
