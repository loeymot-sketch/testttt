# ⚠️ CORRECTIF (fait autorité) — La CAISSE paie INLINE, elle ne va PAS dans « à encaisser »

> Ce correctif **remplace** toute instruction antérieure demandant `POS_WALKIN_ROUTE_TO_COUNTER=true`
> ou décrivant les commandes CAISSE comme apparaissant dans `/admin/encaissement`. C'était une erreur
> de ma part (unification mal interprétée). Comportement CORRECT ci-dessous.

## Le bug (constaté par l'owner)
Une commande prise à la **caisse** apparaissait dans « à encaisser » (comme une borne), alors qu'une
commande caisse doit être **déjà encaissée** (payée sur place, inline). Cause : `POS_WALKIN_ROUTE_TO_COUNTER=true`
forçait `OrderService::posOrderStore` à mettre CHAQUE vente caisse en `COUNTER_DEFERRED` + `PENDING_COUNTER`.

## Le correctif
```env
POS_WALKIN_ROUTE_TO_COUNTER=false   # défaut ; caisse = paiement INLINE (déjà encaissée)
KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER=true   # inchangé : la BORNE, elle, va bien en « à encaisser » (Plan B)
```
Puis `php artisan config:clear && php artisan config:cache`.

## Comportement CORRECT (prouvé en local, 02-07)
- **CAISSE** : prise de commande → paiement **inline** (espèces/carte, PaymentComponent) → `payment_status=PAID`
  + `fiscal_sequence_no` alloué immédiatement → **N'apparaît PAS dans « à encaisser »**. (Preuve : commande
  caisse #5405 espèces → PAID, fiscal #2593, absente de la file.)
- **BORNE** : Plan B → `PENDING_COUNTER` → **apparaît dans `/admin/encaissement`** → encaissée via le modal
  (pavé numérique, espèces/carte). (Preuve : borne #5407 → bien dans la file.)
- Les deux flags sont **indépendants** : `POS_WALKIN` = caisse ; `KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER` = borne.

## Ticket (client + cuisine) — doit s'imprimer COMME à l'écran
- Le ticket imprimé passe par le **renderer ESC/POS serveur** (le MÊME contenu que l'aperçu écran), envoyé
  au **pont local `127.0.0.1:9100`**. Vérifié (rendu octets) :
  - **CLIENT** : nom resto + adresse + « À EMPORTER » + `QT ARTICLES MONTANT` + produits + compo + TVA + total.
  - **CUISINE** : symbolique `G | TACOS | L | Cordon P | BL` + suppléments + `MENU`, **sans prix**.
- Si le ticket physique sort **illisible / en HTML** = le pont était injoignable (Chrome est retombé sur
  `--kiosk-printing`). Vérifier `http://127.0.0.1:9100/health` = UP + que le node bridge tourne.

## Ce qui reste à jour dans les missions
- Garder `KIOSK_PAYMENT_ROUTE_ALL_TO_COUNTER=true` (borne). **Mettre `POS_WALKIN_ROUTE_TO_COUNTER=false`.**
- Ignorer, dans les missions antérieures, les phrases « la caisse apparaît dans /admin/encaissement » et
  le test « prendre une commande caisse → doit apparaître dans à-encaisser » : FAUX désormais. La caisse
  est payée inline. Seule la borne alimente « à encaisser ».
