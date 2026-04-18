# Gate Brief – MULTISURF_001 – 2026-04-14

## Trigger
Task MULTISURF_001 defines URL targets and auth behaviors that conflict with the current codebase state. Three architectural decisions require human input before a plan can be finalized.

## Affected Subsystems
- `resources/js/router/` (all surface route modules)
- `resources/js/router/index.js` (global beforeEach guard)
- `routes/api.php` (admin group middleware, kds/oss endpoints)
- `app/Http/Controllers/Auth/LoginController.php` (post-login redirect)
- Role model / DB seeds (`landing_url` field)

## Invariants at Risk
- **branch_id data isolation** — if OSS becomes public, unauthenticated users could see order data without branch scoping
- **Frozen zone** — OrderService and FrontendOrderService are excluded (confirmed)

## Decisions Required

### Decision 1 — OSS Access Model
The task says OSS should be "public ou token ou PIN". Currently OSS is at `/admin/order-status-screen` with `auth: true`.

**Options:**
1. **Keep auth-required** — OSS stays behind login, accessible only to users with `order-status-screen` permission. Simplest, no auth change.
2. **Branch-token public access** — Create a public route `/order-status/:branchToken` that validates a branch-specific token (not user auth). The branch token scopes all data to that branch. Requires new endpoint + middleware.
3. **PIN-based access** — OSS shows a PIN entry screen, PIN is per-branch. Simpler than option 2 but less secure.

### Decision 2 — URL Structure
The task defines these target URLs: `/kds`, `/delivery`, `/order-status`. The actual routes are:
- `/admin/kitchen-display-system`
- `/admin/delivery-boys`
- `/admin/order-status-screen`

**Options:**
1. **Add Vue router aliases** — Keep actual paths, add aliases (`/kds` → same component). Frontend-only change, no API middleware impact. Aliases share the same `auth: true` meta.
2. **Move routes outside /admin/** — Extract KDS, OSS, Delivery to standalone top-level routes with their own auth guards. Bigger change, requires careful middleware mapping on the API side too.
3. **Keep current paths, update task** — No URL change. Document the real URLs as the canonical entry points.

### Decision 3 — Post-Login Redirect Mechanism
LoginController already supports `landing_url` per role (line 94-96). The `authcheck` endpoint mirrors this.

**Options:**
1. **Set `landing_url` in Role seeds/DB** — The code already works. Just ensure each role has the correct `landing_url` value in the database. This is a DB/seed change, not a code change. No schema migration needed — `landing_url` column already exists on the `roles` table.
2. **Add frontend redirect mapping** — After login, the Vue app reads the role from the response and redirects based on a frontend mapping table. More complex, duplicates backend logic.

## Options
1. OSS=auth-required + aliases + DB seeds for landing_url → **minimal code change, no auth model change**
2. OSS=branch-token + move routes + frontend mapping → **maximum scope, requires additional cycle**
3. Cancel cycle — revisit task scope

## Decision Details

### Decision 1 — OSS : option 1 retenue
OSS reste protégé par auth (`auth: true`). Pas de changement de modèle d'authentification.
Aucun risque branch_id — les données restent scopées à l'utilisateur connecté.

### Decision 2 — URLs : option 1 retenue
Ajouter des aliases Vue router uniquement :
- `/kds` → alias vers `/admin/kitchen-display-system`
- `/delivery` → alias vers `/admin/delivery-boys`
- `/order-status` → alias vers `/admin/order-status-screen`
Pas de déplacement de routes hors `/admin/`. Même meta `auth: true`.
Mettre à jour `TASK_MULTISURF_001.md` pour documenter les URLs canoniques réelles.

### Decision 3 — Redirection post-login : option 1 retenue
Utiliser le mécanisme `landing_url` déjà en place dans `LoginController`.
Le code est opérationnel — seules les valeurs en base doivent être renseignées.
Produire un seeder (ou update SQL) pour chaque rôle, pas de migration.

### Valeurs landing_url à appliquer par rôle
| Rôle | landing_url |
|---|---|
| admin / super-admin | /admin/dashboard |
| cashier / caissier | /admin/pos |
| chef / cuisine | /admin/kitchen-display-system |
| delivery_boy / livreur | /admin/delivery-boys |
| waiter | /admin/waiter |
| kiosk | géré séparément par requireKioskAuth — pas de landing_url standard |
| customer / guest | / (frontend.home) |

## Approval
[x] Approved — option selected: 1+1+1 (OSS auth-required + Vue aliases + DB seeds)
[ ] Cancelled
Approved by: Kossay
Date: 2026-04-14
