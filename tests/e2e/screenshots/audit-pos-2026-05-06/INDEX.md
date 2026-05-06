# AUDIT POS Captures Index — 2026-05-06

Total findings: 12

| Step | Slug | State | Severity | Note | Screenshot |
| --- | --- | --- | --- | --- | --- |
| 2 | surface-load | initial | OK | Tokens V5 OK : bgApp=#FFFBF5, brandRed=#E8001C | `tests/e2e/screenshots/audit-pos-2026-05-06/02-surface-load-initial.png` |
| 6 | categories-strip | visible | OK | 15 catégorie(s) affichée(s) | `tests/e2e/screenshots/audit-pos-2026-05-06/06-categories-strip-visible.png` |
| 6 | categories-strip | click-2nd | OK | Clic 2e catégorie réussi (filtrage menu) | `tests/e2e/screenshots/audit-pos-2026-05-06/06-categories-strip-after-click-2nd.png` |
| 7 | items-grid | visible | OK | 64 tuile(s) produit affichée(s) | `tests/e2e/screenshots/audit-pos-2026-05-06/07-items-grid-visible.png` |
| 8 | add-simple-item | wizard-opened | INFO | Premier item testé est composé (wizard ouvert) — capture wizard côté Step 09 | `tests/e2e/screenshots/audit-pos-2026-05-06/08-add-simple-item-after-click.png` |
| 9 | wizard-popup | opened-PROTECTED | OK | Wizard ouvert (DESIGN PROTÉGÉ — audit capture only). Footer=false, CTA=false | `tests/e2e/screenshots/audit-pos-2026-05-06/09-wizard-popup-opened-PROTECTED.png` |
| 11 | cart-panel | visible | OK | Cart panel détecté via selector: #pos-cart | `tests/e2e/screenshots/audit-pos-2026-05-06/11-cart-panel-visible.png` |
| 13 | parked-buttons | missing | P2 | 0 bouton(s) park trouvé(s) | `tests/e2e/screenshots/audit-pos-2026-05-06/13-parked-buttons-missing.png` |
| 16 | payment-trigger | missing | INFO | Bouton paiement non visible (cart probablement vide — normal si aucun item ajouté) | `tests/e2e/screenshots/audit-pos-2026-05-06/16-payment-trigger-missing.png` |
| 22 | tracker-button | visible | OK | Bouton tracker visible : true | `tests/e2e/screenshots/audit-pos-2026-05-06/22-tracker-button-visible.png` |
| 22 | no-sale-button | visible | OK | Bouton no-sale (drawer) visible : true | `tests/e2e/screenshots/audit-pos-2026-05-06/22-tracker-button-visible.png` |
| 26 | network-audit | pos-api | INFO | 0 appel(s) POS API capturés (dont 0 quote) | `tests/e2e/screenshots/audit-pos-2026-05-06/26-network-audit-capture.png` |

## Severity legend
- **OK** : étape validée
- **INFO** : observation neutre
- **P0** : bug bloquant (régression critique, design cassé, JS error fatale)
- **P1** : bug important (UX dégradée, design partiellement KO)
- **P2** : nice-to-have / mineur
