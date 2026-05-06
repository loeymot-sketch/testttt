# AUDIT POS CYCLE 3 — Captures Index 2026-05-06

Total findings cycle 3: 7

| Step | Slug | State | Severity | Note | Screenshot |
| --- | --- | --- | --- | --- | --- |
| C3-01 | wizard-confirm | clicked | OK | Confirm result=clicked, wizardClosed=true, cart="Articles
0" → "Articles
1" (changed=true) | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle3/01-wizard-closed-after-confirm.png` |
| C3-01 | api-calls | count | INFO | 7 POS API calls capturés | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle3/01-wizard-closed-after-confirm.png` |
| C3-02 | payment-modal | sections | OK | Total label=true, cash=true, card=true | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle3/02-payment-modal-opened.png` |
| C3-03 | cash-method | selected | OK | Méthode cash sélectionnée | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle3/03-payment-cash-selected.png` |
| C3-03 | cash-amount | filled | OK | Montant 20.00€ saisi | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle3/03-payment-cash-amount-20.png` |
| C3-03 | cash-change | visible | OK | Change displayed=true | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle3/03-payment-cash-amount-20.png` |
| C3-04 | api-flow | capture | INFO | Total: 9. POS create POST: status=429. Quote: absent | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle3/04-submit-after-submit.png` |