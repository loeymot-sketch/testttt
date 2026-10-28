# OTP-DEV-DISPLAY — Plan local de test OTP

TASK_ID: OTP-DEV-DISPLAY
EXECUTION_TIER: routine
TEST_STRATEGY: local-validation + human-verification

## Objectif

Permettre le test local de l'onboarding mobile lorsque le SMS n'est pas configuré, en affichant le `dev_code` effectivement généré par le backend uniquement lorsque `window.LC.isDev` est actif.

## Périmètre autorisé

- `mobile/api/client.js`
- `mobile/index.html`
- `mobile/screens-onboarding.jsx`
- tests ciblés et rapport sous `reports/`

## Invariants

- Aucun code de test affiché sans le flag local `?dev` ou `localStorage.lecayenne.dev=true`.
- Aucun changement de logique de prix, de commande, de branch_id ou d'authentification serveur.
- Le code utilisé est celui renvoyé par le backend, jamais un code fixe frontend.
