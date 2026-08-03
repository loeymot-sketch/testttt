# GPT Self-Audit — CV1-FIX-KIOSK-QUOTE-TOKEN-REQUIRED

## Diff Summary

- `OrderRequest` conditionally requires `quote_token` and `quote_signature` for kiosk order tokens.
- `OrderQuoteService::sealForCommit()` requires the signed quote pair for both POS and kiosk surfaces.
- Added `KioskQuoteTokenRequiredOnCommitTest` covering missing token, missing signature, expired token, and valid pair.
- Updated existing kiosk tests to request and submit signed quotes where they exercise real commits.

## Validation

- Target test: 4 passed.
- Kiosk/security/order targeted set: passed after POS test migration.
- Broad backend filter `Order|Frontend|Kiosk|Quote`: 561 passed, 7 skipped, 1 failed on M-13 queue number uniqueness gate only.
- Kiosk Vitest suite: 398 passed.

## Invariants Reviewed

- Backend pricing remains SSOT.
- Kiosk branch is resolved from `KioskMachine`, not client payload.
- Existing expired quote behavior remains `410`.
- No changes to `EventContract::REQUIRED_PAYLOAD_KEYS`.

## Risk

The implementation depends on the existing order quote subsystem, which is still affected by the repository's Phase A/A.6 persistence issue because quote files/migration are not all cleanly tracked.

SELF_AUDIT_VERDICT: PASS_WITH_EXTERNAL_GATE
