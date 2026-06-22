# M05 Scope Proof — CV1-M05-ORDER-QUOTE

FOODKING_GPT_ONLY: 1
TASK_ID: CV1-M05-ORDER-QUOTE
STATUS: AUDIT_PASS before final audit rerun

## Why This Exists

The repository working tree contains many dirty files from earlier Caisse V1 missions and orchestration changes. This proof isolates the M05 rework surface so final audit can evaluate M05 without requiring unrelated reverts.

## M05 Product Scope

- `database/migrations/2026_04_25_190000_create_order_quotes_table.php`
- `app/Models/OrderQuote.php`
- `app/Services/Order/OrderQuoteService.php`
- `app/Services/OrderService.php`
- `app/Services/FrontendOrderService.php`
- `app/Http/Controllers/Admin/PosController.php`
- `app/Http/Controllers/Frontend/OrderController.php`
- `routes/api.php`
- `resources/js/components/admin/pos/PaymentComponent.vue`
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue`
- `resources/js/store/modules/kioskCart.js`
- `tests/Feature/QuoteExpirationTest.php`
- `tests/Feature/QuoteTamperTest.php`
- `tests/Feature/QuoteReplayIdempotencyTest.php`
- `tests/Feature/QuoteCurrencyOriginTest.php`
- `tests/Feature/QuoteDiscountAuthoritativeTest.php`

## M05 Governance Scope

- `missions/CV1-M05-ORDER-QUOTE/input.json`
- `missions/CV1-M05-ORDER-QUOTE/execute_brief.md`
- `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md`
- `plans/masterplay/MASTERPLAY_QUEUE.md`
- `reports/masterplay/status.json`
- `reports/post_execute_latest.log`
- `reports/audit/GPT_SELF_AUDIT_CV1-M05-ORDER-QUOTE.md`
- `reports/audit/GPT_AUDIT_CV1-M05-ORDER-QUOTE_REWORK_FIX_2026-04-25.md`

## Explicit Non-M05 Dirty Worktree Note

Other dirty files currently visible in `git status --short` belong to prior Caisse V1 setup, Wave A, M09, M04B, M06, generated bundles, gates, reports, and orchestration files. They are not reverted because the worktree is shared and those changes predate this M05 rework.

## Validation

- `php artisan test --filter=Quote` => 11 passed
- `php artisan test --filter='Quote|PosDiscountPermissionTest|PosDiscountForgeryTest|KioskPaymentStateMachineTest|PaymentConfirmCrossBranchTest|PaymentConfirmAbilityTest|PaymentConfirmMachineResolverTest|OrderStatusNoopSideEffectsTest|PaymentNoopIdempotencyTest|CleanupVsConfirmRaceTest|PosCollectKioskCashRouteTest|PosCashEndpointSentinelTest'` => 37 passed
- `npm run test -- tests/js/kioskCartSendPayload.spec.js tests/js/kioskWizardEditRoundtrip.spec.js` => 12 passed
- `git diff --check` on M05 scoped files => PASS

VERDICT: M05 scoped diff is defensible for final audit.
