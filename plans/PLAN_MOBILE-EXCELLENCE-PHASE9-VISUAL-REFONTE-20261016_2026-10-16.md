# Plan — MOBILE-EXCELLENCE-PHASE9-VISUAL-REFONTE-20261016

## PLAN_REVIEW

PLAN_REVIEW_VERDICT: PASS
EXECUTION_TIER: complex

## Direction artistique

« Atelier Cayenne » : une interface éditoriale de restauration rapide premium,
avec une toile crème, des blocs encre/noir, des accents orange cayenne et jaune
maïs, des photos produits en premier plan et une typographie display déjà
présente dans l’application. Les catégories restent des photos, jamais des
emoji de substitution.

## Fichiers autorisés

| Fichier | Action |
|---|---|
| `mobile/screens-main.jsx` | restructurer l’accueil et les cartes menu sans toucher aux données/prix |
| `mobile/styles.css` | ajouter les tokens et composants visuels de la refonte |
| `mobile/index.html` | versionner les ressources pour éviter un preview stale |
| `mobile/tests/mobile-ui-excellence.spec.js` | ajouter les contrats statiques de structure |
| `tests/mobile-e2e/catalog-visual-critical.spec.js` | prouver les raccourcis contextuels accueil → menu/hero |
| `reports/execution/MOBILE-EXCELLENCE-PHASE9-VISUAL-REFONTE-20261016/report.md` | consigner les validations |
| `.cursor/ACTIVE_CYCLE.md` | état du cycle |
| `reports/AGENT_ACTIVITY_LOG.md` | trace append-only |

## Non-régression

Les fonctions `go`, `addToCart`, `Slot`, `branchPhoneInfo`, `window.LC.menu`,
les IDs, les prix et les labels accessibles doivent rester inchangés. Le test
browser doit confirmer que le clic sur une catégorie et sur une carte produit
ouvre toujours le flux existant.

Les raccourcis de catégorie de l’accueil transmettent le slug au menu afin que
la destination soit contextualisée. Les contrôles cliquables de l’accueil sont
des `<button>` natifs ; aucun bouton ne doit être imbriqué dans un autre.

## Invariants considérés

pricing SSOT backend ; `branch_id` ; ordre/commande ; allergènes détaillés ;
assets locaux réels ou fallback neutre ; aucune zone POS/KDS/frozen touchée.
