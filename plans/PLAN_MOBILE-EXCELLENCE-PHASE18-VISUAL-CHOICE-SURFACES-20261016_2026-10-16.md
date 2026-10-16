# Plan — MOBILE-EXCELLENCE-PHASE18-VISUAL-CHOICE-SURFACES-20261016

## Objective

Terminer la synchronisation visuelle de l’app mobile Le Cayenne sur les
surfaces de personnalisation : aucun emoji alimentaire visible dans le
parcours, choix de viandes et formules illustrés par les assets réels déjà
présents dans le catalogue site/borne, contrôles clavier/tactiles conservés.

## Scope / allowlist

- `mobile/screens-main.jsx`
- `mobile/screens-item-steps.jsx`
- `mobile/styles.css`
- `mobile/tests/mobile-ui-excellence.spec.js`
- `tests/mobile-e2e/catalog-visual-critical.spec.js`
- `mobile/index.html` (cache revision uniquement si nécessaire)
- `tasks/MOBILE-EXCELLENCE-PHASE18-VISUAL-CHOICE-SURFACES-20261016.md`
- `reports/execution/MOBILE-EXCELLENCE-PHASE18-VISUAL-CHOICE-SURFACES-20261016/report.md`
- `.cursor/ACTIVE_CYCLE.md`
- `reports/AGENT_ACTIVITY_LOG.md`

## Out of scope / frozen

`mobile/data/menu.js` et les données prix/catégorie, backend PHP/config,
migrations, payment/Mollie, auth/SMS, fidélité backend, branch isolation,
dispatch, POS/caisse/kiosk source, native iOS packaging/signing et suppression
d’anciens assets.

## Acceptance criteria

1. Les choix viande et formule n’affichent plus `m.emoji`/`o.emoji` ; ils
   affichent les images déjà fournies par le catalogue ou un état neutre
   accessible quand une photo manque.
2. Le wizard garde ses rôles ARIA, son clavier et ses prix calculés depuis les
   données existantes ; aucun prix, identifiant ou flux de commande ne change.
3. Le parcours catégorie → personnalisation → panier prouve le chargement
   d’images, l’absence d’emoji visible et l’absence de régression visuelle.
4. Les invariants FoodKing restent intacts.

## Test strategy

- `node mobile/tests/mobile-ui-excellence.spec.js`
- `npx playwright test tests/mobile-e2e/catalog-visual-critical.spec.js --config=tests/mobile-e2e/playwright.config.js --reporter=line --retries=0`
- `git diff --check`
- contrôle navigateur frais sur accueil, Tacos, choix viandes, formule et
  panier.

## Execution routing

`EXECUTION_TIER: complex` — changement UX/a11y multi-surface borné, exécuté
par `codex-extension` ou son fallback `foodking-complex-implementer`.

## Invariants considered

- backend pricing remains the only price authority;
- no OrderStatus, `branch_id`, dispatch or order service changes;
- no auth/payment/loyalty/native packaging changes;
- existing unrelated dirty worktree changes remain untouched.
