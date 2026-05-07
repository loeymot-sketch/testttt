# AUDIT KIOSK CYCLE K4 — Captures Index 2026-05-07

Total findings: 5

| Step | Slug | State | Sev | Note | Screenshot |
| --- | --- | --- | --- | --- | --- |
| K4-01 | payment-render | rendered | OK | Page rendered. Card=false, Cash=false, TR=true (kiosk redirect to cart if cart empty is normal) | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle4/01-payment-screen-rendered.png` |
| K4-02 | idempotency-wired | wired | OK | Order POST idempotency=true, payment-confirm idempotency=true | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle4/02-idempotency-routes-wired.png` |
| K4-03 | counter-collect-api | status-401 | OK | GET /api/admin/pos/counter-collect/pending status=401 | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle4/03-pos-counter-collect-panel-view.png` |
| K4-04 | sentinel-idempotency-config | configured | OK | frontend/order route in required_routes=true, payment-confirm=true | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle4/04-sentinel-idempotency-config-configured.png` |
| K4-05 | kr2-coupon-svc | wired | OK | CouponService.validateCouponForOrder calls isUsableNow=true, branch+surface signature=true | `tests/e2e/screenshots/audit-kiosk-2026-05-07/cycle4/05-kr2-coupon-svc-wiring-ok.png` |