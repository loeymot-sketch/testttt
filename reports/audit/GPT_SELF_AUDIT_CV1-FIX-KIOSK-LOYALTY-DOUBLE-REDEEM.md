# GPT Self Audit - CV1-FIX-KIOSK-LOYALTY-DOUBLE-REDEEM

SELF_AUDIT_VERDICT: PASS_WITH_EXTERNAL_FAILURES

## Diff Summary

- `FrontendOrderService`: replaced inline kiosk loyalty redemption with a single helper that either attaches an existing pending kiosk redeem transaction or performs one debit plus one ledger write.
- `KioskLoyaltyDoubleRedeemRefusedTest`: added coverage for redeem-then-order, direct order redemption, and mismatched pending redemption amount.

## Invariants

- Pricing SSOT: no frontend pricing logic added.
- Branch isolation: no branch-scope bypass added.
- OrderService symmetry: no POS order path modified by this mission.
- Dispatch after commit: no dispatch behavior changed.
- Loyalty ledger: one real debit maps to one `loyalty_transactions` row.

## Validation

- `php artisan test tests/Feature/KioskLoyaltyDoubleRedeemRefusedTest.php`: PASS, 3 tests
- Broad filter `KioskLoyalty|Loyalty|Frontend|Kiosk`: 245 passed, 7 skipped, 5 failed from unrelated planned POS/KDS lots.

## Residual Risk

The test isolates quote sealing because kiosk quote-token enforcement and loyalty quote parity are scheduled under `CV1-FIX-KIOSK-QUOTE-TOKEN-REQUIRED`.
