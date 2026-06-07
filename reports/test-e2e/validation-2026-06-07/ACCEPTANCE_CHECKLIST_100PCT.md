# FoodKing V1 — 100% Acceptance Checklist (pre-hardware)
**Goal:** validate EVERYTHING functional in software so that on the physical device, the ONLY new thing is the actual paper print.
**PASS bar:** drove it functionally + inspected real output/state + tried to break it. HTTP-200/no-console-error is NOT pass.
**Legend:** ✅ PASS · ⚠️ PARTIAL · ❌ FAIL/blocker · ⬜ TODO (not yet tested) · 🔒 owner-gated · 🖥️ part-hardware
**Harness:** `foodking_e2e` clone on :8766. ⚠️ See FISCAL-CONFIG below — clone (and local operating DB) legal config is BLANK.

---

## 🎯 CLUSTER 1 — THE TICKET / RECEIPT (your #1; must be 100% in software)

| # | Item | State | Evidence / Note |
|---|------|-------|-----------------|
| T-1 | NF525 legal identity on ticket (SIRET, TVA intra, mentions) | ❌ **BLOCKER** | `branches.siret`, `vat_intra`, `legal_footer` = **NULL in BOTH `foodking` (local operating) AND `foodking_e2e`**. Receipt renderer reads these from branch config → ticket prints WITHOUT legally-required SIRET/TVA. Must be set per device before go-live. |
| T-2 | `foodking:set-branch-legal` command (memory said it exists) | ❌ **NOT IN THIS BRANCH** | `php artisan list` has no such command; no class in `app/Console`. Legal must be set via DB/seeder/admin settings, OR the command merged from the cutover branch. |
| T-3 | Operator identity = cashier, not "Client passage" | ✅ PASS | `ReceiptDataService::resolveOperatorName` = editor_id ?: creator_id. #4160 (POS) → creator_id=1 → "Admin Le Cayenne". #4161 (kiosk) → editor_id=1 (stamped at encaissement) → "Admin Le Cayenne". Known bug does NOT manifest for counter-collect flow. |
| T-4 | Fiscal sequence number on ticket | ✅ PASS | `posReceiptBuilder.js:115-116` pushes `fiscal_ticket_no` line from `order.fiscal_sequence_no`. |
| T-5 | Line items + composition (variations/extras/addons) with NF525-correct ratio-adjusted line_total | ✅ PASS (code-verified) | `posReceiptBuilder.js:216-245` uses `line_total` (ratio-adjusted), not catalog_price. |
| T-6 | TVA breakdown on ticket (rate + HT/TTC amounts) | ✅ PASS | `ReceiptComponent.vue:150-188` renders per-item tax + **TVA ventilation by rate** (`tax_lines`) + HT/TVA/TTC totals + tendered breakdown. Data verified: #4160 `tax_lines=[{VAT, rate 10%, base_ht 1,36€, tax 0,14€}]`, HT 1,36 + TVA 0,14 = 1,50 TTC. Math correct. |
| T-1b | Receipt RENDERS all NF525 fields when branch legal IS set | ✅ PASS | After setting clone branch legal, `OrderDetailsResource` for #4160/#4161 returns siret=10417050100019, vat_intra=FR19104170501, legal_footer, operator_name=cashier, fiscal_no. Rendering path is correct; the gap is purely that the config isn't set on devices (T-1). |
| T-11 | VAT regime | ✅ ANSWERED: **VAT-registered** | Owner confirmed E.DELICE collects TVA. So 10% base is correct in principle. Footer must NOT say "TVA non applicable art.293B" — needs correct mention text (owner to provide, or omit). |
| T-12 | VAT rate correct per item type | ⚠️ PARTIAL / gaps | Distribution: Boissons/Burgers/Tacos/Sandwich/Frites/Desserts/Galette/Menu = 10% (correct for immediate-consumption fast-food). **⚠️ 6 items NULL tax_id (5 Bols Gourmands + 1 Supplément) → sell with NO VAT line (NF525 gap, must fix).** 8 Suppléments at No-VAT 0% — confirm intentional. No alcohol (halal) so no 20% needed. Confirm takeaway-vs-dine-in rate policy (10% immediate-consumption default OK). Leftover GST rows in `taxes` table unused (Bangladeshi template) — cosmetic. |
| T-7 | Server-side ESC/POS auto-print of ORDER ticket | ❌ **MAJOR — NOT IN THIS BRANCH (owner chose this path)** | OWNER CONFIRMED: device uses **server-side ESC/POS auto-print**. But this branch has only ESC/POS primitives + transport + `testPrint` — the full order-ticket renderer + `print_jobs` outbox + Node print-agent are on UNMERGED `feat/pos-printer-saga-autoprint` (commit e446a2084) + cloud-prep. **This branch must merge + re-validate that printer-saga branch before hardware.** This is the single biggest "not done" — the auto-print the owner wants literally isn't here. |
| T-8 | Refund marker / Duplicata marker on reprint | ⬜ TODO | Components exist (`ReceiptRemboursementMarker.vue`, `ReceiptDuplicataMarker.vue`); print-count/duplicata tracked in `PosReceiptPrintController`. Not functionally tested. |
| T-9 | Actual rendered ticket visually inspected (full ticket, both origins) | ⬜ TODO | Have NOT yet rendered + read a full ticket image. Next action. |
| T-10 | Z-report / clôture du jour PDF (itself a printed fiscal doc) | ⬜ TODO | "PDF Clôture du jour" on dashboard; not generated/inspected. |

---

## CLUSTER 2 — ENCAISSEMENT (money core)

| # | Item | State | Evidence |
|---|------|-------|----------|
| E-1 | CASH encaissement → PAID + gap-free fiscal | ✅ PASS | #71→2002, 10 orders→2003-2012, #4161→2013. Chain OK. |
| E-2 | 10 sequential encaissements gap-free | ✅ PASS | 2003-2012 consecutive, 0 gaps. |
| E-3 | CARD (Terminal manuel SumUp + ref) | ⬜ TODO | Memory once flagged "carte = STUB". Only CASH tested. Must drive + check no false CashMovement. |
| E-4 | TICKET-RESTAURANT | ⬜ TODO | Memory "TR = STUB". Untested. |
| E-5 | MOBILE banking | ⬜ TODO | Untested. |
| E-6 | Discount/coupon → correct TVA on receipt (historical P0) | ⬜ TODO | "Code promo" field untested; discount→VAT-10 SSOT was a historical P0. |
| E-7 | Encaissement queue FIFO + 200 cap behavior | ✅ noted | `orderBy created_at` ASC, limit 200 (FIFO, correct). Newest buried — by design. |

---

## CLUSTER 3 — ORDER LIFECYCLE

| # | Item | State | Evidence |
|---|------|-------|----------|
| L-1 | POS (Caisse) lifecycle create→encaissement→KDS→OSS→historique | ✅ PASS | #4160 → 2001 → A0001. |
| L-2 | Borne (Kiosk) full lifecycle kiosk UI→encaissement→KDS→OSS | ✅ PASS | #4161 → 2013 → A0002, transitions audited. |
| L-3 | KIOSK WIZARD (composable items: sandwich/tacos/bowl + sauces/options) | ⬜ **TODO (important)** | Only tested a no-option drink to AVOID the wizard. The composer wizard — heart of the borne — is UNTESTED. |
| L-4 | Multi-item order + composition_snapshot integrity | ⬜ TODO | Only single-item orders. |
| L-5 | Refund / cancellation (NF525 fiscal logging) | ⬜ TODO | Untested. |
| L-6 | KDS recall/undo, concurrent orders, OOS badge | ⬜ TODO | Only one happy-path advance. |
| L-7 | Delivery (livreur) + parked orders | ⬜ TODO | Untested. |

---

## CLUSTER 4 — FUNCTIONAL DEPTH (surfaces beyond render)

| # | Item | State | Evidence |
|---|------|-------|----------|
| F-1 | Surfaces render clean (11) | ✅ PASS (render only) | HTTP 200, no crash/raw-label/console-err. NOT functional proof. |
| F-2 | Dashboard data correctness + filters | ⬜ TODO | Only saw it loads; KPIs traced 1 order. |
| F-3 | Stock toggle → real-time sync to POS/kiosk/wizard | ⬜ TODO | On-screen promise; never verified cross-surface. |
| F-4 | Loyalty earn/redeem/consult | ⬜ TODO | Only viewed customer list. |
| F-5 | Admin CRUD (item/category/customer/user/permissions) | ⬜ TODO | Only viewed lists. |
| F-6 | Kiosk error/degraded routes (network/payment-refused/product-removed/menu-unavailable) | ⬜ TODO | Untested. |
| F-7 | "10 commandes" per OTHER subsystems (kiosk, POS) | ⬜ TODO | Only encaissement got 10. |

---

## CLUSTER 5 — CROSS-DEVICE (legitimately part-hardware)

| # | Item | State | Evidence |
|---|------|-------|----------|
| X-1 | Real-time sync borne(devA)→KDS(devB)→caisse(devC) | 🖥️ TODO-on-devices | Single browser context can't prove multi-device. Memory: SYNC-WS-01 (ws:6001 fails→polling fallback). Confirm on the real multi-device setup. |

---

## CLUSTER 6 — NF525 INTEGRITY (already strong)

| # | Item | State | Evidence |
|---|------|-------|----------|
| N-1 | Fiscal sequence gap-free at scale | ✅ PASS | 2013 numbers, span 2013, gaps=0, 0 dups. |
| N-2 | HMAC chain integrity | ✅ PASS | `fiscal:verify-chain --all` CHAIN OK throughout. |
| N-3 | Frozen zones untouched | ✅ PASS | 0 frozen files modified this session. |
| N-4 | Z-report close + chain link | ⬜ TODO | Not run this session (see T-10). |

---

## VERDICT (honest)
**NOT 100% yet.** Genuinely solid: fiscal sequence integrity, operator identity, 2 CASH lifecycles, surfaces-don't-crash. **Real blockers/gaps before "only printing remains":**
1. **T-1/T-2 NF525 legal identity not set** (both local DBs blank; no set command in branch) — a ticket would print non-compliant.
2. **T-7 server-side order-ticket print not merged** in this branch — the print path itself needs deciding/merging.
3. **T-6/T-9 full ticket content not yet inspected** (TVA breakdown, visual).
4. Non-CASH encaissement (E-3/4/5), discount→VAT (E-6), refund (L-5), Z-close (T-10/N-4), kiosk wizard (L-3) — all untested and several historically stub/buggy.
