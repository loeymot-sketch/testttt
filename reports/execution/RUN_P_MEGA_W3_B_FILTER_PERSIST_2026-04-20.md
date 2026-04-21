# RUN — P_MEGA_W3_B_FILTER_PERSIST_2026-04-20

```
EXECUTE_DELEGATION: foodking-routine-implementer
```

## Summary

- **Store Vuex `kioskFilter`** : état `activeFilters` + `customerAllergens` + `hydrated` ; persistance `localStorage` (`kiosk:filters`, `kiosk:customer_allergens`) ; validation des IDs contre `KIOSK_FILTERS` à l’init et au `toggle`.
- **Catalogue** : liste produits toujours rendue ; greyout via `applyKioskFilters` → `Set` d’IDs autorisés + classe `kiosk-product-card--filtered-out` ; pas de `v-if` sur les cartes ; `aria-disabled` / `tabindex` / clic no-op si filtré ; bandeau sous le header (clefs i18n existantes `kiosk.catalog.filters_label` + `kiosk.catalog.filters_reset`).
- **Wizard** : `wizardStepBindings` passe `activeFilters` uniquement aux étapes viande / sauce / garnitures / suppléments ; `activeFilters` lu via getter tolérant l’absence du module (tests).
- **Helper** : `isVariationAllowedByFilters()` aligné sur les filtres catalogue, tolérant champs absents sauf contradiction explicite.

## Tests

- **Vitest local** : `tests/js/kioskFilterPersist.spec.js` — **6/6** verts.
- **Vitest global** : **535/535** verts (529 baseline + 6).

## Findings

### `FINDING_RESOURCE_FLAGS_DEFERRED`

Le filtre grille / wizard reste **partiellement cosmétique** tant que `NormalItemResource` n’expose pas les drapeaux `is_vegetarian` / `is_halal` / `is_pork_free` / `is_gluten_free` / `is_spicy` sur les items (et que les variations n’exposent pas systématiquement les mêmes métadonnées). Documenté comme drift #5 dans `reports/execution/AUDIT_P_MEGA_23_DRIFT_ROOT_CAUSE_2026-04-20.md` ; correction prévue côté **P-MEGA-23** (cycle dédié backend / resource), pas dans W3.B.

## Fichiers touchés

- `resources/js/helpers/kioskFilters.js`
- `resources/js/store/modules/kioskFilter.js` (nouveau)
- `resources/js/store/index.js`
- `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue`
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepViandeComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepGarnituresComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue`
- `tests/js/kioskFilterPersist.spec.js` (nouveau)

## Risque résiduel

Styles greyout uniquement CSS ; aucun changement backend. Quand les flags API seront exposés, le comportement « réel » du filtre s’alignera sans changer le rail UI.

---

## Audit (Claude orchestrateur)

| Item | Vérif | Statut |
|---|---|---|
| SUBSYSTEMS_TOUCHED only | `git diff --stat -- 'app/**' 'database/**' 'routes/**'` vide | ✅ |
| 10/10 fichiers attendus livrés | match plan section P-MEGA-09 | ✅ |
| EXECUTE_DELEGATION line | présente ligne 4 | ✅ |
| Tests Vitest | 6/6 nouveaux + 535/535 global | ✅ |
| A11y greyout sans v-if | Set d'IDs + class additive confirmé | ✅ |
| Finding `FINDING_RESOURCE_FLAGS_DEFERRED` | documenté | ✅ |
| PHPUnit baseline failure | logs du 15 avril (pré-existant W1/W2/W3.A) → hors scope | ✅ noté, pas régression |

**Audit: PASSED**
Cycle: CLOSED after 0 remediation round(s)
Critical zones touched: NONE
Human gate: NONE
