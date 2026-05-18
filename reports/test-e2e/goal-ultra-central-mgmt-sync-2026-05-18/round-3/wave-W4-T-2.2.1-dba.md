# R3-DBA — T-2.2.1 Role/Permission CRUD (Spatie 5 schema + migration safety)

**Specialist**: DBA (Round 3) **Mode**: READ-ONLY **Task**: T-2.2.1
**Scope**: Schema integrity / Spatie 5→6 readiness / AUTO_INCREMENT identity
discipline / cascade behavior / tenancy-readiness / cache surface.

## Verdict snapshot

| Topic                                          | Verdict | Severity |
|------------------------------------------------|---------|----------|
| 1. Spatie v5 → v6 migration delta              | NEEDS WORK | P1 (V1.x backlog) |
| 2. roles.id AUTO_INCREMENT identity drift      | CONFIRMED  | P0 (mitigated, partial) |
| 3. `model_has_roles` UNIQUE / multi-role       | OK         | — |
| 4. Cascade delete on role/permission           | OK (FK CASCADE) | P2 nit |
| 5. `role_has_permissions` scale + indexing     | OK (80×8=640 rows) | P3 |
| 6. Branch-scoped (tenant) permissions schema   | NOT IMPLEMENTED | P0 for V2 SaaS |
| 7. Audit table for role/permission grants      | MISSING    | P1 |
| 8. v5→v6 migration plan                        | INCOMPLETE | P1 |
| 9. Cache: store + key + invalidation           | DRIFT      | P0 (file driver in `.env.example`) |

## Anchors (verified, file:line)

- Spatie version: `composer.lock` → `spatie/laravel-permission 5.11.1`
  (`composer.json` constraint `^5.6`) — v6 NOT installed.
- Tables migration: `database/migrations/2022_05_01_142407_create_permission_tables.php:28-126`
- Extra column: `database/migrations/2026_03_12_120000_add_landing_url_to_roles_table.php:20-23`
- Config (vendor, no published override): `vendor/spatie/laravel-permission/config/permission.php`
  — `'teams' => false` (L114), `'cache.store' => 'default'` (L159),
  `'cache.expiration_time' => 24 hours` (L145), `'cache.key' => 'spatie.permission.cache'` (L151)
- Spatie HasRoles trait: `vendor/spatie/laravel-permission/src/Traits/HasRoles.php:84`
  → `is_numeric($role) ? 'findById' : 'findByName'`
- Role seeder: `database/seeders/RoleTableSeeder.php:17-67`
- Permission seeder: `database/seeders/PermissionTableSeeder.php:18-661`
- Lookup helper: `database/seeders/SpatieRoleLookup.php:13-39`
- Cache config default: `.env.example` → `CACHE_DRIVER=file` (commented warning
  "NOT atomic across PHP-FPM workers"; `redis` is the V1.x heal)

---

## 1. Spatie v5 vs v6 schema delta (P1, V1.x backlog)

The local migration is `2022_05_01_142407_create_permission_tables.php`, which is the
**Spatie v4-style** template carried forward by this project. Two divergences from the
upstream v5 default — and three from the v6 default — exist:

- **v4-template carryover** : `permissions.title` (L30) and `permissions.url` (L33) and
  `permissions.parent` (L34) are LOCAL extensions (not Spatie-vendor columns). They feed
  the Vue admin permission tree (Catégorie → Crud) and are used by `AppLibrary::permissionWithAccess`.
  v6 publishes a stricter migration that does NOT include them; if `vendor:publish --tag=permission-migrations`
  is ever re-run, these columns will be lost. Mitigation: file is now treated as "applied baseline" — but
  there is no `_baseline` marker. Risk for v6 upgrade: medium.

- **v5 default `column_names.role_pivot_key`/`permission_pivot_key`** : the upstream v5 default keys
  these explicitly. Our migration uses `PermissionRegistrar::$pivotRole`/`$pivotPermission`
  resolution (L57, L88, L115-116) — equivalent at runtime but means renaming the pivot is harder
  than in a fresh v5/v6 setup.

- **v6 breaking change : `unsignedBigInteger` is still OK** but v6 deprecates the implicit `morph_key`
  type and recommends `$table->morphs($columnNames['model_morph_key'])` with explicit relation. Our
  schema uses the older two-call pattern (L59-64, L90-92) which v6 still accepts behind a config flag
  but warns about. P1.

- **v6 `enable_wildcard_permission` ON by default** (was OFF in v5). We rely on exact-name lookups (e.g.
  `whereIn('name', [...])` in `RolePermissionTableSeeder` and `Permission::join('role_has_permissions',
  ...)` in `PermissionController:35-39`). Wildcard activation in v6 will NOT break us, but coverage tests
  must assert the boolean remains FALSE if we don't want collateral behavior. P2.

- **v6 `register_octane_reset_listener`** : new option. Irrelevant unless we deploy on Octane. Currently
  irrelevant.

**Required v5→v6 work** (for V1.x backlog item):
```sql
-- (1) Re-publish vendor config and merge with local overrides:
php artisan vendor:publish --provider="Spatie\\Permission\\PermissionServiceProvider" --tag=permission-config
-- (2) Add a v6-marker migration that confirms title/url/parent are still present:
ALTER TABLE permissions
    MODIFY COLUMN title VARCHAR(255) NULL,
    MODIFY COLUMN url VARCHAR(255) NULL,
    MODIFY COLUMN parent BIGINT UNSIGNED NULL DEFAULT 0;
-- (3) Bump composer constraint `spatie/laravel-permission: ^6.10`.
-- (4) Run `php artisan permission:cache-reset` post-deploy (cache key format unchanged in 6.x).
```

## 2. roles.id AUTO_INCREMENT identity drift (P0, MITIGATED, partial)

**Confirmed from primary source.** `database/seeders/SpatieRoleLookup.php:9-14` documents
the bug: *"Legacy code used EnumRole integer constants as if they were roles.id values. That
breaks whenever MySQL rolls back transactions (AUTO_INCREMENT is not reset), so fresh
RoleTableSeeder inserts no longer land on ids 1..8."*

The bug class is **directly exploitable through Spatie internals** — `HasRoles::role()` at
`vendor/spatie/laravel-permission/src/Traits/HasRoles.php:84` does:
```php
$method = is_numeric($role) ? 'findById' : 'findByName';
```
So `$query->role(EnumRole::ADMIN)` (= `role(1)`) **calls `findById(1)`** under v5.11 — and if
roles.id of "Admin" is 73 because of `migrate:fresh --seed` rolling over an existing AUTO_INCREMENT
sequence (very common after PHPUnit `DatabaseTransactions` rollbacks in MySQL — AUTO_INCREMENT
does NOT reset), the lookup misses.

**Heal evidence (partial)** : `AdministratorService.php:43-46`:
```php
// [GOAL-pageby-V1.0.2 class-of-bug] Spatie's ->role($int) calls findById($int) (HasRoles L84).
// Passing EnumRole::ADMIN int breaks whenever roles.id AUTO_INCREMENT skipped past it
// (fresh seed lands at 73-80). Stable identity = role NAME.
})->role('Admin', 'sanctum')->orderBy(...)
```
Similar comments at `WaiterService`, `ChefService`, `CustomerService`, `DeliveryBoyService` — all heal-stamped.

**However**, `assignRole(EnumRole::ADMIN)` is STILL used at `AdministratorService:74`,
`DeliveryBoyService:79`, `WaiterService:78`, `ChefService:77`, `CustomerService:77`,
`GuestSignupController:126`, `SignupController:97`, `EmployeeService:87,123`. These all
pass an INTEGER to `assignRole`. `HasRoles::assignRole` → `getStoredRole($role)` → also
`is_numeric ? findById : findByName`. **Class-of-bug NOT eliminated** — only narrowed to the
listing/destroy paths. P0 to finish the migration to `assignRole('Admin')`.

`hasRole(EnumRole::ADMIN)` at `AdministratorService:119, 145, 162, 181` — same problem. P0.

**Definitive fix** (V1.0.2):
```sql
-- Option A (safest): drop EnumRole entirely, replace integer constants with role names.
-- Option B: pin role IDs at insert time:
INSERT INTO roles (id, name, guard_name, created_at, updated_at) VALUES
  (1, 'Admin', 'sanctum', NOW(), NOW()),
  (2, 'Customer', 'sanctum', NOW(), NOW()),
  ...
-- followed by:
ALTER TABLE roles AUTO_INCREMENT = 100;
-- to prevent future inserts from accidentally claiming id 9..99.
```
Option B is shipped-friendly because no application code changes; A is the long-term right answer.

## 3. `model_has_roles` UNIQUE / multi-role support (OK)

Schema at L87-112:
```sql
CREATE TABLE model_has_roles (
    role_id BIGINT UNSIGNED NOT NULL,
    model_type VARCHAR(255) NOT NULL,
    model_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, model_id, model_type),
    INDEX model_has_roles_model_id_model_type_index (model_id, model_type),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
);
```
The composite PK `(role_id, model_id, model_type)` **does not** prevent multiple roles per
user (good — we have `Admin` + `Branch Manager` cohabit cases). The "unique" enforced is
"same role twice on same model", which is correct semantically. **VERDICT: OK**.

`User` model (`app/Models/User.php:28`) uses `HasRoles` — pivot relation works. No drift.

## 4. Cascade delete behavior (OK, P2 nit)

- `model_has_roles.role_id` → `roles.id` `ON DELETE CASCADE` (L94)
- `model_has_permissions.permission_id` → `permissions.id` `ON DELETE CASCADE` (L66)
- `role_has_permissions.permission_id` → `permissions.id` `ON DELETE CASCADE` (L118)
- `role_has_permissions.role_id` → `roles.id` `ON DELETE CASCADE` (L120)

Dropping a role cascades to all pivot tables — **OK and standard**.

**P2 nit**: `RoleService::destroy` at `app/Services/RoleService.php:90-102` blocks deletion only
for `roleArray = [ADMIN, CUSTOMER, DELIVERY_BOY, WAITER, CHEF]` — `BRANCH_MANAGER` (6), `POS_OPERATOR`
(7), `STUFF` (8) are NOT protected. If owner deletes "Branch Manager" via admin UI, all `model_has_roles`
rows attaching managers cascade-delete; users lose role; no fiscal-relevant data loss, but operations
break. Plus the `roleArray` uses the **integer enum constants** with `in_array($role->id, $this->roleArray)`
— same AUTO_INCREMENT vulnerability: if a fresh seed lands Branch Manager at id=78, the check still
returns false (which is correct here only by accident; if ADMIN landed at id=73 the check would FALSE
and admin role deletion would succeed).

## 5. `role_has_permissions` scale + indexing (OK, P3)

Counted from `PermissionTableSeeder.php`: ~78 permission rows (top-level + 5 children families × 4 each).
With 8 roles, `role_has_permissions` max rows = 78 × 8 = **624 rows** (admin keeps all, others a subset).
PK is `(permission_id, role_id)` composite (L122-125). Lookup pattern in `PermissionController:34-39`:
```sql
SELECT permissions.* FROM permissions
  INNER JOIN role_has_permissions ON role_has_permissions.permission_id = permissions.id
  WHERE role_has_permissions.role_id = ?;
```
Index: PK covers `permission_id` first; the WHERE on `role_id` triggers a **non-leading-column scan**
on the composite PK. For 624 rows this is irrelevant, but if V2 SaaS scales to 500 tenants × 8 roles =
4000 roles × 80 perms = 320k rows, an explicit `INDEX role_has_permissions_role_id_idx (role_id)`
would prevent table scan. **P3 nit** today; pre-V2 prep recommended.

## 6. Branch-scoped (tenant) permissions schema (P0 for V2 SaaS, NO V1 blocker)

`config/permission.php` (vendor) L114: `'teams' => false`. Migration (L42, L67-69) **conditionally**
includes a `team_id` foreign key — but it is gated behind `config('permission.teams')` which is FALSE.
So our current schema is **global-permission** (single-tenant). For V2 multi-tenant SaaS, we need:

```sql
-- (1) Activate teams in published config:
'teams' => env('SPATIE_PERMISSION_TEAMS', false),
-- (2) Add team_id columns:
ALTER TABLE roles ADD COLUMN team_id BIGINT UNSIGNED NULL AFTER id;
ALTER TABLE model_has_roles ADD COLUMN team_id BIGINT UNSIGNED NULL;
ALTER TABLE model_has_permissions ADD COLUMN team_id BIGINT UNSIGNED NULL;
-- (3) Add indexes:
ALTER TABLE roles ADD INDEX roles_team_foreign_key_index (team_id);
ALTER TABLE model_has_roles ADD INDEX model_has_roles_team_foreign_key_index (team_id);
-- (4) Rebuild unique constraints to include team_id (mandatory for multi-tenant):
ALTER TABLE roles DROP INDEX roles_name_guard_name_unique;
ALTER TABLE roles ADD UNIQUE (team_id, name, guard_name);
-- (5) Map team_id = branch_id (or new tenant_id) at the application boundary via
-- PermissionRegistrar::setPermissionsTeamId() in middleware.
```

**Discovery cost in current code**: 88 endpoints use `permission:settings` middleware (BRAIN §9
roadmap V1.0.1 refactor). All will need re-evaluation if permissions become tenant-scoped — owner
gate required. P0 for V2 architectural plan, not V1.

## 7. Audit table for role/permission grants (P1, MISSING)

Searched: `grep audit_logs OR AuditLogService` in PermissionService, RoleService, PermissionController,
RoleController → **0 matches**. Role assignments, permission syncs, and role creations are NOT
audit-logged.

`audit_logs` (`database/migrations/2026_04_22_000002_create_audit_logs_table.php:33-57`) is the
HMAC-chained NF525 trail. While role/permission changes are not strictly NF525-mandated, ANSSI
RGS/PSSI-E and ISO 27001 control 8.5.1 require "user access change" event logging. Currently:
- Adding "Cash variance override" permission to "Branch Manager" → no trace.
- Promoting User X to Admin via `assignRole` → no trace beyond Laravel's debug log.

**Recommended schema (no new table needed)** : piggyback `audit_logs` with action codes
`role.create | role.delete | role.permissions.sync | user.role.assign | user.role.remove`.
Service-layer hook in `PermissionService::update` and `RoleService::destroy`. P1 for V1.x.

## 8. v5 → v6 migration plan (P1, INCOMPLETE)

Spatie 6 major changes (from official UPGRADE doc, not present in vendor copy):
- PHP 8.2+ required (already in our composer.json).
- `permission()->withoutRole` / `withoutPermission` query scopes added — no impact.
- Cache invalidation event names changed: `Spatie\Permission\Events\PermissionAttached` etc. — no impact unless we listen.
- `RoleRequest` validator now blocks duplicate name across the SAME `team_id` — needs team activation else identical to v5.
- **`getStoredRole`** internal API renamed in some helpers; we call `assignRole`/`removeRole`/`syncRoles`
  only — no internals — so transparent.

**Required heal sequence** :
1. `composer require spatie/laravel-permission:^6.10 --update-with-dependencies`
2. `php artisan vendor:publish --tag=permission-config --force` then diff and re-merge
   local `teams` / `cache` settings.
3. `php artisan permission:cache-reset` post-deploy.
4. **NF525 / audit**: bump `composer.lock`, re-run full PHPUnit (Spatie 6 has BC changes in
   permission resolver internals — `tests/Feature/Auth/*` and `tests/Unit/Services/Pos/WalkInCustomerResolver*`
   must re-green).
5. Test rollback: `composer require spatie/laravel-permission:^5.11 --update-with-dependencies`
   does NOT regenerate cache key format (same v5/v6 — verified `cache.key = 'spatie.permission.cache'` in both).

Cost estimate : **~2-4h** including test smoke. Schedule for V1.0.2.

## 9. Cache surface (P0, DRIFT — file driver in `.env.example`)

`.env.example` line 87 (verified): `CACHE_DRIVER=file` is the default committed value. The
comments around it explicitly warn: *"[CRITICAL-PROD] CACHE_DRIVER=file is NOT atomic across
PHP-FPM workers"* — yet `.env.example` is the template ops copies to `.env` on a fresh install.

Spatie's `config/permission.php` (L159) sets `cache.store => 'default'` — i.e. inherits from
`config/cache.php`. With `CACHE_DRIVER=file`, the permission cache is **per-server file** —
under PHP-FPM with N workers, each worker has its own opcode cache; under load-balanced
multi-instance deploy, every node has its own permission cache copy and a `Cache::forget`
fired from worker A on node 1 won't invalidate node 2.

**Net effect on T-2.2.1**: a permission change via `PermissionService::update` calls
`Permission::loadPermissions` (Spatie internal triggered by `syncPermissions`) → `Cache::forget`
on the local file store only. Worker B will serve **stale denied/granted gates for up to 24h**
(the `cache.expiration_time` default) on permissions:settings calls. **This is exactly the type
of inconsistency we cannot live with in NF525-adjacent flows.**

Required heal (V1.x): switch `.env.example` default to `CACHE_DRIVER=redis` (or document
mandatory ops step). The BRAIN already flags this — but it is NOT done. P0 for production
parity, but operations-level (not schema), so the actual schema-level CRUD is fine.

## 10. Convergence checklist

- [x] Schema FK + cascade verified
- [x] PK / UNIQUE composition verified  
- [x] AUTO_INCREMENT vulnerability confirmed (and only partially mitigated)
- [x] Branch / tenant readiness assessed
- [x] Audit log gap captured
- [x] Cache surface DRIFT captured
- [ ] Live DB AUTO_INCREMENT value : **NOT VERIFIED** (both `database.sqlite` files are
  empty — 0 bytes). Recommend ops capture `SELECT AUTO_INCREMENT FROM information_schema.tables
  WHERE TABLE_NAME='roles' AND TABLE_SCHEMA='foodking_prod'` from production replica to confirm
  R2-DBA's "73-76" figure.

## Final verdict

**PROD-READY for V1 single-tenant Le Cayenne** (with two heals to land in V1.0.2) :
1. Finish `assignRole`/`hasRole` integer→string migration class-wide (~9 files, ~25 callsites).
2. Switch default cache to redis (already documented in BRAIN, not enforced in `.env.example`).

**NOT prod-ready for V2 SaaS** without:
3. Tenant scoping migration (Section 6).
4. Audit log hooks on role/permission changes (Section 7).
5. Composite index on `role_has_permissions.role_id` (Section 5).
6. Spatie v6 upgrade (Section 8).

Total V1.x work : **~6-10h** including PHPUnit + smoke + Graphiti episode. V2 work : **~3-5d**
schema migrations + middleware rewire + 88-endpoint authz audit (already BRAIN-tracked).

— DBA, Round 3, READ-ONLY.
