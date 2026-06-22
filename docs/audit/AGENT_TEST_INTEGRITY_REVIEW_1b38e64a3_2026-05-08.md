# Agent TEST-INTEGRITY — Audit batch ULTRA `1b38e64a3`

- **Date** : 2026-05-08
- **Auditeur** : Agent TEST-INTEGRITY (rôle GSTACK QA hostile)
- **Périmètre** : 13 fichiers de tests ajoutés ou modifiés dans `1b38e64a3`
- **Méthodologie** : lecture exhaustive + Trust-but-Verify (run PHPUnit + Vitest), comparaison source ↔ assertion, recherche de tautologies / skips silencieux / mocks trompeurs
- **Verdict global** : **GO conditionnel HEAL** — la suite est globalement plus saine que la critique BYPASS précédente, MAIS un test E2E (CV1-POS-AVAILABILITY-LIVE) teste le mauvais user et 2 sentinels PHPUnit du harness sont skippés silencieusement en local par design.

---

## 1. Tableau test → catégorie → verdict

| # | Fichier | Type | Catégorie | Cas | Pass observé | Verdict |
|---|---------|------|-----------|-----|--------------|---------|
| 1 | `tests/js/sentinels/connectionBannerNavigatorOnlineListener.spec.js` | Vitest | **STATIC-GREP** | 5 | 5 PASS | OK — patterns ancrés sur `_onOffline = () =>` (pas un simple `addEventListener('offline')` partagé) |
| 2 | `tests/js/sentinels/kdsA11yRichStructure.spec.js` | Vitest | **STATIC-GREP** + 1 négatif | 7 | 7 PASS | OK — assertion 7 (cardWrapperRegex avec `kdsWaitClass(...)` + `role="article"` proche) ajoute valeur réelle ; e2e axe complète |
| 3 | `tests/js/sentinels/kdsInflightOosMarkerStructure.spec.js` | Vitest | **STATIC-GREP** + assertions structurelles fortes | 10 | 10 PASS | OK — vérifie présence module Vuex, TTL `10*60*1000`, **negative** `not.toMatch(/setInterval/)` (utile), 4 `data-testid="kds-oos-warning-badge"` occurrences |
| 4 | `tests/js/sentinels/kioskBannerNoSuppressSessionInvalid.spec.js` | Vitest | **STATIC-GREP** ciblé | 3 | 3 PASS | OK — match précis sur balise `<ConnectionStatusBanner ... />` avec `not.toMatch(/suppress-session-invalid/)` côté kiosk + match positif côté POS |
| 5 | `tests/js/sentinels/kioskCartAriaLive.spec.js` | Vitest | **STATIC-GREP** | 3 | 3 PASS | OK mineur — pourrait passer si bloc déplacé ; voir P1-T6 |
| 6 | `tests/js/observabilityOutboxRoute.spec.js` | Vitest | **MIXED** : 5 STATIC-GREP + 1 RUNTIME-MOCK (mount + axios mock) | 6 | 6 PASS | OK fort — le test 6 (compile + mount + DOM probe) sort du source-grep et donne signal réel |
| 7 | `tests/js/userReportedBlockersRuntime.spec.js` (modifié) | Vitest | **STATIC-GREP** (déjà existant ; mis à jour pour kiosk doctrine) | 4 | 4 PASS | OK — le commentaire BLUE 2026-05-08 explicite la doctrine, négatifs (`expect(kiosk).not.toMatch(/suppress-session-invalid/)`) protègent contre régression |
| 8 | `tests/Feature/Sentinels/PosCatalogRequiresBranchSentinelTest.php` | PHPUnit | **STATIC-GREP** (`file_get_contents` + regex) | 3 | 3 PASS | **MOYEN** — c'est exactement le pattern que BYPASS-RED a critiqué : `file_get_contents` + `assertStringContainsString`. Aucun appel HTTP réel à `/admin/item?surface=pos`. **Voir P1-T1**. |
| 9 | `tests/Feature/Sentinels/OutboxPipelineHealthSentinelTest.php` | PHPUnit | **HARNESS-GATED RUNTIME** + 2 tests RUNTIME-MOCK | 4 (2 skipped + 2 PASS) | 2 PASS, 2 SKIPPED | OK fort sur le principe (test 3 + 4 utilisent `Mockery::mock(BroadcastManager)` — runtime réel) ; **MAIS skip silencieux des tests 1+2 en local** — voir P2 |
| 10 | `tests/Feature/Observability/OutboxOverviewControllerTest.php` | PHPUnit | **RUNTIME-REAL** (Sanctum + RefreshDatabase + DB::insert + assertJsonStructure) | 9 | 9 PASS | EXCELLENT — vrai HTTP roundtrip via TestCase, `assertJsonPath('pending.count', 1)` après insert d'un row, `assertDatabaseHas` après retry. Best test du batch. |
| 11 | `tests/e2e/cv1-pos-availability-live-validation-2026-05-08.spec.js` | Playwright | **RUNTIME-REAL mais TEST DU MAUVAIS USER** | 1 | non exécuté ici (suite e2e), MAIS bug confirmé par audit | **P1 BLOQUANT — voir P1-T7** : le spec docstring dit "admin@lecayenne.fr a branch_id=0" puis appelle `loginAsPosOperator(page)` qui se connecte avec `pos@lecayenne.fr` (branch_id=1, cf UserTableSeeder.php L82+L87). Conséquence : la branche du fix `if (bootstrapBranchId)` PASSE TOUJOURS pour cet user — le test ne reproduira jamais le bug d'origine et ne détectera pas une régression du fix. |
| 12 | `tests/e2e/cv1-kds-inflight-oos-marker-2026-05-08.spec.js` | Playwright | **RUNTIME-REAL avec FALLBACK STATIC** | 2 | non exécuté | **P2** — L1 `if (inject.ok) { ... } else { expect(html).toContain('kds-oos-warning-badge') }` : si l'injection Vuex échoue (env minifié ou Vue 3 vs Vue 2 SSR), le test dégrade en STATIC-GREP. Pas faux mais signal affaibli. |
| 13 | `tests/e2e/cv1-kds-a11y-rich-2026-05-08.spec.js` | Playwright | **RUNTIME-REAL** + 1 SKIP-SILENT-conditionnel | 4 | non exécuté | **P2** — `if (a11y && a11y._error) { test.skip(true, ...) }` : axe-core en panne ⇒ skip silencieux. Acceptable pour CI flake mais doit être loggé/comptabilisé. Le reste (article + aria-live + sr-only computed style) est fort. |

**Total** : 13 fichiers, ~62 cas, **33 cas Vitest tous PASS, 12 cas PHPUnit (10 PASS + 2 SKIP harness), 7 cas Playwright non exécutés ici (suite e2e separée).**

---

## 2. Top 5 tests faibles à renforcer (P1)

### P1-T7 — `cv1-pos-availability-live-validation-2026-05-08.spec.js` (BLOQUANT V1)

**Problème (gravité haute)** : le spec teste un user qui a `branch_id=1`, alors que le bug d'origine n'apparaît QUE pour `branch_id=0` (admin global).

**Preuve** :

```js
// tests/e2e/cv1-pos-availability-live-validation-2026-05-08.spec.js:22-23
// admin@lecayenne.fr a branch_id=0 (cf UserTableSeeder)
await loginAsPosOperator(page);  // ← se connecte comme pos@lecayenne.fr
```

```php
// database/seeders/UserTableSeeder.php
'email' => 'admin@lecayenne.fr', 'branch_id' => 0,  // ← user du bug
'email' => 'pos@lecayenne.fr',  'branch_id' => 1,  // ← user du test
```

Le fix SPA est :
```js
const bootstrapBranchId = this.authBranchId();
if (bootstrapBranchId) {
    this.applyPosBranchScope(bootstrapBranchId);
    this.itemList();
}
// else { /* skip itemList */ }
```

Avec `pos@lecayenne.fr` (branch_id=1) → `bootstrapBranchId = 1` → fetch → URL contient `branch_id=1` → l'assertion `fetchSansBranch.toEqual([])` PASSE trivialement, indépendamment du fix.

**Verdict** : test PASS mais ne valide PAS le fix. Si quelqu'un retire le `else`, ce test continuera de PASS. **Tautologique pour le bug réel.**

**Recommandation HEAL (≤30 LOC)** :
1. Ajouter helper `loginAsAdminGlobal(page)` qui se connecte avec `admin@lecayenne.fr`/`admin` — cohérent avec `loginAsPosOperator` existant.
2. Le spec doit avoir 2 cas :
   - `CV1-A — admin global (branch_id=0) NE FETCH PAS itemList sans branch_id` (vrai cas du bug)
   - `CV1-B — POST /admin/item?surface=pos sans branch_id retourne 422` (validation backend pure : peut être un test PHPUnit Feature avec `actingAs($admin, 'sanctum')` au lieu d'un Playwright)

```js
// Pseudocode HEAL
async function loginAsAdminGlobal(page,
  email = 'admin@lecayenne.fr', password = 'admin') {
  clearFoodKingRateLimits();
  await login(page, email, password);
  await page.waitForTimeout(1200);
  if (!/\/admin\/pos(\/|$|\?)/.test(page.url())) {
    await page.goto('/admin/pos', { waitUntil: 'domcontentloaded' });
  }
}

test('admin global (branch_id=0) ne fetch PAS /admin/item au mount POS', async ({ page }) => {
  await loginAsAdminGlobal(page);
  // ... même observation page.on('request')
  // Attendu : itemListUrls.length === 0 OU si fetch existe il a branch_id
});
```

### P1-T1 — `PosCatalogRequiresBranchSentinelTest.php` (faiblesse de catégorie)

**Problème** : 100 % source-grep (`file_get_contents` + `assertMatchesRegularExpression`). Exactement le pattern critiqué par RED-BYPASS-AUDIT (B2 sentinels gonflés).

**Aucun test runtime du contract HTTP** : pas de `getJson('/api/admin/item?surface=pos')` qui assert `assertStatus(422)`.

**Verdict** : passe trivialement en STATIC-GREP. Si quelqu'un déplace la ligne de guard ou réécrit la condition avec une syntaxe équivalente (`!$branchId || $branchId < 1` au lieu de `$branchId === null || $branchId < 1`), le regex casse alors que la sécurité est intacte → faux positif. Symétriquement, si le guard est commenté mais la string `"POS catalog requires branch_id"` reste dans un commentaire, le test PASS → faux négatif.

**Recommandation HEAL (≤30 LOC)** :

```php
public function test_pos_catalog_returns_422_for_admin_global_without_branch(): void
{
    $admin = User::factory()->create(['branch_id' => 0]);
    $admin->assignRole('Admin');
    $admin->givePermissionTo('pos');

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/item?surface=pos')
        ->assertStatus(422)
        ->assertJsonPath('message', 'POS catalog requires branch_id');
}

public function test_pos_catalog_returns_200_with_branch_id(): void
{
    $admin = User::factory()->create(['branch_id' => 0]);
    $admin->assignRole('Admin');

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/admin/item?surface=pos&branch_id=1')
        ->assertOk();
}
```

Garder les tests source-grep en complément (anti-régression structurel) MAIS le contract test runtime devient le sentinel premier-rang.

### P1-T6 — `observabilityOutboxRoute.spec.js` (renforcement modeste)

**Problème** : assertion 6 (compile + mount) n'asserte pas que `mounted()` appelle réellement les 2 endpoints POST (retry/drain). Asserte uniquement le GET initial.

**Verdict** : si quelqu'un retire `axios.post` des handlers retry/drain, le test 6 ne le verra pas.

**Recommandation HEAL (≤20 LOC)** :

```js
// Après mount, déclencher le bouton et asserter axios.post
const retryBtn = wrapper.find('[data-testid="outbox-retry-failed"]');
if (retryBtn.exists()) {
    await retryBtn.trigger('click');
    await flushPromises();
    expect(fakeAxios.post).toHaveBeenCalledWith(
        '/api/admin/observability/outbox/retry-failed'
    );
}
```

### P1-T8 — `kdsInflightOosMarkerStructure.spec.js` (assertion 11 sur i18n keys faible)

**Problème ligne 97-100** :

```js
expect(componentSource).toMatch(/kds_oos_warning_tooltip/);
expect(componentSource).toMatch(/kds_oos_warning_aria/);
```

C'est uniquement la présence d'une string i18n key — n'importe où dans le fichier (commentaire, variable, ailleurs). Tautologique faible.

**Recommandation HEAL (≤10 LOC)** :

```js
it('OOS badge wires aria-label and title to translated i18n keys', () => {
    expect(componentSource).toMatch(
        /<[^>]+data-testid="kds-oos-warning-badge"[^>]*:title="\$t\(['"]kds_oos_warning_tooltip/
    );
    expect(componentSource).toMatch(
        /<[^>]+data-testid="kds-oos-warning-badge"[^>]*:aria-label="\$t\(['"]kds_oos_warning_aria/
    );
});
```

### P1-T11 — `kdsA11yRichStructure.spec.js` (assertion 7 trop lâche sur sr-only CSS)

**Problème** : `expect(componentSource).toMatch(/clip:\s*rect\(0,\s*0,\s*0,\s*0\)/)` — la règle CSS pourrait être un commentaire dans le `<style>`.

**Verdict** : OK car le e2e spec (cv1-kds-a11y-rich tests/e2e) verifie via `getComputedStyle` que `position=absolute, overflow=hidden, width=1px, height=1px`. Donc compensé par le e2e, mais isolé serait faible.

**Recommandation P3** : ajouter un test `getByTestId('kds-aria-live')` mount + computed style (vue/test-utils peut le faire). Pas bloquant.

---

## 3. Skips silencieux (P2)

### Skip-1 — OutboxPipelineHealthSentinelTest tests 1 + 2 (BY DESIGN, mais doit être tracé en CI)

```
✗ outbox pipeline dispatches under active harness    SKIPPED (CI_WEBSOCKETS_HARNESS=1 not set)
✗ harness environment is not the phpunit defaults    SKIPPED
✓ phase 3b release claim on broadcaster failure
✓ contract violation preserves pager grade prefix
```

**Justification** : le runbook `docs/runbooks/CI_WEBSOCKETS_HARNESS.md` documente que ces 2 tests requièrent soketi:6001 + queue:work UP. Tests 3 + 4 utilisent `Mockery::mock(BroadcastManager)` — runtime réel sans broadcaster externe.

**Évaluation hostile** :
- POSITIF : skip est **loud** (`markTestSkipped` avec message complet pointant vers le runbook et les exports)
- POSITIF : commentaire `# INVARIANTS — DO NOT WEAKEN` interdit explicitement de relâcher l'assertHarnessActive
- POSITIF : le workflow GitHub Actions `.github/workflows/ci-sync-rupture-harness.yml` (créé dans le même batch) exporte `CI_WEBSOCKETS_HARNESS=1` et booteloop bootstrap script
- RISQUE RÉSIDUEL : **personne ne peut prouver localement que les tests 1 + 2 PASS sans bootstrapper soketi**. Si la suite CI se casse à un moment ces 2 tests deviennent silencieusement non-couverts. Le test 5 (méta) du batch (`assertHarnessActive` lui-même) ne court pas → vérification meta absente.

**Recommandation P2 (≤15 LOC)** : ajouter un sentinel meta `tests/Feature/Sentinels/OutboxHarnessCiTracerSentinelTest.php` qui `assertFileExists('.github/workflows/ci-sync-rupture-harness.yml')` ET assert que le workflow contient bien `CI_WEBSOCKETS_HARNESS: '1'` ET `BROADCAST_DRIVER: pusher` ET `QUEUE_CONNECTION: database` (regex sur le YAML). Si la pipeline CI dérive, cassage immédiat.

### Skip-2 — `cv1-kds-a11y-rich` test 1 (axe loader fail → silent skip)

```js
// L36-41
if (a11y && a11y._error) {
    console.warn('[CV1-A11Y-RICH] axe analyze unavailable:', a11y._error);
    test.skip(true, `axe loader failed: ${a11y._error}`);
}
```

**Évaluation hostile** : `test.skip(true, ...)` ne fait pas échouer la suite mais **mange l'évidence axe-core**. Si `@axe-core/playwright` plante en CI (réseau bloqué, version incompatible), le test 1 (le plus important pour a11y) devient un non-test silencieux.

**Recommandation P2 (≤5 LOC)** : remplacer par `throw new Error('axe loader failed in CI: ' + a11y._error)` quand `process.env.CI === 'true'` ; garder le skip uniquement en local dev.

### Skip-3 — `cv1-kds-inflight-oos-marker` fallback STATIC

```js
if (inject.ok) {
    // assertions runtime fortes
} else {
    expect(html).toContain('kds-oos-warning-badge');  // ← static fallback
}
```

**Évaluation hostile** : le fallback est intentionnel (Vue 3 vs Vue 2 introspection variable). MAIS `inject.ok = false` est silencieux — pas de log, pas de compteur de "test partiellement runtime / partiellement static".

**Recommandation P2 (≤3 LOC)** : ajouter `console.warn('[CV1-OOS-MARKER] runtime injection failed: ${inject.reason} — fallback to static assertion')` pour visibilité dans le rapport Playwright.

---

## 4. Hypothèses adversaires — vérification point par point

| # | Hypothèse | Verdict |
|---|-----------|---------|
| T1 | Tautological tests (test=source) | **PARTIELLEMENT CONFIRMÉ** : `PosCatalogRequiresBranchSentinelTest` 100 % source-grep (P1-T1). Les sentinels JS sont source-grep par design mais avec patterns ancrés (4 occurrences, négatifs, `aria-labelledby` strict) — moins critiquables. |
| T2 | Tests skipped silencieusement | **CONFIRMÉ ×3** : Outbox harness test 1 + 2 (loud skip OK), axe-core silent skip (P2), inject.ok silent fallback (P2) |
| T3 | Tests sans test d'intent | **CONFIRMÉ ×1** : P1-T7 (CV1-POS-AVAILABILITY-LIVE teste mauvais user → ne reproduit pas le bug). Les autres testent l'intent (badge OOS, aria-live, role=article, etc.). |
| T4 | Playwright trivially-pass | **NON CONFIRMÉ** : aucun `expect(true).toBe(true)`, pas de try/catch swallow. MAIS P1-T7 tautologique ⇒ effet équivalent. |
| T5 | OutboxPipelineHealth log driver mock-only | **NON CONFIRMÉ POUR TEST 3+4** : `Mockery::mock(Broadcaster::class)` injecte un faux broadcaster ET asserte que `dispatched_at` est vraiment null après refresh. C'est un **vrai test runtime** du Job → Phase 3b contract. Test 3 catch l'exception rethrowée. ✓ Honnête. **CONFIRMÉ POUR TEST 1+2** : skipped en local par design — voir Skip-1. |
| T6 | OutboxOverviewControllerTest JSON shape mock | **NON CONFIRMÉ** : `RefreshDatabase` + `DB::table('domain_events')->insert(...)` + `getJson('/api/admin/observability/outbox')->assertJsonPath('pending.count', 1)`. Le JSON est calculé par le vrai controller (`SyncOverviewController` avec `outbox()` action) sur la vraie DB de test. **Best test du batch.** |
| T7 | CV1-POS-AVAILABILITY-LIVE Playwright real HTTP | **NON CONFIRMÉ pour le bon user** : voir P1-T7. Spec utilise `page.on('request')` pour capturer les requests SPA — c'est du runtime réel — mais le user testé n'est pas celui du bug. |
| T8 | Sentinels structurels regex peuvent détecter régression a11y | **PARTIELLEMENT** : oui pour suppression de `role="article"` (regex `\.length >= 4` casse), oui pour TTL renommé. NON pour i18n keys (P1-T8). |
| T9 | userReportedBlockersRuntime mise à jour BLUE correcte | **CONFIRMÉ OK** : commentaire BLUE explicite la doctrine, négatifs (`expect(kiosk).not.toMatch(/suppress-session-invalid/)`) protègent contre régression de la décision. |
| T10 | observabilityOutboxRoute teste router SPA réel | **CONFIRMÉ via test 6** (mount via @vue/test-utils + assert axios.get appelé sur `/api/admin/observability/outbox`). Tests 1-5 sont source-grep (acceptable). |

---

## 5. Verdict GO / NO-GO test integrity V1

### GO conditionnel HEAL

**Justifications GO** :
- 38/38 cas Vitest PASS (verified)
- 12/12 cas PHPUnit en relevant scope, dont 2 skip-loud documentés
- `OutboxOverviewControllerTest` est runtime-real et solide (le meilleur du batch)
- `OutboxPipelineHealthSentinelTest` tests 3 + 4 sont runtime-mock honnêtes (`Mockery` + `RefreshDatabase` + `$event->refresh()`)
- Sentinels JS structurels ont des assertions ancrées (4 occurrences, negative `not.toMatch`, `aria-labelledby` strict) — nettement supérieur à `assertStringContainsString('foo')`
- Le batch a converti la critique BYPASS-RED (sentinels gonflés) en doctrine plus saine sur la majorité des tests

**Justifications HEAL conditionnel** :
- **P1-T7** : `cv1-pos-availability-live-validation` teste `pos@lecayenne.fr` (branch_id=1) au lieu de `admin@lecayenne.fr` (branch_id=0) → ne reproduit pas le bug, tautologique. **À FIX avant V1 release.** ETA fix : 30 min (helper login + 1 cas).
- **P1-T1** : `PosCatalogRequiresBranchSentinelTest` 100 % source-grep — ajouter 1 cas runtime HTTP. ETA : 20 min.
- **P2 skips silencieux** : axe loader + Vuex inject — ajouter logs/throw-in-CI. ETA : 10 min.
- **P2 harness CI tracer** : sentinel meta sur le workflow YAML pour empêcher le silent-skip de devenir silent-non-couvert. ETA : 15 min.

### NO-GO bloquant V1

Si `pos-availability-live` n'est pas corrigé (P1-T7), la mission CV1-POS-AVAILABILITY-LIVE-001 elle-même est partiellement couverte : sentinel statique ✓, controller test ✗ (pas écrit), playwright ✗ (mauvais user). **Le user-facing fix qui était l'objet du plan n'a pas de validation runtime end-to-end pour le user du bug.**

---

## 6. Synthèse — Score test integrity

| Dimension | Score / 5 | Commentaire |
|-----------|-----------|-------------|
| Couverture runtime vs static | 3 | `OutboxOverviewControllerTest` excellent ; `PosCatalogRequiresBranchSentinelTest` 100 % static ; les sentinels JS sont par nature static-grep mais ancrés |
| Honnêteté des skips | 4 | 1 loud skip documenté + 2 silent skips à corriger |
| Reproduction du bug | 2 | **P1-T7 tape à côté de la cible** ; les autres reproduisent l'intent correctement |
| Anti-régression réelle | 4 | Negative assertions (`not.toMatch(setInterval)`, `not.toMatch(suppress-session-invalid)`) montrent maturité |
| Maintenance load | 4 | Tests bien commentés (FK-ID, source RED, runbook), patterns documentés |
| **Score global** | **3.4 / 5** | **GO HEAL** |

---

## 7. Annexe — exécution Trust-but-Verify

```
$ php artisan test --filter=PosCatalogRequiresBranchSentinelTest
PASS  Tests\Feature\Sentinels\PosCatalogRequiresBranchSentinelTest
✓ item controller aborts 422 for surface pos without branch
✓ pos component does not fetch item list without bootstrap branch
✓ investigation doc exists
Tests:  3 passed | 0.17s

$ php artisan test --filter=OutboxOverviewControllerTest
PASS  Tests\Feature\Observability\OutboxOverviewControllerTest
9 passed | 1.52s

$ php artisan test --filter=OutboxPipelineHealthSentinelTest
WARN  Tests\Feature\Sentinels\OutboxPipelineHealthSentinelTest
- outbox pipeline dispatches under active harness        SKIPPED (CI_WEBSOCKETS_HARNESS=1 not set)
- harness environment is not the phpunit defaults        SKIPPED
✓ phase 3b release claim on broadcaster failure
✓ contract violation preserves pager grade prefix
Tests:  2 skipped, 2 passed | 0.61s

$ npx vitest run tests/js/sentinels/connection... [batch 7 fichiers]
Test Files  7 passed (7)
Tests       38 passed (38) | 723ms
```

**Tests Playwright non exécutés** (suite e2e séparée + audit P1-T7 confirmé via lecture du spec + UserTableSeeder.php → bug d'écriture du test indépendant de la run).

---

## 8. Recommandations finales (par priorité)

### P1 (avant V1)
1. **HEAL `cv1-pos-availability-live-validation-2026-05-08.spec.js`** : ajouter `loginAsAdminGlobal` helper et asserter `bootstrapBranchId=0` ⇒ pas de fetch. ETA 30 min.
2. **HEAL `PosCatalogRequiresBranchSentinelTest.php`** : ajouter 2 cas runtime HTTP (`getJson('/api/admin/item?surface=pos')` 422 + 200 avec branch_id). ETA 20 min.

### P2 (V1.x post-release)
3. Ajouter `OutboxHarnessCiTracerSentinelTest.php` pour empêcher dérive YAML workflow. ETA 15 min.
4. Renforcer `cv1-kds-a11y-rich` test 1 : `throw` au lieu de `test.skip` quand `CI=true`. ETA 5 min.
5. Logger `console.warn` quand `inject.ok=false` dans `cv1-kds-inflight-oos-marker`. ETA 3 min.

### P3 (cosmétique, V2)
6. Renforcer assertion 11 de `kdsInflightOosMarkerStructure.spec.js` (i18n keys ancrés sur balise badge). ETA 10 min.
7. Renforcer assertion 6 de `observabilityOutboxRoute.spec.js` (déclenche bouton retry et asserte `axios.post`). ETA 20 min.

### Total HEAL P1 ≤ 50 min, P2 ≤ 25 min, P3 ≤ 30 min — coût négligeable comparé au coût d'une régression silencieuse en V1.

---

**Fin audit Agent TEST-INTEGRITY — `1b38e64a3` — 2026-05-08.**
