# Audit de rework — MOBILE-EXCELLENCE-PHASE15-LOYALTY-REACTIVATION-20261016

**REMEDIATION_AUDIT_CYCLE:** 1/5  
**Canal:** audit fallback en lecture seule  
**Identité:** cet audit n’est pas un audit humain Claude et ne revendique pas ce rôle.  
**Date:** 2026-10-16

## Périmètre et sources relues

- `AGENTS.md` (contrat d’exécution, invariants, audit et clôture) ;
- `plans/PLAN_MOBILE-EXCELLENCE-PHASE15-LOYALTY-REACTIVATION-20261016_2026-10-16.md` ;
- `reports/execution/MOBILE-EXCELLENCE-PHASE15-LOYALTY-REACTIVATION-20261016/report.md` ;
- `.cursor/ACTIVE_CYCLE.md` ;
- les fichiers de l’allowlist et les preuves Playwright associées.

Aucune modification de code, de plan ou de `.cursor/ACTIVE_CYCLE.md` n’a été effectuée pendant cet audit.

## Vérifications ciblées

### 1. Opt-out sans `LC.dev` — PASS

`mobile/index.html` ne contient plus de référence `LC.dev` dans le contrôle client d’opt-out. Le chemin utilise :

- `window.LC.storage.setConsent('opted_out')` ;
- `window.LC.loyalty._replaceAccount({ consent_status: 'opted_out', balance: 0, lifetime_earned: 0, lifetime_redeemed: 0 })` ;
- l’émission de `lc:loyalty-changed` avec le détail `status: 'opted_out'` et `source: 'customer'`.

Le fallback `window.Event` est présent si `CustomEvent` n’est pas disponible. La remise à zéro de l’historique existante est conservée.

### 2. Réactivation et événement — PASS

`reactivateLoyaltyAccount()` dans `mobile/screens-main.jsx` :

- persiste `opted_in` par l’API publique `LC.storage.setConsent` ;
- remet le compte local à zéro avec `consent_status: 'opted_in'` ;
- émet `lc:loyalty-changed` avec `status: 'opted_in'` et `source: 'customer'` ;
- ne dépend pas de `window.LC.dev`.

`ScreenLoyalty` écoute bien `lc:loyalty-changed` et force son rafraîchissement. La référence `window.LC.dev` encore visible dans `screens-main.jsx` concerne uniquement le compteur de rendu de test, pas un contrôle de consentement client ; elle ne contredit donc pas le périmètre du plan.

### 3. Preuves UI, catalogue, E2E et diff — PASS

Preuves relues ou rejouées :

- `bash .cursor/hooks/safety-check.sh` — PASS, zones gelées OK ;
- `node mobile/tests/mobile-ui-excellence.spec.js` — PASS ;
- `node mobile/tests/mobile-catalog-visual.spec.js` — PASS : 39 visuels produits, 9 catégories, 15 boissons, parité wizard et invariants catalogue ;
- `npx playwright test tests/mobile-e2e/loyalty-11-opt-out.spec.js --config=tests/mobile-e2e/playwright.config.js` — preuve JSON `reports/antigravity/mobile-loyalty-latest.json`, 1/1 attendu, 0 erreur ;
- `git diff --check` sur les fichiers de l’allowlist — PASS.

Le scénario E2E supprime `window.LC.dev` avant l’opt-out, vérifie `opted_out`, confirme la disparition du QR, clique la réactivation, vérifie `opted_in` et le solde à zéro, puis vérifie le retour du QR. Le test prouve les effets UI et d’état ; l’émission des événements est en plus vérifiée par inspection statique du code et par les assertions statiques dédiées.

### 4. Cohérence de l’allowlist — PASS avec observation

Les modifications Phase 15 concernent les fichiers prévus par le plan. Le worktree contient aussi des modifications non liées dans les modules d’étiquettes, ainsi que l’artefact JSON généré par le reporter Playwright ; elles ne sont pas attribuées à cette remédiation et n’ont pas été modifiées pendant l’audit. Elles devront rester séparées lors de toute clôture ou commit.

## Invariants FoodKing dans le périmètre

- Prix, commande, `OrderStatus`, `branch_id`, dispatch après commit et parité `OrderService`/`FrontendOrderService` : aucun changement dans le périmètre Phase 15 vérifié.
- Backend, paiement, base de données, auth/SMS, suppression de compte et packaging natif : hors allowlist et non modifiés par cette remédiation.
- Aucun changement de politique backend de rétention ou de restauration n’a été inventé.
- Le comportement contractuel local d’opt-out — effacement du solde et de l’historique — est conservé.
- Les zones gelées sont signalées intactes par `safety-check`.

## Blocages App Store rappelés, hors traitement

Ce PASS est limité à la remédiation du consentement local. Il ne vaut pas approbation App Store. Restent à traiter dans des missions et gates séparées :

- politique backend explicite de restauration sous 30 jours et éventuelle conservation des points ;
- intégration Mollie complète et activation serveur contrôlée ;
- parcours mobile de suppression de compte et conformité associée ;
- projet/build iOS natif, signature, entitlements et archive TestFlight ;
- audit formel Claude puis audit GPT final avant clôture.

## Verdict

**AUDIT_VERDICT: PASS** — remédiation ciblée 1/5 validée.  
Le défaut reworké (dépendance de l’opt-out à `window.LC.dev`) est corrigé et les deux transitions de consentement publient l’événement attendu. Le cycle reste ouvert pour les audits finaux et les blocages App Store explicitement hors scope.
