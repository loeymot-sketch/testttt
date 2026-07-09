# V4-MAX-AMÉLIORATION — Surface PROMO / COUPON / DISCOUNT

Slug: `v4ma-promo-coupon` — HEAD 61e9ea7b7 + working-tree + 47f3ad545
Posture: refute-by-default. LIVE 127.0.0.1:8766 (foodking_e2e). `manual_discount_enabled=true` (vérifié live → coupons ACTIFS en V1).

## Verdict: IMPROVABLE — 1 P2 + 1 P3 réels, reproduits. Zéro fuite d'argent.

Le chemin monétaire du coupon est SOLIDE (recalcul SSOT backend, plafonné à `min(amount, subtotal)`,
`max(0,total)`, pas de stacking, gate fiscal `assertDiscretionaryDiscountAllowed`). Les 2 findings sont
des divergences fonctionnelles fail-closed / preview-menteur, améliorables.

---

## P2 — Coupons "scopés" (surfaces / branch_scope) DÉFINITIVEMENT non-utilisables au checkout

`app/Services/CouponService.php:377-381` (`resolveCouponById`) + tous les call-sites d'encaissement
`OrderService.php:546,1015,1557`, `FrontendOrderService.php:491,502`, `DiscountCalculator.php:17` —
tous appellent `resolveCouponById($id, $subtotal, $userId)` avec **3 args**, donc `branchId=null, surface=null`.
`Coupon::isUsableNow(null,null)` (`app/Models/Coupon.php:135-148`) renvoie **false** dès qu'un coupon a
`surfaces` OU `branch_scope` non vide (null ≠ "pas de filtre" côté modèle).

Résultat: la feature de scoping avancé livrée [PROMO-DASH-2026-05-06] (surfaces/branch_scope) rend le
coupon **impossible à appliquer** — le code valide en preview puis échoue à chaque commande. Fail-closed
(refuse la remise, aucune perte d'argent) mais casse une feature admin shippée. Le commentaire
`CouponService.php:466-468` documente l'intention INVERSE ("null = no branch/surface filter, backward
compatible") → contradiction implémentation/intention = bug confirmé, pas un choix.

Repro LIVE (tinker READ-ONLY, coupons réels de la DB `ADVWEBONLY` #22 / `ADVKIOSKB1` #21) :
```
21 => REJECTED at order-creation: "not applicable to your branch, surface, or current day/hour"
21 => WITH surface/branch OK, discount=5
22 => REJECTED at order-creation: ...
22 => WITH surface/branch OK, discount=5
```
`resolveCouponById(21,50,1)` → throw ; `resolveCouponById(21,50,1,1,'kiosk')` → OK -5€.

Fix proposé (2 options) :
- (A) plomber le contexte : passer `branch_id` + surface (`web`/`kiosk`/`pos`) à `resolveCouponById`
  aux 6 call-sites d'encaissement (déjà connus : branch de la requête / surface = contexte order).
- (B) aligner `isUsableNow` sur l'intention documentée : `surface===null` / `branchId===null` = "skip ce
  filtre" (ne pas renvoyer false). ⚠️ option B affaiblit l'enforcement — préférer (A).

---

## P3 — Preview promo/coupon "menteur" : /promo/validate ignore status+scope+quotas ; /coupon-checking fait confiance au surface/branch client

`app/Services/Kiosk/KioskPromoService.php:59-87` (fallback coupon global) ne vérifie **ni** `status`,
`surfaces`, `branch_scope`, `limit_per_user`, `max_uses_global`, `isUsableNow` — uniquement dates +
`minimum_order`. Donc un coupon web-only / désactivé (status≠ACTIVE) / plafond global atteint renvoie
`valid:true` + `discount_amount` dans le preview kiosk `POST /api/frontend/promo/validate`.

Repro LIVE (tinker) — `ADVWEBONLY` (surfaces=["web"]) validé depuis contexte KIOSK branch 1 :
```
web-only coupon via kiosk-branch preview: valid=true disc=5 source=coupon
```
Le kiosk affiche « code accepté, -5€ » puis la commande ne reçoit AUCUNE remise (rejet P2). Divergence
preview↔order = UX trompeuse.

Aggravant côté web : `app/Http/Requests/CouponCheckRequest.php:33-34` laisse le **client** fournir
`surface` et `branch_id` → le scope surface/branche est contournable au niveau preview `/coupon-checking`
(un client web envoie `surface=pos` pour un coupon pos-only). Sans impact monétaire (le chemin order est
fail-closed, cf. P2) mais confirme que le preview n'est pas une source de vérité de scope.

Fix proposé : faire passer le fallback de `KioskPromoService::validate` par
`CouponService::validateCouponForOrder($coupon, $cartTotal, $userId, $branchId, $surface)` (déjà
existant, applique status+scope+quotas), et dériver `surface`/`branch_id` du contexte serveur (kiosk
machine / token) plutôt que du body client dans `CouponCheckRequest`.

---

## Attaques réfutées (le chemin monétaire est sûr)

- Total négatif via discount>total : NON — `calculateDiscountAmount` = `round(max(0, min(amount, subtotal)),2)`
  et `PricingService.php:355` = `max(0.0, rawTotal)`. Reproduit: discount plafonné au subtotal.
- Pourcentage >100 : bloqué à la création (`CouponRequest.php:88`), et de toute façon plafonné à subtotal au calcul.
- Stacking coupon+loyalty : NON — `DiscountCalculator::kioskLoyaltyRedemption` renvoie 0 si coupon présent ;
  `applyKioskLoyaltyDiscount` skip si `coupon_id>0`. Order ne prend qu'un seul `coupon_id`.
- Discount client-fourni (trust total client) : NON — `unset(total,subtotal,discount)` + recalcul SSOT
  `couponDiscount`→`resolveCouponById` (P0-1 déjà healé). Vérifié.
- Coupon expiré / date : `validateCouponForOrder:429-435` enforce start/end. OK.
- limit_per_user / max_uses_global : comptés sur `order_coupons` (non-atomique, race) = backlog single-box
  documenté, PAS un finding V1.
- RBAC admin coupon : `CouponController` gate `permission:coupons_*` sur index/store/update/toggleStatus/
  destroy/show (lignes 25-29). OK, pas d'escalade.
- Discretionary gate fiscal : `assertDiscretionaryDiscountAllowed` refuse toute remise si
  `pos.manual_discount_enabled!==true`. OK.
