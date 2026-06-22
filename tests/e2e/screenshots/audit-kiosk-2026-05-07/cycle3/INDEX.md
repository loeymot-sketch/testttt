# AUDIT KIOSK CYCLE K3 — Captures Index 2026-05-07

Total findings: 6

| Step | Slug | State | Sev | Note | Screenshot |
| --- | --- | --- | --- | --- | --- |
| K3-01 | categories | count | P2 | 0 cards/buttons détectés via heuristique | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle3/01-categories-rendered.png` |
| K3-02 | category-click | attempted | OK | Premier élément clicable cliqué | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle3/02-categories-after-click.png` |
| K3-03 | wizard-render | blank | P1 | Wizard route /kiosk/wizard/1: text length=44 | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle3/03-wizard-item-1.png` |
| K3-04 | cart | rendered | OK | Cart length=118, hasError=false | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle3/04-cart-state.png` |
| K3-05 | kr2-coupon-echo | wired | OK | CouponChanged broadcastAs=true, handler=true, KR2 ref=true | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle3/05-kr2-sentinel-wired.png` |
| K3-06 | kr2-branch-isolation | enforced | OK | _normalizeBranchId=true, _getActiveBranchId=true | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle3/06-kr2-branch-isolation-enforced.png` |