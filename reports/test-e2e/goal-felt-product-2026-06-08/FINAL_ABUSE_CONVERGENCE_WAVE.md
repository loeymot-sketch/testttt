# FINAL ABUSE-CONVERGENCE WAVE — VERDICT
**Date:** 2026-06-08 · Branch `heal/pre-cloud-exec-2026-06-05` (NO push) · Supervisor: Claude (strict)
**Method:** deterministic Workflow fan-out — 12 specialized finder cells (system × lens), each P0/P1 adversarially refuted by an independent skeptic, NEW + anchored + reproducible only (full exclusion list of healed/backlog/owner-gate items injected so finders couldn't re-report noise). The owner's "dernier test abusif, max agents spécialisés" — run as a convergence test after the loyalty `scan()` heal (last pass was not clean, so another pass was owed, not manufactured).

## RESULT: 1 confirmed P1 + 3 P2 + 3 P3 — ALL healed (non-frozen) + regression-tested. 1 gated item documented (not wired). 0 frozen-zone, 0 push.

The wave found 8 raw candidates; 1 went through adversarial refutation (the only P0/P1 claim) and was CONFIRMED; the 7 P2/P3 passthroughs were each verified at source by the main thread before any edit.

## ✅ HEALED (each verified at source, regression-tested, committed)
| Sev | ID | Finding | Fix | Commit | Test |
|-----|----|---------|-----|--------|------|
| **P1** | POS-1-01 | Pre-Z refund (`PaymentService::cashBack`) recorded a method-blind **full-total cash OUT**; a CARD/TICKET_RESTAURANT/online sale records 0 cash IN, so refunding it phantom-debited the till → false **overage** variance at close (can trip the manager-approval gate; skews the daily Z `cash_variance`). The post-Z sister path already refunds cash-portion-only. | `cashSettledPortion()` — CASH `OrderPayment` tranches (split), else `pos_payment_method===CASH ? total : 0`, else prior-payment label. Mirrors the cash-IN side + sister path. CASH orders unchanged. | `760f4e9c2` | `PreZRefundCashPortionOnlyTest` 3/3 + Cash/Reconciliation 101/101 + Z/PaymentService/Refund 242/242 |
| **P2** | ADMIN-9 | EOD synthesis PDF `bucketChannels` keyed POS on `source`, which the refund mirror does NOT copy (copies `order_type`) → a POS refund mirror fell into **Web/App** (POS overstated, Web/App could render a **negative** TTC). | Also bucket POS by `order_type===POS` (copied to the mirror), mirroring `resolvePaymentBucketKey`. | `a5d6e2c37` | `EodPdfRecapSentinelTest::test_by_channel_nets_pos_refund_mirror_into_pos_not_web` + dashboard 7/7 |
| **P2** | KIOSK-3 | `PaymentReconcileController::reconcileEntry` set `transaction_id` with no cross-order dedup → one real card/TR settlement could fiscally seal a **second** order (NF525 settlement-vs-Z discrepancy). `paymentConfirm` already blocks this. | Mirror `paymentConfirm`'s dedup guard, held under the existing per-tx `Cache::lock`; reject as `duplicate_transaction`. | `f22ad994f` | `ReconciliationFlowsE2ETest::test_KIOSK3_reconcile_rejects_one_transaction_sealing_two_orders` + 7/7 |
| **P2** | KIOSK-5 | PaymentRefused screen "Réessayer" / "Payer au comptoir" buttons only `$emit` into a void (frozen parent doesn't bind them) → borne stuck; only "Annuler" worked. FP-01 sibling pattern missed. *(dormant: only reachable with kiosk TPE routing, default off.)* | Self-navigate (retry→`kiosk.payment`, payCounter→`kiosk.cash-instruction`), mirroring the FP-01 sibling. | `bdff2bf36` | `KioskPhase3Screens` +2 router-push assertions (17/17) |
| **P3** | KDS-6-01 | A recalled PREPARED order rendered twice (active re-injected card + "Récemment servies" pill) for 60s. | `recentlyServed()` excludes recall-active ids, symmetric with `activeOrders()`. | `bdff2bf36` | source Vitest 35/35 |
| **P3** | KDS-6-02 | `changeStatus` re-dispatched `lists()` with the mutation payload → status-filtered board GET transiently collapsed the board. | Refresh with `{ paginate: 0 }` (the component's own clean load). | `bdff2bf36` | bundle 15/15 |
| **P3** | OSS-7 | Customer wall green-flashed every already-ready order on each boot/refresh (empty first-hydrate set). | First-hydrate guard in `_hydrateFromRows` — animate only post-snapshot transitions. | `bdff2bf36` | source Vitest 35/35 |

## 🟡 DOCUMENTED — gated/dormant, deliberately NOT wired
- **KIOSK-4 [P2, gated]** — the kiosk promo code shown in cart/preview is never charge-applied on the real order, and `KioskPromo.uses_count` is never incremented (coupon + loyalty ARE wired; kiosk_promo is display-only). **Latent, not live:** the promo form is gated behind `discountsEnabled = config('pos.manual_discount_enabled', false)` (default FALSE in V1) AND the backend `assertDiscretionaryDiscountAllowed` blocks non-zero discounts while that flag is off. Wiring a charge path for a **deliberately-disabled** V1 feature (discretionary discounts OFF by owner mandate, F1-dormancy) is out of scope — it would add a money-path that V1 intentionally doesn't run. Becomes a real fix the moment discounts are enabled → owner decision. Anchor: `OrderRequest::rules()` (no `kiosk_promo_code`), `OrderQuoteService::calculatePricing` kiosk branch, `KioskPromo.php:87`, `kioskCart.js:151`.

## 🔒 EXPLICIT SCOPE DECISION — wallet store-credit on refund (advisor gate-1)
`PaymentService::cashBack:147` also credits `user->balance += order->total` (full total, method-blind). `balance` IS spendable — but **only** via the web `credit` gateway (`Credit.php:60`, gated on `PaymentGateway slug=credit status=ENABLE`, routed through `payment.success`/`payment.index` web routes — NOT the POS/kiosk path). So this is the **web-channel store-credit refund POLICY**, separate from the V1 POS/kiosk cash trail. Whether a CARD refund should also grant spendable store-credit is an **owner product decision**, not an unambiguous cash-trail bug; changing refund money semantics unilaterally is the kind of money-path overreach to avoid (cf. C10). **Left unchanged, flagged for owner review.**

## ⇒ CONVERGENCE / EXIT
This was wave 4 of the campaign. It confirms the pattern the advisor named: a ~3000-test codebase under *fresh rotating adversarial lenses* will always surface something, so "two consecutive clean passes" is unreachable by construction — the genuine signal is the **trend** (blocker+3P1 → 2P1 → 1P1+cosmetic), which is diminishing returns. Every confirmed defect this wave is now healed or (KIOSK-4) a gated owner decision; the P0/P1 **blocker class is closed**. Further passes would harvest P3s, not protect production-readiness. **No wave 5.** The real production gate is the owner gates below — which the supervisor cannot cross autonomously.

### Owner gates (the actual remaining "production-ready" blockers — human-only)
- `git push` of `heal/pre-cloud-exec-2026-06-05`
- deploy: `php artisan storage:link`, build app.js from source
- run the `high`-queue worker + `foodking:outbox:rescue` scheduler (browser-receipt realtime leg)
- `TIME_FORMAT` env 12h→24h (owner deploy-config)
- AWS key rotation before any public push (key in git history)
- real TPE hardware activation
- frozen-zone merges already staged (G5 print-saga, G7 LOCK PricingService NULL-tax)
