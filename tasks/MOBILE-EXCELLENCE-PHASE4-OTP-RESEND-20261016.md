# Task — MOBILE-EXCELLENCE-PHASE4-OTP-RESEND-20261016

## Demande

Rendre le parcours OTP mobile testable et honnête : le renvoi de code doit appeler
le backend réel avec un cooldown, le code local `dev_code` doit être utilisé uniquement
pour les tests locaux, et aucun code OTP inventé ne doit rester dans le helper E2E.

## Périmètre borné

- `mobile/screens-onboarding.jsx`
- `mobile/index.html`
- `tests/mobile-e2e/utils/realAuth.js`
- `mobile/tests/mobile-otp.spec.js`
- `tests/mobile-e2e/auth-otp-local.spec.js`
- `reports/execution/MOBILE-EXCELLENCE-PHASE4-OTP-RESEND-20261016/report.md`

## Hors périmètre / escalades

- Aucun changement du contrôleur OTP, du stockage des OTP, de la sécurité serveur,
  des secrets ou de la configuration d’un fournisseur SMS.
- La livraison réelle des SMS reste une dépendance de configuration opérateur et sera
  explicitement vérifiée séparément avant production.
- Aucun changement catalogue, prix, commande, fidélité, Mollie ou packaging iOS.

## Invariants

- Le code OTP n’est jamais deviné, hardcodé ou affiché hors environnement local.
- `dev_code` n’est consommé par le helper que lorsque l’API locale le fournit ; staging
  et production doivent continuer à exiger le code reçu par le canal réel.
- Le renvoi réutilise `guest-signup/otp`, conserve le téléphone courant et respecte un
  cooldown côté interface.
- Les erreurs réseau, HTTP 429 et code invalide restent compréhensibles en français.
- Aucun token, numéro ou OTP ne doit être journalisé.

## Validation prévue

- Tests Node statiques du contrat OTP.
- Parcours Playwright local login → envoi OTP → renvoi cooldown → utilisation du `dev_code`
  → vérification, sans écrire de données catalogue ou de paiement.
- `php artisan test --filter=DevOtpExposureTest` pour conserver le garde-fou local-only du
  `dev_code` côté serveur.
- `git diff --check` et garde-fous de portée.

## Plan review

PLAN_REVIEW_CHANNEL: foodking-complex-implementer (codex-extension-fallback)
PLAN_REVIEW_MODEL: gpt-5.5-pro
PLAN_REVIEW_REASONING_EFFORT: xhigh
PLAN_REVIEW_VERDICT: PASS

REMEDIATION_AUDIT_CYCLE: 1 — audit requested stale `dev_code` clearing before closure.
