# Task — MOBILE-EXCELLENCE-PHASE5-LOYALTY-REACTIVATION-20261016

## Objet

Préparer la correction du parcours fidélité mobile : désactivation, réactivation
dans les 30 jours, cohérence du solde et persistance serveur.

## Constat vérifié

- L’app mobile désactive aujourd’hui la fidélité uniquement dans `localStorage`.
- Le bouton « Réactiver mon compte » modifie uniquement `window.LC.dev` ; il
  n’appelle aucune API.
- Le backend expose `POST /api/frontend/loyalty/opt-in`, mais aucune route
  d’opt-out ou de réactivation avec fenêtre de 30 jours.
- `loyalty_consents` est un journal RGPD sans état courant ni échéance de
  réactivation.
- Le texte UI actuel promet un solde remis à zéro, tandis que la demande métier
  ne précise pas si les anciens points doivent être restaurés.

## Gate humain requis

Avant toute modification de contrôleur, service, modèle ou migration, confirmer :

1. Après réactivation sous 30 jours, le solde revient-il à **0** ou les anciens
   points sont-ils **restaurés** ?
2. L’opt-out doit-il être persistant côté serveur sur le compte connecté, avec
   suppression/masquage immédiat du solde et historique ?
3. La fenêtre de 30 jours commence-t-elle à la date de désactivation et expire-t-elle
   définitivement ensuite ?

## Hors périmètre tant que le gate n’est pas approuvé

- Aucune migration.
- Aucun changement backend de fidélité/RGPD.
- Aucun changement de logique de points.
- Aucun changement de paiement Mollie.

## Validation future après décision

- Tests Feature du contrat serveur et de l’autorisation par utilisateur.
- Tests de frontière temporelle : 29 jours, 30 jours, 30 jours + 1 seconde.
- Test d’idempotence et reprise après perte réseau.
- E2E mobile : opt-out → fermeture/réouverture → réactivation autorisée ou
  refusée selon contrat → solde affiché conformément à la décision.
- Vérification d’isolation utilisateur/branche et absence de fuite du solde.
