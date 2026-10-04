# C2 — Écart de prix sur les suppléments : mesure sur la production

Agent C2 · 2026-09-05 · production en **lecture seule**. Aucun fichier modifié hors ce rapport, aucune
zone gelée touchée. **Signalement** : « au paiement ça affiche un prix ; à l'impression du ticket un autre ;
[…] **ça ne prend pas les suppléments** ».

---

## § Mesure sur commandes réelles

### Réponse directe : les suppléments SONT facturés

Instrument : `JSON_TABLE(order_items.item_extras)` → `item_extras`, recalcul de la somme des suppléments
au prix catalogue vs le montant enregistré (`order_items.item_extra_total`). Fenêtre **2026-08-01 →
2026-09-04**, hors Uber :

| lignes avec supplément payant | € de suppléments facturés | € manquants |
|---|---|---|
| **224** | **289,50 €** | **0,00 €** |

Le compteur brut affiche `-10,00 €`, c'est-à-dire **10 € facturés EN PLUS** de mon recalcul — jamais en moins.
Ces 10 € sont 4 lignes × 2,50 € (`order_items` 660, 851, 913, 1783) portant l'extra **#487 « Viande
supplémentaire »**, aujourd'hui **supprimé en dur** du catalogue (`SELECT * FROM item_extras WHERE id=487`
ne renvoie **aucune ligne**). Mon recalcul comptait donc 0 pour lui. La preuve que la facturation était juste
est dans le snapshot figé de la ligne 660 :

```json
{"extra_id": 487, "quantity": 1, "extra_name": "Viande supplémentaire", "line_total": 2.5, "unit_price": 2.5}
```

**Aucune sous-facturation de supplément sur 35 jours. Le signalement, pris au pied de la lettre
(« le total facturé ne contient pas les suppléments »), est RÉFUTÉ.**

### Somme des composants vs total enregistré

`orders.total` vs `Σ order_items.total_price − discount + delivery_charge`, 3 derniers jours
(40 commandes) : **écart nul sur `pos`, `phone`, `kiosk`, `web`**. Seules en écart : **20 commandes
`uber_eats`** (901…963), où `Σ total_price = 0,00 €` pour un `orders.total` de 12,40 € à 56,40 € (§ Défaut 3).

### Prix de base : pas de dérive non plus

`order_items.price` vs `items.price` : 17 lignes `phone` + 11 `pos` à −1,00 € — **toutes antérieures au
2026-08-24 21:29:33**, sur le seul « Tacos L », passé à 8,90 € le **2026-08-25 03:51:49**
(`items.updated_at`). Revalorisation catalogue, pas un défaut.

---

## § composition_snapshot

La colonne existe (`json`). **Aucune ligne NULL** sur les 3 derniers jours (173 lignes, toutes surfaces).
Elle contient les suppléments choisis avec leur prix figé :

- ligne 2168 (cmd 921, kiosk) : `[{"extra_id":571,"extra_name":"Sauce supplémentaire","unit_price":0.5,"line_total":0.5}, … 562 Raclette 0.9, 564 Œuf 0.9, 565 Légumes sautés 0.9, 569 Viande supplémentaire 2.5]` → `item_extra_total = 5,70 €` ✔
- ligne 2184 (cmd 929, **phone**) : `Sauce supplémentaire 0,5 + Œuf 0,9` → `1,40 €` ✔
- ligne 2244 (cmd 955, web) : `Sauce supplémentaire 0,5 + Boursin 0,9` → `1,40 €` ✔

Le snapshot est fidèle et scellé (cas #487 ci-dessus : il survit à la suppression du catalogue). **Invariant NF525 respecté.**

---

## § Le chemin de calcul — et les 52 extras `group_label` NULL

`app/Services/Pricing/PricingService.php:135` — le prix de base vient **de la base**, jamais du payload :
`$itemPrice = (float) $dbItem->price;`. Le `convert_price` calculé côté caisse
(`ItemComponent.vue:1672-1674`) est donc **ignoré** pour la facturation.

`:167-192` — chaque extra est relu en base, contrôlé (appartenance à l'article, `:183`), puis
`$extraTotal += (float) $dbExt->price * $extraQuantity` (`:190`). `:234` :
`$unitSum = $itemPrice + $variationTotal + $extraTotal + $addonTotal`.

**Question décisive — un extra `group_label` NULL est-il facturé ou ignoré ?**
`PricingService.php:768-786` (`choixCouvertParLeCatalogue`) : `$groupe = 'default'` si `group_label` est vide.
Aucune étape publiée ne décrit ce groupe → `return true` → **accepté et facturé au prix catalogue**. Une
étape le décrit sans le lister → **rejet 422, vente bloquée**. **Aucun chemin ne l'ignore silencieusement :
il est facturé, ou la commande est refusée.**

**Le cadrage « 52 extras à 1,00 € non facturés » est réfuté.** Ces 52 extras appartiennent aux articles
1, 2, 4-11 — eux-mêmes des **produits-suppléments** (« Menu (Frites + Boisson) », « Frites Seules »,
« Boursin », « Œuf »…) — et **aucun n'a de profil composeur publié**. Requête sur tout l'historique :
**0 ligne, 0 commande** n'a jamais porté un extra `group_label` NULL. Catalogue mort, pas une fuite.

---

## § La commande par téléphone

**Il n'existe pas de chemin « téléphone » séparé.** Même route, même contrôleur, même FormRequest, même
service, même builder JS que la caisse. Seul delta — `app/Services/OrderService.php:1273` :

```php
$this->order->source_surface = $request->boolean('phone_order') ? 'phone' : 'pos';
```

Payload identique bit à bit (`PosComponent.vue:6112` pour les deux flux). Aucun élagage par
`validated()` possible : `items` est une **chaîne JSON** validée en bloc (`PosOrderRequest.php:159`) et
relue en brut (`OrderService.php:889`). **Hypothèse réfutée** ; la mesure le confirme : 0 écart sur
874 lignes `phone`.

---

## § Où le montant diverge (file:line)

### La cause racine, en une ligne

`public/js/pos-wizard.js:4594` (**zone gelée, lu seulement**) écrit sur la ligne « produit ajouté » :
`item_extra_total: 0` — le montant de la formule n'existe QUE dans `total_price`.
**Toute formule `convert_price + item_variation_total + item_extra_total` perd donc l'addon.**
Cette formule est écrite **11 fois** dans le dépôt.

### Recensement des surfaces

| Surface | Fichier:ligne | Verdict |
|---|---|---|
| **Ticket client imprimé (ESC/POS serveur)** | `OrderReceiptEscPosRenderer.php:186,197,228` (`$order->subtotal`/`->total`), `:581,603` (`$oi->total_price`), `:842` (TVA) | **LIT LA BASE** ✔ |
| Reçu écran caisse · encaissement d'une commande existante · suivi caisse | `ReceiptComponent.vue:140,232` ; `PosCounterCollectModal.vue:336` ; `PosRefundModal.vue:255` ; `PosOrdersTrackerComponent.vue:456` | **LIT LA BASE** ✔ |
| API de sérialisation | `OrderItemResource.php:47-49`, `OrderDetailsResource.php:47-60,324`, `SimpleOrderResource.php:46` | **LIT LA BASE** ✔ |
| Ticket cuisine · KDS · écran client (CDS) · OSS | `OrderReceiptEscPosRenderer.php:283` (« NO prices ») ; `KDSOrderItemsResource.php`, `CDSOrderDetailsResource.php` : 0 champ prix | Aucun montant ✔ |
| **Montant encaissé** | `PaymentComponent.vue:796-803` : total affiché **écrasé par le devis serveur** (`quote.total_ttc`) ; `:942` retire `total/subtotal/discount` du payload | **SSOT serveur** ✔ |
| **CTA « Encaisser X € » (AVANT le devis)** | `PosComponent.vue:3315` (`grandTotal`) ← `posCart.js:487` ← `posCartLineMath.js:44` ← `:12` (`rowUnitMain`) | **RECALCULE** ⛔ |
| Total provisoire du wizard caisse | `pos-wizard.js:1501` (`calculateRunningTotal`, formule maison), `:4680` (montant **lu en scrapant le texte du bouton DOM**) | **RECALCULE** ⛔ |
| **Ticket borne — repli navigateur** | `kioskPrinter.js:482-484` | **RECALCULE** ⛔ |
| Panier borne (5 occurrences) | `kioskCart.js:251-256`, `KioskCartComponent.vue:186` | **RECALCULE** ⛔ |
| Paniers web / table | `frontendCart.js:146`, `tableCart.js:144` (ligne identique) | **RECALCULE** ⛔ |
| Clés API exposant les composants | `OrderItemResource.php:38-39` (`item_extra_currency_total`) | **0 consommateur** (grep = 0) — arme chargée |

### Défaut 1 — le ticket borne (repli navigateur) sous-affiche chaque ligne

`kioskPrinter.js:482-484` :
```js
unitPrice: (parseFloat(item.convert_price) || 0)
         + (parseFloat(item.item_variation_total) || 0)
         + (parseFloat(item.item_extra_total) || 0),
```
Le pied du ticket est, lui, autoritatif (`KioskConfirmationComponent.vue:222-236`).
**Les lignes imprimées ne totalisent donc pas le total imprimé.**

Résidu mesuré `total_price − (price + variation + extra) × quantité`, 35 jours :

| surface | lignes | lignes en résidu | € masqués | pire ligne |
|---|---|---|---|---|
| kiosk | 181 | **114** | **267,80 €** | 5,00 € |
| web | 47 | **24** | **53,40 €** | 2,50 € |
| pos / phone / uber | 1 576 | **0** | 0,00 € | — |

**Reproduction** : borne → Tacos M (art. 22, 7,40 €) + formule « Menu (Frites + Boisson) » (2,50 €).
Base : `order_items` 2237/2238 (cmd 953) → `price = 7,40`, `item_extra_total = 0,00`, `total_price = 9,90`.
Ticket de repli : ligne **« 7,40 € »**, pied **« 9,90 € »**. Attendu : ligne « 9,90 € ».
Atténuation : le chemin nominal est le renderer **serveur** (`KioskConfirmationComponent.vue:419`,
`printServerTicketsViaBridge`), qui est juste. Ce repli ne sort qu'en mode dégradé (pont local absent).

### Défaut 2 — prix codés en dur dans le wizard caisse (piège latent)

`public/js/pos-wizard.js:95` et `:193` (zone gelée, lu seulement) :
```js
var SAUCE_EXTRA_PRICE  = typeof _cfg.sauceExtraPrice  === 'number' ? _cfg.sauceExtraPrice  : 0.50;
var VIANDE_SUPPL_PRICE = typeof _cfg.viandeSupplPrice === 'number' ? _cfg.viandeSupplPrice : 2.50;
```
Vérifié en base **aujourd'hui** : « Sauce supplémentaire » = 0,50 € sur **45 articles**, « Viande
supplémentaire » = 2,50 € sur **28 articles** → **les constantes coïncident, aucun écart actuel**. Mais le
jour où ce prix change dans l'admin, la caisse AFFICHERA l'ancien pendant que le serveur FACTURERA le
nouveau. C'est le seul mécanisme identifié capable de produire « ça affiche un prix, ça en encaisse un
autre » à la caisse.

### Défaut 3 — Uber : toutes les lignes sont à 0 € en base

`app/Services/Uber/UberOrderMapper.php:213` pose `'price' => 0` **par construction**. Sur 35 jours :
**407 lignes sur 420** à `price = 0` ET `total_price = 0`, pour **10 551,40 €** de totaux enregistrés.
Le total de la commande est juste, le détail ligne ne l'est pas. Ex. cmd 960 : `total = 56,40 €`, 5 lignes à 0,00 €.

## § Exposition NF525

- **Chaîne de prix intacte.** `PricingService` reste seul maître du montant (`:135`, `:190`, `:234`) ;
  `PaymentComponent.vue:942` retire les totaux client du payload, verrouillé par
  `tests/Feature/Pos/PosOrderRequestNoClientTotalsTest.php`. Le snapshot est figé et complet.
- **Défauts 1 et 2 : exposition d'AFFICHAGE, pas de facturation.** Le montant encaissé et le ticket
  serveur sont justes.
- **Défaut 3 : exposition réelle.** Les lignes Uber à 0 alimentent la ventilation TVA par ligne
  (`OrderDetailsResource.php:324`, `OrderReceiptEscPosRenderer.php:842`) → **base HT nulle par taux** sur
  10 551,40 €. À confirmer par l'agent du canal Uber.

---

## § Correctif scope-minimal proposé

**Défaut 1 — `resources/js/helpers/kioskPrinter.js:482-484` (NON gelé).** Préférer la valeur autoritative,
comme `posCartLineMath.js:32-40` le fait déjà pour les addons :
```js
unitPrice: (item.total_price != null && Number.isFinite(parseFloat(item.total_price)))
  ? parseFloat(item.total_price) / Math.max(1, parseInt(item.quantity, 10) || 1)
  : (parseFloat(item.convert_price) || 0) + (parseFloat(item.item_variation_total) || 0)
    + (parseFloat(item.item_extra_total) || 0),
```
Banc : imprimer « article + formule menu », vérifier `Σ lignes == pied`, puis **restaurer le défaut pour
prouver que le banc mord**. **Zone gelée touchée : AUCUNE.**

**Défaut 2 — piège latent.** Le correctif propre (lire les prix du catalogue au lieu des constantes) touche
`public/js/pos-wizard.js` : **ZONE GELÉE → LOCK + gate propriétaire obligatoires**. Mesure d'attente sans
risque : un test comparant `SAUCE_EXTRA_PRICE` / `VIANDE_SUPPL_PRICE` au catalogue, rouge à la première
divergence.

**Défaut 3 — Uber.** Hors périmètre C2 et **hors scope-minimal** : renseigner `order_items.price` /
`total_price` change la ventilation TVA. **Escalade propriétaire requise.**

**Ne rien corriger sur `PricingService`, `OrderService`, le chemin téléphone, ni les 52 extras
`group_label` NULL** : la mesure prouve qu'ils sont sains.
