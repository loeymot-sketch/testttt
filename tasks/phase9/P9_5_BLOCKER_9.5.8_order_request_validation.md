# P9.5 BLOCKER — 9.5.8 `OrderRequest` validation scope gap

## Date

2026-04-18

## Item bloqué

- `9.5.8` — strip prices from kiosk cart outgoing payload

## Ce qui bloque

Le brief demande de retirer du `POST /api/frontend/order` **tout champ monétaire** (`price`, `total`, `subtotal`, `discount`, `tax`, etc.) pour n'envoyer que `ids + quantities + variations + extras + instructions`.

Or, dans l'état actuel du code :

- `resources/js/store/modules/kioskCart.js` construit encore un `orderPayload` racine avec `subtotal`, `discount`, `delivery_charge`, `total` et des montants par ligne.
- `app/Http/Requests/OrderRequest.php` exige toujours `total` via la règle :
  - `'total' => ['required', 'numeric']`

Supprimer les montants racine côté kiosque sans modifier `OrderRequest.php` ferait échouer immédiatement la validation HTTP de `/api/frontend/order`.

## Pourquoi je m'arrête ici

- `app/Http/Requests/OrderRequest.php` n'est **pas** dans le bloc `P9.5 SUBSYSTEMS_TOUCHED`.
- Le message utilisateur interdit explicitement de toucher quoi que ce soit hors du périmètre P9.5 autorisé.
- Continuer avec une implémentation partielle (suppression des montants ligne uniquement) ne satisferait pas le brief exact ni le grep/assert demandé.

## Ce qu'il faut pour débloquer

Choisir l'une des deux options :

1. **Étendre le scope P9.5** pour autoriser une modification additive de `app/Http/Requests/OrderRequest.php` afin que le payload kiosque sans montants racine soit accepté.
2. **Réviser l'exigence 9.5.8** pour ne supprimer que les champs monétaires au niveau des lignes `items[]`, tout en conservant provisoirement les montants racine exigés par la validation backend actuelle.

## Evidence minimale

- `resources/js/store/modules/kioskCart.js` — bloc `submitOrder()` construit encore `subtotal`, `discount`, `delivery_charge`, `total`, `item_price`, `total_price`, `item_variation_total`, `item_extra_total`.
- `app/Http/Requests/OrderRequest.php` — `rules()` requiert `total`.

## Résolution — 2026-04-18

**Option 1 retenue** (extension de scope additive). Le PLAN P9.5 `SUBSYSTEMS_TOUCHED` inclut désormais `app/Http/Requests/OrderRequest.php` avec contrainte explicite : modification **additive uniquement** — les champs monétaires passent en `nullable` / `sometimes`, et le serveur recompute via `PricingService` SSOT. C'est le même pattern que `PosOrderRequest` post POS-9.1.8 (`d2dbc7392`).

Pas de modification du cœur `PricingService` (frozen). Pas de modification des règles non-monétaires (items, variations, extras) ni du contrat d'authentification. Un test Feature doit prouver que le payload strict IDs-only est accepté et que `order.total` est recomputé côté serveur de façon cohérente avec les prix en base.

**Status** : RESOLVED (scope étendu, reprise de l'implémenteur autorisée).
