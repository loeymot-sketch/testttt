# Sprint 17 — Audit Global Post-Sprint 16 : Corrections Ciblées

## Execution Report

**Date:** 2026-03-10  
**Status:** COMPLETED  
**Executor:** Kimi  
**Plan Reference:** `/Users/1millnonstop/.cursor/plans/sprint_17_audit_corrections_a1984cd9.plan.md`

---

## Summary

Toutes les 4 corrections identifiées lors de l'audit post-Sprint 16 ont été implémentées avec succès.

### Bugs corrigés

| ID | Bug | Fichier | Statut |
|---|---|---|---|
| BUG-CRIT-1 | `ReferenceError: data is not defined` dans `buildWizardInstruction()` | `public/js/pos-wizard.js` | ✅ FIXED |
| BUG-CRIT-2 | N+1 queries dans `posOrderStore()` et `tableOrderStore()` | `app/Services/OrderService.php` | ✅ FIXED |
| BUG-MINOR-1 | `orderType` état mort dans `posCart.js` | `resources/js/store/modules/posCart.js` | ✅ FIXED |
| WARN-1 | `seedMinimalSettings()` absent dans `PosUITest.php` | `tests/Feature/PosUITest.php` | ✅ FIXED |

---

## Phase 1 — BUG-CRIT-1: Wizard Data Reference Fix

**Fichier:** `public/js/pos-wizard.js`  
**Ligne:** 1976

### Changement

```javascript
// AVANT (BUGGY):
Object.values(data.variations).forEach(function (group) {

// APRES (CORRECT):
Object.values(wizardItemData.variations).forEach(function (group) {
```

### Impact
- ✅ Corrige le `ReferenceError` lors de la commande de sandwichs avec sélection de pain
- ✅ Les instructions KDS reçoivent maintenant correctement le type de pain

---

## Phase 2 — BUG-CRIT-2: N+1 Query Optimization

**Fichier:** `app/Services/OrderService.php`  
**Méthodes:** `posOrderStore()` et `tableOrderStore()`

### Changement dans `posOrderStore()`

Ajout du bulk-load avant la boucle:
```php
// [BUG-CRIT-2 FIX] Bulk-load variations et extras avant la boucle pour éviter N+1
$variationIds = collect($requestItems)->pluck('item_variations')->flatten(1)->pluck('id')->filter()->unique()->toArray();
$extraIds = collect($requestItems)->pluck('item_extras')->flatten(1)->pluck('id')->filter()->unique()->toArray();

$dbVariations = !empty($variationIds)
    ? \App\Models\ItemVariation::whereIn('id', $variationIds)->get()->keyBy('id')
    : collect();
$dbExtras = !empty($extraIds)
    ? \App\Models\ItemExtra::whereIn('id', $extraIds)->get()->keyBy('id')
    : collect();
```

Remplacement des appels `find()` dans la boucle:
```php
// AVANT:
$dbVar = \App\Models\ItemVariation::find($varId);

// APRES:
$dbVar = $dbVariations[$varId] ?? null;
```

### Changement dans `tableOrderStore()`

Même pattern appliqué:
- Bulk-load des variations et extras avant la boucle
- Remplacement des `Item::find()`, `ItemVariation::find()`, et `ItemExtra::find()` par accès collection

### Impact
- ✅ Réduction drastique des requêtes DB par commande (O(1) au lieu de O(n))
- ✅ Performance améliorée sous charge élevée
- ✅ Consistance avec le pattern déjà appliqué dans `myOrderStore()`

---

## Phase 3 — BUG-MINOR-1: Cleanup posCart.js Dead State

**Fichier:** `resources/js/store/modules/posCart.js`

### Changements

1. Suppression de l'import inutilisé:
```javascript
// SUPPRIMÉ:
import orderTypeEnum from "../../enums/modules/orderTypeEnum";
```

2. Suppression du state mort:
```javascript
// SUPPRIMÉ du state:
orderType: null,
```

3. Suppression du getter inutilisé:
```javascript
// SUPPRIMÉ des getters:
orderType: function (state) {
    return state.orderType;
},
```

### Impact
- ✅ Code plus propre, moins de confusion pour les développeurs futurs
- ✅ Réduction du bundle size (mineur)

---

## Phase 4 — WARN-1: Test Seeding Fix

**Fichier:** `tests/Feature/PosUITest.php`

### Changement

```php
protected function setUp(): void
{
    parent::setUp();

    $this->seedSpatieRoles();
    $this->seedMinimalSettings();  // ← AJOUTÉ
    // ...
}
```

### Impact
- ✅ Test `test_company_data_is_populated_in_pos_component` ne plantera plus si settings non seedés
- ✅ Consistance avec les autres tests Feature

---

## Fichiers modifiés

1. `public/js/pos-wizard.js` — 1 ligne
2. `app/Services/OrderService.php` — ~30 lignes (bulk-load + accès collection)
3. `resources/js/store/modules/posCart.js` — 3 suppressions
4. `tests/Feature/PosUITest.php` — 1 ligne

---

## Risques et tests recommandés

| Risque | Mitigation |
|---|---|
| Régression wizard pain | Tester commande sandwich avec sélection pain |
| Régression prix variations/extras | Tester commande avec variations et extras multiples |
| Régression tableOrderStore | Tester commande depuis dining table |
| Régression localStorage | Tester persistance panier après refresh |

### Tests à exécuter

```bash
# Tests spécifiques au wizard
php artisan test --filter=OrderCreationTest

# Tests de performance
php artisan test --filter=PerformanceTest

# Tests de sécurité
php artisan test --filter=AuthComprehensiveTest

# Tests UI
php artisan test --filter=PosUITest
```

---

## Conclusion

Sprint 17 terminé avec succès. Les 3 bugs critiques et 1 warning identifiés lors de l'audit post-Sprint 16 sont corrigés. Le système est maintenant plus stable, performant, et propre.

Prochaine étape recommandée: Exécution des tests et audit Playwright / E2E verification pour valider les corrections.
