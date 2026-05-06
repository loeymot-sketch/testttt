# AUDIT POS CYCLE 4 — Captures Index 2026-05-06

Total findings cycle 4: 7
Order ID utilisé: #188

| Step | Slug | State | Severity | Note | Screenshot |
| --- | --- | --- | --- | --- | --- |
| C4-01 | print-receipt | 1st | P1 | status=429, is_duplicata=undefined, count=undefined | `—` |
| C4-02 | print-receipt | 2nd-duplicata | P1 | status=429, is_duplicata=undefined, count=undefined (must be >= 2 + duplicata=true) | `—` |
| C4-03 | pusher-websocket | no-ws | P2 | WebSockets: 0, subscribe events: 0 | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle4/03-tracker-pusher-live.png` |
| C4-03 | pusher-subscribe | absent | INFO | Pusher subscribe frames captured: false | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle4/03-tracker-pusher-live.png` |
| C4-04 | drawer-api | not-called | P2 | Aucun appel /api/pos/cash-drawer/open — bouton no-sale peut-être ouvre un confirm modal d'abord | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle4/04-no-sale-after-click.png` |
| C4-05 | pos-orders-list | loaded | OK | Historique POS orders: page chargée OK | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle4/05-pos-orders-list.png` |
| C4-05 | pos-orders-show | loaded | OK | Show order #188: détail chargé | `tests/e2e/screenshots/audit-pos-2026-05-06/cycle4/05-pos-orders-show-188.png` |