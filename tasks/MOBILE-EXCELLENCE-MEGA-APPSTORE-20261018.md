# Mission — Méga correction App mobile Le Cayenne

TASK_ID: `MOBILE-EXCELLENCE-MEGA-APPSTORE-20261018`

Objectif : rendre le parcours mobile réellement testable et préparer la soumission sans
contourner les invariants backend.

## Périmètre d’exécution

- cache-busting des scripts mobiles après corrections ;
- affichage des nouveautés et images du wizard sur le bundle réellement chargé ;
- rafraîchissement fidélité après une commande réussie, sans crédit client local ;
- tests E2E order/cart/loyalty et non-régression ;
- audit documenté des promotions, notifications, frais et paiements.

## Hors périmètre sans gate humain

- backend PHP, migrations, prix, frais de livraison et calcul de remise ;
- Mollie/Apple Pay réel, certificats Apple, APNs/FCM et signature iOS ;
- auth/SMS de production, OrderService/FrontendOrderService, POS/KDS/kiosk.

## Invariants

Pricing backend SSOT, pas de prix métier frontend, OrderStatus unique, branch_id isolé,
dispatch après commit, zones frozen inchangées, parité OrderService/FrontendOrderService.

## Phases

1. Baseline et cache-busting déterministe.
2. Correction mobile des nouveautés, images wizard et synchronisation fidélité.
3. Tests statiques + E2E critiques.
4. Rapport App Store avec blockers externes et gates requises.

## Verdict de sortie

Pas de PASS App Store sans 100 % des E2E critiques, paiement réel TestFlight, push réel,
validation backend des promotions/frais et contrôle humain Apple.
