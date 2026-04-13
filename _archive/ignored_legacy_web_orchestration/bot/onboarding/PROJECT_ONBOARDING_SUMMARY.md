# FoodKing — Project Onboarding Summary

> Compact intelligence brief for Claude project onboarding.
> Generated 2026-04-12 from full repo scan.

---

## What is FoodKing?

FoodKing is a **restaurant SaaS platform** covering the complete operational cycle:
ordering, kitchen management, customer display, point-of-sale, and self-service kiosk.
Currently deployed as **"Le Cayenne"** (single-tenant).

**Stack**: Laravel 9 monolith + Vue 3 SPA + MySQL + Sanctum + Spatie Permission + Pusher/WebSockets + FCM.

---

## Architecture in 10 lines

1. **Laravel MVC monolith** — REST API in `routes/api.php`, Vue 3 SPA in `resources/js/`.
2. **Two order creation paths**: `OrderService` (POS/admin) and `FrontendOrderService` (kiosk/web) — both write to shared `orders` table.
3. **Backend is SSOT** for pricing, order totals, tax calculation, coupon validation, and status transitions.
4. **Branch isolation** enforced via `BranchScope` global scope on most models.
5. **Auth**: Sanctum tokens (admin/kiosk/frontend) + `ApiKeyMiddleware` (public routes/OSS).
6. **KDS and OSS** consume realtime events (Pusher/WebSockets); OSS is strictly **read-only**.
7. **Event-driven notifications**: `OrderCreated` / `OrderStatusChanged` → FCM push + mail + SMS listeners.
8. **Queued job**: `SendFcmNotificationJob` — queue worker reliability is a documented P0 risk.
9. **Frozen zones**: payment gateways, `PushNotificationService` internals, analytics, delivery boy.
10. **86 service classes**, **49 models**, **111 controllers**, **80 migrations**, **~41 test classes**.

---

## Order lifecycle (SSOT)

```
PENDING → ACCEPT → PREPARING → PREPARED → DELIVERED
```

- **PENDING**: Created by kiosk/web (`POST /api/frontend/order`) or POS. Server recalculates all prices.
- **ACCEPT**: Cashier confirms. KDS receives order.
- **PREPARING**: Chef sets via KDS. No return to PENDING.
- **PREPARED**: Chef marks done. OSS/POS see queue number.
- **DELIVERED**: Cashier closes. Irreversible. Loyalty points awarded.

**Forbidden**: client-imposed prices, kiosk driving status beyond creation, skipping statuses, modifying after DELIVERED.

---

## Non-negotiable invariants

| # | Invariant | Enforcement |
|---|-----------|-------------|
| 1 | Backend SSOT for price/total | `OrderService` / `FrontendOrderService` recalculate; frontend values ignored |
| 2 | Branch isolation | `BranchScope` global scope; status changes scoped to branch |
| 3 | Order status transitions controlled | `OrderService::changeStatus` — no skips, no reversals past DELIVERED |
| 4 | Kiosk cannot escalate privileges | `kioskToken` with limited Sanctum abilities (`kiosk:order`) |
| 5 | OSS is read-only | No write endpoints; `ApiKeyMiddleware` only |
| 6 | Coupon validation server-side | `CouponService` checks type/date/cap/floor |
| 7 | Events fire after DB transaction commits | `OrderCreated`, `OrderStatusChanged` → listeners/jobs |
| 8 | Queue number consistency | Generated server-side, displayed on KDS/OSS |

---

## Active scope vs frozen zones

### Active (can be modified with plan)
- Backend API core (order lifecycle, pricing, auth, kiosk)
- KDS / OSS realtime flows
- POS wizard and cart logic
- Reporting / QA loop
- Kiosk auth and ordering endpoints
- Vue 3 frontend components (admin, frontend, table)

### Frozen (no changes without explicit architectural plan)
- Payment gateways (Stripe, PayPal, Credit, Razorpay, etc.)
- `PushNotificationService` internals
- Admin analytics module
- Delivery boy module

---

## Key risks and open items (from MEMORY.md + continuity doc)

| ID | Risk / gap | Status |
|----|-----------|--------|
| R1 | `OrderService::changeStatus` — needs deep audit for edge cases | inspection queue |
| R2 | `BroadcastableOrder` / `ShouldBroadcastNow` — broadcasting reliability | inspection queue |
| R3 | Queue worker reliability (jobs silently failing) | P0 — documented risk |
| R4 | FCM push reliability and fallback | P1 — partial |
| R5 | Order amendment on web POS | P1 — not implemented |
| R6 | `FrontendOrderService` — needs full audit | inspection queue |
| R7 | Auth token refresh flow | inspection queue |
| R8 | `CouponService` edge cases | inspection queue |

---

## Agent workflow (how work gets done)

1. **Claude** (orchestrator): reads docs → writes plan in `reports/planning/latest.md` → reviews in `reports/review/latest.md`
2. **Cursor / Kimi** (executor): implements plan → writes report in `reports/execution/latest.md`
3. **Playwright** (verifier): E2E tests when Claude specifies `playwright-*` strategy → reports in `reports/antigravity/latest.md`
4. **Bugbot** (scanner): passive diff scan → `reports/review/bugbot-latest.md`
5. **Human** validates at plan and final review gates.

Test strategy vocabulary: `no-test` | `static-inspection` | `local-validation` | `playwright-mcp` | `playwright-critical-flow` | `playwright-full-e2e` | `human-verification`.

---

## Bot runtime (local orchestration)

The `bot/` directory contains a file-based orchestration engine (v0):
- **State machine**: `bot/runtime/cycle_controller.py` — manages `idle` → `waiting_claude` → `waiting_cursor` → `waiting_validation` → `completed`
- **Handoff generation**: `bot/runtime/prompt_compiler.py` — produces `claude_handoff.md` / `cursor_handoff.md`
- **Review bridge**: `bot/runtime/review_bridge.py` — produces `claude_review_handoff.md`
- **Local supervisor**: `bot/supervisor.py` — file-based dropzone polling (inbox/outbox)
- **CLI**: `bot/cli.py` (via `bot-cli.ps1` on Windows)
- **State storage**: `bot/state/cycle_state.json` + `bot/state/handoffs/<cycle_id>/`

---

## Document hierarchy (priority order)

1. `CLAUDE.md` — operating constitution
2. `MEMORY.md` — working memory index
3. `AGENTS.md` — workflow rules and role separation
4. `docs/PROJECT_CONTINUITY_AND_VISION.md` — vision, fixes, backlog
5. `docs/ARCHITECTURE.md` — system architecture + frozen zones
6. `docs/BUSINESS_RULES.md` — pricing SSOT, order total formula, coupons
7. `docs/ORDER_FLOW.md` — order lifecycle stages
8. `docs/AUTHZ_MATRIX.md` — actor × permission matrix
9. `docs/DEVICE_FLOW.md` — per-device behavior rules
10. `docs/ops/*` — operational contracts (intake, output, scoring, routing, runtime)
11. `docs/roles/*` — Claude role specializations (orchestrator, architecture, product/UX, audit, research)
12. Remaining `docs/*` — API map, core modules, DB schema, test plans, security, deployment, etc.

---

## File counts (code-confirmed)

| Area | Count |
|------|-------|
| Controllers | 111 |
| Services | 86 |
| Models | ~49 |
| Migrations | 80 |
| Events | 15 |
| Listeners | 15 |
| Jobs | 1 |
| Middleware | 15 |
| Config files | 22 |
| Test classes | ~41 |
| Vue components | large tree (admin/frontend/table/layouts) |
| Governing docs | 12 primary + 6 ops + 5 roles + ~15 supplementary |
