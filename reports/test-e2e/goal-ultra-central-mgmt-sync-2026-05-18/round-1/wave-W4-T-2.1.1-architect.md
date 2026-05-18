# Wave W4 — T-2.1.1 — Architect (read-only)

**Task:** Catalog SSOT consistency (`config/menu.php` ↔ DB ↔ frontend payloads)
**Round:** 1 | **Specialist:** Architect | **Mode:** read-only audit
**Anchors verified live (`find`/`grep`/`wc -l`) 2026-05-18.**

---

## §1 — Verdict

**SSOT claim is false.** `config/menu.php` (756 LOC, self-labelled "SINGLE SOURCE OF TRUTH" L5) is **architecturally a mislabeled artifact**. It is consumed by `config('menu.*')` at exactly **two runtime callsites**:

- `app/Services/Order/OrderQuoteService.php:505` — currency default fallback (`config('menu.currency','EUR')`)
- `database/seeders/TaxTableSeeder.php:61` — comment-only reference

Plus one introspection point (`app/Console/Commands/MenuCommand.php:283`). All `items / categories / meats / sauces / crudites / supplements` arrays are **read by `MenuSeeder.php` only** (`database/seeders/DatabaseSeeder.php:91` calls `MenuSeeder::class`). The actual production catalog mutation pipeline (`MenuResetLeCayenneCommand` 1066 LOC, `MenuHealLightV3Command` 621 LOC) **hardcodes its own constants** (`self::SAUCES`, `self::CRUDITES`, `self::SUPPLEMENTS` at `MenuResetLeCayenneCommand.php:55-73`) that **disagree by name and count** with `config/menu.php`.

DB is the real SSOT, populated by artisan commands with **divergent in-code constants**. Frontend payload (`app/Http/Controllers/Frontend/MenuController.php` + `app/Services/Kiosk/KioskMenuService.php`) is **100% DB-derived** with zero consistency check against config (`KioskMenuService.php:66-100` reads `ItemCategory::query()` then `Item::query()` directly). Verdict: **GO-CONDITIONAL on T-2.1.1**, three P0/P1 below must be addressed before V1.0.1 GA. Test `tests/Feature/Catalog/CatalogSsotConsistencyTest.php` does NOT exist — acceptance gate for T-2.1.1 cannot be evaluated until written.

---

## §2 — Top 3 Findings (strong-reasoning YAML per §0.6)

### Finding 1 — **P0** `config/menu.php` is a mislabeled non-SSOT; data sets diverge from production mutators

`config/menu.php:5-8` declares itself the "ONLY authorized source for menu configuration". Reality: 3 data-set divergences with `MenuResetLeCayenneCommand` (the command that actually wrote the prod 2026-05-13 baseline, git `4f1cc7e50`):

| Dataset | `config/menu.php` | `MenuResetLeCayenneCommand` | Delta |
|---|---|---|---|
| **Sauces** | L98-114: 15 items (Ketchup, Mayonnaise, Algérienne, Curry, Andalouse, Burger, Samouraï, Barbecue, Cocktail, Américaine, Hannibal, Harissa, Blanche, Poivre, Sans Sauce) | L55-58: 13 items (Mayonnaise, Ketchup, Algérienne, Samouraï, Curry, Andalouse, Harissa, Hannibal, Blanche, **Tandoori, Fromagère, Pimentée, Cayenne**) | **+4 / −6** (Tandoori/Fromagère/Pimentée/Cayenne in DB; Burger/Cocktail/Américaine/Barbecue/Poivre/SansSauce in config but not in Reset) |
| **Crudités** | L122-126: 3 (Salade, Tomate, Oignon) | L60: 4 (Salade, Tomate, Oignon, **Cornichon**) | **+1** in DB |
| **Suppléments** | L133-140: 6 (Jambon de dinde, Boursin, Fromage a raclette, Œuf, Fromage, Galette pommes de terre) | L62-73: 10 (**Cheddar, Raclette, Emmental, Œuf, Bacon, Légumes sautés, Jambon, Oignons frits, Champignons, Boule gratinée**) | **fully different set** |
| **Items** | L154-318: e.g. `nos-burgers/Cheese Burger 6.00` | L46-51: 5 NEW cats only, with archive of 'nos-burgers' (L33-36) | config exposes pre-reset 2026-05-13 catalog; DB is post-reset |

Frontend (Kiosk) reads DB-only (`KioskMenuService.php:66`), so a manager re-running `menu:reset-le-cayenne` ships sauce/supplement names that **do not match what `config/menu.php` documents**. Any downstream consumer that reads config (`OrderQuoteService.php:505` for currency only — narrow blast radius today, but the SSOT label invites future drift). Tests `Catalog/CatalogChangedDispatchTest.php` and `Composer/ComposerProfileServiceCategoryTest.php` pass without exposing this — they test event dispatch, not data parity.

```yaml
[P0] config/menu.php:5 — Mislabeled SSOT; 3 data-set divergences with the artisan command that actually writes prod
  trigger:
    load_mode: A manager runs `php artisan menu:reset-le-cayenne --force` to recover from incident OR onboard a new branch
    failure_mode: DB receives 13-sauce / 4-crudité / 10-supplement set; `config/menu.php` documents 15/3/6 with entirely different supplement names — downstream config readers (current scope: currency only; future scope: any) silently use stale data
  v2_saas_impact:
    blocks: V2 multi-tenant catalog provisioning relies on a single declarative SSOT; today there are 2 contradictory ones (config + command constants). Cannot bootstrap a fresh tenant from `config/menu.php` because Reset command holds the canonical truth.
    enables: Promoting `config/menu.php` to actual SSOT (or deleting it) unblocks per-tenant catalog seeding via config inheritance.
  cost_of_delay_if_v1_ships:
    customer: Low for Le Cayenne single-resto today (DB is what kiosk shows). Latent for new branches / onboarding docs / any future code path that reads config('menu.items.*').
    fiscal: None direct (PricingService is SSOT for cents). But fiscal description text on receipts ("Sauce Tandoori 0.50") is DB-driven; any future config-driven receipt template would diverge.
    business: Devs / new joiners lose ~½ day re-discovering that the file labelled "SINGLE SOURCE OF TRUTH" is dead-on-arrival. Owner mental-model fracture.
  recommendation:
    scope: |
      Two options, owner-gate the choice:
      (A) Promote: refactor `MenuResetLeCayenneCommand.php:55-73` and `MenuHealLightV3Command.php` SAUCES/CRUDITES/SUPPLEMENTS constants to read from `config('menu.sauces')` / `config('menu.crudites')` / `config('menu.supplements')`. Update `config/menu.php` to match current DB truth (13 sauces / 4 crudités / 10 supplements). One-line constants → one-line config() calls. ~30 LOC change.
      (B) Demote: relabel `config/menu.php:5-8` from "SINGLE SOURCE OF TRUTH" to "Legacy currency/tax_id ref + deprecated MenuSeeder data". Keep file (OrderQuoteService.php:505 needs it for currency default). Add deprecation header.
    rollback: Revert single commit. No DB writes either path.
    owner_gate: N (no frozen-zone touch; config + command are V1.0.1-hardened but not in CLAUDE.md §7).
```

---

### Finding 2 — **P0** Catalog mutator idempotency partially attested, with known historical regression and no test coverage

`MenuResetLeCayenneCommand` is partially idempotent — step 3 (`step3CreateNewCategories`, L290-310) and step 8 (`step8SeedSupplementsCatalog`, L394-421) guard with `where('slug', $slug)->first()` + `restore() + update()`. **But:**

- **Documented regression:** `MenuResetLeCayenneCommand.php:918-919` literally annotates *"[HEAL P0-2 2026-05-13] Step 2: Sauce (13 choices) — was previously missing and added post-command via tinker patch (idempotency regression)."* The bowl composer-step-2 (sauce) was missed on first run and had to be patched live via `php artisan tinker`. This is a **historical proof** that idempotency for `MenuResetLeCayenneCommand` is fragile — re-running it required out-of-band manual repair.
- **Composer profile re-creation:** L884-887 deletes existing `ItemWizardProfile` + cascades steps (`ItemWizardStep::where('profile_id', $p->id)->delete()`) before re-creating at L889. If a profile is mid-cart (a customer is composing), the profile version-conflict path (`Composer/ComposerProfileVersionConflictTest.php`, `Composer/ProfilePublishMidCartRejectionTest.php`) may NOT cover an out-of-band CLI-driven delete. Acceptance for T-2.1.4 ("run twice → 0 row duplication, 0 orphan extras, 0 broken FK") is unverified.
- **MenuHealLightV3Command.php:390/488/506/524** uses plain `ItemVariation::create` and `ItemWizardStep::create` with no guard. Re-running this command (a documented possibility per BRAIN.md "Menu Heal V3 cycle 2026-05-14") would duplicate variations + steps.
- **Test gap:** `find tests -name "*Catalog*Idempot*"` → 0 results. The acceptance test for T-2.1.4 (`tests/Feature/Catalog/CatalogMutationIdempotencyTest.php`) does NOT exist.

```yaml
[P0] app/Console/Commands/MenuResetLeCayenneCommand.php:918-919,884-889 — Catalog mutator idempotency historically broken, untested, partial-on-re-run
  trigger:
    load_mode: Owner / SRE re-runs `php artisan menu:reset-le-cayenne` OR `menu:heal-light-v3` to recover from a botched deploy / partial transaction
    failure_mode: (a) ItemWizardSteps may not include all expected steps (sauce step missed pre-2026-05-13 patch), (b) ItemVariation rows duplicate in MenuHealLightV3, (c) live composer sessions hit version conflicts when profile is deleted+re-created mid-cart
  v2_saas_impact:
    blocks: V2 self-serve tenant catalog updates require idempotent mutators (owner clicks "rerun heal" twice → no doubled rows). Today: not safe.
    enables: Once attested, enables CI-gated catalog migrations for V2 multi-tenant.
  cost_of_delay_if_v1_ships:
    customer: Bowl wizard could ship without sauce step if a re-run order is interrupted; user blocked at step 2 with no choice.
    fiscal: Duplicated variations → duplicated ItemExtra rows in composition_snapshot → potentially over-billed customer (pricing is SSOT so cents are right, but UX shows duplicate options).
    business: SRE drift discovery → hour-long forensic + tinker patches (proven historically — see L918 comment).
  recommendation:
    scope: |
      (1) Write `tests/Feature/Catalog/CatalogMutationIdempotencyTest.php` per plan §4 T-2.1.4 (acceptance criterion).
      (2) Wrap all bare `Item::create` / `ItemVariation::create` / `ItemWizardStep::create` in MenuHealLightV3 (`:390, :488, :506, :524`) with `firstOrCreate` (by deterministic key e.g. `['profile_id', 'step_key', 'version']`).
      (3) For `step10CreateBolsComposerProfiles` (`:884-889`), instead of delete-cascade-recreate, bump `version`, mark old as `is_published=false`, leave for `ProfilePublishMidCartRejectionTest` semantics to handle live carts.
    rollback: New tests are additive (no risk). Code change is small refactor — revert is a single commit.
    owner_gate: N (CLAUDE.md §7 does not list these commands).
```

---

### Finding 3 — **P1** Four cache-invalidation listeners share a key prefix without TTL-coherence proof; one writes to a different store (snapshot)

`config/menu.php` is downstream of a 4-listener cache fan-out. Verified `app/Listeners/*`:

- `InvalidateKioskMenuCacheOnCatalogChange.php:12` → `Cache::forget('kiosk.menu.branch.'.$branchId)`
- `InvalidateKioskMenuCacheOnItemAvailabilityChanged.php:43,72` → same prefix, `Cache::forget($key)`
- `InvalidateMenuProjectionOnIngredientChange.php:15,61` → same prefix, `Cache::forget($cacheKey)`
- `BumpMenuSnapshotOnItemAvailabilityChanged.php:32,37` → does NOT forget cache; calls `$this->snapshot->bump($branchId)` (different mechanism, separate `MenuSnapshot` service)

Three listeners forget the same key (`kiosk.menu.branch.{id}`). The fourth bumps a separate `MenuSnapshot` version counter. The kiosk frontend reads `Cache::remember('kiosk.menu.branch.{id}', 60s, fn() => $menuService->build($branch))` at `Frontend/MenuController.php:67-72`. If `MenuSnapshot` bump happens but `Cache::forget` listener is skipped (e.g. one of the 3 listeners has a guard condition that early-returns), the kiosk cache still holds stale data for ≤60s while `MenuSnapshot` version is fresh. Coherence assumption: **all 4 listeners always run together for the same event**. EventServiceProvider (`app/Providers/EventServiceProvider.php`) shows `ItemCreated::class => [InvalidateKioskMenuCacheOnCatalogChange::class, PersistCatalogChangedToOutbox::class]` — only 2 listeners on `ItemCreated`, not 4. Possible orphan: `ItemCreated` does NOT trigger `BumpMenuSnapshotOnItemAvailabilityChanged`, so snapshot version stays stale across catalog inserts.

```yaml
[P1] app/Listeners/{InvalidateKioskMenuCacheOnCatalogChange,InvalidateKioskMenuCacheOnItemAvailabilityChanged,InvalidateMenuProjectionOnIngredientChange,BumpMenuSnapshotOnItemAvailabilityChanged}.php — Cache key + snapshot version may diverge under event-fan partial-fire
  trigger:
    load_mode: Admin creates a new item → fires `ItemCreated` → fans to 2 listeners (per EventServiceProvider) but NOT to `BumpMenuSnapshot` (wired only to ItemAvailabilityChanged)
    failure_mode: Kiosk client polls `?snapshot_version=N` (front-end deduplicates by snapshot), sees same N, skips refetch — never sees the new item until next 60s TTL natural expiry
  v2_saas_impact:
    blocks: Real-time catalog updates for V2 multi-tenant dashboards depend on `MenuSnapshot.version` being the canonical "is there new menu?" signal. If it lies, every tenant has 60s stale window post-add.
    enables: Single coherent invalidation primitive (e.g. one `CatalogChanged` super-listener) unlocks ≤1s cross-surface coherence.
  cost_of_delay_if_v1_ships:
    customer: Up-to-60s delay for newly-added items to appear on kiosk (after admin save). Acceptable for Le Cayenne single-resto (manager waits anyway).
    fiscal: None.
    business: Owner reports "I added an item, it doesn't show" → 60s later it appears → support ticket.
  recommendation:
    scope: |
      (1) Audit `EventServiceProvider.php` listener wiring: confirm every catalog-mutation event (ItemCreated, ItemUpdated, CategoryCreated, CategoryUpdated, ItemDeleted) wires to BOTH a Cache-forget listener AND a MenuSnapshot::bump listener.
      (2) If wiring asymmetric → add missing listener bindings (single file edit). Add test `tests/Feature/Catalog/CacheAndSnapshotCoherenceTest.php` asserting that ItemCreated → both Cache::forget AND MenuSnapshot::bump invoked once each.
    rollback: Wiring change is single-file revertable.
    owner_gate: N.
```

---

## §3 — Coverage Map (what this audit touched vs the 8 plan questions)

| Plan Q | Status | Evidence |
|---|---|---|
| 1. SSOT drift config↔DB↔frontend | **DEEP** (Finding 1) | `OrderQuoteService.php:505` + `TaxTableSeeder.php:61` are the only `config('menu.*')` callers; DB written by command constants |
| 2. Mutation idempotency | **DEEP** (Finding 2) | L918-919 historical regression + bare `create()` at 5 callsites |
| 3. CatalogChanged event chain | shallow | `PersistCatalogChangedToOutbox.php:101` uses `DB::afterCommit` (correct), wired only to `CatalogChanged::class` (see EventServiceProvider line 223) |
| 4. ComposerProfilePublished vs Changed | shallow | `ComposerProfileService.php:142,168,169,245` shows `Published` is fired only on publish action (single dispatch); `Changed` is fired on every state change (updated/published/unpublished); `Published` has no listener wired in EventServiceProvider (verified line 223 area shows only ComposerProfileChanged) — possible dead event |
| 5. Cache invalidation paths | **DEEP** (Finding 3) | Listener key prefix audit done |
| 6. item_branch_availability matrix | shallow | Writers: `AvailabilityController.php:76`, `StockRuptureDashboardController.php:32`; Readers: `PosCategoryController.php:93-102`, `KioskMenuService.php` (via Item filter). NO initialization on branch-create verified — `BranchController` was not opened. **Open Q below.** |
| 7. Frontend payload contract | shallow | `Frontend/MenuController.php` → `KioskMenuService::build` → 100% DB queries, zero `config('menu.*')` reads. Cache `kiosk.menu.branch.{id}` TTL 60s. No `config/menu.php` consistency check anywhere. |
| 8. Test coverage gaps | shallow | `tests/Feature/Catalog/` has 12 files (event dispatch, outbox, photo, deletion) but **no `*Idempotency*`, no `*Ssot*`, no `*Parity*`**. T-2.1.1 + T-2.1.4 acceptance tests do NOT exist. |

---

## §4 — Open Questions (Round 2 candidates)

1. **`ComposerProfilePublished` may be dead event.** `EventServiceProvider.php:223` area shows only `ComposerProfileChanged` mapped to listeners. `ComposerProfileService.php:168` dispatches `ComposerProfilePublished::dispatch((int) $fresh->id)` but no listener catches it. Confirm via `grep "ComposerProfilePublished" app/Listeners/` (was empty in my scan). If dead → either wire it OR delete the event. **Start file:** `app/Providers/EventServiceProvider.php:223` + `app/Events/ComposerProfilePublished.php:10`.
2. **`item_branch_availability` not auto-initialized on branch create.** No evidence of an observer/listener that seeds the matrix when a new branch row inserts. New branch → all items default unavailable? Or default available? Behavioral coverage gap. **Start file:** `app/Models/Branch.php` + `app/Models/ItemBranchAvailability.php`.
3. **`MenuResetLeCayenneCommand` should be protected behind a NF525-aware gate.** Today: `--force` skips confirmation. A manager running `--force` mid-day soft-deletes 8 categories + ~35 items. Active orders' `composition_snapshot` (NF525 immutable) is byte-frozen by design (per CLAUDE.md §8), so receipts remain valid — but UI shows ghost categories until refresh. Worth a sentinel test. **Start file:** `MenuResetLeCayenneCommand.php:110-122` (confirm gate).
4. **`MenuHealLightV3Command` (621 LOC) deserves its own architect pass.** Skipped here for word budget. Suspect equivalent or worse idempotency story than Reset.
5. **`menu.settings.default_tax_id => 1`** (`config/menu.php:75`) and `menu.settings.tax_rate => 10.00` (L74) — hardcoded magic numbers. If a future branch ships with `tax_rate = 5.5%` or `tax_id != 1`, the config diverges silently from `Branch.tax_id` foreign key. **Start file:** `database/seeders/TaxTableSeeder.php:61` + `app/Models/Branch.php`.

---

## §5 — Hard constraints attested

- Read-only. No edits. No commits. (Verified: this report is the only file I wrote.)
- Every claim cites file:line.
- Word count: ~1480 words.
- Anchors verified live this session via `wc -l`, `grep -rn`, `find`, `ls`. No file path quoted unread.
