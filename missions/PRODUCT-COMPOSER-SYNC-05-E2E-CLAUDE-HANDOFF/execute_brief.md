# PRODUCT-COMPOSER-SYNC-05-E2E-CLAUDE-HANDOFF

## Intent

Run final end-to-end proof and produce the handoff report for Claude to audit independently.

## E2E scenarios

1. Admin creates a composed sandwich with bread, crudites, sauces, extras, and photo.
2. Kiosk receives the product and renders the right wizard steps.
3. POS receives the same product but keeps its staff-oriented UI.
4. Admin creates an assiette with a different step set; kiosk and POS do not ask sandwich-only questions.
5. A stock-tracked extra reaches zero; kiosk and POS show `RUPTURE` without hiding the option.
6. Kiosk order creates one queue number; POS live order board sees it.
7. KDS bumps order to ready; POS handover closes it.
8. Payment/emporter/livraison path is checked against the known current issues and recorded.

## Report requirements

The final report must include:

- list of implemented missions;
- files touched;
- commands run and results;
- open defects;
- unresolved gates;
- exact Claude audit prompt;
- `VERDICT: PASS|NEEDS_FIX|ESCALATE`.

## Known user-reported issues to verify

- kiosk must not expose admin/caisse navigation;
- kiosk connection-lost banner must be diagnosed;
- POS order must not require an arbitrary customer id for normal takeaway;
- delivery address and 5 km fee calculation must be verified;
- Google Maps dependency/fallback must be reported.

## Validation

- Playwright suite for the scenarios above.
- Backend sentinel suite for stock/catalog/order.
- Frontend unit tests for composer and rupture UI.
- Manual in-app browser readback on kiosk payment path.

## Exit criteria

- Claude can audit without chat context.
- Every known issue is either fixed by earlier missions or listed as a blocking defect with file references and repro steps.
