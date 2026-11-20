# Plan — MOBILE-EXCELLENCE-MEGA-APPSTORE-20261018

TASK_ID: `MOBILE-EXCELLENCE-MEGA-APPSTORE-20261018`
EXECUTION_TIER: `complex`
PRIMARY_EXECUTION_MODEL: `gpt-5.5-pro`
FALLBACK: `foodking-complex-implementer` si le binaire `codex` reste indisponible.

## Fichiers autorisés

`mobile/index.html`, `mobile/screens-main.jsx`, `mobile/screens-item-steps.jsx`,
`mobile/api/client.js`, `mobile/tests/mobile-ui-excellence.spec.js`,
`tests/mobile-e2e/catalog-visual-critical.spec.js`, tests E2E mobiles ciblés,
`tasks/`, `plans/`, `reports/`, `.cursor/ACTIVE_CYCLE.md`.

## Corrections autorisées

- augmenter les versions de query des scripts réellement modifiés pour invalider le cache ;
- rendre les nouveautés dépendantes de la source de catalogue actuelle ;
- conserver les attributs d’accessibilité et data-testid des choix visuels ;
- déclencher une synchronisation profile/history après commande, sans inventer de points ;
- ajouter des tests de régression.

## Interdits

Ne pas appliquer de remise locale, ne pas modifier le total client, ne pas activer Apple Pay,
ne pas simuler push/APNs, ne pas modifier backend ou politique fidélité.

## Tests

`mobile-ui-excellence`, `mobile-catalog-parity`, `ordersParity`, `mobile-identity`,
catalogue visual critical, OTP local, commande/confirmation, loyalty earn/redeem/opt-out.

## Gate

Le rapport doit séparer les corrections locales des dépendances nécessitant credentials,
configuration serveur ou validation TestFlight.
