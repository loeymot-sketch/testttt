# FROZEN-ITEMS RISK AUDIT — audit-first + adversarial dispute (feeds GOAL §RISK)
**Date** 2026-06-05 · **Method** workflow `wf_ac25fec1-47f` (3 deep auditors + enemy disputers, read-only) + supervisor verification. Detail behind the GOAL; the GOAL references this file.

---

## M6-002 + S13-02 — `ZReportService.php` (NF525)
**Change surface** : `applyOrderToTotals` **:661-668** (buckets full order total under one `pos_payment_method`, ignoring per-tranche `order_payments`). `total_by_method` written at :454, signed at :740.

**Adversarial resolution (auditor vs disputer vs supervisor):**
- Auditor claimed risk CRITICAL because "verifyChain re-derives signatures with NEW code" → historical chain at risk.
- Disputer called that FALSE: `computeSignature` reads the STORED row field.
- **Supervisor verified directly (decisive):** `verifyChain` (:488-597) iterates stored `z_reports` rows and calls `computeSignature($zReport,…)` (:554); `computeSignature` (:730-754) reads **stored** `$report->total_by_method` (:740) — it NEVER calls `aggregate()`/`applyOrderToTotals`. → **A code change to `applyOrderToTotals` is FORWARD-ONLY. Historical signed Z reports remain immutable + verifiable by construction.**
- **Net risk = MEDIUM** (not CRITICAL): a guarded forward-only branch. NOT the enrichment-layer dodge — `total_by_method` is inside the HMAC-signed payload (:740), so the SIGNED legal Z must be correct; a runtime decorator (`ZReportCashEnrichmentService`) would leave the signed field permanently wrong = NF525-non-compliant. **Frozen LOCK is unavoidable.**

**Forward-only consequence (owner must see):** any historical signed Z that already contains a split-payment order stays mis-bucketed (immutable by law). Fix corrects forward only. Pre-cloud split-payment volume ≈ near-zero → impact negligible; quantify at LOCK time via `SELECT count(*) FROM z_reports WHERE …`.

**Fix (LOCK-gated, forward-only):** in `applyOrderToTotals`, if `order->payments` (order_payments) non-empty → distribute the order total across tranches by mode; else → existing `pos_payment_method` path **byte-identical**.

**MUST-NOT-BREAK invariants (acceptance spec):**
1. `Σ total_by_method == total_ttc` (per-method identity).
2. Byte-identical signed aggregates for legacy single-tender (no `order_payments`) → historical verifyChain stays green.
3. HMAC determinism: `ksort(aggregates)` at `sign()` (FiscalSealingHmacTest).
4. F1 identity `total_tva == Σ total_by_tax_rate` (`LOCK_ZREPORT_F1_DISCOUNT_NETTING`, :425-434) untouched.
5. Refund mirror negation: split order refunded via counter-entry nets each mode bucket to 0.
6. `verifyChain` reads stored fields only — no historical recomputation.

**Tests:** `tests/Feature/Fiscal/ZReportSplitPaymentBucketingTest.php` (TO CREATE — split 30€cash+20€card → buckets 30/20 not 50; legacy fallback regression; split+discount ratio) · extend `ZReportDiscountNettingTest.php` · `RefundMirrorSplitPaymentTest.php` (existing) · `FiscalSealingHmacTest.php` (existing — sig determinism).

**S13-02 — CORRECTED 2026-06-05 (execution audit):** the clean fix is **FROZEN**. Verified: `PricingService::calculateOrder` computes `$totalTax = Σ per-line $taxPrice` PRE-discount (`:317`, `round :323`) and returns it un-netted in `PricingResult` (`:364`); the discount is applied only to the total (`:353`). So **all order paths** (SSOT `OrderService:1043/1578` + legacy `:562/1048/1583`) store a pre-discount `total_tax`, breaking `TTC = HT + TVA` on discounted orders (`Order::getTotalHt = total - total_tax`, `OrderTotalHtDecompositionTest`). **TTC mode confirmed** (`pricing.tax_inclusive_prices=true`) → netting `total_tax` is SAFE (total = subtotal − discount, independent of total_tax) and matches the Z's F1 ratio `(subtotal−discount)/subtotal`.
- **Option 1 (clean, FROZEN):** net `totalTax` in `PricingService` (the SSOT) → `LOCK_PRICINGSERVICE_TVA_NETTING` (3rd LOCK). One source, matches Z exactly.
- **Option 2 (non-frozen workaround, risky):** override `total_tax` at the 5 `OrderService` write-sites with `round($totalTax * ((subtotal−discount)/subtotal), 2)`. No LOCK, but OrderService re-derives what the SSOT owns → divergence risk if the Z netting ever changes; must be kept in lock-step with `taxBreakdownForOrders`.
- **Option 3:** document the asymmetry as accepted (Z is already correct; only the per-order receipt over-states TVA on discounted orders — rare in V1).
→ **Owner gate G4 now picks among 1/2/3** (was "net vs document"; the "net" path is frozen).

**S13-02 — RESOLVED for V1 = UNREACHABLE (execution-audit 2026-06-05, the M3-01/M8-01 pattern):** discounts (manual/coupon/loyalty) are **refused in production V1** — `assertDiscretionaryDiscountAllowed` (OrderService:2871-2878 + FrontendOrderService:803) throws when `config('pos.manual_discount_enabled') !== true`, whose **default is false** (config/pos.php:172; the F1-dormancy guard, comment :2886-2896: "so no discounted order can sign a wrong Z"). Empirical: **0 orders with discount>0** in the DB. → No discounted order can exist in V1 → the S13-02 order-vs-Z TVA mismatch cannot manifest. **No V1 heal needed (G4 = option 3 document-accept).** The order-side net (option 1/2) lands ONLY when discounts are re-enabled, coupled with the frozen F1 PricingService net — a post-V1 change, explicitly out of the V1 cloud gate. (NOTE: this worktree's env has `manual_discount_enabled=true` for dev; production V1 = default false.)

---

## M3-02 — `pos-wizard.js` frites under-billing (POS-only)
**Change surface** : `pos-wizard.js:4153` (`item_extras:{extras:[],names:[]}` empty), `:4159` (`menu_extras` text). Prices = client config booleans (`:90-91`, `:1325-1326`); `menu_extras` parsed by **0 files in app/**.

**Adversarial resolution:** disputer DISAGREED with the auditor's "seed an ItemExtra" fix and found the real shape:
- POS folds **TWO** upgrades into one `frites_style` `max_select=1` group: **Grande** (size, UI boolean) + **Cheddar** (topping, extra group). Seeding an `ItemExtra` recovers **Cheddar (+1)** but **NOT Grande (+1)** — Grande is a SIZE, not a topping, and can't co-exist in a `max=1` group.
- **Kiosk is ALREADY CORRECT**: sends `fritesStyleExtraId` (structured) and models size as separate items **#402 (Grande) / #403 (Normale)** (`KioskWizardComponent.vue`). So under-billing is **POS-specific**.
- **Hazards:** double-charge if `menu_extras` text is ever priced downstream; receipt-coherence (text shows "+1,00€" while `item_extras` empty). NF525: per-line detail only — **no chain/sequence impact**.

**Fix options (all need design + likely frozen `pos-wizard.js` → LOCK):**
- (c) seed `ItemExtra` + non-frozen translate in `PosController::normalizePosRuntimePayload` (:217-241) — recovers Cheddar only; **Grande needs size-modeling** (mirror kiosk #402/#403, or a priced size variation). Disputer: incomplete as written.
- (a) frozen `pos-wizard.js` sends structured `item_extras{id}` (+ size as item/variation) — most correct, **LOCK**.
- (b) frozen `PricingService` re-derive from settings — WORST (fragile, frozen, no signal carrier).

**Tests:** `FritesWizardComposerTest.php:211-228` (existing — proves PricingService prices a STRUCTURED cheddar at +1) · TO CREATE: `test_frites_addon_with_grande_and_cheddar_upgrades` (POST /api/admin/pos → grand_total includes +2,00) · `PricingServiceTest` unit for `extra_group=frites_style`.

**MUST-NOT-BREAK:** standalone Frites #361/#402/#403 pricing; `frites_style` `max_select=1`; `menu_extras` stays display-only (no double-charge); kiosk path unchanged.

---

## G-H — `PaymentComponent.vue` unified encaissement (FEATURE, owner objective #1)
**Anchors** : `PaymentComponent.vue` 66KB **FROZEN §7** (caisse payment, full 4-mode + split). `PosCounterCollectModal.vue` 29KB **NON-FROZEN** — already mirrors PaymentComponent's V5 atoms (hero total, mode picker, numpad) **specifically to preserve the frozen state** (sibling pattern, its own header comment). `EncaissementComponent.vue` exists (`admin/encaissement/`, the "Vue caisse unifiée"). Backend `PaymentService::confirmCounterPayment` **:193** (each method persists its own `OrderPayment`; card/TR = ref-only, no `CashMovement`). `PosComponent.vue` mounts both (`:1105` PaymentComponent, `:1396` PosCounterCollectModal).

**Two paths — OWNER DECISION (don't pre-pick):**
- **Path A — "vraie fusion incluant le frozen" (owner's prior choice):** merge caisse + borne collect into one surface inside `PaymentComponent`. Touches frozen → **LOCK + countersign**. Highest fidelity, highest risk.
- **Path B — non-frozen unification (newly viable):** extend `PosCounterCollectModal`/`EncaissementComponent` (the existing sibling) into the single unified surface for both flows; keep `PaymentComponent` frozen. **No LOCK.** The codebase already leans this way.
- **Supervisor recommendation:** Path B (lower risk, no frozen edit, the sibling pattern was built for exactly this). Owner reconfirms A vs B at the gate.

**NF525/risk:** feature, not a defect — does NOT block cloud migration. Each method → own `OrderPayment`; card/TR ref-only no drawer over-count; split-payment interaction must be preserved either path.

---

## Cross-cutting: DUPLICATION control (owner: "no duplication")
An approved **remote ultraplan is executing as a PR on these same frozen files**. This GOAL is the **authoritative audit-grounded spec + risk register + acceptance gates**; the remote PR is **one execution validated against this GOAL before merge** (PR-review checklist = GOAL convergence criteria). Not two competing plans.
