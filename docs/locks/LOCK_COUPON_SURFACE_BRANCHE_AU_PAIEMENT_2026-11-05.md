# LOCK — un code promo limité à une surface ou à une branche est vérifié avec SA surface et SA branche au paiement

**Fichier gelé visé** : `app/Services/Pricing/PricingService.php` (CLAUDE.md §7 — SSOT des prix), 2 lignes d'arguments
**Date** : 2026-11-05
**Gate** : décision du propriétaire du 2026-11-05 (audit final avant Apple : « Oui, avec un LOCK et des tests »)
**État** : ✅ autorisé par le propriétaire

## 1. Le défaut (prouvé par deux équipes indépendantes + tinker)
Un code promo dont l'admin a coché une surface (« Web ») ou une branche est accepté par « Appliquer »
(`POST /api/frontend/coupon/coupon-checking`, qui transmet branche + surface) puis **refusé à « Confirmer »** :
422 « This coupon is not applicable to your branch, surface, or current day/hour. »

Chaîne : `FrontendOrderService::myOrderStore` (chemin SSOT, `pricing.use_ssot_service` = vrai) →
`PricingService::calculateOrder` (l.346) → `DiscountCalculator::couponDiscount` →
`CouponService::resolveCouponById($couponId, $subtotal, $userId)` **sans branche ni surface** →
`Coupon::isUsableNow(null, null)` refuse tout code qui porte `surfaces` ou `branch_scope`.
Le chemin historique (hors SSOT, `FrontendOrderService.php:636-646`) les passait déjà
(« [S3 coupon surface/branch enforced-at-commit 2026-07-18] ») : le SSOT avait perdu cette règle.

## 2. Le changement
- `PricingRequest` (hors zone gelée) : paramètre optionnel `couponSurface` (null par défaut), et `forKiosk(…, ?string $couponSurface = null)`.
- `DiscountCalculator::couponDiscount` (hors zone gelée) : `?int $branchId = null, ?string $surface = null` transmis à `resolveCouponById`.
- `PricingService.php` (**zone gelée**, 2 lignes) : passe `$req->branchId > 0 ? $req->branchId : null` et
  `$req->couponSurface ?? (context ∈ {pos, kiosk, web} ? context : null)`.
- `FrontendOrderService` (hors zone gelée) : `forKiosk(…, $isKioskMachineOrder ? 'kiosk' : 'web')`.

Aucun calcul de montant ne change : seule la VALIDATION d'éligibilité du code reçoit enfin la surface et la branche.
Un code sans restriction : `isUsableNow` ignore ces deux arguments → même montant qu'avant (test de non-régression).

## 3. Preuves exigées
- test : commande web avec un code `surfaces=["web"]` → 201, remise appliquée ; le même code limité à `kiosk` → 422 sur le web ;
- test : code sans restriction → montant identique ;
- `FrozenZoneSha256BaselineSentinelTest` : baseline mise à jour dans le MÊME commit que le correctif ;
- `php artisan fiscal:verify-chain --all` → CHAIN OK.

## 4. Retour arrière
`git revert` du commit de correctif (la baseline revient avec lui).
