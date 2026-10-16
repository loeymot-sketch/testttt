# MOBILE-EXCELLENCE-PHASE15-LOYALTY-REACTIVATION-20261016

## Verdict d'exécution

**IMPLEMENTED — VALIDATION CIBLEE PASS**

Le contrôle client « Réactiver mon compte » ne dépend plus de `window.LC.dev`.
Il persiste `opted_in` via `LC.storage.setConsent`, remet le compte local à zéro
via `LC.loyalty._replaceAccount`, puis émet `lc:loyalty-changed` afin que
`ScreenLoyalty` réaffiche immédiatement l'état actif et le QR.

Le décor plein écran du bandeau fidélité est également marqué non interactif.
Il ne peut donc plus intercepter les clics du contrôle de réactivation.

## Fichiers de l'allowlist

- `mobile/screens-main.jsx`
  - ajout du helper client `reactivateLoyaltyAccount` ;
  - ajout de `data-testid="loyalty-reactivate-btn"` ;
  - décor `loyalty-pattern` avec `pointerEvents: 'none'`.
- `mobile/tests/mobile-ui-excellence.spec.js`
  - garde-fous sur consentement, remise à zéro, événement, absence de dépendance à `LC.dev` et décor non bloquant.
- `tests/mobile-e2e/loyalty-11-opt-out.spec.js`
  - scénario opt-out → clic réactivation → consentement `opted_in` → compte à zéro → QR visible.
- `reports/execution/MOBILE-EXCELLENCE-PHASE15-LOYALTY-REACTIVATION-20261016/report.md`

## Validation

- `bash .cursor/hooks/safety-check.sh` — **PASS** ; zones gelées intactes.
- `node mobile/tests/mobile-ui-excellence.spec.js` — **PASS**.
- `node mobile/tests/mobile-catalog-visual.spec.js` — **PASS** ; 39 visuels produits, 9 catégories, 15 boissons et parité wizard contrôlés.
- `npx playwright test tests/mobile-e2e/loyalty-11-opt-out.spec.js --config=tests/mobile-e2e/playwright.config.js` — **PASS**, 1/1 en 6 secondes après correction du décor intercepteur.
- `git diff --check -- mobile/screens-main.jsx mobile/tests/mobile-ui-excellence.spec.js tests/mobile-e2e/loyalty-11-opt-out.spec.js` — **PASS**.

## Invariants vérifiés

- aucun changement backend, prix, commande, paiement, branche, dispatch, auth/SMS, suppression de compte ou packaging natif ;
- opt-out inchangé : les points locaux sont effacés ;
- réactivation locale à zéro point, sans invention de fenêtre de rétention de 30 jours ;
- rafraîchissement par l'événement de fidélité existant ;
- modifications non liées du checkout préservées.

## Blocages et suite

- La politique métier « restauration sous 30 jours » et la conservation/restauration des points restent hors scope et nécessitent une mission backend + décision propriétaire.
- La validation formelle Claude/audit GPT final n'est pas exécutée dans cette étape ; le cycle reste à passer en VALIDATE puis AUDIT.
- La préparation complète App Store (paiement Mollie activé côté serveur, suppression de compte réelle côté mobile, signature/native build et conformité finale) reste distincte de ce correctif local.

`EXECUTE_DELEGATION: foodking-complex-implementer (codex-extension-fallback)`

`FALLBACK_REASON: le canal codex-extension gpt-5.5-pro n'était pas disponible dans cette session ; exécution locale bornée au plan.`

`VERDICT: PASS (validation ciblée uniquement; pas de clôture de cycle)`
