# Execute Brief — CV1-M21B-PAYMENT-REFACTOR

Mode: GPT-only, no Claude, no sub-agent.

## Objective

Apply the signed `GATE_PAYMENT_PROP_MUTATION_2026-04-26` Option A:

- Refactor `resources/js/components/admin/pos/PaymentComponent.vue` away from direct prop mutation.
- Let `PosComponent.vue` own updates to the payment form through an explicit event or local handler.
- Add a one-shot 401 retry in the payment confirm path: refresh auth once, retry once, then fail with a clear session-expired error.

## Scope

Allowed files:

- `resources/js/components/admin/pos/PaymentComponent.vue`
- `resources/js/components/admin/pos/PosComponent.vue`
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue` only if the same prop-mutation anti-pattern exists and needs symmetric handling.
- New or updated Vitest files listed in `input.json`.

Do not edit backend, routes, migrations, public built assets, OrderService, or FrontendOrderService.

## Validation

- `npm test -- tests/js/paymentComponentPropMutation.spec.js tests/js/paymentComponent401Retry.spec.js tests/js/posPaymentComponentContract.spec.js`

## Invariants

- pricing_ssot: no frontend price authority; payment form reset is UX state, not pricing truth.
- dispatch_after_commit: no backend dispatch touched.
- frozen_zones: gate prop mutation is signed in `docs/gates/GATE_LOG.md`.
- OS/FOS symmetry: N/A unless backend services are edited; they are off-limits here.
