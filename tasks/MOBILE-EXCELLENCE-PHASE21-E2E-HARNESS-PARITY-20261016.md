# Task — MOBILE-EXCELLENCE-PHASE21-E2E-HARNESS-PARITY-20261016

## Objectif

Remettre le harnais Playwright mobile en correspondance avec l’application Le Cayenne
actuelle afin que les validations visuelles et fonctionnelles ne testent plus un ancien
port, un ancien profil fictif ou une surface kiosk sur le serveur statique mobile.

## Périmètre autorisé

- Normaliser les URLs des vagues mobile A, B, C et D sur le serveur mobile canonique
  (`MOBILE_URL` configurable, défaut `http://127.0.0.1:8087/index.html`).
- Aligner les assertions de la vague D sur le contrat actuel exposé par l’application
  (nom/téléphone provenant du profil, fidélité et historique réels ou fallback local).
- Supprimer le conflit de tracing manuel dans la vague Fluidity ; conserver les mesures
  de performance et les artefacts de capture.
- Retirer la vague E kiosk du testMatch mobile-only ; elle sera exécutée séparément avec
  le serveur Laravel/kiosk adapté, sans la déclarer verte dans ce cycle.
- Ajouter/mettre à jour la preuve de planification et les tests de harnais.

## Hors périmètre

Backend, API, migrations, tarifs, catalogue, images, Mollie, règles fidélité, code produit
mobile, POS, caisse, KDS, kiosk runtime, signing iOS et configuration App Store.

## Stratégie de test

`playwright-critical-flow` sur le projet mobile-only explicitement allowlisté (A, B, C, D,
Fluidity, Surfaces et `tests/mobile-e2e`). Wave E est un scénario kiosk séparé et n'est pas
une preuve de cette application mobile.

## Risques

- Une assertion historique peut encore révéler un vrai drift produit ; elle doit alors être
  documentée comme écart produit, jamais masquée par une valeur fictive.
- La séparation de Wave E réduit le faux positif du harnais mobile mais impose une validation
  kiosk dédiée dans une phase ultérieure.

## Revue du plan

- Première revue fallback : `REWORK` — discovery trop large, contrat d'URL insuffisamment explicite,
  assertions D historiques, reporter Markdown manquant et vocabulaire E2E trop large.
- Corrections appliquées puis seconde revue fallback : `REWORK` ; une dernière revue a confirmé
  `PASS` après la suppression du dernier commentaire D historique.
- `PLAN_REVIEW_CHANNEL: foodking-complex-implementer (codex-extension-fallback)`.
- `FALLBACK_REASON: codex CLI rejected gpt-5.5-pro on the signed-in ChatGPT account after two attempts.`

Fichiers supplémentaires autorisés après revue :

- `tests/e2e/test-e2e-mobile-design-perfect-wave-surfaces.spec.js`
- `tests/mobile-e2e/reporters/antigravity-markdown.js`
