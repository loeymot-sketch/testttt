# AUDIT POS CYCLE 5 ULTRA-DEEP — Captures Index 2026-05-06

Total findings cycle 5: 15

| Step | Slug | State | Severity | Note | Screenshot |
| --- | --- | --- | --- | --- | --- |
| C5-01 | a11y-pos-main | analyzed | OK | Violations: 0 (critical+serious=0). Passes: 30 | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle5/01-a11y-pos-main-initial.png` |
| C5-01 | monitoring-pos-main | capture | INFO | JS errors=0, console errors=4, network 4xx/5xx=0 | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle5/01-a11y-pos-main-initial.png` |
| C5-02 | floorplan-page | loaded | OK | Floorplan: page chargée. Bonjour
Caissier
Caissier

pos@lecayenne.fr

+330600000002

0.00€
Edit Profile
Change Password
Logou | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle5/02-floorplan-loaded.png` |
| C5-02 | a11y-floorplan | analyzed | OK | Violations a11y floorplan: 0 (crit+seri=0) | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle5/02-floorplan-loaded.png` |
| C5-03 | tracker-page | loaded | OK | Tracker: chargée | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle5/03-tracker-loaded.png` |
| C5-04 | cancel-line | after-click | OK | Cart chip: "Articles
1" → "Articles
0" (decremented=true) | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle5/04-cancel-last-line-after.png` |
| C5-05 | discount-input | filled | OK | Discount 5% saisi | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle5/05-discount-amount-filled.png` |
| C5-05 | discount-apply | disabled | INFO | Bouton apply disabled — peut nécessiter reason préalable | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle5/05-discount-amount-filled.png` |
| C5-05 | grand-total | displayed | INFO | Grand total = Total
3.00€ | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle5/05-discount-amount-filled.png` |
| C5-06 | cash-open-button | invisible | P2 | Bouton kiosk-cash-open invisible | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle5/06-kiosk-cash-panel-btn-missing.png` |
| C5-07 | responsive-1280 | rendered | OK | Grid visible at 1280x800: true | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle5/07-responsive-1280x800.png` |
| C5-07 | responsive-1920 | rendered | OK | Grid visible at 1920x1080: true | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle5/07-responsive-1920x1080.png` |
| C5-07 | responsive-768 | rendered | OK | Grid visible at 768 tablette: true | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle5/07-responsive-768x1024-tablet.png` |
| C5-08 | search-filter | works | OK | Tuiles avant=64, après recherche "Tacos"=4 (filtered=true) | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle5/08-search-after-typing-tacos.png` |
| C5-09 | delivery-toggle | missing | P2 | Bouton Livraison non détecté | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle5/09-delivery-button-missing.png` |