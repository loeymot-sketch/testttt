# MOBILE-EXCELLENCE-PHASE20-ACCOUNT-DELETION-20261016

## Initialisation

Phase ouverte pour exposer le parcours de suppression de compte déjà supporté
par l’API, avec confirmation et purge locale après succès serveur.

`EXECUTE_DELEGATION: local-complex-execution`
`FALLBACK_REASON: codex-extension/Composer channel unavailable; bounded mobile-only auth surface executed locally after stale reservation cleanup.`
`VALIDATION_VERDICT: PASS (scoped)`
`AUDIT_VERDICT: PASS (scoped, cursor-session fallback)`
`GPT_FINAL_AUDIT_VERDICT: PASS (scoped, cursor-session fallback)`

## Implémentation livrée

- `mobile/api/client.js` expose `deleteAccount()` vers `POST /api/delete-account`, avec gestion explicite de l’authentification et des erreurs 401.
- `mobile/screens-main.jsx` ajoute l’action destructive « Supprimer mon compte » uniquement pour un utilisateur authentifié.
- `mobile/screens-modals.jsx` ajoute une confirmation explicite, un état de chargement et l’affichage de l’erreur sans faux succès.
- `mobile/index.html` n’efface l’état local qu’après une réponse serveur positive, puis revient à la connexion.
- `tests/mobile-e2e/account-deletion-critical.spec.js` couvre le header `Authorization`, l’appel serveur, la purge locale et le retour à la connexion.

## Validation

- `node mobile/tests/mobile-ui-excellence.spec.js` — PASS.
- `node mobile/tests/mobile-catalog-visual.spec.js` — PASS.
- `npx playwright test tests/mobile-e2e/account-deletion-critical.spec.js --config=tests/mobile-e2e/playwright.config.js --reporter=line --retries=0` — **1 passed**.
- `git diff --check` — PASS.
- Le parcours mobile a été servi sur `http://127.0.0.1:8088/` pour vérification manuelle.

## Suite E2E globale — résultat honnête

La suite globale de 130 tests n’est pas verte. Elle a été interrompue après 8,2 minutes :
5 tests ont passé, 10 ont échoué, 1 a été interrompu et 113 n’ont pas été exécutés.
Les erreurs observées sont dans les anciens scénarios de design : plusieurs tests ciblent encore
`127.0.0.1:8081` alors que l’application mobile actuelle est servie sur 8087/8088, certains
attendent d’anciennes données de profil/loyauté, et plusieurs tests ont un problème de harnais
(`Tracing has been already started`). Elles ne sont pas utilisées pour déclarer le parcours de
suppression de compte vert ; elles doivent être traitées dans une mission de remise à niveau E2E
séparée.

## Invariants contrôlés

- Aucun prix, catalogue, paiement, fidélité métier ou backend n’a été modifié.
- La purge locale ne précède pas le succès du backend.
- Les données de commande historiques restent régies par le backend ; aucun effacement local ne
  simule une suppression serveur.
- Aucune zone POS/caisse/kiosk gelée, migration ou configuration Mollie n’a été touchée.

## Audit et décision

`AUDIT_CHANNEL: cursor-session`
`AUDIT_FALLBACK_REASON: claude command not found; audit limité au périmètre mobile modifié et aux validations ciblées.`
`FULL_SUITE_VERDICT: NEEDS_FOLLOWUP (legacy harness and stale expectations)`
`VERDICT: PASS pour le périmètre Phase20 ; RELEASE_GLOBAL: NO-GO tant que les prérequis App Store et les régressions E2E globales ne sont pas traités.`
