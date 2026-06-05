# PHASE 2 — S-CAISSE (the box) decomposed audit digest

**Date** 2026-06-05 · **Fleet** wf phase2-scaisse-decomposed-audit (8 worktree-pinned functionality
auditors + RED dispute, 551k tok) · raw → `PHASE2-SCAISSE-AUDIT-RAW.json`.

## Verdict: ALL 8 box functionalities = NEEDS_FIX (corroborates the 19-P1 reconcile)
| Functionality | Confirmed P1 | Heal class |
|---|---|---|
| Order-taking / POS wizard composition | M3-01 (no mandatory-step validation → 0-viande Tacos addable), M3-02 (frites upcharge preview-only, under-billed by quote) | FROZEN `pos-wizard.js` → **server-side fix preferred** (re-tariff in OrderQuoteService; enforce step completeness server-side) else LOCK |
| Manual discount + reason persistence | M4-02 (reason lost on reload → 422) | FRONTEND (posCart.js) — rebuild+visual |
| Park / recall | M7-02 (silent drop of unavailable variations/items; **+ NEW: GET /parked/{id} deletes the row inside the tx → non-idempotent, retry=404=lost ticket**) | FRONTEND (surface warnings) + BACKEND (idempotent recall) |
| Payment / encaissement | M6-002 (Z mis-buckets split total under dominant tender — **elevated: M6-001 now lets cash-dominant splits reach the signed Z**), M10-01 (cash-no-drawer: PAID but no queryable cash-trail row) | M6-002 = FROZEN `ZReportService` → **LOCK+countersign**; M10-01 = BACKEND (PaymentService) |
| Cash drawer session + reconciliation | (variance gate / audit-trail binding review) | BACKEND |
| Refund pre-Z / post-Z | M8-01 (pre-Z skips `RefundCreated` → no stock/availability/loyalty/payment-status release) **— independently confirmed; my earlier "double-release" objection was wrong** | BACKEND (PosOrderController) — validated recipe, trace RETURNED cashback path first |
| Receipt / NF525 operator identity | M11-01 / S11-02 / S16-01 (`ReceiptDataService:70` prints customer not cashier; creator_id captured-unused; counter-collect cashier only in audit log) | BACKEND (non-frozen) — **highest-priority NF525, needs collector-recording design** |
| Offline replay / idempotency | M1-02 (offline CASH enqueues pos_received_amount=null → every replay 422 → sale lost), M1-01 (no-sale drawer audit POST never reaches backend) | FRONTEND+BACKEND |

## RED cross-functional finding (the per-func agents missed it)
**Split-payment + Refund-mirror + Z-bucketing**: a split order's Z bucketing (M6-002) compounds with the
refund-mirror path — a refunded split can mis-attribute tenders in the signed daily close. Confirms M6-002
is not isolated; it sits at the intersection of split (M6-001 path), refund (M8-01/post-Z mirror), and the
Z close. **All three must be reconciled together when M6-002's LOCK is countersigned.**

## Heal classification for the box (next waves)
- **BACKEND, no countersign (do next, PHPUnit+TDD)**: M11-01/S11-02/S16-01 (operator identity — design collector recording), M10-01 (cash-trail), M8-01 (refund cascade — trace RETURNED cashback first), M7-02-backend (idempotent recall).
- **FRONTEND (rebuild + Playwright visual gate)**: M4-02, M7-02-frontend (warnings), M1-01, M1-02, S1-DASH-01 (dashboard, S-CENTRAL), S7-03 (App-Debug toggle, S-CENTRAL).
- **FROZEN-GATED ⛔ LOCK + owner countersign**: M6-002 + S13-02 (`ZReportService`), M3-01/M3-02 (`pos-wizard.js` — try server-side first), G-H fusion (`PaymentComponent.vue`).

## Live E2E surfaces (for the interface + sync + visual-timing pass, when server is up)
`http://127.0.0.1:8000/admin/pos` (the box) · POS Vanilla wizard popup (frozen — capture only) ·
`PosCounterCollectModal` (encaissement borne→comptoir) · `PaymentComponent` V5 (caisse payment) ·
parked-orders panel + recall · refund button (pre-Z/post-Z) · printed receipt (operator/NF525 fields) ·
offline-mode banner + replay. Timing capture on each interaction; cross-surface sync → KDS/OSS.

## Next
Heal the BACKEND cluster first (operator-identity is the NF525 headline), each TDD→confirm→RED-dispute→commit;
then frontend batch (rebuild+visual); frozen-gated awaits gate-G/G countersign. Then live E2E pass on the
surfaces above, looping until the box is VALIDATED with proof. Then S-SYNC/KDS/OSS/BORNE/CENTRAL.
