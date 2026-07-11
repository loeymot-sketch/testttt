# Audit LOGIQUE — Gestion Catalogue / Stock / Offres / Coupons — FoodKing V1
Date : 2026-07-11 · Auditeur adversaire (lecture seule + tinker rollback) · DB live :8766
Enums clés : `DiscountType` FIXED=5 PERCENTAGE=10 · `Status` ACTIVE=5 INACTIVE=10 (recouvrement d'entiers volontaire, chaque champ lit son propre enum).

Chaque finding est reproduit avec IDs/valeurs réels. Zéro spéculation. `PricingService` non touché (frozen, audit-only).

---

## P1-A — Kiosk : coupon POURCENTAGE prévisualisé comme MONTANT FIXE (mauvais nombre magique)
**Fichier** : `app/Services/Kiosk/KioskPromoService.php:82` et `:98`
**Type** : P1 — incohérence prix affiché client (borne) vs prix réellement encaissé.

`computeCouponDiscount()` et le champ `type` détectent le pourcentage avec `discount_type == 1`.
Or l'enum projet est `DiscountType::PERCENTAGE = 10` (jamais 1). Donc AUCUN coupon n'est
jamais traité comme pourcentage dans le chemin borne : un coupon « 15 % » est prévisualisé
comme une remise FIXE.

**Repro (réel, coupon #15 `WV3PCT1781387121`, type=10/PERCENTAGE, discount=15, maximum_discount=10, min=20)** :
```
Panier 40 € :
  KioskPromoService::validate → type="amount", discount_amount = 10.00   (faux : lu comme fixe 15€, plafonné 10€)
  CouponService::calculateDiscountAmount (SSOT) = 6.00                    (correct : 15% de 40€)
```
La borne annonce **-10 €**, la commande finale (SSOT via `coupon_id → resolveCouponById`)
déduit **-6 €**. Le client voit un montant plus avantageux que ce qu'il obtient.
Impact borné : la commande finale reste correcte (SSOT gagne), donc pas de mauvais encaissement
fiscal — mais divergence d'affichage / rupture de confiance.

**Fix** : remplacer `== 1` par `== \App\Enums\DiscountType::PERCENTAGE` (ligne 82 et 98), ou
mieux, faire déléguer `KioskPromoService` à `CouponService::calculateDiscountAmount()` pour le
fallback coupon (une seule SSOT de calcul).

---

## P1-B — Kiosk : le fallback coupon ignore status / limites / scope (isUsableNow jamais appelé)
**Fichier** : `app/Services/Kiosk/KioskPromoService.php:60-87`
**Type** : P1 — un coupon DÉSACTIVÉ / épuisé / hors-scope est annoncé « valide » sur la borne.

Le chemin fallback ne vérifie QUE `start_date`, `end_date`, `minimum_order`. Il n'appelle pas
`Coupon::isUsableNow()` et ne lit donc pas : `status` (ACTIVE/INACTIVE), `limit_per_user`,
`max_uses_global`, `branch_scope`, `surfaces`, `valid_days_of_week`, `valid_hours_*`.
`CouponService::validateCouponForOrder()` (chemin caisse/web) appelle bien `isUsableNow()` — la
borne est le seul chemin laxiste.

**Repro (coupon INACTIVE créé puis rollback)** :
```
Coupon status=INACTIVE(10), fenêtre de dates courante, panier 30 € :
  KioskPromoService::validate → valid = true, discount_amount = 5   (annoncé valide)
  Coupon::isUsableNow(1,"kiosk") = false                            (gate correct : refusé)
```
Le client saisit un code désactivé/épuisé, la borne dit « valide -5 € », puis la création de
commande (qui repasse par le SSOT) le rejette ou le laisse tomber → incohérence visible.

**Fix** : dans le fallback coupon, appeler `$coupon->isUsableNow($branchId, 'kiosk', $at)` (et
`limit_per_user` si un user est connu) avant de retourner `valid=true`, comme le fait
`CouponService`.

---

## P1-C — Suppression de catégorie : items ACTIFS laissés orphelins (aucun blocage/réaffectation)
**Fichier** : `app/Services/ItemCategoryService.php:170-183`
**Type** : P1 — état incohérent gérant : items actifs pointant vers une catégorie invisible.

```php
$checkItem = $itemCategory->items->whereNull('deleted_at'); // collection d'items actifs
if (!blank($checkItem)) {      // VRAI quand il RESTE des items actifs
    $itemCategory->delete();   // ... on supprime quand même (soft delete, SoftDeletes)
} else { /* FK checks off + delete */ }
```
`ItemCategory` utilise `SoftDeletes`. Supprimer une catégorie qui a encore des items actifs
la masque (`deleted_at`) mais laisse `items.item_category_id` pointer vers elle : items orphelins,
sans réaffectation ni blocage ni désactivation des items. `$item->itemCategory` devient null → la
grille « catégorie d'abord » (POS/borne) et tout accès à `->itemCategory->name` casse ou fait
disparaître l'item de tout onglet.

**Repro (catégorie #1 « Sandwichs », 4 items actifs — rollback)** :
```
destroy(cat#1) → ItemCategory::find(1) = null (soft-deleted)
  item 22 item_category_id=1 status=5(ACTIVE)  → orphelin
  item 36 item_category_id=1 status=10         → orphelin
  item 103 item_category_id=1 status=5(ACTIVE) → orphelin
```

**Fix** : bloquer la suppression si `items()->whereNull('deleted_at')->exists()` (message
« réaffectez/désactivez les N produits d'abord »), OU réaffecter/soft-delete les items en cascade
explicite. La branche actuelle fait l'inverse de l'intention.

---

## P1-D — Un « extra » gratuit (0 €) est impossible à créer/éditer (78 crudités déjà en base bloquées)
**Fichier** : `app/Http/Requests/ItemExtraRequest.php:35` (`'price' => ['required', new IniAmount()]`)
+ `app/Rules/IniAmount.php:35-45` (mode `zero=false` : rejette `<= 0`).
**Type** : P1 — état incohérent gérant : données existantes non ré-enregistrables.

`ItemExtra` (store ET update) valide le prix avec `new IniAmount()` (zero=false) → **0 € refusé**
(« negative amount not allow »). Or 78 des 377 extras en base sont à 0 € — ce sont des choix
gratuits légitimes (Salade #49, Tomate #50, Oignon #51, … crudités). Éditer l'un d'eux (même juste
le nom, le statut ou la visibilité) resoumet `price=0` → **validation rejetée**, l'extra gratuit
devient non-modifiable via le formulaire admin.

Asymétrie confirmée : les **variations** utilisent `new IniAmount(true)` (0 autorisé,
`ItemVariationRequest.php:40`), les **extras** non. Deux règles opposées pour deux choix wizard
comparables.

**Repro** :
```
IniAmount(false).passes('price', 0) = false   (extra : 0€ refusé)
IniAmount(true).passes('price', 0)  = true    (variation : 0€ accepté)
ItemExtra 0€ en base = 78 / 377   (ex : 49 Salade, 50 Tomate, 51 Oignon)
```

**Fix** : passer `ItemExtraRequest` à `new IniAmount(true)` (autoriser 0 pour les extras
gratuits), cohérent avec les variations et avec la présence de 78 extras 0 € en base.

---

## P2 — Observations (impact faible / feature dormante)

- **Offres non câblées au prix** — `OfferService` / `OfferItemService` / modèle `Offer`/`OfferItem` :
  `offer_items` n'est consommé par AUCUN calcul de prix (grep : seulement `OfferController` frontend
  d'affichage + `OfferComponent.vue`). `OfferRequest` nomme pourtant `amount` « discount percentage ».
  Un gérant qui crée une offre « -20 % » n'a AUCUN effet sur le prix borne/web/caisse. En base : 0 offre,
  0 offer_item (feature dormante). `OfferRequest.php:45` borne bien `amount` à `>0` et `max:100` (pas de
  négatif — `IniAmount` bloque). Point : la page « Offres » est décorative, pas fonctionnelle.

- **OfferItemService::store — anti-doublon fragile** (`OfferItemService.php:58-70`) : ne teste que le
  PREMIER `offer_item` du même `item_id` (`->first()`), et la logique de non-chevauchement temporel
  (`prevStart >= newStart && prevStart > newEnd`, etc.) laisse passer des chevauchements avec un 3e offre.
  Sans effet prix (cf. ci-dessus), donc P2.

- **discount_type hors-enum silencieusement traité comme « fixe »** — coupons réels #21 `ADVKIOSKB1`
  et #22 `ADVWEBONLY` ont `discount_type=2` (ni 5 ni 10). `CouponService::calculateDiscountAmount:399`
  et `KioskPromoService:98` tombent tous deux dans la branche « montant fixe » → -2 €… non, -5 € fixe.
  `CouponRequest` impose désormais `Rule::in([5,10])` mais les lignes legacy persistent. Pas de crash,
  fallback silencieux. P2.

- **`AvailabilityService::isAvailable()` (read-helper, ligne 196)** ne lit QUE la ligne branche : il
  ignore `item.status` et `item.is_available` global. Un item globalement INACTIF sans ligne de rupture
  branche renverrait `true`. Le chemin commande `assertItemsOrderableForBranch` (ligne 216) vérifie bien
  status + is_available global + branche, donc les commandes restent sûres ; seul un consommateur read-only
  s'appuyant sur `isAvailable()` seul serait trompé. P2 read-path.

---

## Zones VÉRIFIÉES SAINES (pas de bug)

- **Cascade rupture ingrédient (area #1)** — `IngredientAvailabilityService::toggle` propage par NOM à
  toutes les lignes sœurs (`ItemExtra`/`ItemAttribute` du même nom) → « Sauce ketchup → rupture » impacte
  bien tous les produits. Cohérent. (Réserve mineure : match par nom EXACT ; une variation de casse/espace
  raterait des sœurs — data-quality, pas logique.)
- **Rupture branche vs global (area #1)** — `assertItemsOrderableForBranch` : global indispo OU branche
  rupture → rejet ; la rupture branche gagne. Cohérent.
- **Bornes du discount coupon (area #2/#3)** — `CouponService::calculateDiscountAmount` :
  `round(max(0, min($amount, $subtotal)), 2)` — jamais négatif, jamais > sous-total, cap `maximum_discount`
  appliqué. Dates + `minimum_order` + `limit_per_user` + `max_uses_global` vérifiés (chemin caisse/web).
- **Prix négatifs items/variations** — `IniAmount` bloque `<= 0` (items/extras) et `< 0` (variations).
  Items en base : 0 à prix ≤ 0, 0 à prix null, 0 à tax_id null. Pas d'item cassé.
