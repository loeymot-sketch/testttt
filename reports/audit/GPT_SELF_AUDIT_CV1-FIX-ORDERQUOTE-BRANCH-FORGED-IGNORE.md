# GPT Self Audit - CV1-FIX-ORDERQUOTE-BRANCH-FORGED-IGNORE

SELF_AUDIT_VERDICT: PASS

## Diff Summary

- Removed the kiosk-only `branch_id` mismatch rejection in `OrderQuoteService::resolveBranchId`.
- Added sentinel coverage proving forged kiosk `branch_id` is ignored and the quote is signed for the machine branch.
- Updated existing quote currency/origin coverage to the new kiosk branch-forcing contract.

## Invariants

- Branch isolation: kiosk branch remains server-resolved from `KioskMachine`, never from client payload.
- POS behavior: POS quotes still require a coherent branch payload and permission.
- Pricing SSOT: no pricing calculation path was changed.
- HMAC/intent hash: unchanged except that kiosk canonical payload now receives the authoritative machine branch.

## Validation

- Focused quote/security filter: PASS, 15 tests.

## Residual Risk

None in this mission scope. Kiosk quote token enforcement remains scheduled under `CV1-FIX-KIOSK-QUOTE-TOKEN-REQUIRED`.
