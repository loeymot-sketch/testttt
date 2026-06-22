# GPT Self-Audit — CV1-FIX-R1-POS-QUOTE-BINDING-TESTS

## Diff Summary

- Added `HasPosQuoteBinding` test concern.
- Migrated POS legacy suites to include quote token/signature through the helper.
- Preserved forged total/subtotal inputs where tests are proving backend SSOT behavior.
- Added kiosk quote helper usage for parity/sync tests that commit through frontend order paths.

## Validation

- POS quote-binding migration filter: 68 passed.
- Kiosk Vitest suite: 398 passed.
- Broad backend filter: 561 passed, 7 skipped, 1 failed on M-13 queue number uniqueness only.

## Invariants Reviewed

- No backend production behavior changed in this mission.
- POS commit still consumes server-generated quotes.
- Client price spoofing tests still assert backend recomputation.

SELF_AUDIT_VERDICT: PASS
