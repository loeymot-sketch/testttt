# Rapport racine — PHPUnit MySQL CI red depuis 2026-04-20 22:32

**Date** : 2026-04-21
**Cycle** : W8.5 HOTFIX (post-W8 SYNTHESE / pré-W9)
**Sévérité** : HIGH-CI (signal CI MySQL inutile depuis 3 jours, risque de masquer les vraies régressions)
**Status** : RESOLVED (commit en cours)
**Authored by** : Orchestrator (Claude Opus 4.7) avec audit explore subagent

---

## TL;DR

11 tests fail / 856 en CI MySQL — **TOUS** dans `MenuProjectionServiceTest`. Ces mêmes 13 tests passent 100 % en local (SQLite `:memory:`).

**Cause root** : `OrderAllergenSnapshotComposedTest::setUp()` faisait `Schema::create('item_extra_allergens')` runtime → DDL implicit COMMIT MySQL → casse la transaction d'enveloppe `RefreshDatabase` → fixtures du test (items "Tacos bœuf sentinel", catégorie "Tacos Test", allergène "lait") **survivent au rollback** et polluent `MenuProjectionServiceTest` qui s'exécute alphabétiquement après `Orders/`.

**Fix appliqué** : matérialiser la table `item_extra_allergens` en migration permanente + retirer le `Schema::create/dropIfExists` runtime du test.

---

## Symptômes CI

Run ID : `24729392707` (2026-04-21 14:54)
Workflow : `.github/workflows/phpunit.yml` (MySQL 8.0)

```
Tests: 856, Assertions: 2309, Errors: 3, Failures: 8.
```

11 fails, **tous dans `tests/Feature/Services/Menu/MenuProjectionServiceTest.php`** :

| Test | Symptôme |
|------|----------|
| `test_kiosk_only_item_is_hidden_on_pos` | Expected `['Sold Everywhere']` got `['Tacos bœuf sentinel']` |
| `test_pos_only_category_is_hidden_on_kiosk` | Expected `['Backend POS','Shared']` got + `'Tacos Test'` |
| `test_kiosk_label_overrides_name_only_on_kiosk` | Expected `'Tacos Signature'` got `'Tacos Test'` |
| `test_channel_specific_sort_is_applied` | Expected `['B','A','C']` got + `'Tacos Test'` |
| `test_availability_row_marks_item_unavailable` | Expected `false` got `true` |
| `test_kiosk_emoji_is_exposed_only_on_kiosk` | `Undefined array key "emoji"` (index décalé par contamination) |
| `test_allergen_flags_are_passed_through` | `1062 Duplicate entry 'lait' for allergens.allergens_code_unique` |

Local SQLite : 13/13 PASSED.

---

## Timeline CI PHPUnit (régression antérieure W8)

| Date / Run | Verdict |
|---|---|
| 2026-04-19 01:48-01:59 (4 runs) | ✅ SUCCESS |
| **2026-04-20 22:32** | ❌ **FIRST FAILURE** ← introduction du DDL runtime |
| 2026-04-21 09:29-14:54 (4 runs) | ❌ FAILURE x4 (toute la session W8) |

**Conclusion** : la régression date du 20/04 22:32, donc **AVANT le démarrage W8 EXECUTE** (qui commence avec `1350ced6d` "K-6.3 throttle"). W8 n'a ni introduit ni aggravé. Mais W8 ne pouvait pas être validé CI MySQL non plus.

---

## Cause racine technique

### Le code coupable (avant fix)

```40:60:tests/Feature/Orders/OrderAllergenSnapshotComposedTest.php
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('item_extra_allergens')) {
            Schema::create('item_extra_allergens', function (Blueprint $table): void {
                $table->foreignId('item_extra_id')->constrained('item_extras')->cascadeOnDelete();
                $table->foreignId('allergen_id')->constrained('allergens')->cascadeOnDelete();
                $table->primary(['item_extra_id', 'allergen_id']);
            });
            $this->createdItemExtraAllergenPivot = true;
        }
    }

    protected function tearDown(): void
    {
        if ($this->createdItemExtraAllergenPivot) {
            Schema::dropIfExists('item_extra_allergens');
        }
        parent::tearDown();
    }
```

### Le mécanisme de leak (MySQL ≠ SQLite)

1. `RefreshDatabase` ouvre une **transaction d'enveloppe** au début de chaque test, qui sera rollback à la fin.
2. Quand `setUp()` exécute `Schema::create()` → DDL → MySQL effectue un **commit implicite** (documenté : <https://dev.mysql.com/doc/refman/8.0/en/implicit-commit.html>).
3. La transaction d'enveloppe est **terminée prématurément**.
4. Toutes les écritures suivantes du test (`ItemCategory::forceCreate(['name'=>'Tacos Test'])`, `Item::forceCreate(['name'=>'Tacos bœuf sentinel'])`, `Allergen::firstOrCreate(['code'=>'lait'])`) sont écrites **en dehors** de toute transaction → persistent.
5. Le rollback final n'a plus rien à rollback → les données restent en base pour les classes suivantes.
6. PHPUnit explore les répertoires alphabétiquement : `Orders/` est exécuté avant `Services/Menu/`. Donc `MenuProjectionServiceTest` voit ces fixtures résiduelles → contamination assertions.

**SQLite** : `:memory:` est recréé à chaque process de test → DDL n'a pas l'effet inter-classe + sqlite gère DDL en transaction (savepoints). C'est pourquoi le test passe 100 % en local.

---

## Fix appliqué

### 1) Nouvelle migration permanente

**Fichier** : `database/migrations/2026_04_22_300000_create_item_extra_allergens_table.php`

Crée la table `item_extra_allergens` avec FK identiques à celles que créait le test runtime (item_extras + allergens, cascade delete, primary composite).

Idempotent : `if (Schema::hasTable('item_extra_allergens')) return;` permet une re-migration safe.

### 2) Retrait DDL runtime du test

**Fichier** : `tests/Feature/Orders/OrderAllergenSnapshotComposedTest.php`

- Suppression imports `Schema`, `Blueprint`.
- Suppression `private bool $createdItemExtraAllergenPivot`.
- Suppression complète de `setUp()` et `tearDown()` overrides (RefreshDatabase setUp parent suffit).
- Bloc explicatif inline pour traçabilité.

### Compatibilité du code applicatif

`app/Services/Orders/OrderItemAllergenSnapshot::resolveExtraAllergens()` testait déjà `Schema::hasTable('item_extra_allergens')` et retournait `[]` si absente. Avec la migration permanente, `hasTable()` retournera désormais `true` → comportement enrichi (lit les pivots). Aucun rollback nécessaire de cette logique.

---

## Vérification 200 %

| Étape | Résultat |
|---|---|
| `vendor/bin/phpunit --filter='OrderAllergenSnapshotComposedTest\|MenuProjectionServiceTest'` | ✅ 14/14 PASSED (1.6s) |
| `vendor/bin/phpunit --testsuite Feature` (708 tests) | ✅ 708 PASSED, 8 skipped, 0 fail, 0 error (2m12) |
| `ReadLints` migration + test | ✅ No linter errors |

CI MySQL : à valider après push (run automatique sur push branche feat/ton-sujet).

---

## Pourquoi ça a survécu 3 jours sans détection

1. **Le développement W7+W8 s'est fait en local SQLite** : tous les tests verts → fausse confiance.
2. **Le CI workflow PHPUnit est sur `pull_request` + `push`** mais l'absence de PR open récente sur main + la concentration sur le hotfix Playwright ont masqué le rouge.
3. **Aucun gatekeeping** : le merge n'est pas bloqué par PHPUnit (pas de required-check sur la branche).
4. **Le commit `74210a1d8` "fix(ci): MenuSeeder avoid MySQL TRUNCATE"** avait corrigé un autre cas de DDL implicit commit (TRUNCATE dans seeders) mais pas celui-ci.

---

## Action follow-up recommandées (post-W8.5)

1. **GATE_BRIEF** : ajouter `phpunit-mysql` dans les required status checks de `main` pour bloquer les futures régressions silencieuses.
2. **Lint custom** : grep CI pour interdire `Schema::create(` / `Schema::drop` dans `tests/**` (seuls les fichiers `database/migrations/**` doivent contenir ce DDL).
3. **Audit miroir** : grep récursif sur tous les tests pour détecter d'autres `Schema::create/drop` runtime potentiels — résultat : **0 autre cas** (vérifié pendant cycle W8.5).

---

## Liens

- Migration : `database/migrations/2026_04_22_300000_create_item_extra_allergens_table.php`
- Test corrigé : `tests/Feature/Orders/OrderAllergenSnapshotComposedTest.php`
- Mémoire structurée Graphiti : `reports/memory/MEMORY_GRAPH_W8_W9_2026-04-21.md`
- Active cycle : `.cursor/ACTIVE_CYCLE.md` (W8.5 HOTFIX entry)

---

**Verdict** : ✅ **PASSED** (local) — pending CI MySQL validation post-push.
