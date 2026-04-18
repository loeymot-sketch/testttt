# AUDIT_MENU_TAX_PRICING_CASCADE_014 — TVA & cascade de prix

## Meta
- **Priority** : P0
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : AUDIT_MENU_ITEM_CRUD_011, AUDIT_MENU_VARIATIONS_ADDONS_EXTRAS_013
- **Estimation** : 1 j-h
- **Vague** : B4

## Contexte

La cascade : `item.base_price → + variation_price → + Σ(addon_price) → + Σ(extra_price) → × quantity → + TVA`. La TVA française est multi-taux (sur place 10%, à emporter 5.5%, boissons alcoolisées 20%). Risques majeurs : TVA appliquée au mauvais endroit dans la cascade, arrondis cumulatifs divergents entre POS et Kiosk, TVA non ventilée pour la conformité ticket.

## Questions d'audit

1. La TVA est-elle stockée sur l'item, la catégorie, ou globale par type (food/drink/alcool) ?
2. Le taux TVA dépend-il du mode de consommation (sur place / emporter) ? Si oui, où est-ce décidé dans le flow ?
3. L'arrondi se fait-il ligne par ligne ou sur le total ? Documenté ?
4. La cascade est-elle exprimée dans un seul endroit (PricingService) ou dupliquée entre OrderService et FrontendOrderService ?
5. La TVA cascade-t-elle correctement aux addons/extras ou reste-t-elle calculée sur le base_price uniquement ?
6. Les promotions / coupons s'appliquent-ils TTC ou HT ? Conséquence fiscale claire ?
7. Les prix affichés kiosk/POS sont-ils TTC (usage B2C) partout ?
8. Un test de parité POS/Kiosk (X fixtures de paniers) existe-t-il pour le total final ?
9. Les changements de taux TVA (ex future réforme) nécessiteraient-ils un code change ou juste une config ?
10. L'audit ticket présente-t-il la TVA ventilée par taux (obligation légale) ?

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Services/OrderService.php` — calcul total
- `app/Services/FrontendOrderService.php` — calcul total
- `app/Services/Pricing/PricingService.php` (si créé V1)
- `app/Models/Item.php`, `Tax.php` (si existe), `Category.php`
- `config/pricing.php` (si existe)

### SUBSYSTEMS_OFF_LIMITS
- UI affichage (autres audits)

## Invariants at Risk
- [x] **Backend pricing SSOT** (central)
- [x] OrderService / FrontendOrderService symmetry
- [x] branch_id data isolation (taux par branche pays)

## Fichiers à lire
1. `app/Services/OrderService.php` — section calcul
2. `app/Services/FrontendOrderService.php` — section calcul
3. `app/Services/Pricing/*` (si existe)
4. `app/Models/Tax.php` ou `app/Enums/TaxRate.php`
5. `docs/BUSINESS_RULES.md` section pricing / TVA
6. Tests parité (`tests/*Pricing*`)

## Grep patterns

```
grep -rn "tax\|Tax\|tva\|TVA" app/Services/ app/Models/
grep -rn "round\|number_format\|bcadd\|bcmul" app/Services/OrderService.php app/Services/FrontendOrderService.php
grep -rn "sur_place\|emporter\|takeaway\|dine_in" app/ config/
grep -rn "tax_included\|tax_inclusive\|ttc\|ht" app/
grep -rn "PricingService\|PricingRequest" app/
```

## Evidence required
- Schéma textuel de la cascade avec exemple chiffré (item €10 + variation +€2 + addon +€1, ×2, TVA 10% → total attendu).
- Comparaison code OrderService vs FrontendOrderService (signaler divergences).
- Emplacement du taux TVA (DB / config / enum).
- Présence d'un test de parité bit-à-bit.

## Grille de verdict
- **PASS** : cascade unifiée (PricingService ou code identique prouvé), arrondi documenté, TVA config, test parité vert.
- **WARN** : cascade dupliquée mais équivalente, test parité absent.
- **BLOCKED** : divergence calcul POS vs Kiosk observée, TVA hardcodée, arrondis incohérents.

## Livrable
`reports/review/AUDIT_MENU_TAX_PRICING_CASCADE_014_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed
