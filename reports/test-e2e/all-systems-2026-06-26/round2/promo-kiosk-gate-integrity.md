# Round-2 attack — Promo/remise : intégrité après le gate borne

**Label**: promo-kiosk-gate-integrity
**Surface**: Borne (kiosk) → POST /api/frontend/order — coupon_id / kiosk_promo_code / loyalty
**Heal re-attaqué**: `4fe7c2a7f` (cache le bloc promo borne, `KIOSK_PROMO_ENABLED` défaut false)
**DB**: foodking_e2e (canonique) · `pos.manual_discount_enabled = true` (gate fiscal OUVERT) · `kiosk.promo_enabled = false`
**Verdict**: **HOLD** — la défense contre une remise *non voulue / mal-scopée* tient (prouvée). Résidu by-design documenté + heal de durcissement proposé.

---

## Méthode
Read du chemin coupon order-create (FrontendOrderService → PricingService → DiscountCalculator → CouponService) + `Coupon::isUsableNow` + KioskPromoService/PromoController, puis preuve tinker READ-ONLY (isUsableNow / resolveCouponById / calculateDiscountAmount = lectures pures, 0 écriture, 0 ordre) contre foodking_e2e.

---

## Sous-attaque (a) — forger `kiosk_promo_code` au POST /order
**HOLD.** `kiosk_promo_code` n'est JAMAIS lu par le chemin order-create. Lecteurs (grep app/) :
- `app/Http/Requests/Kiosk/PricingPreviewRequest.php:66/91` (aperçu)
- `app/Http/Controllers/Frontend/PricingPreviewController.php:55` (aperçu)
- `app/Services/Order/OrderQuoteService.php:496` (devis preview)

`FrontendOrderService::myOrderStore` n'y touche pas → forger `kiosk_promo_code` est **inerte** sur la commande réelle (n'altère ni total ni discount).

## Sous-attaque (a/b) — forger `coupon_id` (coupon SCOPÉ surface/branche)
**HOLD — fail-closed prouvé.** Le chemin order-create appelle le resolver SANS surface/branche :
- `app/Services/Pricing/DiscountCalculator.php:17` → `resolveCouponById($couponId, $subtotal, $customerUserId)` (SSOT, défaut kiosk)
- `app/Services/FrontendOrderService.php:467,478` (legacy + ligne OrderCoupon)
- `app/Services/OrderService.php:546,1015,1548` (POS/admin)

Mais `Coupon::isUsableNow` (`app/Models/Coupon.php:135-148`) est **fail-closed** : si le coupon est scopé et que `branchId`/`surface` sont null, il **rejette** (lignes 137 et 145 : `$surface === null || !in_array(...)` → `return false`). Le commentaire CouponService:467-468 (« null = no filter ») est trompeur : pour un coupon scopé, null = REJET.

Preuve tinker (foodking_e2e), coupon id=22 `ADVWEBONLY` (surfaces=["web"], branch_scope=[1], ACTIF) :
```
isUsableNow(1,"web")   = true   (son vrai canal)
isUsableNow(null,null) = false  (args order-create -> FAIL-CLOSED)
resolveCouponById(22,50,1) -> THROWS "This coupon is not applicable to your branch, surface, or current day/hour."
```
→ Un client borne forgeant un coupon web-only (ou kiosk-only, ou autre branche) est **rejeté (422)**. Aucune fuite cross-surface/cross-branche. Défense solide.

## Sous-attaque (c) — remise loyalty borne réellement inopérante backend ?
**Observation (non-finding).** Elle n'est PAS inopérante : `applyKioskLoyaltyDiscount` (`FrontendOrderService.php:815-912`) tourne côté backend et, comme `pos.manual_discount_enabled = true`, le gate `assertDiscretionaryDiscountAllowed` (`:803-810`) ne bloque pas. Le heal n'a caché que l'UI. MAIS : (1) tout est dans `DB::transaction` (`:177`) → si un throw survient, la déduction de points roll-back (atomique, pas de perte de points) ; (2) la remise est plafonnée par les points du `loyalty_code` ciblé (`DiscountCalculator::kioskLoyaltyRedemption`) ; (3) exige un `loyalty_code` valide. Avec son PROPRE code = rachat légitime (juste UI cachée). Borné, pas de remise « gratuite ». Pas un finding de classe « remise non voulue ».

## Sous-attaque (d) — bug aperçu `computeCouponDiscount` (% traité comme fixe) fuit-il dans un calcul réel ?
**HOLD.** `KioskPromoService::computeCouponDiscount` (`app/Services/Kiosk/KioskPromoService.php:93-104`) teste `discount_type == 1` pour le %, or l'enum réel est `PERCENTAGE=10` / `FIXED=5` → un coupon % est bien rendu comme montant fixe en aperçu (le bug). MAIS unique appelant = `PromoController::check` (`POST /api/frontend/promo/validate`, docblock « validation lecture-seule … La consommation réelle intervient uniquement à la création de commande via FrontendOrderService »). La charge RÉELLE passe par `CouponService::calculateDiscountAmount` (`CouponService.php:397-409`) qui distingue correctement `PercentageType=10`. → Le bug reste en aperçu, ne touche **jamais** le total facturé. (Doublement mort : l'UI borne est cachée par le heal.)

---

## Résidu by-design (durcissement, pas une remise mal-scopée)
Coupons NON-scopés (surfaces=NULL) actifs `9 WVALFIX5 / 14 WV3EUR / 15 WV3PCT` (test-pollution e2e) **résolvent** sur les args kiosk :
```
id=9  isUsableNow(null,null)=true -> couponDiscount(svc,9,50,1)  = 5   EUR
id=14 isUsableNow(null,null)=true -> couponDiscount(svc,14,50,1) = 3   EUR
id=15 isUsableNow(null,null)=true -> couponDiscount(svc,15,50,1) = 7.5 EUR
```
Un coupon NON-scopé est par définition multi-surface ; le rabais qui atterrit est CORRECT (pas mal-scopé). Le heal admet lui-même n'être qu'un gate UI (`config/kiosk.php:57-70` : « the kiosk only sends `kiosk_promo_code` »). Donc l'intention « borne = 0 promo en V1 » est contournable par un POST `coupon_id` direct **uniquement** : (1) en forgeant la requête (l'UI borne ne l'envoie pas → exige l'extraction du token Sanctum de la machine physique mono-poste), (2) si un coupon NON-scopé actif existe. Borné, fiscalement net (post-F1-fix). Aucune remise *mal-scopée* possible (scopés = fail-closed). → severity NONE pour la classe attaquée.

## Heal de durcissement proposé (NON-frozen, defense-in-depth)
Aligner l'API sur l'intention du gate UI : quand `config('kiosk.promo_enabled') !== true` ET le token est une borne (`isKioskOrderToken()`), forcer `coupon_id = 0` + ignorer `loyalty_code`/`discount` dans `OrderRequest::prepareForValidation` (ou en tête de `FrontendOrderService::myOrderStore`). Fichiers non-frozen (`app/Http/Requests/OrderRequest.php`, `app/Services/FrontendOrderService.php`). Réversible (flip `KIOSK_PROMO_ENABLED`). Ferme la couture UI↔API sans toucher POS/checkout/web.

---
**Conclusion**: HOLD. Les 3 vecteurs durs (kiosk_promo_code inerte, coupon scopé fail-closed, bug % preview-only) tiennent avec preuve. Le résidu est un coupon NON-scopé honoré via forge API (by-design multi-surface, borné, token physique requis) — durcissement souhaitable, pas une remise non voulue.
