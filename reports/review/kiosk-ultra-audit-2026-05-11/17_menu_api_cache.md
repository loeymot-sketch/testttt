# K17 — Menu API + Cache + Locale

**HEAD** : `6a33a9763b7ef8da9ffb350732b1cdff1fab2261` (branche `feature/mobile-app-le-cayenne-2026-05-10`).
**Mode** : READ-ONLY, citations primaires lues, pas d'invention.

## Files audited

- `app/Http/Controllers/Frontend/MenuController.php` — 89 LOC
- `app/Services/Kiosk/KioskMenuService.php` — 507 LOC
- `app/Listeners/InvalidateKioskMenuCacheOnItemAvailabilityChanged.php` — 79 LOC
- `app/Listeners/InvalidateKioskMenuCacheOnCatalogChange.php` — 62 LOC
- `app/Http/Middleware/ValidateKioskLocale.php` — 112 LOC
- `resources/js/helpers/kioskMenuCache.js` — 101 LOC (IndexedDB snapshot)
- `resources/js/store/modules/kioskMenu.js` — 367 LOC
- `tests/js/kioskMenuStore.spec.js` — 94 LOC
- `routes/api.php:1229-1232` — route `frontend.menu.kiosk`
- `app/Providers/EventServiceProvider.php:169-222` — listener wiring
- `app/Providers/RouteServiceProvider.php:73-75` — throttle bucket `kiosk-menu`
- `app/Models/KioskMachine.php`, `app/Models/Scopes/BranchScope.php`, `app/Models/Branch.php:45-53` (cross-ref)

## Findings

### P0 (blocker pre-merge V1)
None.

Branch isolation chain verified end-to-end:
- Route stack `auth:sanctum + kiosk.locale + throttle:kiosk-menu` (`routes/api.php:1230-1232`).
- `MenuController::kiosk` guards `tokenCan('kiosk:order')` then resolves the **KioskMachine via `where('user_id', $user->id)`** (`MenuController.php:44-46`). Because `KioskMachine` carries `BranchScope` (`KioskMachine.php:38`) and the kiosk-user's `User.branch_id` mirrors the machine row (seed/`EnsureKioskMachineCommand:94-103`), an attacker swapping `kiosk_machine.user_id` rows across branches would be filtered out by the scope (defense-in-depth even though `user_id` is the discriminant).
- `branchId` is **read from `KioskMachine.branch_id` server-side, never from payload** (`MenuController.php:56`). Cache key embeds it (`MenuController.php:67`).
- `KioskMenuService` queries: `Item` / `ItemCategory` are global catalog filtered by `channels=kiosk` + `status=ACTIVE` only (no leak — same payload for all branches of a same tenant by design); per-branch *availability* and *pricing-side* data are filtered explicitly: `ItemBranchAvailability::where('branch_id', $branchId)` (`KioskMenuService.php:94`), `UpsellRule::activeForBranch($branchId)` (`KioskMenuService.php:105`), `KioskPromo::where('branch_id', $branchId)` (`KioskMenuService.php:139`), `ItemWizardProfile` scoped to `branch_id_scope=null OR =branchId` (`KioskMenuService.php:419-422`), `ChoiceAvailabilityResolver::snapshotForItems($items, $branchId, 'kiosk')` (`KioskMenuService.php:283`).

### P1 (high — V1.0.1 sprint)

- **K17-P1-01: Catalog-change listener iterates branches synchronously inside the admin save request.**
  - File: `app/Listeners/InvalidateKioskMenuCacheOnCatalogChange.php:30-40`
  - Issue: No `ShouldQueue` ; `Branch::query()->pluck('id')->each(...)` runs inline on `ItemCreated`/`ItemDeleted`/`CategoryCreated`/`CategoryUpdated`/`CategoryDeleted`/`ComposerProfileChanged`/`StockLevelChanged`. Author wrote `SYNC_WARNING_THRESHOLD = 100` (line 13) and a `Log::warning` (line 32) — acknowledging the issue without fixing it. Same pattern in `InvalidateKioskMenuCacheOnItemAvailabilityChanged.php:56-58` whenever admin pushes a global flip (price/name/variations edit dispatches the branchless `ItemAvailabilityChanged` — confirmed `app/Services/ItemService.php:336-341`).
  - Evidence: admin price edit → `ItemService::update` dispatches branchless `ItemAvailabilityChanged` → listener loops every active branch synchronously → P95 admin latency grows linearly with branch count + each `Cache::forget` round-trip on Redis.
  - Suggested fix: implement `ShouldQueue` on both listeners ; bulk-delete via `Cache::deleteMultiple(array_map(fn($id) => 'kiosk.menu.branch.'.$id, $branchIds))` ; bound by `Branch::active()` scope (currently fetches all incl. soft-deleted — `Branch.php` uses `SoftDeletes` but query lacks scope).

- **K17-P1-02: Catalog listener bumps snapshot version per branch but doesn't reset cache key when bump fails.**
  - File: `app/Listeners/InvalidateKioskMenuCacheOnCatalogChange.php:51-53`
  - Issue: `Cache::forget($cacheKey)` and `$this->snapshot->bump($branchId)` are not atomic. If the Redis cache forget succeeds but the snapshot bump fails (network blip, queue store mismatch), kiosks reload immediately and observe a payload with the OLD `snapshot_version` from `MenuSnapshot::current()` — long-poll clients that compare versions will not detect change. The `try/catch` (line 41) swallows the exception silently.
  - Suggested fix: bump snapshot BEFORE forget (so a stale read still wins on version comparison) ; log the partial-failure case with `level=error` not `warning`.

- **K17-P1-03: Frontend store falls back to broken legacy endpoints on first 401/4xx, masking auth issues.**
  - File: `resources/js/store/modules/kioskMenu.js:270-301`
  - Issue: The kiosk store calls `axios.get('frontend/menu')` ; on ANY error (404, 500, network) the outer `try` swallows it (line 290 `catch (_)`) and falls back to two legacy endpoints `frontend/item-category` + `frontend/item` (lines 297-301) which do NOT include `item_branch_availability` and do NOT carry kiosk channel filters server-side. After K-9 deployment, real 403 (locale rejected) and 503 (`BRANCH_NOT_FOUND`) get silently downgraded to a degraded menu that bypasses kiosk branch isolation.
  - Evidence: `MenuController.php:38-64` returns 403/503 — the JS swallows them blindly.
  - Suggested fix: scope the swallow to network errors only (axios `err.code === 'ERR_NETWORK'` or `!err.response`) ; on HTTP errors propagate or at minimum log to observability ; remove or feature-flag the legacy fallback before V1.

- **K17-P1-04: `MenuSnapshot::current()` called inside cached payload — version freezes for TTL window.**
  - File: `app/Services/Kiosk/KioskMenuService.php:117`
  - Issue: `'snapshot_version' => $this->snapshot()->current($branchId)` is computed inside `Cache::remember(..., $ttl, fn() => build())`. If an `ItemAvailabilityChanged::forBranch` event fires while a cache entry is hot, the listener bumps the snapshot but DOES `Cache::forget` (`InvalidateKioskMenuCacheOnItemAvailabilityChanged.php:72`) — so this is mitigated. **However** for branch-scoped *cache* invalidation triggered by events NOT in the wiring map (e.g. an `Item->is_chef_pick` flag toggle that doesn't dispatch any event), the version inside the cached payload stays stale until natural TTL expiry. No event/listener covers admin updates of `is_chef_pick`, `is_new`, `is_spicy`, `is_halal`, `kiosk_emoji` columns.
  - Suggested fix: either (a) ensure every admin write that mutates a column projected to the kiosk payload dispatches a catalog event, or (b) split `snapshot_version` out of the cached payload so the API response always carries the live version even on a cache hit.

### P2 (medium — backlog)

- **K17-P2-01: Thundering herd on first request post-invalidation.**
  - File: `app/Http/Controllers/Frontend/MenuController.php:70-72`
  - Issue: `Cache::remember` with no lock. After a forget event, N concurrent kiosk requests each execute `KioskMenuService::build()` in full (Item eager-load with 5 relations + `ChoiceAvailabilityResolver::snapshotForItems` + `ComposerProfileProjection::project` × items). On `Le Cayenne` with 1 branch / few bornes this is fine, but P95 will spike under franchise scale.
  - Suggested fix: switch to `Cache::lock("kiosk.menu.branch.{$branchId}.lock", 10)->block(2, fn() => Cache::remember(...))` (Laravel atomic lock) or `Cache::flexible()` with stale-while-revalidate.

- **K17-P2-02: Race window between `Cache::forget` and in-flight `Cache::remember` closure can re-seal stale data.**
  - File: `app/Http/Controllers/Frontend/MenuController.php:70-72` / `app/Listeners/InvalidateKioskMenuCacheOnItemAvailabilityChanged.php:72`
  - Issue: Laravel-documented behavior of `remember`: if request R1 is mid-`build()` when event E fires `Cache::forget`, R1's `set()` after E re-seals the pre-event payload for the full TTL. Mitigated by TTL=60s.
  - Suggested fix: same as P2-01 (atomic lock around the build closure) — also closes this race.

- **K17-P2-03: `kioskMenuCache.js` snapshot freshness window (24h) doesn't surface allergen-staleness risk.**
  - File: `resources/js/helpers/kioskMenuCache.js:20,75-78`
  - Issue: `DEFAULT_MAX_AGE = 24h` is used to display cached menu offline. Allergens (EU FIC 1169/2011) can change daily in real kitchens ; a 24h-old snapshot displaying stale allergen info to a customer is a legal exposure. The `isSnapshotStale` 4h helper exists (lines 86-89) but isn't called anywhere in `kioskMenu.js`.
  - Suggested fix: drop offline allowance to 4h max for allergen-bearing items, OR mark cached menu with a visible "Mode hors-ligne — données < 4h" banner in `KioskAppComponent`.

- **K17-P2-04: 60s TTL hardcoded path doesn't fail open on cache store outage.**
  - File: `app/Http/Controllers/Frontend/MenuController.php:70-77`
  - Issue: If Redis is down, `Cache::remember` throws ; the `catch (Exception)` returns 500 (line 78-87). The kiosk has no menu — full outage. Owner should consider a `try/catch` per layer that falls back to `$this->menuService->build($branch)` direct when the cache store throws.

- **K17-P2-05: `KioskPromo::valid_to` comparison uses `>= $now` but DB column likely `DATETIME` ; off-by-1s at expiry boundary.**
  - File: `app/Services/Kiosk/KioskMenuService.php:144-146`
  - Suggested fix: use `>= $now` for `valid_from` and `> $now` for `valid_to`, or document expiry inclusivity.

- **K17-P2-06: ValidateKioskLocale passthrough on missing KioskMachine masks a real config issue.**
  - File: `app/Http/Middleware/ValidateKioskLocale.php:68-70`
  - Issue: if `$user` exists but no `KioskMachine`, the middleware passes through silently — the upstream auth (`kiosk:order` ability check) catches it later, but locale validation is bypassed. Acceptable but warrants an observability log to detect orphan tokens.

### P3 (low — nice-to-have)

- **K17-P3-01: Task-framing mismatch — middleware does NOT enforce "FR-lock V1".**
  - File: `app/Http/Middleware/ValidateKioskLocale.php:72-73`
  - Note: middleware validates requested locale ∈ `Branch.available_locales` ; FR-lock is enforced by branch seed (`config('kiosk.default_locale', 'fr')` + branch.available_locales=['fr']) and `KIOSK_DEFAULT_LOCALE=fr`. No code defect — flagging for spec-vs-impl alignment in the consolidated audit.

- **K17-P3-02: `MenuController` no `Accept-Language` honoring.**
  - File: `app/Http/Controllers/Frontend/MenuController.php:34-88`
  - Issue: `kiosk.locale` middleware validates locale but `MenuController` doesn't `App::setLocale($locale)` for the response. Strings inside the payload (`displayNameFor('kiosk')`, allergens `name_key`) might return wrong i18n if the branch supports multi-locale. V1 FR-lock makes this moot ; flag for V2.

- **K17-P3-03: `KioskMenuService::loadActivePromos` swallows ALL Throwable to `collect()` (line 148-151).**
  - Issue: hides real DB connectivity errors as "promo table missing" — should narrow exception to `QueryException` with `SQLSTATE 42S02` (table not found).

## Existing E2E coverage

- `tests/Feature/KioskPhase1/InvalidateKioskMenuCacheListenerTest.php` — branch-scoped and global event invalidation ✓
- `tests/Feature/Routes/MenuControllerRateLimitTest.php` — throttle:kiosk-menu bucket ✓
- `tests/js/kioskMenuStore.spec.js` — `UPDATE_ITEM` mutation merge-non-destructive ✓
- **Gap**: No test for cross-branch leak via `MenuController::kiosk` (only listener unit).
- **Gap**: No test for `kiosk.locale` middleware passthrough behavior on orphan KioskMachine.
- **Gap**: No test for stale-snapshot serving when `KioskMenuService::build()` throws.

## Proposed new E2E tests

- **T-K17-01: Cross-branch isolation feature test.**
  - Steps: seed Branch A (items 1,2,3 — item 2 `branch_availability.is_available=false`) and Branch B (items 4,5 — item 5 unavailable). KioskMachine_A → user_A; KioskMachine_B → user_B. Hit `GET /api/frontend/menu` with each token.
  - Assertions: response A contains items 1,2,3 with `2.is_available=false` ; response B contains items 4,5 with `5.is_available=false` ; no item leakage across branches.

- **T-K17-02: Cache invalidation latency under concurrent admin flip + kiosk fetch.**
  - Steps: warm cache for branch A (GET menu). Concurrently dispatch `ItemAvailabilityChanged::forBranch(item_id=2, branch_id=A, is_available=false)` and refetch menu immediately.
  - Assertions: second response shows `2.is_available=false` within 100ms (no waiting on 60s TTL).

- **T-K17-03: Locale middleware blocks off-allowlist and logs observability.**
  - Steps: Branch A `available_locales=['fr']`. Send `GET /api/frontend/menu` with `X-Kiosk-Locale: en`.
  - Assertions: HTTP 400, `code=LOCALE_NOT_ALLOWED_FOR_BRANCH`, observability log line `kiosk_locale.not_allowed` emitted with `branch_id=A`.

- **T-K17-04: Frontend store falls back to snapshot on network error but NOT on HTTP 4xx/5xx.**
  - Steps: mock axios to reject with `ERR_NETWORK` → expect snapshot load. Then mock with HTTP 503 → expect store to surface error, not load snapshot.
  - Assertions: this captures K17-P1-03 regression.

- **T-K17-05: Catalog listener queues invalidation when ≥10 branches present.**
  - Steps: seed 15 branches, prime each cache key, dispatch `CategoryUpdated`. Wait for queue worker.
  - Assertions: listener implements `ShouldQueue`, all 15 keys forgotten, admin request returns <300ms (no synchronous loop).

## Risks & open questions

- **Owner gate** : K17-P1-01 (queue migration) requires Horizon/queue topology check — defer to SRE before implementation.
- **Owner gate** : K17-P2-03 (allergen snapshot TTL drop to 4h) is a UX trade-off (offline kiosk loses menu sooner) — needs owner decision.
- **Spec drift** : K17-P3-01 — current `ValidateKioskLocale` design (allowlist per branch) is BETTER than `config('kiosk.default_locale')` FR-lock, but the task framing and the audit plan describe a stricter model. Recommend updating the spec doc rather than the code.
- **Out of K17 scope but flagged**: `Branch::factory()` in `InvalidateKioskMenuCacheListenerTest` doesn't soft-delete simulate ; combined with K17-P1-01's `Branch::query()->pluck('id')` (no active scope), there's a potential perf cliff after years of soft-deleted branches accumulate.
