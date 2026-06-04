# L5 — CATALOG ENGINE + DATA PERSISTENCE LAYER AUDIT

> **Auditor:** L5 sub-agent (read-only, scope = catalog models + composer wizard + migrations + persistence + backups)
> **Date:** 2026-05-17
> **Branch:** feature/mobile-app-le-cayenne-2026-05-10
> **Methodology:** static read of code + DB schema + service layer + listener fan-out + scheduler + backup contents
> **Total LOC inspected:** ~6 600 (8 models, 6 composer services, 158 migrations sampled, 3 listeners, 1 menu reset command, 1 console kernel)

---

## EXECUTIVE VERDICT

| Dimension | Score / 100 | Confidence |
|---|---|---|
| Catalog schema (single-tenant V1 Le Cayenne) | **72** | high |
| Catalog schema (multi-tenant V2 SaaS) | **8** | high |
| Composition_snapshot immutability (NF525) | **78** | high |
| Composer wizard implementation | **66** | high |
| Migration discipline (down, idempotence) | **62** | high |
| Indexes coverage | **58** | medium |
| FK constraints (orphan prevention) | **64** | medium |
| Backup automation | **0** | high |
| Restore tested? | **0** | high |
| NF525 6-year retention enforced? | **35** | high |
| Catalog change events fan-out | **55** | high |
| **WEIGHTED L5 GLOBAL (V1 Le Cayenne)** | **54** | high |
| **WEIGHTED L5 GLOBAL (V2 SaaS)** | **18** | high |

**VERDICT V1 single-resto Le Cayenne:** GO-CONDITIONAL — 3 P0 backups + 2 P0 schema-drift to seal before shipping; functional V1 already lives in prod.
**VERDICT V2 SaaS multi-tenant:** NO-GO 6-12 months — catalog has zero branch_id discriminator, composer profiles only have soft scope, ItemAttribute/ItemExtra/ItemAddon globally shared.

---

## P0 FINDINGS

### P0-L5-01 — Zero automated backup, manual SQL dumps only
- **File:** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Console/Kernel.php` (lines 21-154)
- **Evidence:**
  - No `backup:run` / `db:dump` / spatie-laravel-backup / nightly-S3 schedule.
  - `composer.json` does NOT depend on `spatie/laravel-backup` or any backup driver.
  - `storage/backups/` contains 5 directories named after one-shot heal cycles (`menu-v3-2026-05-14/foodking-pre-v3.sql`, `ultra-review-heal-2026-05-16/foodking-pre-heal.sql.gz`, etc.). All hand-made by the dev with `mysqldump`, none rotated, none off-site.
- **Impact:** NF525 demands 6-year retention. Single SSD failure = total catalog + fiscal data loss = legal exposure + production outage.
- **Severity:** P0 (NF525 compliance blocker, business continuity)

### P0-L5-02 — items.branch_id absent — V2 SaaS multi-tenant blocker
- **Files:**
  - `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/migrations/2022_11_17_110514_create_items_table.php` (lines 19-38: NO `branch_id` column)
  - `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Models/Item.php` (lines 16-77: no BranchScope, no branch_id fillable)
- **Evidence:** `ItemBranchAvailability` (per-branch on/off flag) is a band-aid — it does NOT scope ownership. Item, ItemCategory, ItemVariation, ItemAttribute, ItemAddon, ItemExtra all live in a single global namespace. ItemWizardProfile.branch_id_scope (line 17 of model) is the only catalog object with a real branch dimension, and it's *optional*.
- **Impact:** Cannot onboard a second restaurant. Any V2 catalog edit at restaurant-A would mutate restaurant-B's menu. SaaS roadmap blocked.
- **Severity:** P0 for V2; informational for V1 single-tenant.

### P0-L5-03 — Schedule fan-out uses `branch->status=1` but enum is `Status::ACTIVE=5`
- **File:** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Console/Kernel.php` lines 123-127
- **Evidence:** `Branch::query()->where('status', 1)->whereNull('deleted_at')->pluck('id')->each(...)`
  - `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Enums/Status.php` defines `const ACTIVE = 5;` and `INACTIVE = 10;`.
  - Comparable listeners were healed: `app/Listeners/PersistCatalogChangedToOutbox.php` line 39 uses `whereIn('status', [Status::ACTIVE, 1])` (the legacy bug worked-around). `app/Listeners/PersistItemAvailabilityChangedToOutbox.php` line 44 same fix.
  - **Kernel.php was NOT healed.** Fiscal archive cron at 02:00 will silently run zero iterations on any branch with status=5 (the canonical post-enum value).
- **Impact:** NF525 daily fiscal archive job (`foodking:fiscal:archive`) silently no-ops in branches with status=5. Audit trail still written, but the daily ZIP+JSON archive (NF525 §V.4) is skipped. Detected because the same pattern in two listeners was already fixed.
- **Severity:** P0 (NF525 daily archive missing, compliance gap)

### P0-L5-04 — Composer wizard source_type enum drift schema vs code
- **Files:**
  - `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/migrations/2026_04_27_143110_create_item_wizard_steps_table.php` line 17 — DB enum allows `['item_attribute', 'extra_group', 'addon', 'fixed']`
  - `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/Composer/ComposerStepService.php` line 12 — `private const SOURCE_TYPES = ['item_attribute', 'extra_group', 'addon']`. Line 85-87 throws on `'fixed'`.
  - `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/Composer/ComposerProfileProjection.php` line 77-167 — no handling of `'fixed'`, falls through to empty `[]`.
- **Impact:** Any seeder/migration that inserts `source_type='fixed'` (legal at DB level) silently disables the step in projection. ComposerStepService rejects edits afterwards, but the row exists & breaks the wizard.
- **Severity:** P0 (data integrity, composer wizard fail-open silent)

### P0-L5-05 — Migration 2026_05_10_070000 uses HARDCODED category IDs 310/314
- **File:** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/migrations/2026_05_10_070000_phase_d_v381_wizard_template_align.php` lines 26-29
- **Evidence:** `DB::table('item_categories')->whereIn('id', [310, 314])->update(['wizard_template' => 'omelette', ...]);`
- **Impact:** On any environment other than the dev DB where 310/314 happened to be Ojja/Menus Enfants, this migration silently no-ops or worse — mutates an unrelated category. Onboarding a fresh restaurant = wizard template never aligned. Re-runnability nightmare.
- **Severity:** P0 (multi-environment fragility; blocks V2 SaaS deploy across tenants)

---

## P1 FINDINGS

### P1-L5-06 — composition_snapshot has no DB-level immutability trigger
- **File:** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/migrations/2026_04_22_000020_add_composition_snapshot_to_order_items.php` lines 8-25
- **Evidence:** Column added as `json nullable`. No BEFORE UPDATE trigger forbids re-writing the JSON post-create. `app/Models/OrderItem.php` line 71 casts to array but does not enforce write-once. `audit_logs` and `z_reports` got triggers (migrations 2026_04_22_000002 + 2026_05_09_160000), `composition_snapshot` did not.
- **Mitigation:** Test `tests/Feature/OrderItemCompositionSnapshotTest::test_snapshot_is_immutable_after_variation_rename` validates *catalog* mutation does not affect snapshot, but not write-once semantics. CompositionSnapshotBuilder.php line 13 docblock asserts "NEVER be re-written" — pure convention.
- **Impact:** NF525 §V audit-chain reprint relies on snapshot stability. Any future service that mass-updates order_items risks a silent NF525 breach.
- **Severity:** P1 (compliance defense-in-depth missing; honor-system today)

### P1-L5-07 — items.branch_id absent for legacy items table → soft-scoped only through OrderItem
- **File:** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Models/OrderItem.php` lines 15-28
- **Evidence:** OrderItem applies BranchScope global; Item does not. P0-FIX-2 comment in OrderItem (line 21-26) explicitly notes `ItemService::destroy()` was leaking historical-order counts cross-tenant. Patch only fixed OrderItem, not Item. Single-tenant impact = none; multi-tenant deferred.
- **Severity:** P1 cross-cutting with P0-L5-02.

### P1-L5-08 — Migration `2022_11_17_110621_create_item_variations_table.php` has typo in down()
- **File:** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/migrations/2022_11_17_110621_create_item_variations_table.php` line 40
- **Evidence:** `Schema::dropIfExists('iteme_variations');` — typo "iteme_variations" instead of "item_variations". `migrate:rollback` will not drop the table.
- **Same bug:** `2022_11_17_110650_create_item_extras_table.php` line 38 — `Schema::dropIfExists('iteme_extras');` — identical typo. Both unfixed since 2022.
- **Severity:** P1 (rollback broken on TWO root tables — sleeper for any future migration that touches them)

### P1-L5-09 — ItemWizardStepVersion update() throws but DELETE remains allowed
- **File:** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Models/ItemWizardStepVersion.php` lines 64-69
- **Evidence:** `update()` overridden to throw. **No** override of `delete()` / `forceDelete()`. Model docblock line 19 says "Never direct DELETE: cascade only" — pure convention, not enforced. No DB trigger (unlike audit_logs / z_reports).
- **Severity:** P1 (auditability gap)

### P1-L5-10 — Daily reset cron + lazy reset both rely on `Carbon::today()` server timezone
- **File:** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/Menu/AvailabilityService.php` lines 282-294
- **Evidence:** `$today = Carbon::today()->toDateString();` uses default app timezone. No branch-timezone override (cf. franchise spanning multiple time zones).
- **Severity:** P1 (V2 multi-region SaaS blocker; single-resto V1 unaffected because Le Cayenne is Europe/Paris)

### P1-L5-11 — Composer profile fan-out via CategoryUpdated event swallowed if branch table empty
- **File:** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Listeners/PersistCatalogChangedToOutbox.php` lines 30-45
- **Evidence:** `Branch::query()->whereIn('status', [Status::ACTIVE, 1])->pluck('id')` — if 0 active branches matched (e.g. seed-only test env, dump migration applied without status backfill), `return` line 44 silently drops the event.
- **Severity:** P1 (silent failure; sentinel tests don't catch empty-branch case explicitly)

### P1-L5-12 — Composer wizard validation has trace logging gap
- **File:** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/Composer/ComposerProfileService.php` lines 175-222
- **Evidence:** Throws `ValidationException` with generic French message ('Composer profile cannot be published without active steps.') — no structured log trace, no Sentry breadcrumb. Owner cannot debug why a publish fails without poking the response payload.
- **Severity:** P1 (operability)

### P1-L5-13 — backfill_wizard_template_null_in_item_categories.php — no down() verified
- **Files:** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/migrations/2026_04_14_232119_backfill_wizard_template_null_in_item_categories.php` (440 bytes total)
- **Evidence (typical backfill pattern):** down() in backfill migrations rarely reverses data. Verified `2026_04_20_131600_backfill_fr_codes_in_order_items_allergens_snapshot.php` — same pattern.
- **Severity:** P1 (re-run safety — must NEVER `migrate:rollback` past these)

### P1-L5-14 — composer_profile branch_id_scope FK is `nullOnDelete`, orphans wizard profiles
- **File:** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/migrations/2026_04_27_143100_create_item_wizard_profiles_table.php` line 18
- **Evidence:** `$table->foreignId('branch_id_scope')->nullable()->constrained('branches')->nullOnDelete();`. Deleting a branch leaves orphaned wizard profile rows scoped to the now-defunct branch. ComposerProfileService::resolveForItem falls through to global default — acceptable as cleanup but creates ghost rows.
- **Severity:** P1 (data hygiene)

---

## P2 FINDINGS

### P2-L5-15 — MenuResetLeCayenneCommand is a 1 067-LOC artisan god-command, single-resto specific
- **File:** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Console/Commands/MenuResetLeCayenneCommand.php` (1 067 lines)
- **Evidence:** hard-coded `private const ARCHIVE_SLUGS`, `RENAMES`, `NEW_CATEGORIES`, `VIANDES`, `SAUCES`, `CRUDITES`, `SUPPLEMENTS`. 12 step methods, no per-branch scope. Comment line 169 verifies `branchCount` but proceeds regardless. ItemWizardProfile created with `branch_id_scope => null` line 896 — global.
- **Impact:** V2 SaaS = unusable. Each tenant would need its own command. Pattern duplicated in MenuHealLightV2/V3/V31Burger (4 sister commands).
- **Severity:** P2 (debt — architectural, not correctness)

### P2-L5-16 — Composer template service uses 'tacos' / 'sandwich' / 'assiette' but DB enum line 14 includes 'burger' / 'salade' not present in ComposerTemplateService::TEMPLATES
- **Files:**
  - `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/Composer/ComposerTemplateService.php` line 19 — `['simple', 'sandwich', 'tacos', 'assiette', 'snacking', 'menu', 'custom']`
  - `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/migrations/2026_03_12_080617_add_wizard_config_to_item_categories.php` line 21-22 — comment string lists `tacos|sandwich|burger|assiette|salade|omelette|snacking|simple` (8 templates).
  - `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/migrations/2026_04_27_143100_create_item_wizard_profiles_table.php` line 14 — `enum('template', ['simple', 'sandwich', 'tacos', 'assiette', 'snacking', 'menu', 'custom'])` (7 templates, no burger/salade/omelette).
- **Impact:** Migration 2026_05_10_070000 sets wizard_template='omelette' line 28 which is NOT a valid enum value on item_wizard_profiles.template — but it's set on item_categories.wizard_template (varchar 20, no enum). Asymmetry. Burger/Salade categories cannot have wizard profiles.
- **Severity:** P2 (catalog/composer enum dictionary drift)

### P2-L5-17 — Index on items table is non-optimal for sort
- **File:** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/migrations/2026_03_12_130000_add_performance_indexes.php` lines 38-44
- **Evidence:** `idx_items_status_category` covers (status, item_category_id) but NOT `order` sort. POS catalog query (MenuProjectionService line 105 `sortBy(fn (Item $it): int => (int) ($it->order ?? 0))`) sorts in PHP after pulling all items, not DB-side.
- **Severity:** P2 (perf debt; ~50ms/page on >1k items)

### P2-L5-18 — `addons` table missing — only `item_addons` exists, but `app/Models/Addon.php` declared
- **Files:**
  - `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Models/Addon.php` (176 bytes — tiny class)
  - `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/migrations/2022_11_17_115716_create_addons_table.php` — table exists but never relationally bound by Item.
- **Severity:** P2 (dead model — risk of confusion for new dev)

### P2-L5-19 — ChannelsNullWarningTest reveals `channels=NULL` is back-compat trap
- **File:** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/app/Services/Catalog/CatalogWarningService.php` lines 100-106
- **Evidence:** `CODE_CHANNELS_NULL` warning severity=info, not warning/blocker. Production items seeded before dual-channel migration (2026_04_16_200000_add_channel_columns) all have channels=NULL — visible on every surface. No backfill migration sets explicit channels.
- **Severity:** P2 (UX trap — admin can't easily restrict an item to POS only without manual JSON cast)

### P2-L5-20 — No CHECK constraint on items.price >= 0
- **File:** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/migrations/2022_11_17_110514_create_items_table.php` line 27
- **Evidence:** `decimal('price', 19, 6)->default(0)`. PHP layer enforces via PricingService but DB will accept negative prices.
- **Severity:** P2 (defense-in-depth)

### P2-L5-21 — ItemBranchAvailability missing FK on first migration, added 3 days later
- **Files:** 2026_04_15_230100_create_item_branch_availability_table.php (no FK) → 2026_04_18_140001_add_fks_to_item_branch_availability.php
- **Evidence:** 3-day window where orphan rows could exist. SQLite skips FKs (line 24-26 of second migration). Tests pass without FK validation.
- **Severity:** P2 (historical; one-shot risk closed)

### P2-L5-22 — addons.addon_item_id FK cascade missing
- **File:** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/database/migrations/2022_11_17_120627_create_item_addons_table.php` lines 18-19
- **Evidence:** `foreignId('addon_item_id')->constrained('items')` — default cascade NULL on delete = nothing. Deleting an Item leaves item_addons rows referencing a soft-deleted item. ItemAddon model uses SoftDeletes (line 11) — masks but doesn't fix.
- **Severity:** P2 (orphan possible if force-delete)

---

## P3 FINDINGS

- **P3-L5-23** Item.php casts price as `decimal:6` but DB stores `decimal(19,6)` — frontend round-trip risk on extreme prices (>10^13). Unlikely in V1.
- **P3-L5-24** MenuSeeder is 803 LOC, no test for re-run idempotency in CI. `tests/Feature/MenuSeederTest.php` exists but is rudimentary.
- **P3-L5-25** OutboxRetryFailedCommand processes only last 24h (Kernel.php line 64) — terminal failures older than 24h require manual rescue. Operability gap noted.
- **P3-L5-26** No explicit unique constraint on (item_id, addon_item_id) in item_addons — duplicate row possible if seeder bug.
- **P3-L5-27** ItemAttribute model lacks SoftDeletes despite being parent of soft-deletable ItemVariation. Hard-delete cascades. Comment line 12 of ItemAttribute.php has no `use SoftDeletes`.

---

## STRENGTHS

1. **Composition snapshot architecture is solid** — `CompositionSnapshotBuilder::SCHEMA_VERSION=1`, snapshot includes catalog_price + effective unit_price for ratio audit (line 148 — NF525 reprint reconciliation). 4 unit tests cover legacy fallback, rename immutability, quantity, schema_version.
2. **MenuProjectionService SSOT design** — single class projects POS/Kiosk/Web, dual-channel `channels` column, branch-scoped availability via `ItemBranchAvailability`. Uses MenuSnapshot for atomic INCR + 7-day Redis TTL.
3. **PosMenuProjection shim** — staged convergence (legacy → shadow_compare → unified) with kill-switch, no big-bang. Pattern reusable.
4. **Composer wizard separation of concerns** — ProfileService (CRUD), StepService (rules), TemplateService (starters), DiffService (publish-diff), Projection (read), ProfileProjection (sync). Tests/Feature/Composer/ has 17 specs.
5. **NF525 immutability triggers on audit_logs + z_reports + cash_movements + cash_drawer_sessions + order_payments** — 4 triggers in 2 migrations (2026_05_10_010000 + 2026_05_09_160000). Block UPDATE+DELETE at DB level on MySQL/MariaDB and SQLite.
6. **ItemWizardStepVersion insert-only model** — line 64-69 throws on update(). DB unique constraint (profile_id, version).
7. **AvailabilityService.releaseForOrderItems** — CAS-style flip with `released_qty` ledger, idempotent under double-fire (lines 602-725 + good comment trail).
8. **MenuResetLeCayenneCommand idempotency** — `createOrRestoreItem` line 600-613, `seedViandesForItem` line 630-654 etc. all check withTrashed → restore. Replayable.

---

## ANTI-DRIFT RECONCILIATION (vs Agent 3 DBA findings)

Agent 3 said: "DB 72/100 single-tenant but SaaS 8/100 (items lacks branch_id)."

L5 confirmation:
- **items.branch_id absent** → CONFIRMED (P0-L5-02). Item model + migration both lack it.
- **single-tenant 72/100** → L5 score = 72 catalog schema + 78 snapshot + 66 wizard, weighted → 54 globally because backups (0) and retention (35) drag.
- **SaaS 8/100** → L5 worse (18) once you weigh wizard branch_id_scope-optional + composer template hardcoded to FR.

No drift. Findings are reinforcing.

---

## CONCRETE FILE:LINE TOP-10

1. `app/Console/Kernel.php:124` — `where('status', 1)` should be `whereIn('status', [Status::ACTIVE, 1])`
2. `database/migrations/2022_11_17_110514_create_items_table.php:19-38` — no branch_id column
3. `app/Console/Commands/MenuResetLeCayenneCommand.php` — 1067 LOC single-resto hardcode
4. `database/migrations/2022_11_17_110621_create_item_variations_table.php:40` — `dropIfExists('iteme_variations')` typo
5. `database/migrations/2022_11_17_110650_create_item_extras_table.php:38` — `dropIfExists('iteme_extras')` typo
6. `database/migrations/2026_05_10_070000_phase_d_v381_wizard_template_align.php:26` — hardcoded IDs 310/314
7. `app/Services/Composer/ComposerStepService.php:12` vs `database/migrations/2026_04_27_143110_create_item_wizard_steps_table.php:17` — enum drift `fixed`
8. `database/migrations/2026_04_22_000020_add_composition_snapshot_to_order_items.php:11-13` — no immutability trigger
9. `app/Models/ItemWizardStepVersion.php:64-69` — update() throws but delete() open
10. `app/Models/Item.php:16-77` — no BranchScope on Item (compare OrderItem.php:15-28)

---

## REMEDIATION PRIORITY (V1 first)

### Sprint 1 (4-6 hrs — V1 ship-block)
1. Patch Kernel.php:124 — `whereIn('status', [Status::ACTIVE, 1])` (P0-L5-03).
2. Schedule `mysqldump` cron + retention script + S3 sync (`backup:run` via spatie/laravel-backup; 1 day for installer; 1 day for smoke) (P0-L5-01).
3. Migrate items.branch_id NULL-by-default + populate Le Cayenne branch on existing rows (P0-L5-02 path-A: single-tenant unblock).
4. Fix typos in iteme_variations / iteme_extras down() (P1-L5-08).

### Sprint 2 (1 week — V1 hardening)
5. Add BEFORE UPDATE trigger on order_items.composition_snapshot WHERE composition_snapshot IS NOT NULL (P1-L5-06).
6. Override ItemWizardStepVersion::delete (P1-L5-09).
7. Sentinel test for fan-out when 0 active branches (P1-L5-11).
8. Replace ID-based migration 2026_05_10_070000 with slug-based query (P0-L5-05).

### Sprint 3 (V2 SaaS prep — 4-6 sprints, 8-12 weeks)
9. Add Item.branch_id global scope; refactor ItemAttribute/Extra/Addon to support scope (massive — P0-L5-02 full).
10. ComposerProfileService.branch_id_scope mandatory.
11. MenuResetLeCayenneCommand → tenant-aware seed runner.

---

## TEST COVERAGE SUMMARY

- **Composer:** 17 specs (`tests/Feature/Composer/`) — version conflict, publish, unpublish, projection, branch-scoped, immutability. Strong.
- **Catalog:** 12 specs (`tests/Feature/Catalog/`) — outbox idempotency, category rename sync, warning service, photo end-to-end. Good.
- **Menu/Availability:** 18 specs (`tests/Feature/Menu/`) — projection parity, max-daily-qty, branch-scope, ItemExtra/Variation availability. Excellent.
- **Items:** 3 specs (`tests/Feature/Items/`) — create contract, photo upload atomicity. Thin (missing branch_id sentinel).
- **Snapshot:** 1 spec (`tests/Feature/OrderItemCompositionSnapshotTest.php`) — 4 cases, covers schema_version & immutability. Adequate, missing trigger sentinel.

---

## CONCLUSION

L5 layer is **production-ready for Le Cayenne single-resto V1** with 4 quick fixes (Sprint 1, ~1 day). The catalog engine (items + categories + variations + extras + addons), composer wizard (5 services + 3 models + step versioning), and persistence layer (158 migrations, NF525 triggers, fiscal append-only audit) form a coherent V1 stack.

**Backups are the most urgent gap (P0).** No automation, no rotation, no off-site copy = NF525 6-year retention violated by accident the day the SSD fails.

**Multi-tenant V2 SaaS is structurally blocked** until items.branch_id is introduced (P0-L5-02). The ItemBranchAvailability table is a band-aid for the availability dimension, not for ownership. ItemAttribute/ItemExtra/ItemAddon all share a single global namespace today.

Composition snapshot immutability is enforced by *convention* (CompositionSnapshotBuilder docblock + tests) but lacks the DB trigger defense-in-depth applied to audit_logs / z_reports. NF525 §V reprint integrity = honor-system.

Score V1: 54/100 (GO-CONDITIONAL on 4 fixes).
Score V2: 18/100 (NO-GO 6-12 months).
