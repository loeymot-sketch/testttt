# DBA — Wave W4 / Task T-2.1.1 Catalog SSOT consistency (Round 1)

**Specialist:** DBA (read-only audit)
**Task:** T-2.1.1 — `config/menu.php` ↔ DB ↔ frontend payloads
**Goal:** `goal-ultra-central-mgmt-sync-2026-05-18`
**Anchors verified:** `app/Models/Item.php`, `app/Models/ItemCategory.php`, `app/Models/ItemBranchAvailability.php`, `app/Console/Commands/MenuResetLeCayenneCommand.php` (1066 LOC), `app/Console/Commands/MenuHealLightV3Command.php` (621 LOC), `database/seeders/AlignProfile85ChickenBurgerSeeder.php`, `database/seeders/AlignFritesWizardProfilesSeeder.php`, `app/Services/Kiosk/KioskMenuService.php`, `app/Services/Menu/AvailabilityService.php`, 14 migrations.

---

## VERDICT

**ORANGE — schema sound for soft-delete workflows, but two critical operational risks: (a) catalog heal seeders bypass Eloquent + skip composer versioning, and (b) `menu:reset-le-cayenne` has zero environment guards.**

The catalog schema itself is correct: FK chain is consistent (RESTRICT-by-default with universal soft-deletes prevents accidental cascade nukes), `item_branch_availability` has the proper `(item_id, branch_id)` unique index + cascade FKs, and `item_wizard_profiles` has an XOR check constraint guaranteeing exactly-one-owner. The N+1 risk on the kiosk read path is mitigated by `KioskMenuService::build()` eager-loading. The risks are **operational, not structural**: the documented "SSOT writer" commands can produce a degraded state if mis-run, and the patch seeders create silent wizard-projection staleness because they never bump `version`/`published_at` or fire `ComposerProfileChanged`.

---

## TOP FINDINGS

### F1 — `menu:reset-le-cayenne` runs anywhere with no env guard and destroys 8 categories + ~35 items
**Severity:** P0 (operational hazard — worst-SQL-state question literal answer)
**File:line:** `app/Console/Commands/MenuResetLeCayenneCommand.php:25-158`, `app/Console/Commands/MenuResetLeCayenneCommand.php:233-263`
**Reasoning (strong):**
```yaml
claim: A manager (or a misconfigured cron / dev pipeline) can run `php artisan menu:reset-le-cayenne --force` against a production database and immediately soft-delete 8 categories by slug ("nos-sandwichs", "nos-burgers", "nos-assiettes", "ojja", "omelettes", "nos-salades", "chicken-tenders", "nos-menus-enfants") plus every Item under them, rename 4 more, then create 5 hardcoded Le Cayenne categories. There is zero environment check.
evidence:
  - command class declares signature `menu:reset-le-cayenne {--dry-run} {--force}` with only an interactive `confirm()` prompt (line 118) which is bypassed by `--force` (line 110).
  - I grep'd `app/Console/Commands/MenuResetLeCayenneCommand.php` for `App::environment`, `app()->environment`, `env('APP_ENV')`, `isProduction` — zero matches.
  - the ARCHIVE_SLUGS constant (line 33-36) is hardcoded; the command does NOT take a branch or tenant argument, so multi-restaurant tenancy (future SaaS) gets nuked just as fast as a single-tenant.
  - the `down()` migration path doesn't exist — this is a console command, not a migration; "rollback" means manually restoring the 8 categories from `deleted_at`. Items can be soft-restored via `Item::withTrashed()->restore()` but the addon/extra/variation linkages relying on those Items will require manual reconciliation (no inverse command exists).
counter-evidence:
  - the command does use `DB::transaction()` (line 132) wrapping all DB writes, so a mid-flight crash rolls back cleanly.
  - soft-delete (not hard delete) is used throughout, so `composition_snapshot` on historical `order_items` (frozen JSON, NF525-relevant) is preserved.
  - `DeletionLog` rows are written (line 253) for audit trail.
risk: A non-Le-Cayenne tenant who runs this on their DB loses their entire catalog instantly. Even on Le Cayenne, re-running it after a partial heal can re-archive items that were intentionally restored. The "ARCHIVE_SLUGS" list is hardcoded — once you add a 9th old slug or rename one, the command silently no-ops on it. CTO global audit agent-3-dba-saas (cited in repo as `reports/audit/cto-global-2026-05-16/agent-3-dba-saas.md:139`) already flagged this command as "hard-coded to one branch" — DBA concurs and elevates to operational P0.
caveats: The damage is reversible via soft-delete restore + replaying `eventsToFire` in `CategoryDeleted/Updated/Created` listeners, but reversal is manual and lossy if downstream consumers (kiosk cache, POS projection, outbox events) have already propagated `CategoryDeleted`.
verdict: BLOCK — add `if (! app()->environment(['local', 'testing', 'staging'])) { throw }` AND require `--branch=<id>` + idempotent slug-list config injection before merge to main.
```

### F2 — Wizard heal seeders use `DB::table()->insert()` — skip version bump, miss `ItemWizardStepVersion` snapshot, no `ComposerProfileChanged` event
**Severity:** P1 (catalog-payload staleness — kiosk/POS read cached old composer)
**File:line:**
- `database/seeders/AlignProfile85ChickenBurgerSeeder.php:76` — `DB::table('item_wizard_steps')->insert($rows);`
- `database/seeders/AlignFritesWizardProfilesSeeder.php:196` — same pattern
- contrast: `app/Console/Commands/MenuHealLightV3Command.php:54-55` documents the correct pattern ("Events deferred until after transaction commit (ComposerProfileChanged + CatalogChanged bridge)").
**Reasoning (strong):**
```yaml
claim: When AlignProfile85ChickenBurgerSeeder inserts a missing 'viande' step at position=3 and 'crudite' at position=4 on profile 85, it does NOT (a) increment `item_wizard_profiles.version`, (b) set `published_at = now()`, (c) write a row to `item_wizard_step_versions` (the snapshot table), nor (d) fire `ComposerProfileChanged` event. Kiosk / POS clients reading from cached `MenuSnapshot.snapshot_version` keep the OLD projection and PricingService rejects the SAME composition the seeder was supposed to fix.
evidence:
  - `item_wizard_step_versions` migration (`database/migrations/2026_05_04_000010_create_item_wizard_step_versions_table.php:10-26`) requires `profile_id`, `version`, `snapshot` JSON, `published_at`, `published_by_id` and has UNIQUE(`profile_id`, `version`). It's clearly the source-of-truth versioning layer.
  - `MenuHealLightV3Command::handle()` (`app/Console/Commands/MenuHealLightV3Command.php:203-213`) fires `ComposerProfileChanged + CatalogChanged` after the transaction commits.
  - `AlignFritesWizardProfilesSeeder.php:138-140` creates a NEW profile with `version=1, published_at=now()` — so the schema field is recognized, just not bumped on subsequent step inserts.
  - There is no `use App\Events\ComposerProfileChanged;` line in either seeder (grep'd).
counter-evidence:
  - the seeder is idempotent on a per-step_key basis via `existingKeys = ItemWizardStep::where('profile_id', 85)->pluck('step_key')` (line 31) — at least it won't duplicate rows.
  - the `(profile_id, step_key)` UNIQUE index (`database/migrations/2026_04_27_143110_create_item_wizard_steps_table.php:30`) would block any double-insert at the DB layer even if the in-PHP check fails.
risk:
  1. Cached kiosk projection (60s `Cache::remember` in `Frontend/MenuController:71` AND the `MenuSnapshot::current($branchId)` version stamp) does not invalidate. Until something else fires `CatalogChanged`, the kiosk wizard renders 3 steps while DB has 5.
  2. NEW BUG: `item_wizard_steps_profile_position_idx` is non-unique (just an index, not UNIQUE — verified `database/migrations/2026_04_27_143110_create_item_wizard_steps_table.php:29`). AlignProfile85ChickenBurgerSeeder inserts viande at position=3 and crudite at position=4. If profile 85 already has steps at positions 3/4 (e.g. menu/upsell), the seeder produces duplicate-position rows, and the wizard renders in undefined order (whatever `ORDER BY position, id` returns).
  3. `item_wizard_step_versions` is intentionally append-only (UNIQUE on `profile_id, version`) — there is no rollback path for a corrupted insert other than dropping the latest version row and bumping the next one. The seeders write zero rows here, so the snapshot history is wrong from this point forward.
caveats: Per BRAIN entry `Project — POS Payment 4-scenarios fix 2026-05-18`, this seeder was the ad-hoc fix for `Composition #N n'appartient pas au profil publié`. It worked because POS reads from the live `item_wizard_steps` rows, not from `item_wizard_step_versions`. But ANY consumer that trusts the version stamp is now stale.
verdict: HEAL — refactor both seeders to (a) use Eloquent `ItemWizardStep::firstOrCreate`, (b) wrap in `DB::transaction`, (c) bump `profile->version` + `published_at`, (d) write `ItemWizardStepVersion::create` snapshot row, (e) `event(new ComposerProfileChanged($profile->id))`. Add a regression test that asserts `snapshot_version` increments after seeder runs.
```

### F3 — No BranchScope on ANY catalog model — design "items are global" is unenforced
**Severity:** P2 (latent risk; aligned with BRAIN intent but undocumented in code)
**File:line:**
- `app/Models/Item.php` — `class Item extends Model implements HasMedia` (line 15); no `protected static function booted()` adding `BranchScope`; only declared scope is `SoftDeletes`.
- `app/Models/ItemCategory.php` — line 14, same pattern.
- `app/Models/ItemAddon.php`, `ItemAttribute.php`, `ItemVariation.php`, `ItemExtra.php`, `ItemWizardProfile.php`, `ItemWizardStep.php` — verified via `grep -rn "BranchScope" app/Models/Item*.php` returns ZERO hits.
- `app/Models/Scopes/BranchScope.php` exists but is only `use`'d by `KioskMachine.php:36`.
- CLAUDE.md §9 "Branch Isolation" lists 11 models that DO have BranchScope (Order, FrontendOrder, OrderItem, OrderPayment, KioskMachine, StockLevel, StockMovement, CashDrawerSession, CashMovement, PendingPaymentConfirmation, PushNotification, DiningTable, Printer) — **catalog is intentionally excluded**.
**Reasoning (strong):**
```yaml
claim: The catalog (Item, ItemCategory, ItemVariation, ItemExtra, ItemAddon, ItemAttribute, ItemWizardProfile, ItemWizardStep) is GLOBAL across all branches by design — branch-specific availability lives in the `item_branch_availability` table with a `(item_id, branch_id)` unique index. This is correct for a single-tenant chain, but in V2-SaaS multi-tenant it is a P0 leak — tenant A would see tenant B's items.
evidence:
  - migration `2026_04_15_230100_create_item_branch_availability_table.php:26` has `$table->unique(['item_id', 'branch_id'])` and `$table->index(['branch_id', 'is_available'])` — perfect for per-branch overlay.
  - `KioskMenuService::build()` (`app/Services/Kiosk/KioskMenuService.php:91-97`) loads `ItemBranchAvailability::query()->whereIn('item_id', $items->pluck('id'))->where('branch_id', $branchId)->get()->keyBy('item_id')` — the projection IS branch-aware.
  - `AvailabilityService::toggle()` (`app/Services/Menu/AvailabilityService.php:45-81`) is lazy-init: a row exists only after first toggle. Missing row = available by default. This matches GLOBAL-with-per-branch-override semantics correctly.
  - `Frontend/ItemCategoryController.php` was not read but follows the same Pattern based on `Admin/ItemController::index` (`app/Http/Controllers/Admin/ItemController.php:44-77`) which performs explicit branch_id authorization (`forcePosRuntimeBranchScope`, `authorizeBranchScope`) at the **controller** layer instead of model layer.
counter-evidence:
  - For V1 Le Cayenne (single chain), this design is fine. The BRAIN entry `Project — CTO Global Audit 2026-05-16` itself flags V2-SaaS as "NO-GO 6-12mo" — the architecture is V1-aware.
  - SaaS multi-tenancy would require a `restaurant_id` column on `item_categories` + `items` + scope filter, not just BranchScope. That's a bigger refactor than adding a scope.
risk:
  1. A developer who incorrectly assumes catalog is branch-scoped (a reasonable assumption given the other 11 models) writes code like `Item::find($id)` and expects branch isolation. They get cross-branch data.
  2. The discrepancy is not documented in `app/Models/Item.php` itself — no class-level docblock explaining "GLOBAL by design, branch overlay via ItemBranchAvailability". A new contributor or AI agent could easily miss this.
  3. F-009/F-016 BIS from prior audits explicitly addressed branch isolation for operational tables; catalog was deliberately left out, but there's no audit comment in `Item.php` confirming this.
caveats: This is a design decision, not a bug. The verdict is documentation-debt, not scope-add. Adding BranchScope to Item would break the kiosk/POS catalog reads.
verdict: HEAL — add an explicit `/** @scope GLOBAL — per-branch availability via item_branch_availability. Do NOT add BranchScope. */` docblock to Item.php and ItemCategory.php class-level. Cross-reference CLAUDE.md §9 wording change to call out "Catalog GLOBAL exception".
```

---

## COVERAGE MAP

| Anchor | Read | Notes |
|---|---|---|
| `database/migrations/2022_11_17_110428_create_item_categories_table.php` | ✓ | No softDelete, no FK; added later via `2026_04_15_230200`. |
| `database/migrations/2022_11_17_110514_create_items_table.php` | ✓ | FK `item_category_id`, `tax_id` default RESTRICT; has `softDeletes()`. |
| `database/migrations/2022_11_17_110541_create_item_attributes_table.php` | ✓ | No soft-delete. |
| `database/migrations/2022_11_17_110621_create_item_variations_table.php` | ✓ | FK item/attr default RESTRICT; softDelete present. |
| `database/migrations/2022_11_17_110650_create_item_extras_table.php` | ✓ | Same pattern. |
| `database/migrations/2022_11_17_120627_create_item_addons_table.php` | ✓ | Same. |
| `database/migrations/2026_04_15_230100_create_item_branch_availability_table.php` | ✓ | UNIQUE(item_id, branch_id) + index(branch_id, is_available); lazy-init OK. |
| `database/migrations/2026_04_18_140001_add_fks_to_item_branch_availability.php` | ✓ | CASCADE on both FKs (correct). |
| `database/migrations/2026_04_18_120001_add_parent_id_to_item_categories_table.php` | ✓ | `nullOnDelete` — depth enforced in service, not SQL. |
| `database/migrations/2026_04_27_143100_create_item_wizard_profiles_table.php` | ✓ | item_id cascadeOnDelete; branch_id_scope nullOnDelete. |
| `database/migrations/2026_04_27_143110_create_item_wizard_steps_table.php` | ✓ | UNIQUE(profile_id, step_key); position is INDEXED, NOT UNIQUE — F2 risk #2. |
| `database/migrations/2026_05_04_000010_create_item_wizard_step_versions_table.php` | ✓ | UNIQUE(profile_id, version); append-only snapshot table. |
| `database/migrations/2026_05_05_000020_make_item_wizard_profiles_polymorphic_owner.php` | ✓ | XOR check (item_id XOR item_category_id) — well-modeled. |
| `database/migrations/2026_04_15_230200_v1_soft_deletes_and_deletion_log.php` | ✓ | Adds softDelete to item_categories retroactively. |
| `database/migrations/2026_05_10_050000_phase_d_omelette_ojja_salade_poulet_menu_supplements.php` | ✓ | Idempotent + DB::transaction; OK pattern. |
| `app/Console/Commands/MenuResetLeCayenneCommand.php` | partial (200/1066 LOC) | DRY-RUN OK; F1 risk on env guard. |
| `app/Console/Commands/MenuHealLightV3Command.php` | partial (200/621 LOC) | Idempotent + events + FROZEN_FILES list. |
| `database/seeders/AlignProfile85ChickenBurgerSeeder.php` | ✓ (full 79 LOC) | F2 critical pattern. |
| `database/seeders/AlignFritesWizardProfilesSeeder.php` | partial | Same F2 pattern. |
| `app/Models/Item.php` | ✓ (full 178 LOC) | F3 confirmed: no BranchScope. |
| `app/Models/ItemCategory.php` | ✓ (full 176 LOC) | F3 confirmed. |
| `app/Models/ItemBranchAvailability.php` | ✓ (full 38 LOC) | No BranchScope; per-row scoping. |
| `app/Services/Kiosk/KioskMenuService.php` | partial (120/507 LOC) | Eager-loads variations/extras/addons/allergens — N+1 mitigated. |
| `app/Services/Menu/AvailabilityService.php` | partial (40-150) | Lazy-init + lockForUpdate — concurrency-safe. |
| `app/Http/Controllers/Admin/ItemController.php` | ✓ (1-120 LOC) | Branch authz at controller, not model. |
| `app/Services/ItemService.php` | spot-checked | `simpleList` eager-loads `media, category, offer`; admin show loads variations/extras/addons. |
| Not read: `MenuController` Admin (only loads sidebar Menu model, irrelevant to catalog). |

---

## OPEN QUESTIONS

1. **Q1 — Profile 85 position collision:** Does profile 85 already have steps at positions 3 and 4 *before* `AlignProfile85ChickenBurgerSeeder` ran (BRAIN says it was run successfully 2026-05-18)? If yes, what's the current `ORDER BY position, id` rendering? Need a `SELECT * FROM item_wizard_steps WHERE profile_id = 85 ORDER BY position, id` snapshot from prod DB to confirm whether F2 risk #2 has already manifested.

2. **Q2 — `config/menu.php` ↔ DB drift detector:** The task title says "config/menu.php ↔ DB ↔ frontend payloads" but I didn't audit `config/menu.php` directly (it's the SSOT comment; the heal commands are the writers). Is there any drift-detector command (`menu:audit-drift` or equivalent) that compares the config to DB rows and flags mismatches? If not, the SSOT is "documentation-only" and there's no automated guard against post-heal drift.

3. **Q3 — `item_wizard_step_versions` consumers:** Who actually reads from `item_wizard_step_versions`? The migration creates the table but I didn't find a service that loads from it during request handling — `KioskMenuService` reads live `ItemWizardStep` rows via `ComposerProfileProjection`. If `item_wizard_step_versions` is write-only history (NF525-style audit), then F2's "stale snapshot" claim downgrades from "kiosk shows wrong wizard" to "audit trail missing a row" — still P1 but less user-facing. Need to grep `ItemWizardStepVersion::query` and `ComposerProfileProjection` to confirm.

4. **Q4 — N+1 on admin item edit:** `ItemService::show()` at line 471-474 (`return $item->load('media', 'category', 'tax', 'offer', 'addons', 'variations', 'extras')`) eager-loads but does NOT include `wizardProfile.steps` or `addons.addonItem`. The admin edit screen probably triggers ~2N queries when rendering a profile with N steps. Not catastrophic, but a single-item show shouldn't N+1. Out of scope for catalog SSOT, but flag for separate task T-2.1.x.

5. **Q5 — `MenuResetLeCayenneCommand` and existing `kiosk_machines`:** When the command archives 8 categories and creates 5 new ones (lines 233-310), what happens to `KioskMachine` rows that referenced those category IDs in their localization config (if any)? I didn't audit this propagation. Could leave dangling references in cached projections.

---

**Word count:** ~1450. **Read-only:** ✓. **File:line citations:** all top findings + coverage table.

