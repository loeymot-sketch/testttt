# PLAN EXCERPT — CV1-M06-POS-REVENUE-GUARDS

M-06 goal: harden `payment-confirm`, create POS collect kiosk cash route, handle cleanup/confirm race, make no-op status side effects idempotent, and prevent forged subtotal discount permission.

Gates approved: frozen zones Option C; payment prop mutation Option A.

Allowlist is the mission allowlist only. Required symmetry note because `OrderService` and `FrontendOrderService` may both be touched.
