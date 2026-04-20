# RUN T08b — Kiosk event ability enforcement

**Date**: 2026-04-20  
**Cycle**: KIOSK_PHASE_9_5_2026-04-18 — extension scope T08b autorisée par planner-orchestrator (correction P0 sécurité, clos l'audit T08 PARTIAL).  
**Verdict**: **PASS** ✅

## Contexte

L'audit T08 (REPORT_TASK08_BRANCH_ISOLATION_2026-04-20.md, finding §8) a relevé que les routes `POST /api/frontend/kiosk-event` (legacy hyphen) et `POST /api/frontend/kiosk/event` (alias slash) n'avaient que `auth:sanctum` + `throttle:30,1` comme middleware, **sans** `abilities:kiosk:order`. Ceci contredit les commentaires inline et les tests Phase 7. Tout token Sanctum valide (POS, admin, customer) pouvait poster sur ces endpoints kiosk.

Référence d'alignement : `testttt-kiosk-p93/routes/api.php` (lignes 924 et 978) où l'enforcement est en place.

## Patches livrés

### 1. `routes/api.php` (2 routes)

```diff
-    Route::post('/kiosk-event', [..\KioskEventController::class, 'store'])
-        ->middleware(['auth:sanctum', 'throttle:30,1'])
+    Route::post('/kiosk-event', [..\KioskEventController::class, 'store'])
+        ->middleware(['auth:sanctum', 'abilities:kiosk:order', 'throttle:30,1'])
         ->name('kiosk.event');

     // 1.9 — POST /api/frontend/kiosk/event : alias slash (master prompt §1.6).
+    // [K-6.1] Same ability enforcement as /kiosk-event — both aliases must fail-closed. [T08b]
-    Route::post('/kiosk/event', [..\KioskEventController::class, 'store'])
-        ->middleware(['auth:sanctum', 'throttle:30,1'])
+    Route::post('/kiosk/event', [..\KioskEventController::class, 'store'])
+        ->middleware(['auth:sanctum', 'abilities:kiosk:order', 'throttle:30,1'])
         ->name('frontend.kiosk.event');
```

### 2. `app/Http/Kernel.php` (alias middleware)

L'alias `abilities` n'était pas enregistré dans `$routeMiddleware` de `testttt` (présent dans p93 lignes 83-84). Sans cet alias, Laravel lève `BindingResolutionException: Target class [abilities] does not exist`.

```diff
         'localization' => \App\Http\Middleware\localization::class,
         'installed' => \App\Http\Middleware\Installed::class,
+        // [T08b / K-6.1] Register Sanctum ability middleware aliases so routes
+        // can use `abilities:kiosk:order` / `ability:kiosk:order` without the
+        // full FQCN. Mirrors Sanctum's documented usage and aligns with the
+        // testttt-kiosk-p93 reference worktree.
+        'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
+        'ability'   => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
     ];
```

### 3. `tests/Feature/KioskSecurity/KioskEventAbilityTest.php` (créé)

Spec dédiée, 6 tests via data provider `routeProvider` couvrant les 2 routes :
- `test_route_rejects_token_without_kiosk_order_ability` × 2 (hyphen + slash) → 403 attendu
- `test_route_accepts_token_with_kiosk_order_ability` × 2 → 200
- `test_route_rejects_unauthenticated_request` × 2 → 401

### 4. `tests/Feature/KioskPhase7/KioskEventBranchIsolationTest.php` (adapté)

Le test `test_any_valid_sanctum_token_passes_auth_documented_behavior` consignait l'ancien comportement (200 pour token sans ability). Renommé en `test_token_without_kiosk_order_ability_is_rejected` avec assertion 403 + commentaire `[T08b] route now ability-gated`.

## Tests — `phpunit --filter "KioskEventAbility|KioskEventBranchIsolation"`

```
PHPUnit 9.6.29 by Sebastian Bergmann and contributors.
...........                                                       11 / 11 (100%)
Time: 00:01.467, Memory: 71.00 MB
OK (11 tests, 27 assertions)
```

- `KioskEventAbilityTest` : 6/6 PASS
- `KioskEventBranchIsolationTest` : 5/5 PASS (test renommé inclus)
- Total : **11/11 PASS, 0 fail, 0 skip**

## Sécurité — invariants restaurés

| Invariant | Avant T08b | Après T08b |
|-----------|------------|------------|
| Token POS / admin peut poster sur `/kiosk-event` | ✅ accepté (200) | ❌ rejeté (403) |
| Token POS / admin peut poster sur `/kiosk/event` | ✅ accepté (200) | ❌ rejeté (403) |
| Token kiosk valide poste sur les 2 routes | ✅ 200 | ✅ 200 (inchangé) |
| Requête non-authentifiée | ❌ 401 | ❌ 401 (inchangé) |
| `branch_id` du payload ignoré pour la logique métier | ✅ (Phase 7.4) | ✅ (inchangé) |

## Verdict

**PASS** — T08 PARTIAL → CLOS. Les 2 routes kiosk-event sont alignées sur le reste de la surface kiosk. Worktree principal `testttt` uniquement (p93 = référence intacte).

## Suivi

- T08 PARTIAL → fermé.
- Reste sur backlog T08 (hors T08b) : endpoint `/kiosk/context` formel, validation hex thème, convergence menu legacy `kioskMenu/fetchMenu` vers SSOT — non bloquants pour canary.
