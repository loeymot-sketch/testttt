# GATE-G — Frozen-zone LOCK request (owner countersign needed)

**Date** 2026-06-05 · **Branch** `heal/pre-cloud-exec-2026-06-05` · **Status** 15/19 P1 resolved (ALL non-frozen).
The remaining **4 active P1 all live in frozen-zone files** (CLAUDE.md §7). Per the hard rule, I will NOT
touch them without this LOCK + your explicit countersign. This doc is the override request.

## Why we're here
Verified: **PHPUnit 2857/0 real · Vitest 1895/0** (all sentinels green) · 28 commits · 0 frozen lines touched.
Every fix achievable without entering a frozen zone is done + tested. What's left is genuinely gated on you.

## The 4 frozen items (countersign each you approve)

### 1. M6-002 + S13-02 — `app/Services/Fiscal/ZReportService.php` (NF525, FROZEN) — **CRITICAL**
- **M6-002**: Z-report `total_by_method` buckets a split order's FULL total under the dominant tender
  (`applyOrderToTotals` reads `pos_payment_method` only). My M6-001 fix now lets cash-dominant splits reach
  the signed Z → the mis-bucketing is **live in the daily fiscal close**. The RED finding (Phase-2) showed
  split+refund-mirror+Z compounding.
- **S13-02**: per-order `total_tax` on pre-discount subtotal; only the Z re-nets TVA → order/receipt vs Z disagree.
- **Scope if approved**: distribute split totals across `order_payments` tranches in the Z bucketing;
  net TVA consistently. Append-only NF525 attestation before+after (`fiscal:verify-chain --all`). Reconcile
  with the RED split+refund finding together.
- **Rollback**: revert the ZReportService patch; the chain is append-only so prior Z reports are untouched.

### 2. M3-01 — `public/js/pos-wizard.js` (FROZEN strict no-touch) — **SERVER-SIDE PATH AVAILABLE (may NOT need this LOCK)**
- No mandatory-step validation in the active single-page wizard → a Tacos with 0 viande/0 sauce is addable.
- **Preferred (non-frozen)**: enforce mandatory-step completeness **server-side** in `OrderQuoteService` /
  `PosOrderRequest` against the wizard/composition profile → rejects incomplete items at quote/creation
  WITHOUT touching the frozen wizard. I can do this next pass without a LOCK. (Listed here for visibility.)

### 3. M3-02 — `public/js/pos-wizard.js` (FROZEN strict no-touch)
- Frites supplements (Grande +1,00 / Cheddar +1,00) shown in the preview total but sent only as TEXT →
  the server quote can't re-tariff → under-billing vs the preview.
- **Options**: (a) wizard sends the upgrade as a structured priced addon (needs the frozen wizard → LOCK);
  (b) server parses the menu_extras text to re-derive the upcharge (non-frozen but fragile). Owner pick.

### 4. G-H — `resources/js/components/admin/pos/PaymentComponent.vue` (FROZEN §7) — your stated objective #1
- Unified encaissement: you chose **"vraie fusion incluant le frozen"** (borne + caisse → one system,
  Espèces / Tickets-resto / Terminal-manuel-SumUp). The non-frozen `PosCounterCollectModal` is the foundation;
  full fusion routes the caisse flow through it / edits the frozen `PaymentComponent`.
- **Scope if approved**: design the single unified surface (new gate G-H), route both borne-collect and
  caisse-collect through it, modes = Espèces/TR/Terminal(manuel+réf). Frozen edit under LOCK + triple-vert.

## What I need from you
- **Countersign which of #1/#3/#4 I may LOCK + edit** (I'll write the per-file `/lock-plan` LOCK doc for each you approve).
- #2 (M3-01) I can do **server-side without a LOCK** on your "go" — confirm that's acceptable.
- Non-P1 follow-ups (no countersign): S7-03 UI-toggle removal (cosmetic; risk already closed backend-side) +
  the live branch-push SYNC timing test (needs a branch-staff session; soketi + worker + websocket already verified UP).

**No frozen file will be touched until you countersign. The 15/19 non-frozen work is complete, tested, and committed (no push).**
