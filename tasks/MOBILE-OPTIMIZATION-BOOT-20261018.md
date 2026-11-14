# Mission — Optimisation démarrage application mobile

TASK_ID: `MOBILE-OPTIMIZATION-BOOT-20261018`

Objectif : réduire le coût de démarrage de l’application mobile sans modifier les prix,
promotions, commandes, fidélité, auth ou paiement.

Périmètre : chargement conditionnel des outils `dev` uniquement avec `?dev`, cache-busting,
tests de démarrage et non-régression E2E.

Hors périmètre : backend, assets propriétaires, Apple Pay, APNs/FCM, migrations, signature iOS.
