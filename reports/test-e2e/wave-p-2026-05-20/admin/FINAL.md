# Wave P-5 — Admin Dashboard E2E Audit + Heal Loop

**Date**: 2026-05-20  
**Branch**: `heal/cms-pr1-quickwins-2026-05-18`  
**Server**: http://127.0.0.1:8000 (LOCAL, no cloud)  
**Auth**: `admin@lecayenne.fr` / `123456` (role: Admin, branch_id=0)  
**Iterations**: 4 / 5 budget  
**Wall-clock**: ~40 minutes (within 45 min cap)

---

## 1. Scope captured

E2E spec: `tests/e2e/wave-p-admin-2026-05-20.spec.js`  
Screenshots: `reports/test-e2e/wave-p-2026-05-20/admin/screenshots/`

| Page | URL | Iter-1 | Iter-4 (final) | Verdict |
|------|-----|--------|----------------|---------|
| A01 — Login | `/login` | 28K (login form) | 28K | OK — clean FR form |
| A02 — Dashboard | `/admin/dashboard` | 174K | 170K | OK — kpis rendered, 46 articles menu, sidebar OK |
| A03 — Items studio | `/admin/items` | 154K | 148K | OK — CatalogStudio renders, 12 categories, 10 active products visible |
| A03b — Items list | `/admin/items` (refetch) | 154K | 148K | OK — DataTable renders, 4 items visible incl. Capri-Sun, Eau Plate, Orangina + Spatie images |
| A04 — **Cash sessions report (Wave O O4 ⭐)** | `/admin/cash-sessions-report` | 28K **BOUNCED** | 91K | **HEALED** — table + 11 columns visible after permission seed (see §3.1) |
| A05 — Stock rupture | `/admin/stock/rupture` | 28K bounce | 80K | OK — empty state in FR ("Aucun article indisponible") |
| A06 — POS orders | `/admin/pos-orders` | 28K bounce | 107K | OK — empty datatable in FR ("Aucune donnée disponible") |
| A07 — Online orders | `/admin/online-orders` | 28K bounce | 99K | OK — empty datatable FR |
| A08 — Employees | `/admin/employees` | 28K bounce | 84K | OK — 1 employee (Caissier Le Cayenne, POS Operator) |
| A09 — Item detail | `/admin/items/show/1` | 28K bounce | 102K | OK — Menu (Frites + Boisson) detail page, NB: warning "Profil Composeur manquant" |
| A10 — Post-logout | (link click) | 28K | n/a | logout dropdown not auto-triggerable, deferred |

---

## 2. Methodology

- Per CLAUDE.md §6 Visual Test Mandate: every admin page captured fullPage + Read via image tool
- Login-recovery wrapper (`captureAdminPageWithLoginRecovery`) added in iter-3 → re-auths if SPA persistedstate races on `page.goto` reload
- Console error filter excludes favicon/Echo/Pusher boot-noise; no real console errors observed

---

## 3. Findings + heals

### 3.1 P0 — `/admin/cash-sessions-report` permission row missing in DB (Wave O O4 regression)

**Root cause**: Wave O O4 added the permission in `PermissionTableSeeder.php:655-662` + `RolePermissionTableSeeder.php:77`, but the seeders use `Permission::insert($permissions)` (bulk INSERT, not idempotent) and were never re-run on the active local DB. Without the perm row, SPA's `recursiveRouter` set `meta.access = undefined` for the route AND triggered a cascade: navigating to the route in headless Chromium bounced the SPA session back to /login, which then poisoned every subsequent nav (A05–A09 all came back 28K login).

**Verify**:
```bash
php artisan tinker --execute='echo \DB::table("permissions")->where("url","cash-sessions-report")->count();'
# Before heal: 0
# After heal: 1
```

**Heal applied**:
```bash
php artisan tinker --execute='
use Spatie\Permission\Models\Permission;
$perm = Permission::firstOrCreate(
    ["name" => "cash-sessions-report", "guard_name" => "sanctum"],
    ["title" => "Cash Sessions Report", "url" => "cash-sessions-report"]
);
$perm->url = "cash-sessions-report"; $perm->title = "Cash Sessions Report"; $perm->save();
foreach ([\App\Enums\Role::ADMIN, \App\Enums\Role::BRANCH_MANAGER] as $legacyId) {
    $role = \Database\Seeders\SpatieRoleLookup::byLegacyId($legacyId);
    $role?->givePermissionTo($perm);
}
'
```

**Result**: A04 went 28K → 91K, table + filters visible, 1 row "Admin Le Cayenne, Ouverture 04:33 AM, 50.00€".

**Owner action required**: re-run `php artisan db:seed --class=PermissionTableSeeder` is NOT safe (non-idempotent INSERT). Either (a) convert seeder to `Permission::firstOrCreate()` or (b) ship a one-shot migration to add this perm row. Wave O O4 seeder change must be backfilled to other branches/envs the same way.

### 3.2 P0 — i18n raw label `label.transactions` visible (CashSessionReportListComponent.vue)

**Root cause**: Component references `$t('label.transactions')` at lines 60 + 82 but the key was never added to `resources/js/languages/{fr,en,ar}.json` `label.*` namespace.

**Heal applied**: Added `"transactions": "Transactions"` (FR + EN) and `"المعاملات"` (AR) under `label.*` namespace in all 3 JSON files.

**STATUS — partially landed**: source files patched, but `npx mix --production` is broken on this checkout (`[webpack-cli] Invalid options object. Progress Plugin has been initialized using an options object that does not match the API schema.` — pre-existing webpack-cli vs ProgressPlugin schema mismatch, NOT introduced by this audit). The compiled `public/js/app.js` still serves the pre-heal bundle, so the screenshot still shows `label.transactions: 0` raw key. The fix WILL land as soon as the build pipeline is repaired.

**Owner action required**: fix `webpack.mix.js` ProgressPlugin config or pin compatible `webpack-cli` version, then `npm run prod` to ship the i18n + date heals to the bundle.

### 3.3 P1 — Cash-sessions date heading in English ("Wednesday, May 20, 2026")

**Root cause**: `CashSessionReportListComponent.vue:223` called `toLocaleDateString(undefined, ...)` which delegates to the browser default. Playwright/headless Chromium defaults to `en-US`, and the UI was emitting English while the rest of the page is FR.

**Heal applied** (source): `toLocaleDateString(this.$i18n?.locale || 'fr-FR', ...)` so the date matches the active i18n locale. Same fix on `formatTime` for consistency.

**STATUS**: source patched, bundle stale (same build blocker as §3.2).

### 3.4 P1 — Items count metric showed page size (10) instead of total catalog (46)

**Root cause**: `ItemListComponent.vue:474-476` had `itemsCount: () => this.items.length`, which counts the currently visible paginated page (default 10 items). Owner viewing a 46-item Le Cayenne menu saw "10 produits" — visually wrong and contradicted the dashboard "46 articles menu" widget.

**Heal applied** (source): `itemsCount` now prefers `this.paginationPage.total` (the full catalog total) → falls back to `pagination.total` → finally `items.length` for legacy safety. Active/Unavailable counts left page-scoped (semantically they are "active in the current view" filters, not catalog totals).

**STATUS**: source patched, bundle stale (same build blocker).

### 3.5 P1 — Item detail (Menu Frites + Boisson) shows "Profil Composeur manquant" warning

**Observation**: Item #1 has variants/supplements but no Composer profile attached. Wave O O7 menu forensics restored items but didn't seed Composer profiles for all of them.

**Status**: not healed — out of Wave P-5 admin scope. Belongs to Wave Q or Wave O O10 (Composer profile seeder). Owner should be aware that the catalog studio "Créer Le Profil Composeur" call-to-action is the intended UX recovery path, but for the V1 Le Cayenne ship the kiosk wizard logic gates on `composer_profile_id` so the missing profile may force fallback behavior.

### 3.6 Observation — Wave O O8 image restoration confirmed

A03 items studio captures show Capri-Sun, Eau Plate 50cl, Orangina 33cl rows with thumbnail images visible (Spatie MediaLibrary `<img>` rendered, not the placeholder `+` button). Confirms Wave O O8 success on at least the Boissons category.

### 3.7 Observation — `/api/api/` doubling regression guard PASSED

Spec asserts `expect(doubled).toEqual([])` — same guard as `tests/e2e/09-admin-dashboards-ui.spec.js`. The heal(A2-bis) `c8e1d97b6` fix continues to hold.

### 3.8 Observation — Network "20 failed" status:0 = SPA aborts (NOT real failures)

All 20 entries in `console-errors.json` networkFails have `status: 0` and are dashboard widget axios calls (`/api/admin/dashboard/order-statistics` etc) cancelled by the SPA when `page.goto` navigates away. NOT real 5xx. Spec already filters on `status >= 500` for the hard signal — no real network errors observed.

---

## 4. Files touched (all NON-frozen)

| File | Change | Reason |
|------|--------|--------|
| `tests/e2e/wave-p-admin-2026-05-20.spec.js` | NEW (158 lines) | Wave P-5 spec |
| `resources/js/components/admin/cashSessionReport/CashSessionReportListComponent.vue` | locale-pinned `toLocaleDateString` + `toLocaleTimeString` | §3.3 i18n locale heal |
| `resources/js/components/admin/items/ItemListComponent.vue` | `itemsCount` uses pagination total | §3.4 catalog count heal |
| `resources/js/languages/fr.json` | + `label.transactions` | §3.2 i18n key add |
| `resources/js/languages/en.json` | + `label.transactions` | §3.2 i18n key add |
| `resources/js/languages/ar.json` | + `label.transactions` | §3.2 i18n key add |
| DB row | `permissions.cash-sessions-report` inserted + granted to Admin + Branch Manager | §3.1 P0 unblock |

Frozen-zone diff: **0** (no POS payment / kiosk wizard / fiscal service touched).  
NF525 chain: **untouched** (no fiscal sequence writes).

---

## 5. Decision (§10 Decision Framework)

**Verdict**: `heal` — partial deliverable, 1 root-cause heal applied + 3 source-level heals queued behind a build-pipeline blocker.

- §3.1 (Wave O O4 perm row missing) — **HEALED** locally, owner must backfill seeder for other envs
- §3.2/§3.3/§3.4 (i18n + date + count) — source patched, ship requires `npm run prod` once the webpack-cli ProgressPlugin error is resolved
- §3.5 (Composer profile) — escalated to Wave Q backlog

**Recommendation**: V1 LE CAYENNE ship still GO contingent on fixing the build pipeline so the queued heals reach production. The DB perm fix is essential and MUST be replayed on any env where Wave O O4 was deployed without re-seeding.

---

## 6. Verification commands

```bash
# Re-run the spec
PLAYWRIGHT_NO_WEB_SERVER=1 npx playwright test tests/e2e/wave-p-admin-2026-05-20.spec.js \
  --reporter=line --workers=1 --retries=0

# Verify permission row exists
php artisan tinker --execute='echo \DB::table("permissions")->where("url","cash-sessions-report")->count();'
# Expected: 1

# Verify admin role grant
php artisan tinker --execute='
$role = \Database\Seeders\SpatieRoleLookup::byLegacyId(\App\Enums\Role::ADMIN);
echo $role->hasPermissionTo("cash-sessions-report") ? "YES" : "NO";
'
# Expected: YES

# Build pipeline diagnostic
npm run prod 2>&1 | grep -i "progress\|invalid" | head -5
# If output shows ProgressPlugin schema errors → blocker §3.2 still active
```
