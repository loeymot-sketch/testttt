# GATE-G — Frozen-zone LOCK request (owner countersign needed)

**Date** 2026-06-05 · **Branch** `heal/pre-cloud-exec-2026-06-05` · **Status** 16/19 P1 resolved (15 non-frozen
fixes + M3-01 proven false-positive). The remaining **3 active P1 all live in frozen-zone files** (CLAUDE.md §7):
M6-002/S13-02 (`ZReportService`), M3-02 (`pos-wizard.js`), G-H (`PaymentComponent.vue`). Per the hard rule, I
will NOT touch them without this LOCK + your explicit countersign. This doc is the override request.

> **M3-01 UPDATE: RESOLVED (false-positive), NOT gated.** §2 below is retained for the record but needs no
> countersign — the server already rejects omitted mandatory composition via
> `PricingService::assertComposerStepConstraints` (`calculateOrder:110`), regression-locked
> (ComposerStepConstraintTest 13/13 + FritesWizardComposerTest 4/4). See `M3-01-CAREFUL-PASS-SPEC.md`.

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

### 2. M3-01 — `public/js/pos-wizard.js` (FROZEN) — **SERVER-SIDE PATH, but NF525/blast-radius careful pass (no LOCK needed)**
- Frontend: the active single-page wizard never calls `canProceedFromStep` → a Tacos with 0 viande is *addable to cart*.
- **Server reality (verified this session, corrects the catalog):** `MultiVariationConstraint` (wired to POS via
  `PosOrderRequest:255` → `ValidatesOrderItemVariations`) ALREADY rejects a **present-but-short** mandatory
  attribute (`min > 0 && totalQty < min`, MultiVariationConstraint:118). So a partially-incomplete composed
  order IS blocked server-side. **The narrow real gap**: the constraint builds `$byAttr` only from attributes
  PRESENT in the payload (`:90-104`), and skips items with zero `item_variations` (`:64`) → a mandatory
  attribute **entirely omitted** is never failed.
- **Fix (non-frozen, but careful)**: in `MultiVariationConstraint::validateCollectionKeyedByItemIndex`, also
  load each item's REQUIRED attributes (`ItemAttribute` where `item_id IN (...)` AND `min_select > 0`) and fail
  when a required attribute is absent from the payload. **Blast radius = ALL order creation (POS/kiosk/table)**
  → a careless version false-rejects valid composed orders (worse than the bug). MUST be done with full PHPUnit
  regression + production-flow review (kiosk/parked-recall/edit submit full composition?) — a dedicated careful
  pass, NOT a depleted-context rush. (No LOCK required; the frozen wizard UX polish is a separate optional item.)

### 3. M3-02 — `public/js/pos-wizard.js` (FROZEN strict no-touch) — ✅ VERIFIED REAL (under-billing)
- **Defect (now code-verified, not catalog's word)**: frites **Grande (+1,00) / Cheddar (+1,00)** are priced
  **client-side from config booleans** and emitted to the server as **`menu_extras` free-text only** — there is
  **no structured `ItemExtra` id** for them. Evidence in the frozen wizard:
  - `FRITES_GRANDE_PRICE` / `FRITES_CHEDDAR_PRICE` client constants (pos-wizard.js:90-91, default 1.00).
  - Added to the *displayed* total client-side (`addonTotal += FRITES_GRANDE_PRICE/...`, pos-wizard.js:1325-1326).
  - `selections.fritesGrande/fritesCheddar` are **booleans** (pos-wizard.js:988-989), persisted as booleans in
    `menu_restore` (4162-4163); the cart line sends `item_extras:{extras:[],names:[]}` **empty** (4153) and the
    upgrades only in `menu_extras: extras` **text** (4159).
  - The settings `order_setup_frites_grande_price/..._cheddar_price` are injected **only into the client**
    (admin-pos-v4.blade.php:132-133) for display.
- **Server proof of under-billing**: `menu_extras` is processed by **0 files in `app/`** (display/instruction
  string only); **no** server pricing code references the frites grande/cheddar settings. Per the **NF525 SSOT
  invariant** (§8: 100% backend pricing, server re-tariffs from structured ids, client totals ignored), an
  upcharge with no structured representation is **dropped server-side** → the order/receipt charges **base**,
  while the customer was shown **+2,00 €**. This is a real revenue loss + a receipt-vs-display NF525 mismatch.
  (Distinct from `FritesWizardComposerTest`, which hand-crafts a STRUCTURED `item_extras{id}` cheddar — that path
  prices correctly; the FROZEN wizard does **not** send it structured, which is the bug.)
- **Fix is frozen-gated either way**: (a) wizard sends Grande/Cheddar as the real `ItemExtra` ids (the seeder
  already defines "Cheddar fondu" @1,00 with `group_label='frites_style'`) — **frozen wizard → LOCK**; or
  (b) `PricingService` re-derives the upcharge from the settings/`menu_restore` — but `PricingService.php` is
  **also §7 frozen** → LOCK. Owner pick; both need countersign. **Priority: this leaks revenue every frites+upgrade sale.**
- **One residual to fully close (owner-optional)**: a live POS frites+cheddar order → confirm the persisted
  `grand_total`/receipt omits the +2,00 (would make it incontrovertible). The code path + NF525 invariant already
  make under-billing the expected behavior.

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
