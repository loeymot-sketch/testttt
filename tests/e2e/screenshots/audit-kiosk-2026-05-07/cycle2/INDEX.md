# AUDIT KIOSK CYCLE K2 — Captures Index 2026-05-07

Total findings: 10

| Step | Slug | State | Sev | Note | Screenshot |
| --- | --- | --- | --- | --- | --- |
| K2-01 | tokens | check | OK | Tokens: primary=#F4501E, bgPrimary=MISSING, bold=NOT_SET | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle2/01-surface-1080x1920-idle.png` |
| K2-01 | a11y-idle | analyzed | OK | Violations a11y: 0 (critical+serious=0, passes=18) | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle2/01-surface-1080x1920-idle.png` |
| K2-01 | monitoring-idle | capture | INFO | JS errors=0, console errors=2, network 4xx/5xx=0 | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle2/01-surface-1080x1920-idle.png` |
| K2-02 | a11y-categories | analyzed | OK | Violations: 0 (crit+seri=0) | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle2/02-surface-1920x1080-categories.png` |
| K2-02 | monitoring | capture | INFO | JS=0, console=4, network=0 | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle2/02-surface-1920x1080-categories.png` |
| K2-03 | tablet-rendering | check | OK | Shell kiosk visible at 768×1024: true | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle2/03-tablet-768x1024-idle.png` |
| K2-04 | categories-screen | loaded | OK | Page catégories chargée | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle2/04-screen-categories.png` |
| K2-04 | cart-screen | loaded | OK | Page cart chargée (peut être empty fallback) | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle2/04-screen-cart.png` |
| K2-04 | error-network-screen | loaded | OK | Page error/network chargée | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle2/04-screen-error-network.png` |
| K2-05 | kr3-env | observed | INFO | KR3 sentinel — env validated par parent agent (KioskMachine #1 branch_id=1, pas 0). Vrai test côté Feature PHP backend. | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle2/05-kr3-channel-isolation-env-check.png` |