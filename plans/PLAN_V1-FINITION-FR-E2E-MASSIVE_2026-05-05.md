# PLAN — V1-FINITION-FR-E2E-MASSIVE

Date: 2026-05-05  
TASK_ID: `V1-FINITION-FR-E2E-MASSIVE`  
EXECUTION_TIER: complex  
Mode: finition produit V1, francisation métier, audit visuel par captures, corrections ciblées, tests E2E massifs.

## PRIOR_CONTEXT

- FoodKing couvre POS/caisse, borne kiosk, KDS cuisine et OSS/status screen.
- Invariants critiques: backend pricing SSOT, `OrderStatus` enum, isolation stricte `branch_id`, dispatch/outbox après commit, parité `OrderService`/`FrontendOrderService`, frozen zones.
- Le rapport final-readiness précédent est `HOLD` pour release terrain, mais beaucoup de suites POS/Kiosk/KDS/admin sont déjà vertes localement.
- Une réservation active récente existe sur `BAND-KDS-OSS-V1`; KDS/OSS sera traité seulement après libération ou preuve stale.

## Objectif utilisateur

Finir la base V1 avant toute amélioration concurrentielle:

- Tout libellé visible doit être en français métier, pas en jargon informatique.
- La caisse doit être fonctionnelle, lisible, bien nommée et validée par captures + E2E.
- La borne doit être fonctionnelle, lisible, bien nommée et validée par captures + E2E.
- Le KDS doit afficher clairement les commandes, instructions et récapitulatif cuisine; les cuisiniers doivent savoir quoi préparer.
- L'administration produits/stock/commandes doit employer un vocabulaire métier clair.
- Chaque système est audité un par un, corrigé, puis validé avant de passer au suivant.
- Fin de cycle: test global POS -> borne -> KDS -> admin/stock/commandes, avec preuves dans `reports/`.

## Subsystems touched

- POS/caisse: `resources/js/components/admin/pos/**`, langues, tests POS.
- Borne: `resources/js/components/frontend/kiosk/**`, langues, tests kiosk.
- KDS/OSS: `resources/js/components/admin/kitchenDisplaySystem/**`, `resources/js/components/admin/orderStatusScreen/**`, langues, tests KDS/OSS. A traiter après collision activity-log.
- Admin produits/stock/commandes: `resources/js/components/admin/items/**`, `resources/js/components/admin/ingredients/**`, `resources/js/components/admin/stock/**`, langues, tests admin/catalog/stock.
- Rapports/preuves: `reports/execution/`, `reports/antigravity/`, `reports/e2e-massive/`.

## Invariants at risk

- Pricing backend SSOT: ne pas ajouter de calcul prix métier côté front; affichage seulement.
- `OrderStatus`: ne pas introduire chaînes ou entiers magiques.
- `branch_id`: ne pas relâcher les scopes de branche.
- Dispatch: ne pas déplacer d'events/jobs hors after-commit.
- OS/FOS: pas de changement de création/transition commande sans `SYMMETRY_NOTE`.
- Frozen: pas de paiement gateway, delivery boy, fiscal core ou legacy cutover sans gate.

## Execution protocol

1. Preflight: `npm run verify:boucle`, activity log, Graphiti facts.
2. Audit initial par screenshots existants et nouvelles captures Playwright.
3. POS first:
   - lancer design audit POS;
   - inspecter screenshots;
   - corriger libellés anglais/jargon et défauts visuels bloquants;
   - lancer Vitest/PW POS.
4. Borne:
   - lancer design audit kiosk;
   - inspecter screenshots;
   - corriger libellés/jargon/visuel;
   - lancer Vitest/PW kiosk.
5. KDS/OSS:
   - attendre/libérer collision;
   - lancer design audit KDS/OSS;
   - corriger affichage cuisine, libellés, instructions, tickets;
   - lancer Vitest/PHPUnit/PW KDS/OSS.
6. Admin produits/stock/commandes:
   - corriger vocabulaire technique visible;
   - valider catalogue, ingredients, stock, commandes.
7. Global:
   - lancer batteries E2E critiques;
   - produire rapport consolidé avec chemins de captures et verdict.

## Mandatory tests target

- `npx playwright test -c playwright.config.js tests/e2e/design/pos/d2-pos-design-audit.spec.js`
- `npx playwright test -c playwright.config.js tests/e2e/design/kiosk/d1-kiosk-design-audit.spec.js`
- `npx playwright test -c playwright.config.js tests/e2e/design/kds/d3-kds-oss-design-audit.spec.js`
- POS flows: cash, card, receipt/KDS sync, tacos flow, POS design.
- Kiosk flows: wizard, lockdown, post-payment auto-return, SPA guard, full process, kiosk design.
- KDS/OSS flows: KDS status, KDS/OSS design, receipt/KDS instruction sync.
- Admin/catalog/stock flows: catalog studio, category wizard, ingredient rupture, stock route, central management.
- Unit/static guard batches as needed: POS Vitest, kiosk Vitest, KDS Vitest, catalog/ingredients Vitest, critical PHPUnit filters.

## Blockers / escalation

- `BAND-KDS-OSS-V1` reservation active at cycle start. Do not edit KDS/OSS until released or verified stale.
- If a requested visual correction touches pricing, fiscal, payment gateway, schema, auth, frozen zones, or OS/FOS behavior: stop and open a scoped plan/gate.

## Output

- Product code diffs only where necessary.
- Screenshots under existing Playwright screenshot folders or `reports/e2e-massive`.
- Consolidated report: `reports/execution/RUN_V1-FINITION-FR-E2E-MASSIVE_2026-05-05.md`.
- `reports/post_execute_latest.log` with `EXECUTE_DELEGATION: explicit-prompt-bind (human-acknowledged)` because the human explicitly bound this Codex session to execute autonomously.
