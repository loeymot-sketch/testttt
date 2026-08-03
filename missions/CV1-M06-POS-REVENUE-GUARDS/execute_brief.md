# EXECUTE BRIEF — CV1-M06-POS-REVENUE-GUARDS

Implement only M-06 from the parent plan.

Do not touch schema, fiscal, KDS, offline or web/Stripe surfaces. Use `OrderStatus` enum constants; no magic statuses. Preserve backend pricing SSOT and dispatch-after-commit.

Minimum evidence: focused tests for payment confirm ability/cross-branch, no-op side effects, cleanup-vs-confirm, POS collect kiosk cash route, and POS discount forgery.
