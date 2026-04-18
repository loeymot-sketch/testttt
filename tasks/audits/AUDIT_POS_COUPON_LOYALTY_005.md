# AUDIT_POS_COUPON_LOYALTY_005 — Coupons & Loyalty points côté POS

## Meta
- **Priority** : P1
- **PRIMARY_MODEL** : Claude
- **TEST_STRATEGY** : `static-inspection`
- **DEPENDS_ON** : AUDIT_POS_ORDER_CREATION_001
- **Estimation** : 0.5 j-h
- **Vague** : A5

## Contexte

Les coupons (code promo, pourcentage, montant fixe, panier min) et loyalty points (ratio €→points, burn/earn) impactent le total. Risques :
- Cumul illégal (plusieurs coupons).
- Bypass des règles panier minimum.
- Double utilisation d'un coupon expiré / usage unique.
- Loyalty crédité sur commande annulée.
- Application côté frontend uniquement (prix non-SSOT).

## Questions d'audit

1. L'application d'un coupon est-elle effectuée côté backend (PricingService / OrderService) et **jamais** côté Vue seul ?
2. Un coupon à usage unique est-il verrouillé transactionnellement (pas de race "double redemption") ?
3. Les règles panier min / limite catégorie / limite client sont-elles validées serveur ?
4. La date de validité coupon est-elle vérifiée avec `now()` serveur et pas `new Date()` client ?
5. Un coupon peut-il être appliqué plus d'une fois par commande ? Cumul de plusieurs coupons interdit ?
6. Les loyalty points sont-ils crédités **après** transition DELIVERED et non à la création ? Sont-ils retirés si CANCELED/RETURNED ?
7. Le taux de conversion (x€ = y points) est-il une config branche ou global ? Trace d'audit ?
8. Le client/manager POS peut-il manuellement appliquer un "discount libre" ? Si oui, contrôle de rôle + log ?
9. Les events `CouponApplied` / `LoyaltyEarned` / `LoyaltyBurned` existent-ils, ou tout est silencieux ?
10. Le ticket POS imprime-t-il : code coupon, montant remise, points gagnés, solde points après commande ?

## Scope

### SUBSYSTEMS_TOUCHED
- `app/Services/CouponService.php` (si existe) ou logique dans `OrderService`
- `app/Services/LoyaltyService.php` ou équivalent
- `app/Models/Coupon.php`, `app/Models/LoyaltyPoint.php` (ou colonnes sur customer)
- `app/Http/Controllers/Admin/Coupon/*`
- `resources/js/components/admin/pos/**/Coupon*.vue`, `*Loyalty*.vue`

### SUBSYSTEMS_OFF_LIMITS
- Kiosk (audit C8)
- Marketing campaigns (hors scope V1)

## Invariants at Risk
- [x] Backend pricing SSOT
- [x] OrderService / FrontendOrderService symmetry (mêmes règles coupon)
- [ ] Dispatch after DB commit (secondaire)

## Fichiers à lire
1. `app/Models/Coupon.php` + migrations associées
2. `app/Services/CouponService.php` (grep pour trouver)
3. `app/Services/OrderService.php` — chercher "coupon", "discount", "loyalty"
4. `app/Models/Customer.php` — colonnes loyalty
5. Vues POS coupon

## Grep patterns

```
grep -rn "coupon\|Coupon" app/Services/ app/Models/ --include="*.php"
grep -rn "loyalty\|Loyalty\|points" app/Services/ app/Models/
grep -rn "discount\|Discount" app/Services/OrderService.php
grep -rn "redeem\|applyCoupon\|earnPoints\|burnPoints" app/
grep -rn "coupon" resources/js/components/admin/pos/
grep -n "usage_limit\|used_count\|per_user" app/Models/Coupon.php
```

## Evidence required
- Tableau des règles coupon codées vs règles `docs/BUSINESS_RULES.md`.
- Preuve du lock transactionnel sur redemption (SELECT FOR UPDATE ou equivalent).
- Vérification que le calcul final de total intègre le coupon côté serveur uniquement.
- Flow loyalty : quand on crédite, quand on annule (sur CANCELED/RETURNED).

## Grille de verdict
- **PASS** : application 100% backend, locks OK, symétrie POS/Kiosk (préparation pour audit C8).
- **WARN** : pas de lock explicite mais transaction DB suffisante OU manque event d'audit loyalty.
- **BLOCKED** : application coupon côté client, double redemption possible, loyalty crédité prématurément.

## Livrable
`reports/review/AUDIT_POS_COUPON_LOYALTY_005_<DATE>.md`

## Status
- [x] Brief rédigé
- [ ] Plan approuvé
- [ ] Audit exécuté
- [ ] Rapport
- [ ] Tasks correctrices
- [ ] Closed
