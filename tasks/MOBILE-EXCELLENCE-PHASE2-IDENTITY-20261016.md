# Mission — MOBILE-EXCELLENCE-PHASE2-IDENTITY-20261016

## Objectif borné
Réaligner l’identité visible de l’application mobile Le Cayenne sur la source canonique locale : vrai logo de marque, numéro de téléphone de la branche et contact appelable.

## Hors périmètre
- Backend, migrations, auth SMS, fidélité serveur, suppression de compte et Mollie.
- Prix, produits, payloads de commande, `OrderStatus`, `branch_id`, dispatch et zones POS/caisse gelées.
- Ajout de boissons ou d’assets de marque non validés.

## Preuves de départ
- Branche DB `id=1` : téléphone canonique `0365678291`.
- `mobile/assets/menu/logo.png` inspecté : ancien logo « Le RotTacos », à ne pas utiliser.
- `public/images/kiosk-attract/logo.webp` inspecté : logo Le Cayenne canonique, source autorisée pour copie locale.
