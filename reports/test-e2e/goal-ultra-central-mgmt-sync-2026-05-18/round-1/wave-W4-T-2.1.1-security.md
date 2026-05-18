# Wave W4 — T-2.1.1 Catalog SSOT consistency (SECURITY)

**Audit type**: read-only, hostile mindset, attacker = `Branch Manager` (or `Manager`) credentials at a single branch.
**Scope**: `config/menu.php` ↔ DB ↔ frontend payload boundary, every catalog mutation endpoint, kiosk payload, CLI heal commands.
**Outcome**: 1 P0 (cross-tenant catalog DoS), 1 P1 (CLI catalog reset has no env / audit gate), 2 P2 (defense-in-depth holes), 1 NOT-FOUND closed.

---

## Findings ranked by severity

### F-W4-SEC-01 — P0 — Cross-tenant ingredient DoS via `permission:ingredients_manage`

```yaml
id: F-W4-SEC-01
severity: P0
title: Branch Manager from branch A can globally disable any ingredient (cheddar, mayonnaise, viande), DoS'ing kiosks at every other branch with one HTTP call
attack_surface: catalog mutation, multi-tenant boundary
attacker_role: Branch Manager  # or Manager — both seeded with ingredients_manage

evidence:
  - file: database/seeders/IngredientPermissionSeeder.php
    line: 19
    quote: "foreach (['Admin', 'Tenant Admin', 'Manager', 'Branch Manager'] as $roleName)"
    note: ingredients_manage is granted to per-branch managers, not just super-admin
  - file: routes/api.php
    line: 682
    quote: "Route::prefix('ingredients')->middleware('permission:ingredients_manage')"
    note: middleware gates the permission only — NO branch scope check at route or controller
  - file: app/Http/Controllers/Admin/IngredientController.php
    line: 55-78
    quote: "public function toggleAvailability(Request $request, string $globalId): JsonResponse"
    note: |
      No call to authorizeWritableBranchScope(); no branch_id parameter accepted;
      validate() only enforces is_available + reason. The user's branch_id is never
      consulted before mutating shared catalog tables.
  - file: app/Services/Ingredients/IngredientAvailabilityService.php
    line: 30-40
    quote: |
      ItemAttribute::query()
        ->where('name', $name)
        ->update(['is_available' => $isAvailable, 'unavailable_reason' => $reason]);
    note: |
      Cascade-by-name across the GLOBAL item_attributes table. No where('branch_id', ...).
      Grep confirms no branch_id column on item_attributes or item_extras (zero hits in
      either model and the service).
  - file: app/Services/Ingredients/IngredientAvailabilityService.php
    line: 50-62
    note: |
      ItemExtra cascade is identical — keyed on (name, group_label), no branch scope.
      One Branch Manager toggling "Cheddar → rupture" propagates to every restaurant
      in the tenant, killing every kiosk wizard step that depends on Cheddar.

attack_scenario: |
  Branch A's Branch Manager logs in (legitimate session), sends
  PATCH /api/admin/ingredients/extra:1234/availability {"is_available": false}.
  Service runs UPDATE item_extras SET is_available=false WHERE name='Cheddar'
  AND group_label='supplement' across ALL branches. KioskMenuService rebuilds
  (60s TTL) → every Cheddar option, every restaurant, marked unavailable.
  Repeat for "Mayonnaise" / "Poulet curry" / 13 sauces → competitor branch's
  kiosk wizard breaks (min_select=1 on Sauce step → checkout 422).
  Hostile franchisee = catalog DoS warfare. No NF525 chain trip (catalog
  state, not fiscal). No audit_logs entry. Event payload (line 67) carries
  no branch_id, no actor_id.

blast_radius: every branch in the tenant, all kiosks + POS surfaces, instantly
detection: none — IngredientAvailabilityChanged event omits actor + branch_id

fix:
  - Add branch_id param + authorizeWritableBranchScope() to IngredientController::toggleAvailability.
  - Introduce branch-overlay table `ingredient_branch_availability(branch_id, type, name, ...)` and re-route the cascade to upsert per branch.
  - KioskMenuService availability projection consults branch overlay, not global item_extras.is_available.
  - Add actor_id + branch_id to event payload + audit_logs entry per toggle.
  - Until fix ships: revoke `ingredients_manage` from Branch Manager + Manager roles, restrict to Admin / Tenant Admin only.
confidence: high — citations re-read, zero branch_id hits in service/models.
```

---

### F-W4-SEC-02 — P1 — `menu:reset-le-cayenne` artisan command has no environment gate, no audit_log row, only `--force` flag

```yaml
id: F-W4-SEC-02
severity: P1
title: MenuResetLeCayenneCommand archives ~35 items / 8 categories with only a CLI confirm prompt; --force bypasses it; production deploys with shell access can wipe the catalog with one command
attack_surface: CLI / deploy / supply-chain (compromised shell or runaway deploy hook)
attacker_role: any user with `php artisan` shell access (deploy bot, sysadmin, CI runner with leaked credentials)

evidence:
  - file: app/Console/Commands/MenuResetLeCayenneCommand.php
    line: 27-29
    quote: |
      protected $signature = 'menu:reset-le-cayenne
                              {--dry-run : ...}
                              {--force : Skip confirmation prompt}';
  - file: app/Console/Commands/MenuResetLeCayenneCommand.php
    line: 110-122
    quote: "if (!$this->option('force') && !$dryRun) { $this->confirm('Proceed?'); }"
    note: No app()->environment() check — `php artisan menu:reset-le-cayenne --force` runs in prod identically to dev. Grep "APP_ENV / environment(" / "production" in the file returns zero hits.
  - file: app/Console/Commands/MenuResetLeCayenneCommand.php
    line: 253-259
    quote: "DeletionLog::create(['model_type' => ItemCategory::class, ..., 'actor_type' => 'console'])"
    note: |
      DeletionLog row created per category — but actor_id is null (no user attribution),
      and no entry to the NF525 audit_logs table. Per CLAUDE.md §8 "Audit Chain"
      every business-state mutation MUST flow through AuditLogService (chained HMAC).
      MenuReset bypasses this — it directly soft-deletes Items + Categories.

attack_scenario: |
  Compromised CI runner or stolen deploy creds runs
  `php artisan menu:reset-le-cayenne --force` in prod. Wipes 8 categories (~35
  items soft-deleted), renames 4 kept cats destructively (slug change breaks
  deep-links + cached payloads), seeds Cayenne menu over existing brand.
  KioskMenuService picks up next cache miss → every restaurant boots Cayenne
  menu regardless of brand. No audit_logs trail names the actor; only a
  DeletionLog row with actor_type=console (no actor_id). The category RENAME
  step is NOT auto-recoverable.

fix:
  - Add `abort_if(app()->environment('production') && ! $this->option('confirm-production'), ...)` requiring a distinct `--confirm-production` flag in prod.
  - Write to AuditLogService at start + end with actor=auth()->user() or caller_ip.
  - Add `config('catalog_v15.menu_reset.allowed_in_env', ['local','staging'])` check at handle().
confidence: high — command source reads exactly as cited; zero env/audit hits.
```

---

### F-W4-SEC-03 — P2 — `authorize(): return true` in 4 catalog FormRequests (defense-in-depth gap)

```yaml
id: F-W4-SEC-03
severity: P2
title: Catalog FormRequests rely entirely on route-level `permission:*` middleware; if a future maintainer attaches the FormRequest to a route that forgets the middleware, mass-assignment authz vanishes silently
attack_surface: regression / future-developer footgun, not currently exploitable
attacker_role: future-developer mistake (no live exploit today)

evidence:
  - file: app/Http/Requests/ItemRequest.php
    line: 18-21
    quote: "public function authorize(): bool { return true; }"
  - file: app/Http/Requests/ItemCategoryRequest.php
    line: 16-18
    quote: "public function authorize(): bool { return true; }"
  - file: app/Http/Requests/ItemAttributeRequest.php
    line: 15-18
    quote: "public function authorize(): bool { return true; }"
  - file: app/Http/Requests/ComposerStepRequest.php
    line: 10-13
    quote: "public function authorize(): bool { return true; }"

current_compensating_control: |
  ItemController.php:31-41 attaches `permission:items_create/edit/delete/show`
  middleware per action. ItemCategoryController.php:27 attaches `permission:settings`.
  ItemAttributeController.php:22 attaches `permission:settings`.
  ComposerStepController is routed under `permission:catalog.compose` (routes/api.php:696).
  So today, the FormRequests are belt-and-suspenders unnecessary — but the
  `return true` is a regression trap.

attack_scenario_future: |
  A future PR adds a new route binding ItemRequest (e.g. a "bulk-edit catalog" endpoint)
  outside the items.* group, forgets the `permission:items_edit` middleware. The
  endpoint becomes mass-assignment-vulnerable to any authenticated user (any role).
  Same shape Wave 5G already had to heal.

fix:
  - Change all four authorize() to: `return (bool) $this->user()?->canAny(['items_edit', 'items_create']);`
    (or category/attribute-appropriate gate). Belt-and-suspenders.
  - Add a static-analysis rule (PHPStan / custom rector) that `authorize(): return true`
    is forbidden in App\Http\Requests except for explicitly public-facing ones.

confidence: high — file/line evidence; not currently exploitable because route-level
              gates exist; ship with P2 for next hardening cycle.
```

---

### F-W4-SEC-04 — P2 — Catalog mutations do not write to `audit_logs` HMAC chain

```yaml
id: F-W4-SEC-04
severity: P2
title: ItemController / ComposerProfileController / IngredientController perform mutations without AuditLogService::record() — no actor+branch trail for forensics
evidence:
  - file: app/Services/Ingredients/IngredientAvailabilityService.php
    line: 67
    quote: "IngredientAvailabilityChanged::dispatch($type, $id, $isAvailable, $reason, null);"
    note: trailing null = no actor_id / branch_id / source_surface
  - file: app/Http/Controllers/Admin/ComposerProfileController.php
    line: 82-94
    note: publish / unpublish — no audit_log entry on state change that affects kiosk render
attack_scenario: |
  Insider publishes a malicious composer profile (e.g. hijacked upsell role).
  Weeks later, owner asks "who when" — no actor+timestamp+diff trail.
fix: Wire mutations to AuditLogService::record() with actor_id, branch_id, before/after snapshot. CLAUDE.md §8 wording is broad enough to require this.
confidence: medium — depends on owner's policy on NF525 scope.
```

---

### F-W4-SEC-05 — NOT FOUND (closed) — XSS via raw item name/description rendering in catalog Vue components

```yaml
id: F-W4-SEC-05
severity: NOT_FOUND
title: Searched for v-html on item.name / item.description in admin + kiosk catalog components — none found
evidence:
  - grep "v-html" across resources/js/ returned 8 hits, all wrap user-controlled HTML through `safeHtml(...)` (DOMPurify wrapper) per PageComponent.vue:12 and KsThemeToggle.vue:22.
  - No v-html on item.name, item.description, category.name, or anywhere in catalog admin Vue components.
  - Confirmed item names use `{{ item.name }}` interpolation everywhere — Vue auto-escapes.
verdict: passes audit; closed.
```

---

### F-W4-SEC-06 — Other items inspected & cleared

| Brief item | Verdict | Evidence |
|---|---|---|
| (1) CRUD authz on every catalog route | PASS for primary controllers | ItemController L31-41 / ItemCategoryController L27 / ItemAttributeController L22 / ItemExtra/Variation/Addon/Photo (L18-23 each) all wire `permission:items_*` or `permission:settings` correctly. |
| (2) Price tampering via mass-assignment | PASS for `price` field on ItemRequest | `price` is in rules() (L42), gated by `permission:items_edit` at route level. ComposerStepRequest L32: `'price' => ['prohibited']`. No `cost_price` / `supplier` / `wholesale` fields exist in Item model — grep confirms zero. |
| (3) XSS in item name/description | PASS | F-W4-SEC-05 above. |
| (4) Branch leak via catalog query | PASS | Admin ItemController::index() L46-64 forces branch_id for POS-runtime callers; SimpleItemResource collection respects surface filter. KioskMenuService correctly scopes via Branch param + ItemBranchAvailability per branch. |
| (5) Kiosk menu payload exposure | PASS | KioskMenuService.php — no cost_price, supplier, internal_notes, or fiscal_sequence_no leak. Only public-render fields (name, price, description, image, allergens, channels). |
| (6) Command-line abuse | FAIL → F-W4-SEC-02 (P1) | MenuResetLeCayenneCommand env-gate + audit-log gap, see above. |
| (7) Composer compose vs publish gate distinction | PASS | routes/api.php:696 = `permission:catalog.compose` for store/update/steps; L717-718 = `permission:catalog.publish` is a SEPARATE permission. ComposerPermissionsMinimalSeeder L12 grants BOTH to ['Admin', 'Branch Manager', 'Tenant Admin', 'Branch Admin']. Distinct gates exist, owner can revoke `catalog.publish` from Branch Manager and force a 2-step workflow. |
| (8) Ingredient inventory bypass by chef role | PASS (chef cannot toggle) | RolePermissionTableSeeder.php L99-109: chef gets only `dashboard / kitchen-display-system / order-status-screen`. NOT `ingredients_manage`. Chef cannot inflate ingredient availability — but Branch Manager can globally suppress (F-W4-SEC-01). |

---

## Summary verdict for T-2.1.1 SECURITY

| Severity | Count | Action |
|---|---|---|
| P0 | 1 | Cross-tenant ingredient DoS — fix before V1 ship (catalog SSOT consistency claim depends on this) |
| P1 | 1 | menu:reset CLI env+audit gate — fix before any prod deploy of catalog tooling |
| P2 | 2 | FormRequest authorize() + audit_logs catalog hook — hardening backlog (next cycle) |
| NOT_FOUND | 1 | XSS on catalog rendering — confirmed clean |

**Catalog SSOT consistency cannot be claimed** until F-W4-SEC-01 is fixed: today, the
`config/menu.php` ↔ DB ↔ kiosk payload chain is technically consistent at rest, but
the runtime mutation surface (`/api/admin/ingredients/*/availability`) breaks the
multi-tenant invariant by silently propagating a single branch's manager action
across every branch's kiosk projection. That is the definition of an SSOT integrity
hole at the runtime layer, not the data layer.

**Recommended PR scope (smallest viable patch)**:
1. IngredientController + Service: add `branch_id` parameter, authorizeWritableBranchScope,
   introduce branch-overlay table.
2. MenuResetLeCayenneCommand: env-gate + audit_logs entry.
3. Defer P2s to V1.0.2 hardening cycle.

End of report.
