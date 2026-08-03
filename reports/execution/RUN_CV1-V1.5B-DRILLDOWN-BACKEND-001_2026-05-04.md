# RUN — CV1-V1.5B-DRILLDOWN-BACKEND-001 — 2026-05-04

| Champ | Valeur |
| --- | --- |
| TASK_ID | `CV1-V1.5B-DRILLDOWN-BACKEND-001` |
| MASTER | `CV1-V1.5B-DRILLDOWN-INGREDIENTS-MASTER` |
| Plan | `plans/PLAN_CV1-V1.5B-DRILLDOWN-INGREDIENTS-MASTER_2026-05-04.md` § E1 |

**EXECUTE_DELEGATION:** `foodking-complex-implementer`

## Fichiers produit (créés / modifiés)

| Fichier | Action |
| --- | --- |
| `app/Services/Ingredients/IngredientService.php` | **Modifié** — `usageDetailsForGlobalId()` + helpers (attribute / extra / addon, tri, eager load) |
| `app/Http/Controllers/Admin/IngredientController.php` | **Modifié** — `usage()` |
| `app/Http/Resources/IngredientUsageResource.php` | **Créé** |
| `routes/api.php` | **Modifié** — `GET /admin/ingredients/{globalId}/usage` (avant `show`, même `where` que les autres routes ingrédients) |

## Tests

| Fichier | Action |
| --- | --- |
| `tests/Feature/Ingredients/IngredientUsageDrillDownTest.php` | **Créé** — 7 scénarios (attribute category+item + tri, unused, extra 2 catégories, addon, invalid type 404, missing id 403, permission 403) |

**Note routage :** les chemins qui **ne matchent pas** le motif `globalId` (`[a-z]+:[0-9]+`) tombent sur le catch-all `Frontend\RootController@index` (réponse **200** HTML), pas sur un 404 Symfony. Le test « global id invalide » utilise donc un segment qui **matche** la route (`notatype:42`) pour obtenir le **404 JSON** contrôlé par le contrôleur (`parseGlobalId` → null).

## Validation

### PHPUnit ciblé

```text
Tests:  7 passed
```

Commande : `php artisan test tests/Feature/Ingredients/IngredientUsageDrillDownTest.php --colors=never`

### PHPUnit filtre `Ingredient`

```text
Tests:  30 passed
```

Commande : `php artisan test --filter="Ingredient" --colors=never`

### PHPUnit global

```text
Tests:  24 skipped, 1428 passed
Time:   237.35s
```

Commande : `php artisan test --colors=never`

### Vitest

```text
Test Files  193 passed (193)
     Tests  1162 passed | 2 skipped (1164)
```

Commande : `npx vitest run`

## Performance (estimé)

- **Attribute / extra :** 1 requête `ItemWizardStep` filtrée + eager `profile` (+ `category`, `item` via relations `BelongsTo`) — pas de N+1 sur owners.
- **Addon :** 1 chargement `ItemAddon` (+ `item`) + 1 requête optionnelle `ItemWizardProfile` pour `wizard_profile_id` (nullable).
- **`findByGlobalId` dans `usageDetailsForGlobalId` :** réutilise la logique existante (`listAll()`) comme `show()` — coût connu catalogue global mono-filiale (hors périmètre d’optimisation de ce cycle).

## Invariants checklist

| ID | Statut |
| --- | --- |
| **I1** Pricing SSOT | Non touché (lecture métadonnées / usages). |
| **I2** OrderStatus | Non touché. |
| **I3** `branch_id` | Non modifié — même cadre catalogue global que `listAll()` / doc PHPDoc existante. |
| **I4** Dispatch post-commit | Non touché (pas d’events/jobs). |
| **I5** OrderService / FrontendOrderService symmetry | Non touché. |
| **I6** Frozen zones | Aucune zone gelée ouverte ou modifiée. |
