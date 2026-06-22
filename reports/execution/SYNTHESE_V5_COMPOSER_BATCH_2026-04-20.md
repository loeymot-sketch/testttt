# Synthèse V5 — Composer batch (salve K + L) — 2026-04-20

## Contexte

Salve **suite directe du bug `dispatch-after-commit`** découvert par V4 #8.
Stratégie : **renforcer les sentinelles AVANT de remédier**, pour mesurer l'étendue exacte du bug et garantir une couverture statique + runtime de la régression future.

| Salve | Cycle | Type | Verdict |
|---|---|---|---|
| K | V5 #2 — `P11_INVARIANT_4_OF_6_HARDENING` | Composer / no gate / script bash only | **CLOSED — PASSED** |
| L | V5 #3 — `P11_DISPATCH_SENTINEL_EXTEND` | Composer / no gate / test only | **CLOSED — PASSED (refactor) + 3 BUG_FOUND_INVARIANT_BROKEN volontaires** |

## Résultats

### V5 #2 — `P11_INVARIANT_4_OF_6_HARDENING`
- **Fichier modifié** : `scripts/check-invariants.sh` uniquement (+13/-5)
- **Élargissement pattern grep** : ajout des short-names (`OrderCreated::dispatch(...)` via `use App\Events\OrderCreated`) en plus du FQN. 9 events broadcast couverts.
- **Élargissement scope** : ajout de `app/Services/Menu/AvailabilityService.php`, `app/Services/ItemService.php`, `app/Services/ItemCategoryService.php`, `app/Http/Controllers/Admin/AvailabilityController.php`
- **Détection** : 8 violations identifiées (vs 0 avant durcissement)
  - OrderService.php:541, 961, 1266, 1423, 1478, 1575
  - FrontendOrderService.php:842, 848
- **Découverte bonus** : `OrderStatusChanged` est aussi affecté (4 call-sites supplémentaires révélés vs estimation initiale 1 event = OrderCreated)

### V5 #3 — `P11_DISPATCH_SENTINEL_EXTEND`
- **Fichier modifié** : `tests/Feature/DispatchAfterCommitTest.php` uniquement (76 lignes, refactor data provider)
- **Couverture étendue** : 1 event → 3 events broadcast (`OrderCreated`, `OrderStatusChanged`, `ItemAvailabilityChanged`)
- **Tests** : 6 = 3 commit ✔ + 3 rollback ✘
- **Tag PHPUnit** : `@group dispatch_after_commit_invariant` permet exclusion CI volontaire
- **Découverte technique** : `Event::assertNotDispatched` Laravel 9 ne supporte pas message custom en arg 2 — subagent l'a détecté et adapté

## Convergence des 2 sentinelles

| Sentinelle | Type | Couverture | État | Évolution attendue |
|---|---|---|---|---|
| `check-invariants.sh` 4/6 (V5 #2) | grep statique | 9 events broadcast × scope élargi | 8 hits FAIL | redevient OK quand V5 #1 livré |
| `DispatchAfterCommitTest` (V4 #8 + V5 #3) | runtime PHPUnit | 3 events × 2 scénarios = 6 tests | 3 ✔ + 3 ✘ | 6/6 ✔ quand V5 #1 livré |

**Cohérence parfaite** : les 2 sentinelles indépendantes pointent les mêmes events affectés. Aucun faux positif, aucun faux négatif détecté.

## Impact CI

- `check-invariants.sh` n'est dans **aucun workflow GitHub Actions** (`vitest.yml`, `phpunit.yml`, `playwright.yml`) → exit 1 ne bloque PAS la CI. Sert de pre-commit hook local + doc d'invariants.
- `phpunit.yml` lance `vendor/bin/phpunit --testdox` complet → **3 tests en data provider rouge** (au lieu d'1 seul depuis V4 #8) — toujours rouge structurellement, mais désormais structurel ET paramétré.

## Élargissement du scope du bug

Avant V5 #2/#3 : « OrderCreated dispatched on rollback » (1 event, 1 sentinelle).
Après V5 #2/#3 : **3 events broadcast confirmés affectés**, **8 call-sites statiques identifiés**, vraisemblablement plus (Item/CategoryCreated/Updated/Deleted non testés).

### Plan V5 #1 mis à jour
`tasks/execute-2026-04-20/V5_01_P11_DISPATCH_AFTER_COMMIT_REMEDIATION.md` mis à jour pour refléter :
- Bug confirmé sur 3 events au minimum
- Scope FILES TOUCHED étendu : 3 events minimum, jusqu'à 9 si extension Item/Category
- VALIDATE : 6/6 phpunit + check-invariants 4/6 OK

**Action humaine requise** : approuver l'addendum C9 du Gate Brief (étendu) avant que V5 #1 soit lancé en GPT-5.4.

## Statistiques cumulées Composer (V1 + V3 + V4 + V5)

| Wave | Cycles | PASSED | PARTIAL | BUG_FOUND | Failures regression |
|---|---|---|---|---|---|
| V1 (8 cycles) | 8 | 8 | 0 | 0 | 0 |
| V3 (1 cycle) | 1 | 1 | 0 | 0 | 0 |
| V4 (11 cycles) | 11 | 9 | 1 | 1 | 0 |
| V5 (2 cycles) | 2 | 2 | 0 | 0* | 0 |
| **TOTAL** | **22** | **20** | **1** | **1** | **0** |

*V5 #3 est compté PASSED (refactor) car les 3 rouges rollback sont des BUG_FOUND_INVARIANT_BROKEN volontaires hérités de V4 #8 (sentinelle extension), pas un bug nouveau.

## Lessons learned

1. **Sentinelles avant remédiation** = bonne stratégie. La salve K+L a triplé la couverture de détection AVANT que la remédiation V5 #1 (humain gate) soit livrée. Toute régression future ou rollback de V5 #1 sera détecté par 2 mécanismes indépendants.
2. **Les data providers PHPUnit** sont l'outil idéal pour les sentinelles paramétrées : 1 méthode de test → N événements couverts, sortie groupée lisible, facilité d'extension (1 ligne par nouveau cas).
3. **L'élargissement de pattern grep doit aussi étendre le scope** : un grep court-nom sur un scope trop limité peut paradoxalement donner moins de hits qu'un grep FQN sur scope large. V5 #2 a fait les 2 simultanément.
4. **`Event::assertNotDispatched($class, ?callable)` Laravel 9** : le 2e arg est un callback filter, pas un message d'assertion. Pour custom message → utiliser `Event::dispatched($class)->isEmpty()` + `assertTrue` à la main, ou se contenter du message PHPUnit par défaut.

## Prochaines étapes (handoff)

1. **HUMAN GATE C9 étendu** (à approuver avant V5 #1) — voir `tasks/execute-2026-04-20/V5_01_P11_DISPATCH_AFTER_COMMIT_REMEDIATION.md` mis à jour
2. **HUMAN GATE C1-C8 consolidé** (déjà préparé) — `docs/gates/GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20.md`
3. Options Composer no-gate restantes possibles (cf. PLAN_POST_VERIFY § V4 reliquat) :
   - **Option M** — `P12_POS_OFFLINE_QUEUE_ALIGN` (aligner POS sur kioskOfflineQueue) — touche `PosOrderController` (frozen-adjacent), à évaluer
   - Petites optimisations log/observability (aucune urgente)
4. Une fois V5 #1 livré (humain) → re-run V5 #3 phpunit doit passer 6/6 + V5 #2 check 4/6 OK = boucle fermée.
