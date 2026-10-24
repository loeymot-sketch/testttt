# Plan — MOBILE-EXCELLENCE-PHASE24-HOME-PROMO-ORDER-20261018

## TASK_ID

`MOBILE-EXCELLENCE-PHASE24-HOME-PROMO-ORDER-20261018`

## PRIMARY_EXECUTION_MODEL

`gpt-5.5-pro`

## REASONING_EFFORT

`xhigh`

## PLAN_REVIEW

PLAN_REVIEW_CHANNEL: `codex-extension` (à obtenir avant toute édition produit)
PLAN_REVIEW_MODEL: `gpt-5.5-pro`
PLAN_REVIEW_REASONING_EFFORT: `xhigh`
PLAN_REVIEW_VERDICT: `PENDING`

## SUBSYSTEMS_TOUCHED

| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---:|---:|---:|
| Mobile home UI | `mobile/screens-main.jsx`, `mobile/styles.css` | Write | No | No |
| Mobile catalog/order audit | `mobile/data`, `mobile/api`, order/loyalty screens | Read first | No | No |
| Promotion/notification architecture | backend routes/config + mobile contract | Read only | No | No |

## SUBSYSTEMS_OFF_LIMITS

- Backend pricing, promotions, delivery fees and order totals until their API/SSOT contract is
  mapped and a dedicated gate exists.
- Mollie/Apple Pay, APNs/FCM push, loyalty policy, migrations, POS/kiosk/KDS and native signing.

## INVARIANTS_AT_RISK

- Backend remains the price and promotion source of truth.
- No frontend hardcoded business prices or delivery fees.
- No branch data leakage.
- No order dispatch or loyalty mutation from a visual-only change.

## GATE_CONDITIONS

- Human verification is required for real Apple Pay, push notifications, SMTP and TestFlight.
- A backend gate is required before changing production promotion/fee/payment behavior.

## Execution Steps

1. Baseline the real `mobile/` app and inspect current reservations/diffs.
2. Apply only the requested home copy/logo/status layout change.
3. Run mobile static and targeted E2E checks.
4. Audit remote-update capability: catalog, promotions, notification delivery, order status,
   loyalty refresh and payment providers; record gaps without inventing a frontend workaround.
5. Capture the end-to-end order flow and create follow-up gates for backend/payment/push work.

## SYMMETRY_NOTE

N/A — no `OrderService` or `FrontendOrderService` edit authorized.

## TEST STRATEGY

`local-validation` + `playwright-critical-flow` + `human-verification` for external services.

## ESCALATION

Do not claim App Store readiness until a signed iOS build and real TestFlight run exist.
