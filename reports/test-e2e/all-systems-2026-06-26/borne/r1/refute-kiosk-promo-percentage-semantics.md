# Vérification adversaire — Panier + loyalty + paiement Plan-B (BORNE)

## Finding examiné
[P2 proposé] `app/Services/Kiosk/KioskPromoService.php:82` et `:98` — Mauvaise sémantique `discount_type` :
coupons PERCENTAGE traités comme montant fixe (remise affichée fausse).

## VERDICT : RÉEL — mais reclassé **P3** (pas P2) pour V1-LOCAL · heal SAFE non-frozen

---

### Le bug existe (confirmé code + repro live)
- `DiscountType::PERCENTAGE = 10`, `FIXED = 5` (`app/Enums/DiscountType.php:7-8`).
- `KioskPromoService.php:82` → `$coupon->discount_type == 1 ? 'percent' : 'amount'`
- `KioskPromoService.php:98` → `((int)($coupon->discount_type ?? 0) == 1) ? %  : fixe`
- Le littéral `1` ne matche JAMAIS un vrai `discount_type` → tout coupon PERCENTAGE retombe dans la branche « montant fixe ».

Repro live (foodking_e2e), coupon `WV3PCT1781387121` (`discount_type=10`, `discount=15` → 15%, `maximum_discount=10`, `minimum_order=20`) :

| cart | kiosk `type` | kiosk `discount_amount` | SSOT `calculateDiscountAmount` |
|------|------|------|------|
| 25,0 | amount (faux) | **10,00** | **3,75** |
| 30,0 | amount | 10,00 | 4,50 |
| 50,0 | amount | 10,00 | 7,50 |
| 100,0| amount | 10,00 | 10,00 |

→ La borne affiche -10,00 € (15 traité en 15 € fixe, capé à `maximum_discount`=10) au lieu de -3,75 € (15 % capé à 10 €).

### Reachable (pas du code mort)
`POST /api/frontend/promo/validate` (`routes/api.php:1460`) → `PromoController::check`
(`app/Http/Controllers/Frontend/PromoController.php:45`) → `KioskPromoService::validate`. Endpoint borne live, auth:sanctum.

### Périmètre exact
- Seul le **fallback coupon global** est touché. Le chemin prioritaire `kiosk_promos`
  (`KioskPromo::computeDiscount`, `app/Models/KioskPromo.php:101`, `match($this->type)` sur `'percent'|'amount'`) est **correct**.
- Exposition DB : 1 coupon PERCENTAGE (toujours faux), 2 coupons FIXED (corrects par coïncidence — la branche `1` défaute tout en « fixe »). Croît avec chaque nouveau coupon %.

### POURQUOI PAS P0/P1/P2 — aucun impact argent / NF525 / commande
L'endpoint est **lecture-seule/advisory** (docstring « Aucune persistance », « Le discount n'est JAMAIS définitif »).
À la création de commande, le prix liant est **recalculé via le SSOT** depuis `coupon_id`, PAS depuis le `discount_amount` de la borne :
- `app/Services/FrontendOrderService.php:466-483` → `couponService->resolveCouponById()` + `calculateDiscountAmount()`
- `CouponService::calculateDiscountAmount` (`:399`) utilise correctement `DiscountType::PERCENTAGE`.
→ Le client paie -3,75 € (correct) même si la borne a affiché -10,00 €. Pas de surfacturation, pas de Z faux, pas de fuite, pas de perte de commande.
De plus la borne montre une remise **plus grande** que celle réellement appliquée → 0 surprise-overcharge au paiement (le total payé est le vrai).

Reclassement P2→**P3** : défaut de qualité/cohérence d'aperçu (mismatch preview vs total payé), pas un préjudice client. Le total au paiement est correct ; seul un littéral d'aperçu est faux ; portée limitée aux coupons % + `minimum_order` contraint la reach. Aucun test ne couvre `KioskPromoService` (cohérent avec le slip).

### Lentille
Réimplémentation locale d'une règle SSOT avec une constante magique périmée (jumeau du pattern « ne pas réimplémenter, déléguer au SSOT »).

### Heal (NON-frozen — `KioskPromoService.php` n'est pas frozen ; zone kiosk frozen = .vue uniquement)
Déléguer au SSOT au lieu de réimplémenter :
- `:76` `$discount = $this->computeCouponDiscount(...)` → `app(CouponService::class)->calculateDiscountAmount($coupon, $cartTotal)`
- `:82` `$coupon->discount_type == 1 ? 'percent' : 'amount'` → `$coupon->discount_type == \App\Enums\DiscountType::PERCENTAGE ? 'percent' : 'amount'`
- Supprimer `computeCouponDiscount` (`:93-108`) devenu redondant, OU y remplacer `== 1` par `== DiscountType::PERCENTAGE`.
- TDD : test `KioskPromoService::validate` sur 1 coupon PERCENTAGE (15 % cap 10 € sur cart 25 → 3,75) + 1 FIXED (montant brut capé), assert `type` + `discount_amount` == SSOT.
