# REPORT — Index V1 Global Audit — 2026-04-15

Formal audit record for **Index V1 — FoodKing MVP** ([tasks/INDEX_V1.md](../../tasks/INDEX_V1.md)). Source: global audit cycle; aligns with V1 success criteria in INDEX.

## Executive summary

| Metric | Value |
|--------|--------|
| Tasks completed (executable) | 8 / 12 |
| Tasks shelved at human gate | 3 |
| Tasks blocked on upstream code | 1 (TEST_PRICING_STATE until Pricing + StateMachine exist) |
| Mandatory gate briefs on disk | 4 (`docs/gates/GATE_V1_*_2026-04-15.md`) |

## Vague 1 — Synchro foundation

| Task | Verdict | Evidence |
|------|---------|----------|
| TASK_V1_SYNC_BACKBONE_001 | PASS | `WebSocketService.js`, `ConnectionStatusBanner.vue`, `docs/PRODUCTION_SETUP.md`, boot guard in `AppServiceProvider` |
| TASK_V1_OUTBOX_001 | PASS | `domain_events` migration, `DomainEvent`, `DispatchDomainEventsJob`, `HasDomainEvents`, outbox listeners, commands, `docs/OUTBOX_PATTERN.md`, `OutboxTest.php`; no `implements ShouldBroadcastNow` in `app/` |
| TASK_V1_EVENT_CONTRACT_001 | PASS | `EventType`, `eventContract.js` + schema, `docs/EVENT_CONTRACT.md`, `EventContractTest.php` |

## Vague 2 — Domain SSOT

| Task | Verdict | Notes |
|------|---------|-------|
| TASK_V1_PRICING_SSOT_001 | See implementation cycle | Gate: `docs/gates/GATE_V1_PRICING_SSOT_001_2026-04-15.md` — frozen zone + feature-flag rollback |
| TASK_V1_STATUS_MACHINE_001 | See implementation cycle | Gate: `docs/gates/GATE_V1_STATUS_MACHINE_001_2026-04-15.md` — frozen zone + `order_status_transitions` |
| TASK_V1_MENU_86_001 | See implementation cycle | Gate: `docs/gates/GATE_V1_MENU_86_001_2026-04-15.md` — `item_branch_availability` migration |

## Vague 3 — Security base

| Task | Verdict | Evidence |
|------|---------|----------|
| TASK_V1_SEC_XSS_001 | PASS | `safeHtml.js`, DOMPurify, 3× `v-html` all via `safeHtml()`, no raw `innerHTML`, `docs/SECURITY_NOTES.md` |
| TASK_V1_SEC_CORS_RATELIMIT_001 | PASS | CORS origins from env (no `*` on origins), rate limiters + `CorsTest`, `RateLimitTest`, `docs/RATE_LIMITS_MATRIX.md` |

## Vague 4 — Data, observability, tests

| Task | Verdict | Notes |
|------|---------|-------|
| TASK_V1_DATA_SOFTDELETE_001 | See implementation cycle | Gate: `docs/gates/GATE_V1_DATA_SOFTDELETE_001_2026-04-15.md` |
| TASK_V1_OBS_HEALTH_CORR_001 | PASS | `HealthController`, `CorrelationIdMiddleware`, `JsonFormatter`, `HasCorrelationId`, routes, tests, `docs/OBSERVABILITY.md` |
| TASK_V1_TEST_PW_5FLOWS_001 | PASS | 5 Playwright specs + helper, `.github/workflows/playwright.yml`, `docs/PLAYWRIGHT_SUITE.md` |
| TASK_V1_TEST_PRICING_STATE_001 | Runs after Pricing + StateMachine | PHPUnit targets `PricingService`, `OrderStateMachine` |

## V1 success criteria scorecard

| Criterion | Status |
|-----------|--------|
| POS / Kiosk / KDS / OSS flows | E2E specs + CI workflow in place |
| Menu 86 &lt; 2s propagation | Depends on MENU_86 implementation |
| 0 pricing outside `PricingService` | Enforced after PRICING_SSOT + CI grep (recommended) |
| 0 status transitions outside state machine | Enforced after STATUS_MACHINE + CI grep (recommended) |
| 0 runtime `ShouldBroadcastNow` | PASS (no `implements` / `use` in `app/`) |
| Playwright + PHPUnit green | Run `php artisan test` + `npx playwright test` in CI/local |
| XSS / CORS / rate limits | PASS per Vague 3 |
| `/health` + correlation + JSON logs | PASS per OBS task |

## Minor findings

1. **Grep for `ShouldBroadcastNow`**: string may appear in docblocks only; CI should match `implements ShouldBroadcastNow` or `use Illuminate\...ShouldBroadcastNow`, not raw substring-only rules.
2. **`config/cors.php`**: `allowed_methods` may remain `['*']`; V1 rule targets **origin** wildcard, not HTTP method list.

## Human gate batch (prerequisite for frozen-zone / migration work)

Sign the four gate briefs (checkboxes in each file) before production deploy of Vague 2–4 schema/frozen edits:

1. `docs/gates/GATE_V1_PRICING_SSOT_001_2026-04-15.md`
2. `docs/gates/GATE_V1_STATUS_MACHINE_001_2026-04-15.md`
3. `docs/gates/GATE_V1_MENU_86_001_2026-04-15.md`
4. `docs/gates/GATE_V1_DATA_SOFTDELETE_001_2026-04-15.md`

Companion checklist: [docs/gates/GATE_BATCH_V1_APPROVAL_CHECKLIST.md](../../docs/gates/GATE_BATCH_V1_APPROVAL_CHECKLIST.md).

## Post-audit implementation (2026-04-15 cycle)

The following were implemented in-repo after the audit checklist:

- **Pricing SSOT:** `config/pricing.php`, `App\Services\Pricing\*`, feature flag `PRICING_USE_SSOT`, wiring in `OrderService` + `FrontendOrderService` — see [docs/PRICING_SSOT.md](../../docs/PRICING_SSOT.md).
- **Order state machine:** `App\Domain\Order\OrderStateMachine`, `IllegalTransitionException`, `order_status_transitions` migration + `OrderStatusTransition` model; `ValidStatusTransition` delegates to `OrderStateMachine`; audit rows written from `OrderService`, `FrontendOrderService`, `KitchenDisplaySystemOrderService` on successful transitions.
- **Menu 86:** `item_branch_availability` table, `ItemBranchAvailability`, `AvailabilityService`, `DecrementItemAvailabilityOnOrder` on `OrderCreated` — [docs/MENU_AVAILABILITY.md](../../docs/MENU_AVAILABILITY.md).
- **Soft delete:** `deleted_at` on `orders`, `order_items`, `branches`, `item_categories` + `deletion_log`; `SoftDeletes` on models; `SoftDeleteAuditObserver` — [docs/SOFT_DELETE_POLICY.md](../../docs/SOFT_DELETE_POLICY.md).
- **Human gate batch checklist:** [docs/gates/GATE_BATCH_V1_APPROVAL_CHECKLIST.md](../../docs/gates/GATE_BATCH_V1_APPROVAL_CHECKLIST.md) (sign-off still required for production frozen-zone governance).

## Sign-off

| Role | Name | Date |
|------|------|------|
| Audit author | Cursor Agent | 2026-04-15 |
| Human approver (gates) | _Pending_ | |
