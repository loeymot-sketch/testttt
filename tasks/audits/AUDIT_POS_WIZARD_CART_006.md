# AUDIT_POS_WIZARD_CART_006 — Wizard panier POS (options / variations / addons)

## Meta
- **Priority** : P1
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : AUDIT_POS_ORDER_CREATION_001
- **Estimation** : 0.75 j-h
- **Vague** : A6

## Contexte

Le wizard POS permet au caissier de composer un item : variation obligatoire, addons min/max, extras, suppléments, instructions libres. L'état doit être sérialisable, réhydratable, et générer un OrderItem fidèle. Risques : divergence prix Vue↔API, règles min/max contournables, options fantômes sorties de stock.

## Questions d'audit

1. Le format de sérialisation du wizard (structure options) est-il commun aux deux surfaces POS et Kiosk ? Ou y a-t-il deux formats divergents ?
2. Les règles min/max addons sont-elles validées **côté serveur** (FormRequest ou service) et pas seulement Vue ?
3. Le prix affiché dans le Vue est-il calculé localement ou via un endpoint `/api/pricing/preview` serveur ?
4. Si l'item est 86 (indisponible) après ouverture du wizard, que se passe-t-il à l'envoi ? Rejet 409 ? Blocage UI préalable ?
5. Les quantités supportent-elles 0.5 (poids) ou seulement entiers ? Cohérent avec `docs/BUSINESS_RULES.md` ?
6. Les instructions libres (textarea) sont-elles nettoyées (strip tags, length max) ?
7. La modification d'un item déjà dans le panier (edit) réutilise-t-elle le même wizard en réhydratation ?
8. Les raccourcis clavier POS (touches rapides) aboutissent-ils au même flow que le wizard complet ?
9. Les "combos" / menus composés (plusieurs items liés) sont-ils modélisés ou bricolés ?
10. Le panier est-il persisté côté serveur (draft order ?) ou seulement client ? Impact reprise de shift.

## Scope

### SUBSYSTEMS_TOUCHED
- `resources/js/components/admin/pos/**/*Wizard*.vue`, `*Cart*.vue`, `*Item*.vue`
- `resources/js/stores/pos*.js` (Pinia/Vuex)
- `app/Http/Requests/*Order*` (validation items[])
- `app/Services/OrderService.php` — ingestion items
- `app/Models/ItemVariation.php`, `ItemAddon.php`, `ItemExtra.php`, `Supplement.php`

### SUBSYSTEMS_OFF_LIMITS
- Kiosk wizard (audit C5)
- Pricing core (audit dédié)

## Invariants at Risk
- [x] Backend pricing SSOT (prix wizard)
- [x] OrderService / FrontendOrderService symmetry (structure items)
- [ ] branch_id (indirect : disponibilité dépend de la branche)

## Fichiers à lire
1. `resources/js/components/admin/pos/**` — chercher Wizard, Cart
2. `app/Http/Requests/` — validations commande POS
3. `app/Services/OrderService.php` — parsing items
4. Modèles `ItemVariation`, `ItemAddon`, `ItemExtra`
5. `docs/BUSINESS_RULES.md` section panier

## Grep patterns

```
grep -rn "Wizard\|wizard" resources/js/components/admin/pos/
grep -rn "addons\|variations\|extras\|supplements" app/Http/Requests/
grep -rn "min_selection\|max_selection\|required" app/Models/Item*
grep -rn "calculatePrice\|computePrice\|localTotal" resources/js/
grep -rn "instructions\|notes" app/Http/Requests/ app/Models/OrderItem.php
grep -rn "combo\|Combo\|menu_set" app/ resources/js/
```

## Evidence required
- Comparaison structure payload items POS vs Kiosk (JSON exemple).
- Extrait FormRequest avec rules items.* (variations, addons, etc.).
- Preuve que le prix envoyé au serveur est **ignoré** (recalculé) ou validé strictement.
- Statut combos (présents / absents / bugués).

## Grille de verdict
- **PASS** : structure items unifiée, validation serveur complète, prix serveur prévaut.
- **WARN** : divergence structure POS vs Kiosk non critique, règles min/max côté Vue seulement.
- **BLOCKED** : prix client accepté sans recalcul, addons fantômes possibles, item 86 accepté.

## Livrable
`reports/review/AUDIT_POS_WIZARD_CART_006_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed
