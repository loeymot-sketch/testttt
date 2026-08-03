# MEGA PARCOURS E2E — Index — 2026-05-08

Total findings: **28** (P0=2, P1=4, P2=5, OK=15, INFO=2)
Total screenshots: **47**
Total DOM probes: 19
Total HTTP traces: 15
Total domain_events snapshots: 15
Total KDS reception traces: 4

## Findings table
| Order | Step | Slug | Severity | Note | Screenshot |
| --- | --- | --- | --- | --- | --- |
| PRE | 0 | bypass-on | OK | Bypass payment=true printing=true queue=database env=local | `—` |
| pos-1 | 11 | no-events-created | P0 | Aucun domain_event créé après UI+API submit (status=422). Body={"status":false,"message":"Article 363 indisponible pour cette branche (mega-kiosk-4)."} | `—` |
| pos-1 | 12 | kds-reception | P2 | KDS no match within 7s polling (websockets DOWN expected) | `pos-1-kds-reception.png` |
| pos-2 | 11 | no-events | P0 | UI added=3, UI events=0, API status=422, new events after API=0 | `—` |
| pos-2 | 12 | kds-reception | P2 | no KDS match (websockets DOWN expected) | `pos-2-kds-reception.png` |
| pos-3 | 11 | api-fallback-ok-tr | OK | TR mode submit via API, status=201, new events=1 | `—` |
| pos-3 | 12 | kds-reception | P2 | no KDS match | `pos-3-kds-reception.png` |
| pos-4 | 1 | toggle-off | OK | Toggle OFF 363 status=200 ms=32 | `—` |
| pos-4 | 3 | pos-ui-blocks-oos | OK | POS UI bloque tuile 363 (disabled/86badge présent) | `pos-4-step-03-tile-after-oos.png` |
| pos-4 | 5 | backend-other-status | P2 | Status=429, body={"message":"Too Many Attempts."} | `—` |
| pos-4 | 6 | toggle-on | P1 | Re-toggle ON status=429 | `—` |
| pos-5 | 1 | extra-off | OK | Extra 172 toggled OFF: {"ok":true} | `—` |
| pos-5 | 3 | extra-oos-not-marked-ui | P1 | Wizard ouvre mais extra OOS non marqué visuellement. Caissier peut sélectionner extra indispo. | `pos-5-step-03-wizard-with-oos-extra.png` |
| pos-5 | 4 | submit-without-oos-extra | P1 | Commande sans extra OOS: status=422 | `—` |
| pos-5 | 5 | backend-rejects-oos-extra | OK | Backend rejette 422 commande avec extra OOS | `—` |
| pos-5 | 6 | extra-restored | OK | Extra 172 restored | `—` |
| kiosk-1 | 10 | kiosk-ui-flow-ok | OK | Kiosk UI flow: products=1, cart count=7, events created=2 | `—` |
| kiosk-1 | 11 | kds-reception | P2 | no KDS match | `kiosk-1-kds-reception.png` |
| kiosk-2 | 7 | pending-counter-text-ok | OK | Ticket affiche bien la mention paiement comptoir | `kiosk-2-step-07-ticket-state.png` |
| kiosk-2 | 8 | events-counter | OK | Cash counter flow: 2 events created | `—` |
| kiosk-3 | 9 | kiosk-multi-events | P1 | multi-add result: added=1, cart count=0, events=0 | `—` |
| PRE | 0 | bypass-on | OK | Bypass payment=true printing=true queue=database env=local | `—` |
| kiosk-4 | 1 | toggle-off | OK | Toggle 363 OFF status=200 | `—` |
| kiosk-4 | 2 | tacos-filtered-from-kiosk | INFO | Item Tacos M absent du kiosk catalog (filtré pré-affichage). totalCards=1 | `kiosk-4-step-02-kiosk-cat-after-oos.png` |
| kiosk-4 | 4 | toggle-on | OK | Re-toggle ON | `—` |
| kiosk-5 | 1 | extra-off-kiosk | OK | Extra 172 OFF: {"ok":true} | `—` |
| kiosk-5 | 3 | no-wizard-opened | INFO | Aucun wizard kiosk ouvert (items direct-add). pCount=1 | `kiosk-5-step-03-wizard-or-cart.png` |
| kiosk-5 | 4 | kiosk-extra-restored | OK | Extra 172 restored | `—` |