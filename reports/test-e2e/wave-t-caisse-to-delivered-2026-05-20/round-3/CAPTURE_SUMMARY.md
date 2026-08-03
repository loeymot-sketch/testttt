# Wave T Round 3 — Capture Summary

**Run:** wave-t-caisse-to-delivered-2026-05-20 / round-3
**Branch:** `heal/cms-pr1-quickwins-2026-05-18` HEAD `75f2cd2f3`
**Captured:** 2026-05-20T22:10–22:25Z
**Cap:** 60 min (used ~55 min)

## Wave verdicts (exit codes + 1-line)

| Wave | Spec exit | Outcome | 1-line |
|------|-----------|---------|--------|
| A — POS | 1 (soft assert fail) | PARTIAL | 17/17 PNGs captured; Order #1 (id=74, TAKEAWAY, CASH, 17€) created and posted to tracker EN PRÉPARATION; **Order #2 (DELIVERY, TPE, 19€) POST did NOT fire — same regression as R2 (finding WT-A-R3-001)**. Other R3 heals (drawer preflight, delivery UI, sidebar i18n, currency canonical) **VERIFIED**. |
| B — KDS | 1 (snap crash state 03) | PARTIAL | 5/8 PNGs captured (01–05); state-02 shows EXACTLY 1 card (Order #A0003 = id=74) in EN COURS — R3-F2 stale-clean preflight **VERIFIED** (no leftover orders). Crash on state-03 because card2Sel pointed at non-existent DOM (Order #2 was missing from R3 Wave A). Browser context closed mid-snap caused fail. |
| C — OSS | 0 (passed) | PASS | 6/5 PNGs captured (extra 04b post-pickup); S-3 mandates preserved (token=56px, preparing header=40px bg primary rgb(176,0,77), ready header=40px bg green rgb(26,183,89)); allowlist DELIVERY absent (order_2 id=70 not present in DOM via 4 probed identifiers); pickup_transition `/api/admin/pos-order/change-status/74` → 200 bearer variant; numeric_integrity sample mismatch is benign (queue display vs assigned token, pre-existing). Pulse=0 in this run (counter pre-existing — not R3 regression). |
| D — LIVREUR | 0 (passed) | PASS | 7/7 PNGs captured (Order #2 = id=70 R1 carryover); assign=200 + delivered=200 (ofd=422 because order already terminal `status=13`); driver=13 (Le Cayenne) attached; NF525 chain +1 event verified. Token label, F1 EN LIVRAISON lane, F5 driver chip after assign — all preserved. |

## R2 → R3 fix validation matrix

| Fix ID | Commit | Hypothesis | Wave A evidence | Wave B/C/D evidence | Verdict |
|--------|--------|-----------|-----------------|---------------------|---------|
| **F1** (`e028cfa47`) — `/api/admin/item` 422 short-circuit + axios 4xx toast for critical paths | spec/api heal | Eliminate silent 422 leaks, scope toast to critical | No `/api/admin/item` 422 events in Wave A state network logs; toast spam absent in console.json (only 1 toast trace in state-03/04 R3-F4 cleanup flow) | C/D unaffected (no /api/admin/item calls) | **VERIFIED** |
| **F2** (`70b404cc6`) — AutoPrepareOnPaidPolicy DELIVERY sentinel + Wave B carryover guard | backend heal | DELIVERY orders should NOT auto-transition to PREPARING from PRÊTE 8 carryover | Wave B state-02 KDS shows Order #A0003 (id=74 TAKEAWAY) in **EN COURS = PREPARING (status=7)**, not PRÊTE 8 — confirms backend AutoPrepare on paid is **VERIFIED** for TAKEAWAY happy path. DELIVERY path could not be re-verified (Order #2 not created R3) | DB status=7 for id=74 confirms; id=70 R1 carryover status=13 livrés (terminal) | **PARTIAL VERIFIED** (TAKEAWAY ok; DELIVERY untested R3) |
| **F3** (`75f2cd2f3`) — state-17 order_type re-affirm + KDS stale-clean preflight | spec heal | Re-affirm DELIVERY=5 at state-17 in case reactive watchers flipped back to TAKEAWAY; KDS stale-clean removes pre-test orphan AUDIT-WAVE-T-* | **state-17 re-affirm FAILED** — capture logs `"state17: re-inject (DOM-anchored) ok=false reason=no_vueParentComponent_on_anchor"`. The `#orderdelivery` anchor was no longer in DOM at state-17 (PaymentComponent had taken over). Order_type observed in capture metadata = 10 (TAKEAWAY) — so the DOM-injection at state-16 succeeded but never reached the POST. KDS stale-clean **VERIFIED** (Wave B state-02 = exactly 1 card, no leftover) | KDS: 1 card visible, no leak | **F3 PARTIAL** — KDS stale-clean ok; state-17 re-affirm INEFFECTIVE (DOM anchor missing) |
| **F4** (`b97e43df7` + `ed2db25e3` + `b68795ab1`) — drawer preflight + delivery UI flow + cash-pending badge doc | spec/docs heal | Force-close active drawer before POS landing; UI flow customer+address (not silent inject); document badge semantics | **DRAWER PREFLIGHT VERIFIED** — state-03 PNG shows empty "Ouvrir la caisse" modal with empty 50€ input (no `cash-session-active-view`). State-16 PNG shows delivery form fully populated via UI search-customer flow (name "Wave T E2E 1779307742986" in customer field, address "12 rue Test, Paris 75001" selected, total 19,00€) | D/OSS unaffected | **VERIFIED** (drawer + UI + badge docs) |

## R3 New findings

| ID | Sev | System | Summary | Evidence |
|----|-----|--------|---------|----------|
| **WT-A-R3-001** | P0 | POS | Order #2 (TPE livraison) POST `/api/admin/pos` never fires despite state-16 delivery form fully populated via UI flow. Root cause: between state-16 (form fill) and state-17 (pay click), PaymentComponent reactivity replaces the `#orderdelivery` anchor in DOM, so the spec's state-17 re-injection walks fail (`reason=no_vueParentComponent_on_anchor`). `order_type` remains at default TAKEAWAY=10 when payment modal opens, so `orderSubmit` guard at `PosComponent.vue:3274` does NOT trigger `ensureDeliveryCustomerAndAddress`, and the cart submits as walk-in TAKEAWAY against the missing inline customer → silent bail before axios.post. | `round-3/wave-A-capture.json` `findings_inline[1,2]`; state-17 observation `"state17: re-inject ... ok=false reason=no_vueParentComponent_on_anchor"`; DB confirms only id=74 (TAKEAWAY) created. **Already on R2 (WT-A-R2-001 cluster); R3-F3 fix INSUFFICIENT — needs UI flow at state-17 too, or anchor migration to PaymentComponent's modal node.** |
| **WT-B-R3-001** | P1 | KDS | Spec snap helper crashed on state-03 because card2Sel matched 0 DOM nodes (Order #2 missing from this round). `Target page, context or browser has been closed` (mega-audit-snap.js:66). Wave B captured only 5/8 states; remaining states (06–08) not produced. | Wave B output `b8wg5uszm.output` ; `__screenshots__/wave-t-caisse-to-delivered-B-kds/` has states 01–05 only. **Downstream blocker only if Wave A doesn't create Order #2 — fixture carryover (used in C/D) does not propagate to Wave B because B requires REAL freshly-created order ID to match KDS card token.** |

## NF525 chain delta

- **Pre-R3:** count=19 last_hash=5baf36ccf4846b3d
- **Post-Wave-A:** count=23 last_hash=67351e7a7151676a (+4 events = paid order #1 fiscal_alloc + checkout + status_change + paid)
- **Post-Wave-D:** count=24 last_hash=3fea9836e753dd8a (+1 event = order #2 delivered transition for id=70)
- **Net R3 delta:** +5 events (1 fresh paid TAKEAWAY + 1 terminal DELIVERY transition for carryover)
- **Chain integrity:** APPENDED-ONLY VERIFIED. No DELETE / TRUNCATE. NF525 invariants honored.

## Frozen-zone diff

- `app/Services/Fiscal/FiscalSequenceService.php` — 0 lines
- `app/Services/Fiscal/ZReportService.php` — 0 lines
- `app/Services/Fiscal/AuditLogService.php` — 0 lines
- `app/Models/Scopes/BranchScope.php` — 0 lines
- `app/Http/Middleware/IdempotencyKeyMiddleware.php` — 0 lines
- `app/Services/Pricing/PricingService.php` — 0 lines
- `app/Domain/Order/OrderStateMachine.php` — 0 lines
- `resources/js/components/admin/pos/PaymentComponent.vue` — 0 lines
- `resources/js/components/admin/pos/v5/PosV5TrancheRow.vue` — 0 lines
- `resources/js/components/frontend/kiosk/Kiosk*.vue` — 0 lines
- `public/js/pos-wizard.js`, `public/css/pos-wizard.css`, `resources/views/admin-pos-v4.blade.php` — 0 lines

**Frozen-zone verdict:** CLEAN.

## R2 set-equality convergence (per wave)

| Wave | R2 finding set | R3 finding set | Convergent? |
|------|----------------|----------------|-------------|
| A — POS | {WT-A-R2-001 state-17 reinject, WT-A-R2-005 order2 not posted, Wave S-1 hook fail} | {WT-A-R3-001 state-17 reinject (same root, different mechanism — DOM anchor missing), order2 not posted (same), Wave S-1 hook fail (same)} | **NOT YET** — same root failure persists. Re-inject fix at state-17 needs **stronger** target (PaymentComponent root node, not #orderdelivery which gets replaced). |
| B — KDS | {1 card visible due to Order #2 missing — derived from A regression} | {1 card visible due to Order #2 missing — derived from A regression; snap crash on state-03 is NEW (R2 had Order #2 from R2 fresh, so card2Sel resolved)} | **NOT YET** — but downstream-of-A; convergent once A converges. |
| C — OSS | {token=56px ✓, headers=40px ✓, DELIVERY allowlist absent ✓, pickup 200 ✓, pulse=0 pre-existing} | {token=56px ✓, headers=40px ✓, DELIVERY allowlist absent ✓, pickup 200 ✓, pulse=0 pre-existing} | **CONVERGENT** — identical metrics vs R2; S-3 mandates preserved. |
| D — LIVREUR | {assign=200, delivered=200, ofd=422 for terminal order} | {assign=200, delivered=200, ofd=422 for terminal order} | **CONVERGENT** — identical evidence vs R2. |

## Convergence eligibility per wave

- **Wave A**: NOT eligible — WT-A-R3-001 P0 same root cause as WT-A-R2-001 (re-inject DOM anchor missing).
- **Wave B**: NOT eligible — downstream of A; auto-converges when A creates Order #2.
- **Wave C**: ELIGIBLE — set-equal to R2; all S-3 mandates verified (token+headers+allowlist+pickup); pulse=0 is pre-existing (V1.0.1 backlog).
- **Wave D**: ELIGIBLE — set-equal to R2; all R3 heals (F1 + drawer preflight) verified upstream; LIVREUR self-contained ok.

## Determinism check

The R3 captures are reproducible given the same fixture state. The Wave A failure is **deterministic** — DOM anchor `#orderdelivery` is replaced by PaymentComponent's modal root at state-17 regardless of run. R3-F3 cannot resolve this without re-targeting (either UI click on the segment radio button BEFORE pay click, or walking PaymentComponent's `__vueParentComponent` from a different anchor at state-17, e.g. `#orderpayment` once visible).

## Recommendation for R4

1. **Wave A blocker (WT-A-R3-001)**: Move state-17 order_type re-affirm to fire BEFORE `pos-payment-confirm` modal opens. Two options:
   - **Option A (preferred)** — Click the visible `label[for="delivery"]` segment button (UI action, not DOM injection) immediately before clicking pay. Verify aria-checked state changes.
   - **Option B (fallback)** — Walk `__vueParentComponent` from a stable anchor that persists across state-16→17 (e.g. `[data-testid="pos-payment-confirm"]` once modal opens; OR `#app` global root with breadth-first PosComponent lookup).
2. **Wave B snap crash (WT-B-R3-001)**: Add try/catch around `await snap('03-kds-order2-card-detail')` so Wave B captures all 8 states regardless of card2 missing, then surface the missing-card as P1 finding instead of crashing.
3. **Owner gate**: Order #2 has never been freshly created in R1, R2, or R3 — three consecutive rounds with same root failure. Recommend escalating to owner with the DOM-anchor analysis above and proposing the `label[for="delivery"]` UI click approach as the cleanest path forward.
