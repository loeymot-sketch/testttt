# AUDIT_MENU_CATEGORY_SUPPLEMENTS_012 — Catégories & Supplements

## Meta
- **Priority** : P1
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : AUDIT_MENU_ITEM_CRUD_011
- **Estimation** : 0.5 j-h
- **Vague** : B2

## Contexte

Les catégories structurent l'affichage POS/Kiosk. Supplements = options transversales (ex "sauce piquante" réutilisable). Risques : hiérarchie profonde non gérée ; ordre d'affichage incohérent POS vs Kiosk ; visibilité par surface (ex : "menu entreprise" caché du kiosk) mal implémentée.

## Questions d'audit

1. Les catégories supportent-elles une hiérarchie parent→enfant ou sont-elles plates ?
2. L'`order` / `position` / `sort_order` est-il unique par parent+branch ?
3. La visibilité par surface (POS-only, Kiosk-only, both) est-elle modélisée sur Category/Item ou bricolée ?
4. La réorganisation drag-and-drop est-elle atomique (transaction batch update) ?
5. Les supplements sont-ils partagés multi-branches ou scopés par branche ?
6. Un supplement peut-il avoir un prix variable par item attaché ?
7. La catégorie "hidden" cache-t-elle bien tous les items descendants sur POS et Kiosk ?
8. La suppression d'une catégorie avec items est-elle bloquée ou cascade soft-delete ?
9. Les images de catégories (tuiles kiosk) sont-elles optimisées (dimensions, lazy-load) ?
10. L'ordre d'affichage sur POS (liste catégories latérales) est-il cohérent avec l'ordre sur Kiosk (tuiles) ?

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Models/Category.php`, `app/Models/Supplement.php` (si existe)
- `app/Http/Controllers/Admin/Category/*`
- `resources/js/components/admin/category/*.vue`
- Migrations catégories

## Invariants at Risk
- [x] branch_id data isolation
- [ ] Symmetry POS/Kiosk (visibilité)

## Fichiers à lire
1. `app/Models/Category.php`
2. `app/Http/Controllers/Admin/Category/CategoryController.php`
3. `app/Models/Supplement.php` (ou équivalent)
4. Vues admin category

## Grep patterns

```
grep -rn "parent_id\|children" app/Models/Category.php
grep -rn "sort_order\|position\|order\s*=" app/Models/Category.php
grep -rn "visible_on\|available_on\|surface" app/Models/ app/Enums/
grep -rn "Supplement\|supplement" app/ resources/js/
grep -rn "reorder\|arrangeCategories" app/Http/Controllers/
```

## Evidence required
- Structure hiérarchique ou plate.
- Visibilité par surface documentée.
- Comportement drag-and-drop.
- Scope supplements (branche / global).

## Grille de verdict
- **PASS** : hiérarchie cohérente, visibilité modélisée, reorder atomique.
- **WARN** : pas de visibilité par surface mais pas d'impact actuel.
- **BLOCKED** : reorder non-atomique (positions divergentes), suppression cascade sans protection.

## Livrable
`reports/review/AUDIT_MENU_CATEGORY_SUPPLEMENTS_012_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed
