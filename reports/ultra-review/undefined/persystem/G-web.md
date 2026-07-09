# Ultra-review système G — WEB storefront (working-tree AS-IS)

HEAD `61e9ea7b7` · date 2026-07-02 · verdict **GREEN**

## Périmètre
Storefront client (`resources/js/components/frontend/**` hors kiosk), vitrine désactivée
client-side, guards `/api/frontend/order/*`, delivery fee, guest OTP, SSOT prix commande web.

## Invariants confirmés (verify-before-report)

1. **Vitrine désactivée client-side** — `config/features.php:50` `staff_only_mode` défaut
   **true** (fail-secure, lue via `config()` → survit `config:cache`). Injectée
   `resources/views/master.blade.php:222` `staffOnlyMode: @json(config('features.staff_only_mode'))`.
   Garde Vue Router `resources/js/router/index.js:241-245` : toute route `meta.isFrontend===true`
   hors kiosk et hors allow-list (`:61-69` = auth only) → redirect `/login` (anon) ou
   `/admin/dashboard`. `routes/web.php:43` `Route::redirect('/', '/login')`. Aucune route
   vitrine (menu/checkout/offers) dans l'allow-list.

2. **Guard `/api/frontend/order` = auth + ability + KIOSK=machine** — route `frontend.order.store`
   sous `auth:sanctum` (`routes/api.php:1334,1341`). `OrderRequest::authorize()` (`:37-84`)
   exige `tokenCan('kiosk:order')`, avec fallback tokenless durci exigeant route
   `frontend.order.*` (`:77-80`). Guard **KIOSK=machine** présent working-tree
   `OrderRequest.php:205-208` : `order_type===KIOSK && !isKioskOrderToken` → 422. Live :
   POST sans token → **401** (vérifié curl).

3. **Web guest → PricingService SSOT (pas de total client)** — `config/pricing.php:9`
   `use_ssot_service` défaut **true** → `FrontendOrderService.php:301-317`
   `pricingService->calculateOrder(...)` fixe `$realSubtotal/$totalTax/$discount` serveur.
   `subtotal`/`total`/`discount` client sont `nullable` et ré-écrits
   (`OrderRequest.php:150,162` ; total recalc `FrontendOrderService.php:546-548`).
   Prix toujours DB (`:379` `$itemPrice = $dbItem->price`), items/variations/extras
   introuvables ou cross-item → rejet 422 (`:372-431`).

4. **Delivery fee 4€ + offerte ≥30€** — `DeliveryFeeService.php:38-50` formule branche
   `base + per_km*ceil(d-free_km)` (base owner 4€, seedée `DeliveryConfigSeeder`), fallback
   legacy `max(5, ceil(d/5)*5)` si colonnes NULL. Livraison OFFERTE
   `FrontendOrderService.php:538-543` : `free_delivery_above` défaut 30 comparé au
   **subtotal SSOT serveur** (`$realSubtotal`, non-falsifiable), delivery_charge→0. Chemin
   fee distance client (`OrderRequest.php:117-129`) non exploitable : `address_id` requis en
   DELIVERY (`:165`) force le devis serveur géocodé (`DeliveryQuoteService::quoteForSavedAddress`,
   ownership `user_id`).

5. **Guest OTP** — `GuestSignupController.php` : token scoped `['kiosk:order']` + expiry 30j
   (`:146`), refus compte privilégié (`:102-105`), restauration soft-delete (`:108-112`),
   OTP-bypass si `site_phone_verification=DISABLE` = by-design (garde-fou connu). Live :
   phone_verif=ENABLE, guest_login=YES.

6. **Isolation multi-utilisateur web** (post-wireup, même branch_id) — index `myOrder`
   filtre `user_id=auth()->id()` (`FrontendOrderService.php:100`) ; `show()` ownership
   `user_id===Auth::id()` sinon deny (`:710-713`) ; idempotency recovery user-scoped
   (`:737`). Un guest branch_id=0 ne fuit pas cross-user malgré bypass BranchScope.

## Note mineure (non-finding, pré-existant, non lié aux heals)
`FrontendOrderService::show()` : `abort(403)` (`:713`) est capturé par le `catch (Exception)`
englobant et re-jeté en 422 (`:716`). L'accès reste **refusé** (aucune fuite) — simple
incohérence de code HTTP (403→422). P3 cosmétique, hors périmètre heals.

## Conclusion
Système G WEB storefront **VALIDÉ production-perfect V1 LOCAL**. Aucun P0/P1/P2 nouveau.
Guards, SSOT prix, delivery, OTP, isolation confirmés par file:line + live (401/302).
