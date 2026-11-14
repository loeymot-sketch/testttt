# Plan — MOBILE-EXCELLENCE-PHASE20-ACCOUNT-DELETION-20261016

## Objectif

Rendre le parcours de suppression de compte visible et fonctionnel dans
l’application mobile Le Cayenne, en réutilisant l’endpoint FoodKing déjà
existant (`POST /api/delete-account`). Le parcours doit confirmer l’action,
refuser les faux succès, purger la session locale après succès et revenir à la
connexion.

## Périmètre autorisé

- `mobile/api/client.js`
- `mobile/screens-main.jsx`
- `mobile/screens-modals.jsx`
- `mobile/index.html`
- `mobile/tests/mobile-ui-excellence.spec.js`
- `tests/mobile-e2e/account-deletion-critical.spec.js`

## Hors périmètre

Backend PHP, migrations, modèle utilisateur, fidélité serveur 30 jours,
paiement Mollie, catalogue/prix, assets, POS/kiosk, packaging iOS et signature.

## Invariants

- Le serveur reste l’autorité de la suppression et de l’authentification.
- Aucun prix, `OrderStatus`, `branch_id`, dispatch ou service de commande n’est modifié.
- Aucun succès local ne doit être affiché si la requête serveur échoue.
- La purge locale ne se produit qu’après une réponse serveur réussie.
- L’action reste explicite et réversible uniquement avant confirmation.

## Validation prévue

1. Test statique du contrat API/UI.
2. E2E avec réponse serveur simulée : ouverture du profil, confirmation,
   POST authentifié, purge et retour à la connexion.
3. `git diff --check`.
