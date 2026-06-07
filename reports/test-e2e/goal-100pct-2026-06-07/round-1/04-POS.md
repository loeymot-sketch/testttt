# AGENT 04 — SYSTÈME POS / CAISSE — Round 1 Report
**Date:** 2026-06-07 · **DB:** foodking_e2e (disposable clone) · **Server:** http://127.0.0.1:8766
**Mode:** Read-only audit + abusive E2E + DB verification. **0 frozen-zone lines changed.**

---

## VERDICT
**PASS (no P0/P1 product defects).** The §4 owner-priority gap — non-CASH encaissement
(CARD/SumUp-ref, Ticket-Resto, Mobile) — is now **proven end-to-end through the real
unified modal** with correct DB persistence, gap-free fiscal allocation, correct operator
identity, and the drawer-over-counting invariant (D-3) held. Refund (pre-Z), loyalty redeem,
discount→TVA netting, idempotency, quote SSOT, and composition_snapshot freeze all verified.
The full `tests/Feature/Pos/*` suite passes **82/82** under the canonical runner.
3 non-blocking findings (2 test-quality P2, 1 E2E flake P3). One config posture (manual
discount default ON) is **intentional and proven safe** — NOT a defect (see Note 1).

---

## EVIDENCE BY AXIS

### AXE A — TECHNIQUE (backend / SSOT / contracts)
| Item | Status | Evidence |
|------|--------|----------|
| A1 endpoints no 500/HTML-on-/api | PASS | counter-collect confirm → HTTP 200 ×6 (orders 101,106,110,112,228,229); refund → HTTP 200 |
| A2 FormRequest + authz on mutation | PASS | `routes/api.php:799,839,879` `abort_unless(can('pos'))`; refund `PosOrderController.php:58-73` `can('pos-refund')` + cross-branch guard |
| A3 Idempotency on POST mutating | PASS | `routes/api.php:799` `idempotency` mw; `IdempotencyKeyMiddleware.php:41`; live proof: order-create w/o key → 422 `MISSING_IDEMPOTENCY_KEY` (diagnostic run) |
| A4 price 100% backend SSOT | PASS | `PosController::quote` → `OrderQuoteService` → `PricingService`; front sends id+qty+options only; `PosOrderRequestNoClientTotalsTest` + `QuoteBindingTest` green (in 82/82) |
| A5 composition_snapshot frozen | PASS | order_items 101/106 snapshot `{captured_at, schema_version:1}` immutable at creation |
| A6 0 regression (PHPUnit) | PASS | `php artisan test tests/Feature/Pos/` → **82 passed**; Loyalty 7/7; Refund+Split+Bucketing 19/19; ZReportDiscountNetting 5/5 |

### AXE B — INTERFACE (chaque bouton / état)
| Item | Status | Evidence |
|------|--------|----------|
| B1 register: tile→wizard→qty→cart→park | PASS | LIVE pilot as POS operator at `/admin/pos` (`zz-pos-register-pilot`): clicked `Boisson Seule` tile → **frozen Vanilla JS wizard opened** (piloted, NOT modified) → qty stepper +1 → "Ajouter au panier" → toast "Article ajouté", order panel "2 Articles", **Sous-total 4,00 €** correct → "Mettre en attente" (park) clicked OK; "Ajouter remise" + "Annuler la dernière ligne" + "À emporter/Livraison" all present (`__screenshots__/pos-register-2026-06-07/{A-wizard-open,B-cart-after-add,C-after-park}.png`) |
| B1 register: encaisser piloted | PASS | "Encaisser" piloted ×6 end-to-end (CARD/TR/Mobile/CASH) + park piloted live |
| B1 tiroir / no-sale / Z-close buttons | PARTIAL | "Ouvrir tiroir" + "Caisse" + "À encaisser (200)" + "Suivi commandes" + "Écran client" chrome VISIBLE (screenshot) but **not click-piloted this round**; their LOGIC is backend-covered by `Feature/Pos/*` 82/82 + `PosCashTrailTest` + `CashDrawerSessionOwnershipTest`. → Sequence literal click-coverage (open-drawer / no-sale / Z-close) into round 2 if owner wants UI proof |
| B1 encaissement modal buttons | PASS | 4 mode tiles driven (Espèce/Terminal-manuel/Mobile/Ticket) via `data-testid pos-counter-collect-mode-{CARD,TICKET,MOBILE,CASH}`; confirm/cancel/close/numpad present |
| B2 états vide/cash-block/noncash-block | PASS | empty register = "Aucun article. Sélectionnez un produit"; CASH → `cash-block` numpad; non-CASH → `noncash-block` info; CARD → `card-ref-block` ref input |
| B3 0 raw label / undefined / NaN | PASS | CARD-modal + register + wizard screenshots: FR throughout, 0 `Label.x`, 0 undefined/NaN, prices in FR format (`3,00 €`, `36,00 €`) |
| B5 double-submit guard | PASS | `submitting` flag + idempotency minute-bucket key (`PosCounterCollectModal.vue:446-449`); race → 409 `payment_already_collected` (`PaymentService.php:305`) |

### AXE C — VISUEL (operator perspective)
| Item | Status | Evidence |
|------|--------|----------|
| C1 capture chaque effet | PASS | 8 screenshots (modal+after) per mode in `tests/e2e/__screenshots__/pos-4modes-2026-06-07/` |
| C2 layout/branding Cayenne | PASS | CARD modal: orange #F4501E total, "LE CAYENNE" header, light mode, no overflow |
| C3 perspective OPÉRATEUR | PASS | Encaissement queue (operator) + collect modal both readable; BORNE badges, queue cap chip "200", "Encaisser" CTAs |
| C4 palette correcte | PASS | Cayenne orange total + active-mode highlight, neutral tiles |

### AXE D — FLUIDITÉ / UX
| Item | Status | Evidence |
|------|--------|----------|
| D1 clic→effet <1s | PASS | 4-mode spec full run 34.5s for 4 collects incl. nav; confirm API <1s each |
| D2 parcours complet | PASS | borne-pending → encaissement queue → collect modal → confirm → PAID → (refund) end-to-end no block |
| D3 reprise d'erreur | PARTIAL | race-loser 409 path coded+unit-tested; cash-no-session skip flagged (`cash_movement_skipped`); not abused live this round |
| D3 (drawer over-count) | PASS | **CARD/TR/Mobile wrote 0 cash_movements; only CASH(112) wrote 1** (cm 166→167, id 167, session 7) → drawer NOT over-counted |

### AXE F — DONNÉES / DB / NF525 (within my scope)
| Item | Status | Evidence |
|------|--------|----------|
| F1 séquence gap-free | PASS | after all encaissements: 2019 seq rows, min 1 max 2019 expected 2019, **0 gap 0 dup**; my batch allocated 2014→2019 consecutive |
| F2 chaîne HMAC intègre | PASS | `fiscal:verify-chain --all` → CHAIN OK before AND after 6 collects + 1 refund |
| F3 historique tracé | PASS | each order: fiscal_sequence_no + pos_payment_method + editor_id + Transaction row + audit row |
| F4 cash-trail opérateur=caissier | PASS | **editor_id=1 (collecting cashier) on all 6**; audit `order.counter_payment_confirmed` user_id=1 ×4 |
| F (refund) | PASS | order 112 refund: audit `order.returned`+`payment.cash_back_issued` user_id=1; seq 2017 **preserved/immutable**; chain OK; no new fiscal# consumed (pre-Z) |

### §4 CIBLES OBLIGATOIRES
| Cible | Status | Evidence |
|-------|--------|----------|
| **Encaissement CARD (SumUp ref)** | **PASS** | order 101: pos_payment_method=2, Transaction `counter_card`, note `Encaissement Terminal manuel (SumUp) — réf: SUMUP-REF-1-...`, **0 cash_movement**, seq 2014, editor 1 |
| **Encaissement Ticket-Resto** | **PASS** | order 106: pos_payment_method=5, Transaction `counter_ticket_restaurant`, **0 cash_movement**, seq 2015, editor 1 |
| **Encaissement Mobile** | **PASS** | order 110: pos_payment_method=3, Transaction `counter_mobile_banking`, **0 cash_movement**, seq 2016, editor 1 |
| **Encaissement CASH (baseline)** | **PASS** | order 112: pos_payment_method=1, Transaction `counter_cash`, **1 cash_movement** (session 7), seq 2017, editor 1 |
| **Remboursement + log NF525** | **PASS** | order 112 refund HTTP 200 mode=pre_z; 2 audit rows; chain OK; seq immutable |
| **Remise/coupon → Z-TVA** | **PASS** | LIVE discounted quote on :8766 (item 3,00 € @10%, discount 2,00 €) → order stores pre-discount line `total_tax=0,27` (gross), and the **Z aggregation nets it** (ratio (subtotal-discount)/subtotal) to the post-discount declared TVA — exactly the documented `LOCK_ZREPORT_F1_DISCOUNT_NETTING` design (test ref: 10,00€/-2,00 → gross 0,91 → **declared 0,73**). `ZReportDiscountNettingTest` 5/5 incl. exact `total_tva == Σ total_by_tax_rate` + signed+chain-verified discounted Z. The fiscally-DECLARED TVA (signed Z) is net-base. Manual-discount default ON = post-F1-fix intentional (Note 1) |
| **Remise/coupon → TICKET-TVA** | DELEGATED | The mission line says "vérifier ticket + Z". I verified the **Z** half (net-base declared TVA). The **receipt/ticket** half — does a discounted-order ticket print the netted TVA (0,73) and not the stored gross (0,91)? — depends on whether `ReceiptDataService` reads `order->total_tax` directly vs nets it. That surface is **agent 09 (FISCAL/TICKET)** — NOT verified by me this round. Flagged so the supervisor does not inherit a false green on the printed ticket (§F.6 is the GOAL's whole point). |
| **LOYALTY gagner/échanger** | **PASS** | `PosLoyaltyRedeemTest` 7/7 (decrement+ledger, insuff→422, double→reject, cross-branch→403, perm→403) |
| **10 commandes gap-free** | PASS | 6 real UI collects + 4 consecutive same-batch all gap-free; full-volume seq 1..2019 gap-free; 10-loop spec flaked on SPA nav (P3, not fiscal) |

---

## NOTES (verified, NOT defects — anti-false-positive)

**Note 1 — manual_discount_enabled default = TRUE is intentional & proven safe.**
`config/pos.php:172` defaults `env('POS_MANUAL_DISCOUNT_ENABLED', true)`; `.env.e2e` does not
override → ON. The *first* comment block (`config/pos.php:148-160`) says "DEFAULT FALSE" but it
is **superseded** by the block at `:161-173` ("Default flipped false → true. F1 ... is FIXED in
ZReportService under LOCK_ZREPORT_F1_DISCOUNT_NETTING_2026_05_31"). The F1 fiscal-incorrect-Z
defect was fixed (commits `747204e9c`, `1ff06f171`) and the netting is proven by 5 green tests
including a signed+chain-verified discounted Z. The flag remains a runtime kill-switch. → No finding.

**Note 2 — `--env=testing` (MySQL foodking_test) inflates Pos failures to 28.**
Running `php artisan test --env=testing` loads `.env.testing` which (combined with route idempotency)
makes order-create POSTs 422 with `MISSING_IDEMPOTENCY_KEY` because the test fixtures
(`HasPosQuoteBinding`) don't add `X-Idempotency-Key`. The canonical runner (`php artisan test`,
phpunit.xml → SQLite :memory:, idempotency default OFF) passes **82/82**. The 28 "failures" are a
runner artifact, NOT product or test defects under the canonical config. → No P0/P1.

---

## FINDINGS (all non-blocking)

- **[P2]** `tests/Feature/Fiscal/ManualDiscountDisabledV1SentinelTest.php:115` &
  `SplitPaymentEndToEndTest.php` (and ~26 sibling Pos tests) — the 4 discount-refusal asserts
  use `assertStatus(422)` which is satisfied by EITHER the discount gate (correct) OR a
  `MISSING_IDEMPOTENCY_KEY` 422 when run with idempotency ON. The assertion does not pin the
  422 *reason*, so these tests would silently pass even if discounts were no longer gated and the
  422 came from idempotency. Repro: run with `IDEMPOTENCY_MIDDLEWARE_ENABLED=true` → `zero_discount`
  sub-test 422 (MISSING_IDEMPOTENCY_KEY) proves the ambiguity. Reco: assert on response `code`/body,
  not bare status; add `X-Idempotency-Key` to `HasPosQuoteBinding` so fixtures match the live route.

- **[P3]** `tests/e2e/zz-encaissement-flow-2026-06-07.spec.js:33` &
  `zz-encaissement-10orders-2026-06-07.spec.js:29` — flaky: after `loginAsAdmin` the SPA router
  intermittently keeps `/admin/dashboard` so the loop's `page.goto('/admin/encaissement')` hasn't
  rendered `.enc-collect-btn` before the 25s assert (1st run failed, screenshot = dashboard; 2nd run
  passed encashing order 228). Not a product defect. Reco: `waitForURL(/encaissement/)` +
  `expect(.enc-count-chip)` before asserting the button; or retry the goto.

- **[P3-info]** Counter-collect single-tender writes a `transactions` row but no `order_payments`
  row (only split-tender + refund write `order_payments`). This is correct by design — the Z by-method
  bucket reads `order.pos_payment_method` for single-tender (`ZReportService.php:697`) — but worth a
  one-line doc note so a future reader doesn't mistake the absent `order_payments` for a gap.

---

## ARTIFACTS
- New spec: `tests/e2e/zz-pos-4modes-encaissement-2026-06-07.spec.js` (4-mode, DB-asserted)
- Screenshots: `tests/e2e/__screenshots__/pos-4modes-2026-06-07/` (8 png) + collected.json
- Frozen diff: `git diff --stat` on pos-wizard.js / PaymentComponent.vue / PosV5TrancheRow.vue /
  Services/Fiscal = **empty (0 lines)**
