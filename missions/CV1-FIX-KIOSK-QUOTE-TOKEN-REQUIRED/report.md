# CV1-FIX-KIOSK-QUOTE-TOKEN-REQUIRED

TASK_ID: CV1-FIX-KIOSK-QUOTE-TOKEN-REQUIRED
PHASE: EXECUTE
EXECUTE_DELEGATION: codex-extension
AUDIT_OVERRIDE_PHASE_A: 1
OVERRIDE_REASON: human_authorization_2026-04-26 by user kossayelbenna8

## Summary

Kiosk order commit now requires `quote_token` and `quote_signature` for authenticated kiosk machine tokens. The service-level quote sealing path also treats `surface === kiosk` like POS: a complete signed quote pair is mandatory, partial pairs are rejected, and expired tokens keep the existing `410` behavior.

## Files

- `app/Http/Requests/OrderRequest.php`
- `app/Services/Order/OrderQuoteService.php`
- `tests/Feature/KioskQuoteTokenRequiredOnCommitTest.php`
- Existing kiosk/backend tests updated to generate a valid kiosk quote before commit where the new contract applies.

## Validation

- `php artisan test tests/Feature/KioskQuoteTokenRequiredOnCommitTest.php` -> 4 passed.
- `php artisan test --filter='AntiGravityTest|KioskThrottleKeysTest|ConcurrentOrderTest|OrderRejectsUnavailableBranchItemTest|KioskIdsOnlyPayloadTest|OrderAllergenSnapshotComposedTest|OrderAllergenSnapshotTest|KioskQuoteTokenRequiredOnCommitTest|KioskSecurityTest|KioskPaymentStateMachineTest|KioskFullFlowE2ETest'` -> 47 passed, 2 legacy POS quote-binding failures before mission #9.
- After mission #9/#10 and KDS fixture correction: `php artisan test --filter='Order|Frontend|Kiosk|Quote'` -> 561 passed, 7 skipped, 1 failed only on `QueueNumberUniquenessSentinelTest` (M-13 gate).

## Invariants

- Pricing SSOT: quote validation pins the backend quote; no frontend price logic added.
- Branch isolation: kiosk quotes and commits continue to force machine branch.
- Dispatch after commit: no event/listener contract changed.
- K-09B event payload: unchanged.

EXECUTION_VERDICT: PASS_WITH_EXTERNAL_GATE
