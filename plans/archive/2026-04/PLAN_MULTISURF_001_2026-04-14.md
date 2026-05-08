# Plan – MULTISURF_001 – 2026-04-14

## TASK_ID
MULTISURF_001

## PRIMARY_MODEL
GPT-5.4

## Gate Resolution
Gate `docs/gates/GATE_MULTISURF_001_2026-04-14.md` cleared by Kossay on 2026-04-14.
Options selected: 1+1+1 (OSS auth-required, Vue aliases, DB seeds for landing_url).

## SUBSYSTEMS_TOUCHED
| Subsystem | Intent | branch_id affected | dispatch/event |
|-----------|--------|-------------------|---------------|
| `resources/js/router/index.js` | write — add alias routes | no | no |
| `resources/js/router/modules/kitchenDisplaySystemRoutes.js` | read — verify meta | no | no |
| `resources/js/router/modules/orderStatusScreenRoutes.js` | read — verify meta | no | no |
| `resources/js/router/modules/deliveryBoyRoutes.js` | read — verify meta | no | no |
| `database/seeders/LeCayenneRoleLandingUrlSeeder.php` | write — add missing roles | no | no |

## SUBSYSTEMS_OFF_LIMITS
- `app/Services/OrderService.php` — frozen zone
- `app/Services/FrontendOrderService.php` — frozen zone
- `app/Http/Controllers/Auth/LoginController.php` — already correct, no change needed
- `routes/api.php` — no API route changes
- `app/Http/Middleware/` — no middleware changes
- `database/migrations/` — no schema changes
- Any pricing logic
- Any dispatch/event logic

## INVARIANTS_AT_RISK
- **branch_id data isolation** — LOW RISK: no auth model change, OSS stays auth-required, all routes remain under `/admin/` middleware chain with existing branch scoping. Aliases share the same meta.
- **Frozen zone** — RESPECTED: OrderService and FrontendOrderService not touched.
- **OrderStatus enum** — NOT AT RISK: no status logic changes.

## GATE_CONDITIONS
- Gate already cleared (1+1+1).
- No additional gate anticipated: changes do not modify auth behavior for multiple surfaces simultaneously (aliases reuse existing `auth: true` meta; seeder only sets DB values that LoginController already reads).

## SYMMETRY_NOTE
N/A — neither OrderService nor FrontendOrderService is in scope.

## Test Strategy
`local-validation` — run existing PHPUnit tests (AntiGravityLoginRedirectionTest, AuthComprehensiveTest) to confirm landing_url values produce correct redirects.

---

## Architecture Analysis

### Current state
- `LoginController` (line 94-96) already applies `landing_url` from the Role model to `defaultPermission.url`.
- `authcheck` endpoint (routes/api.php, line 194-195) does the same for F5/refresh.
- `LoginComponent.vue` (line 158-160) does `router.push('/admin/' + defaultPermission.url)` for staff.
- Existing seeder `LeCayenneRoleLandingUrlSeeder` sets landing_url for: Admin, POS Operator, Chef, Branch Manager.
- Missing: Delivery Boy, Waiter, Stuff, Customer.
- Task URLs `/kds`, `/delivery`, `/order-status` do not exist — actual paths are `/admin/kitchen-display-system`, `/admin/delivery-boys`, `/admin/order-status-screen`.

### Solution
1. **Vue router aliases**: Add redirect routes in `index.js` for `/kds`, `/delivery`, `/order-status` pointing to existing named routes.
2. **Seeder update**: Add Delivery Boy → `delivery-boys`, Waiter → `waiters`, Stuff → `dashboard`, Customer → `#` (same convention as TestCase.php).

---

## Execution Steps

### Step 1 — Add Vue router alias routes
**File**: `resources/js/router/index.js`
**Action**: Add three redirect entries in `baseRoutes` array:
- `{ path: "/kds", redirect: { name: "admin.kitchen-display-system" } }`
- `{ path: "/delivery", redirect: { name: "admin.delivery-boys" } }`
- `{ path: "/order-status", redirect: { name: "admin.order-status-screen" } }`

These inherit the auth guard from the target routes (global `beforeEach` checks `meta.auth`).

### Step 2 — Update LeCayenneRoleLandingUrlSeeder
**File**: `database/seeders/LeCayenneRoleLandingUrlSeeder.php`
**Action**: Add missing role mappings to `$roleLandingUrls`:
- `'Delivery Boy' => 'delivery-boys'`
- `'Waiter' => 'waiters'`
- `'Stuff' => 'dashboard'`
- `'Customer' => '#'`

No schema change. No migration. The `landing_url` column already exists.

### Step 3 — Verify and document
**Action**: Confirm all 8 surfaces have correct:
- Direct URL (including alias)
- Auth guard (meta.auth in Vue router)
- Post-login redirect (landing_url in seeder matches LoginComponent.vue pattern)
- branch_id scoping (unchanged — all admin routes behind auth:sanctum)

---

## Expected Surface Status After Execution

| Surface | Canonical URL | Alias | Guard | landing_url | Status |
|---------|-------------|-------|-------|-------------|--------|
| Admin/Dashboard | `/admin/dashboard` | — | auth:true | `dashboard` | EXISTS |
| POS | `/admin/pos` | — | auth:true | `pos` | EXISTS |
| KDS | `/admin/kitchen-display-system` | `/kds` | auth:true | `kitchen-display-system` | NEW alias |
| Kiosk | `/kiosk/*` | — | requireKioskAuth | N/A (machine token) | EXISTS |
| OSS | `/admin/order-status-screen` | `/order-status` | auth:true | `order-status-screen` | NEW alias + NEW seed |
| Delivery | `/admin/delivery-boys` | `/delivery` | auth:true | `delivery-boys` | NEW alias + NEW seed |
| Waiter | `/admin/waiters` | — | auth:true | `waiters` | NEW seed |
| Frontend | `/home` | `/` | public | `#` | EXISTS |

## ESCALATION
(none)

## SCOPE_PRESSURE
(none)
