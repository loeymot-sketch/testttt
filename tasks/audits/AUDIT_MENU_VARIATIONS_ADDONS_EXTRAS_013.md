# AUDIT_MENU_VARIATIONS_ADDONS_EXTRAS_013 — Variations, Addons, Extras

## Meta
- **Priority** : P0
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : AUDIT_MENU_ITEM_CRUD_011
- **Estimation** : 0.75 j-h
- **Vague** : B3

## Contexte

Un item = variation (taille, cuisson) + addons (suppléments au choix, min/max) + extras (gratuit/payant). Risques : modélisation confuse (variation vs addon), prix non cumulatifs, min/max non enforced, stock par option inexistant, divergence POS/Kiosk sur rendu des règles.

## Questions d'audit

1. Quelle est la modélisation : 3 tables distinctes (item_variations, item_addons, item_extras) ou table polymorphique ?
2. Les règles min_selection / max_selection sont-elles au niveau "group" (ex "Sauces : min 1, max 2") ou par option individuelle ?
3. Le prix d'une variation : remplace le prix item (size M=€10, L=€12) ou s'ajoute (base €10 + L +€2) ?
4. Le prix d'un addon : cumulatif avec variation ? Bien additionné côté serveur ?
5. Le stock par option est-il tracé (un addon "Cheddar" peut être 86 indépendamment) ?
6. Les FormRequests validation serveur vérifient-elles les règles min/max et les FK (addon_id appartient bien à l'item) ?
7. Un addon peut-il être "par défaut coché" (pré-sélectionné) ? Cohérent entre POS et Kiosk ?
8. L'ordre d'affichage des options dans le wizard est-il configurable ?
9. Les images d'options sont-elles gérées (critique pour kiosk tactile) ?
10. Les options soft-deletées : les commandes historiques contenant ces options affichent-elles encore le libellé ?

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Models/ItemVariation.php`, `ItemAddon.php`, `ItemExtra.php`, éventuellement `ItemOption.php`
- Migrations associées
- `app/Http/Controllers/Admin/Item/` (édition options)
- Wizard POS/Kiosk (lecture seule pour cet audit)

### SUBSYSTEMS_OFF_LIMITS
- Calcul de prix final (cf B4)
- Wizard UX (cf A6/C5)

## Invariants at Risk
- [x] Backend pricing SSOT
- [x] OrderService / FrontendOrderService symmetry
- [x] branch_id data isolation

## Fichiers à lire
1. `app/Models/Item*.php` (toutes les classes d'options)
2. Migrations `database/migrations/*variations*`, `*addons*`, `*extras*`
3. FormRequests option CRUD
4. `docs/BUSINESS_RULES.md` section menu

## Grep patterns

```
grep -rn "class Item\(Variation\|Addon\|Extra\|Option\)" app/Models/
grep -rn "min_selection\|max_selection\|is_required" app/Models/ database/migrations/
grep -rn "price" app/Models/ItemVariation.php app/Models/ItemAddon.php
grep -rn "stock\|available\|is_86" app/Models/Item*
grep -rn "default_selected\|is_default" app/Models/Item*
```

## Evidence required
- Tableau de modélisation (table × colonnes × règles).
- Exemple de calcul de prix item+variation+addons expliqué.
- Règles min/max appliquées en FormRequest (extrait code).
- État du stock par option.

## Grille de verdict
- **PASS** : 3 axes clairement séparés, prix cumulatif logique, règles min/max serveur, stock géré, historique préservé.
- **WARN** : modélisation unifiée mais règles claires via type column ; stock par option absent mais acceptable V1.
- **BLOCKED** : confusion variation/addon, prix non-cumulatif cassé, validation min/max côté client seulement.

## Livrable
`reports/review/AUDIT_MENU_VARIATIONS_ADDONS_EXTRAS_013_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed
