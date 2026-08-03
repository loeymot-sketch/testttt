# Independent Verification — Other Session's 18-Agent Audit (2026-05-21)

**Verifier** : independent Claude session (READ-ONLY).
**Branch** : `heal/cms-pr1-quickwins-2026-05-18` HEAD `190458edd` (per BRAIN).
**Source under review** : `reports/audit/goal-pre-cloud-2026-05-21/MASTER_SYNTHESIS_REPORT.md`, `plans/GOAL_PRE_CLOUD_PRODUCTION_AUDIT_2026-05-21.md`.

Method : every claim was checked against (a) actual file text at quoted line numbers, (b) the surrounding logic, (c) for sentinels — by running the test, (d) for bundles — by mtime comparison.

---

## Claim 1 — P0-IDEMP-01 — `array`/`null` guard does NOT cover `file`/`database`

**Status** : **CONFIRMED (text)** / **SEVERITY OVERSTATED** for V1 LOCAL.

**Evidence** — `app/Providers/AppServiceProvider.php:214-222` :
```php
$cacheDriver = config('cache.default');
$forbiddenCacheDrivers = ['array', 'null'];
if (in_array($cacheDriver, $forbiddenCacheDrivers, true)) {
    throw new \RuntimeException(
        "CACHE_DRIVER='{$cacheDriver}' is forbidden in production: NF525 audit chain integrity "
        . 'requires a shared cache driver (redis or memcached) for cross-worker locks. '
        . 'Set CACHE_DRIVER=redis (recommended) or CACHE_DRIVER=memcached in your .env file.'
    );
}
```

The guard literally tests against `['array', 'null']`. `file` and `database` drivers PASS this guard. The block comment (lines 206-212) explicitly names `redis or memcached` as the only acceptable drivers — the guard implementation is narrower than its stated intent. Drift between docblock and code is real.

**Severity reassessment** :
- V1 LOCAL Le Cayenne = single Apache/PHP-FPM box → `file` driver works (single filesystem, all workers share OS-level flock on the same file). NOT an ALB-double-charge risk.
- Cloud multi-instance ALB = real risk because `file` lock = per-host filesystem (no cross-instance coherence) and `database` = correct only if MySQL row-lock semantics are enforced (Laravel `database` cache backs the lock on `cache_locks` table — actually safe with InnoDB, BUT idempotency uses `Cache::lock()` semantics that DO map to atomic INSERT on `cache_locks` → safe under contention).
- Real classification : **P2 cloud-prep tightening**, NOT P0 V1 ship blocker. The other session labels it P0-IDEMP-01 — overstated.

**Recommendation** : defer to cloud cutover prep. Heal ≤5 LOC when owner initiates cloud (add `'file'` to forbidden list, document `'database'` as acceptable with explicit comment about `cache_locks` table requirement).

---

## Claim 2 — P0-SENTINEL-02 — `AllergenCoverageSentinelTest` 2 errors

**Status** : **CONFIRMED EXACT**.

**Evidence** — run output :
```
PHPUnit 9.6.29
FF..                                                                4 / 4 (100%)
There were 2 failures:

1) Tests\Feature\Sentinels\AllergenCoverageSentinelTest::coverage_meets_eu_1169_minimum_threshold
EU 1169/2011 FIC coverage below threshold: 0.0% (0/24 items with allergen_flags).
.../AllergenCoverageSentinelTest.php:165

2) Tests\Feature\Sentinels\AllergenCoverageSentinelTest::required_allergens_are_set_per_signature_item
Item 'sandwich-cayenne-classique' MUST declare allergen 'gluten' in allergen_flags JSON cache (EU FIC).
.../AllergenCoverageSentinelTest.php:191

FAILURES! Tests: 4, Assertions: 53, Failures: 2.
```

Root cause = `LeCayenneAllergenSeeder` was retracted to NOOP on Wave Q-4 (2026-05-20, BRAIN line 50, commit `c28f7a452`). The sentinel still seeds the items but the allergen mapping is empty. Tests assume non-empty allergen_flags.

**Severity** : **CONFIRMED P0 for CI integrity**. Any developer running the suite hits 2 failures. This contradicts the BRAIN narrative that "tests pass". CI red.

**Recommendation** : heal NOW. Either (a) actually exclude `manual` group via `phpunit.xml` (see Claim 3), or (b) gate setUp on a feature flag, or (c) skip the 2 failing methods with `markTestSkipped` until chef data. Smallest heal = Claim 3 fix.

---

## Claim 3 — P0-SENTINEL-03 — `@group manual` annotation NON-FUNCTIONAL

**Status** : **CONFIRMED**.

**Evidence** — `phpunit.xml` (full file, 72 lines) :
- No `<groups>` element
- No `<exclude>` element
- Only `<testsuites>`, `<coverage>`, `<php>` env block

The `@group manual` PHPDoc annotation on `AllergenCoverageSentinelTest` methods has ZERO effect unless `phpunit.xml` declares `<groups><exclude><group>manual</group></exclude></groups>` OR the CLI passes `--exclude-group=manual`. Neither is configured.

The actual Wave Q-4 retraction text in BRAIN line 50 says : *"`AllergenCoverageSentinelTest` 4 methods → `@group manual` (CI no longer enforces 80% coverage on fabricated data)"*. This is **false** as shipped — the annotation is decorative, the CI gate is still active. Wave Q-4 thought it solved the problem but the solution was incomplete.

**Severity** : **P0 (contradiction with retraction)**. Fixes Claim 2.

**Recommendation** : add to `phpunit.xml` between lines 23 and 24 :
```xml
<groups>
    <exclude>
        <group>manual</group>
    </exclude>
</groups>
```
3 LOC heal. This will make `@group manual` annotations effective.

---

## Claim 4 — P1-SYNC-01 — silent-loss vector via stranded crash-claimed rows ≥ 5

**Status** : **CONFIRMED with refinement**.

**Evidence** — chain of code :

1. `OutboxRescueCommand.php:47` filters `->where('attempts', '<', 5)` for BOTH the pending lane AND the crash-claimed lane (`dispatched_at IS NOT NULL AND > 10min stale`).

2. `OutboxRetryFailedCommand.php:102-104` calls `->failed(5)` which is `DomainEvent.php:45-49` = `pending()->where('attempts', '>=', 5)`. `pending()` = `whereNull('dispatched_at')`. So crash-claimed rows (dispatched_at != null) are EXCLUDED from retry-failed.

3. `MonitorOutboxStaleness.php:45-46` filters `whereNull('dispatched_at')` — does NOT alert on crash-claimed rows.

4. `PruneOutboxCommand.php:52-57` condition (A) deletes any row where `dispatched_at IS NOT NULL AND dispatched_at < 90d cutoff`. So a crash-claimed-at-attempts≥5 row eventually gets **silently pruned** after 90 days, never broadcast.

5. `DispatchDomainEventsJob.php:65-86` Phase 1 sets `dispatched_at = now()` AND `attempts = attempts + 1` atomically under `lockForUpdate`. A KILL-9 between this commit and Phase 3a (success) or Phase 3b (release on throw, line 161-166) leaves the row in claimed state.

The specific KILL-9 window only matters AT attempts=5 (or beyond), because Rescue handles 0-4 and Retry-failed handles 5+ pending. Combined coverage gap = `attempts >= 5 AND dispatched_at NOT NULL`.

**Severity** : real but narrow.
- For attempts=5 to be reached AND a crash happens during Phase 1-3b on that attempt → requires (a) 5 prior failures already (so `$tries=6` is one attempt from giving up), (b) a SIGKILL inside Phase 1+broadcast+Phase 3 window (typically < 1s in steady state, longer if Pusher/Soketi hangs).
- Probability is low but non-zero — chronic-failure rows are exactly where SIGKILL is more likely (OOM, deploy restart).
- **P1 cloud-prep**, accurate as stated.

**Recommendation** : 1-LOC heal in `OutboxRescueCommand.php:47` :
```php
->where('attempts', '<', 12)   // widen rescue lane to REPLAY_MAX_ATTEMPTS - 1
```
This lets the crash-recovery lane cover attempts 5-11 (matching `OutboxRetryFailedCommand::REPLAY_MAX_ATTEMPTS = 12`). Crash-claimed rows then get re-queued, prune lane only sees truly-terminal rows after 90d.

---

## Claim 5 — Anti-fiction W3.2 — `PosShortcutOrderController` does NOT exist

**Status** : **CONFIRMED**.

**Evidence** :
- `find app/Http/Controllers -name "*ShortcutOrder*"` → 0 results.
- `grep -rn "PosShortcutOrderController" routes/` → 0 hits.
- Routes referencing POS order operations all use `PosOrderController` (`routes/api.php:43, 897-900`) and `PosController` (`routes/api.php:774, 845`).

The cartography brief in the other session referred to a controller that does not exist. The actual shortcut endpoints are sliced across `PosController::store` + inline closures in `routes/api.php` (lines 790-845, named routes `counter-collect.pending`, `counter-collect.confirm`, `counter-collect.cancel`, `collect-kiosk-cash`).

**Severity** : N/A — this is a cartography honesty marker, not a code defect. Useful as ground-truth signal that the other session's W3 cartography was hallucinated in at least one place.

**Recommendation** : trust the verified mapping over the cartography brief. No code action.

---

## Claim 6 — W3.7 — LOCK doc DRAFT but Option B shipped

**Status** : **CONFIRMED**.

**Evidence** :
- `plans/LOCK_POS_LOYALTY_REDEEM_UI_2026-05-18.md:3` : `**Status** : DRAFT — pending owner countersign`
- Yet the following ship-evidence files all exist :
  - `app/Http/Controllers/Admin/PosLoyaltyController.php` (with docblock `[LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19]`)
  - `app/Http/Requests/PosLoyaltyRedeemRequest.php` (same tag)
  - `routes/api.php:922-926` : `Route::post('/{order}/redeem-loyalty', [PosLoyaltyController::class, 'redeem'])` (commented `[LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19]`)
  - `routes/api.php:1326` : `[LCS-S-002 / 2026-05-19] Idempotency middleware on loyalty redeem`

Option B (separate Vue modal + service + controller + route + permission, lines 43-65 of LOCK doc) is **shipped under the LOCK tag** but the doc status was never flipped to APPLIED/SHIPPED.

**Severity** : process-hygiene drift, not a code defect. P3. Owner trust signal — the actual implementation matches the recommended option, just the doc lags.

**Recommendation** : flip status to "APPLIED 2026-05-19" with a 1-line edit. No code change.

---

## Claim 7 — Wave Q-4 retraction contradicted

**Status** : **CONFIRMED (consequence of Claim 3)**.

**Evidence** — BRAIN line 50 verbatim :
> *"(2) `AllergenCoverageSentinelTest` 4 methods → `@group manual` (CI no longer enforces 80% coverage on fabricated data, sentinel preserved as production-ship gate documentation)."*

But Claim 3 proves `phpunit.xml` does NOT exclude `manual` group. So "CI no longer enforces" is FALSE. The retraction narrative is misleading — the intent was right but the implementation was incomplete (annotation added without the corresponding `phpunit.xml` exclusion).

**Honest framing for the BRAIN** : "Wave Q-4 added `@group manual` annotation but DID NOT add `phpunit.xml` exclusion → annotation was decorative; CI gate still active → 2 failures latent until Claim 3 heal."

**Severity** : tied to Claim 3. Healing Claim 3 resolves this contradiction.

---

## Claim 8 — 3 surface fixes already applied — no-regression check

### 8a — `PosComponent.vue:1126` close button `type="button"` + `aria-label`

**Status** : **CONFIRMED**.

**Evidence** — `resources/js/components/admin/pos/PosComponent.vue:1126` :
```vue
<button class="kiosk-cash-panel-close" type="button" :aria-label="$t('button.close')" @click="showKioskCashPanel = false">✕</button>
```
Both attributes present. Frozen-zone check : PosComponent.vue is **NOT** in CLAUDE.md §7 frozen-files (only `PaymentComponent.vue` and `PosV5TrancheRow.vue` are listed under POS). Safe.

### 8b — `delivery_cash_*` keys in `lang/en/all.php` + `lang/fr/all.php`

**Status** : **CONFIRMED** (4 keys × 2 files, exact match) :

`lang/en/all.php:173-176` :
```
'delivery_cash_sessions' => 'Delivery boy cash sessions',
'delivery_cash_status_open' => 'Open',
'delivery_cash_status_closed' => 'Closed',
'delivery_cash_status_reconciled' => 'Reconciled',
```

`lang/fr/all.php:168-171` :
```
'delivery_cash_sessions' => 'Caisses livreur',
'delivery_cash_status_open' => 'Ouverte',
'delivery_cash_status_closed' => 'Clôturée',
'delivery_cash_status_reconciled' => 'Réconciliée',
```

Frozen-zone check : `lang/` is not listed in CLAUDE.md §7. Safe. AR / other locales not added — confirm scope.

### 8c — `KdsHistoryDrawer.vue` Escape key handler + bundle rebuild

**Status** : **PARTIAL — source applied, bundle NOT yet rebuilt**.

**Evidence — source (CORRECT)** : `resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue:188-207` contains the `_onEsc` handler with `addEventListener('keydown')` in `mounted()` and teardown in `beforeUnmount()`. Annotated `[W2 audit 2026-05-21] a11y: WAI-ARIA dialog pattern (WCAG 2.1.2)`. Clean.

**Evidence — bundle (STALE)** :
- Source mtime : `2026-05-21 05:29`
- Bundle `public/js/admin-kds.js` mtime : `2026-05-21 03:37` (BEFORE source change)
- `grep -c "_onEsc" public/js/admin-kds.js` → 0
- `grep "addEventListener.*Escape" public/js/admin-kds.js` → 0 matches

The fix is in source but the production bundle was last compiled BEFORE the source edit. End-users on the deployed `public/js/admin-kds.js` do NOT have the Escape handler. The other session's claim of "+19 LOC, bundle rebuild requis" is honest about needing the rebuild — but the rebuild has not happened.

**Severity** : if shipped as-is, the WCAG fix is invisible to users. Must run `npm run build` (or `npx mix`) and commit the bundle delta before claiming this fix.

**Recommendation** : `npm run build` (or `npx mix --production`), commit `public/js/admin-kds.js` delta. Verify `grep "_onEsc\|key === 'Escape'" public/js/admin-kds.js` returns a hit before declaring 8c shipped.

---

## NEW issue found while verifying (other session missed it)

**KDS bundle staleness pattern** is broader than 8c.

Branch `git status` shows `M public/js/admin-kds.js` (staged) plus the source `KdsHistoryDrawer.vue` is dirty (modified today at 05:29 — 2 hours AFTER the bundle was rebuilt at 03:37). This means at the very least the Escape handler is in source but not in bundle. Other recent KDS source edits may also be unbuilt — worth a sweep.

Prior commits `f0060a138` ("rebuild stale KDS bundle") show this pattern is recurring. Recommendation : add a CI check that `public/js/admin-kds.js` mtime ≥ max(mtime of `resources/js/**/kitchenDisplaySystem/**`) — fails the build on stale bundle.

---

## Per-claim status summary table

| # | Claim | Status | Real severity | Action |
|---|-------|--------|---------------|--------|
| 1 | IDEMP guard misses file/database | CONFIRMED text / **OVERSTATED severity** | P2 cloud-only | Defer to cloud prep |
| 2 | Sentinel 2 errors | CONFIRMED exact | P0 CI integrity | Heal via Claim 3 |
| 3 | `@group manual` non-functional | CONFIRMED | P0 (gates Claim 2 + Claim 7) | Heal now (3-LOC `phpunit.xml`) |
| 4 | Silent-loss crash-claimed ≥5 | CONFIRMED | P1 real but narrow | 1-LOC heal `OutboxRescueCommand:47` |
| 5 | `PosShortcutOrderController` not real | CONFIRMED | N/A — honesty marker | None (cartography fix) |
| 6 | LOCK doc DRAFT + shipped | CONFIRMED | P3 process | 1-line status flip |
| 7 | Wave Q-4 retraction misleading | CONFIRMED | P1 honesty | Heal via Claim 3 + BRAIN edit |
| 8a | PosComponent type="button"+aria | CONFIRMED clean | N/A | None |
| 8b | delivery_cash keys | CONFIRMED clean | N/A | (AR locale TODO?) |
| 8c | KDS Escape source vs bundle | **PARTIAL — bundle stale** | P1 ship-blocker for 8c | `npm run build` + commit |

---

## Final verdict on the other session's audit quality

**Trustworthiness** : **PARTIAL-TO-GOOD**.

Strong points :
- Claims 2, 3, 4, 5, 6, 8a, 8b verified accurate to the line.
- Self-caught the W3.2 cartography hallucination (honesty marker).
- Wave Q-4 contradiction caught — non-trivial, requires correlating BRAIN + code + config.

Weak points :
- Claim 1 (IDEMP) labeled P0 — actually P2 cloud-only. Severity inflation.
- Claim 8c marked "applied" — only source applied, bundle stale. End-user-facing fix is invisible until rebuild. The "(bundle rebuild requis)" note is honest but the rebuild was not done before claiming the fix.
- No regression sweep done after the 3 fixes (e.g., did PosComponent.vue still pass Vitest? Did lang keys avoid `__` namespace collision? Not checked).

**Net** : the audit is mostly honest and the findings are real. 1 severity inflation, 1 incomplete fix. The drift between BRAIN's "Wave Q-4 retracted, CI clean" narrative and the actual CI state is a meaningful catch that justifies the audit existing.

**Owner-facing recommendation for the next decision** : trust Claims 2/3/4/5/6/8a/8b. Reclassify Claim 1 as P2 cloud-prep. Treat Claim 8c as **NOT YET SHIPPED** — require bundle rebuild before counting it as a fix.
