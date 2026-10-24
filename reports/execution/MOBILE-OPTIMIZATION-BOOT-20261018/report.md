# Optimisation boot mobile — 2026-10-18

## Changements

- `data/dev-helpers.js` n’est plus chargé dans le démarrage normal ; il est injecté uniquement
  quand l’URL contient `?dev`.
- Le harnais E2E active explicitement `?dev` lorsqu’il utilise les helpers de seed locaux.
- Le contrôle de fraîcheur des ressources distingue maintenant le boot production du boot dev.

## Mesure locale

- Boot production : 28 ressources, environ 584 Ko transférés.
- Boot dev : 29 ressources, environ 602 Ko transférés.
- Économie observée : environ 17,6 Ko et suppression du runtime de test du boot utilisateur.

## Validation

- `mobile/tests/mobile-preview-freshness.spec.js` : PASS
- `mobile/tests/mobile-ui-excellence.spec.js` : PASS
- Playwright mobile critique : **11/11 PASS**
- Boot production : rendu fonctionnel, `LC.dev` absent.
- Boot `?dev` : rendu fonctionnel, `LC.dev` présent.

## Limites

Cette optimisation ne résout pas les gates externes encore ouvertes : Apple Pay réel,
notifications APNs, promotions backend et assets propriétaires des boissons manquantes.

## Invariants

Prix backend SSOT, OrderStatus, branch_id, dispatch après commit, zones gelées et services
de commande : inchangés.
