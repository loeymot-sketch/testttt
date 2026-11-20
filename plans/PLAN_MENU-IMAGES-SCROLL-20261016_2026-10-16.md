# Plan — menu boissons / visuels / scroll mobile — 2026-10-16

TASK_ID: MENU-IMAGES-SCROLL-20261016
EXECUTION_TIER: complex
TEST_STRATEGY: playwright-full-e2e + local-validation

## Scope

- Corriger le comportement de navigation après scroll dans le prototype web/mobile `mobile/`.
- Empêcher le recadrage des visuels de produits dans le détail et les cartes qui utilisent des images détourées.
- Réaligner les visuels déjà canoniques des boissons entre le miroir mobile et `config/menu_images.php` sans modifier les prix métier côté frontend.
- Ajouter des tests de non-régression pour le reset de scroll / navigation et l’intégrité du catalogue existant.

## Explicit blockers / decisions required

- `Fusty` n’est pas une référence existante dans le catalogue ; ne pas la remplacer silencieusement par Fuze Tea.
- `Schweppes agrume`, `Oasis cassis` et `bouteille de Coca` n’ont pas de SKU/prix canoniques dans le backend ; ajout différé jusqu’à validation de la référence, du volume et du prix.
- Les photos exactes de marque et la nouvelle photo du tiramisu doivent venir de l’owner ou d’une source autorisée. Les assets générés/génériques ne doivent pas être présentés comme des photos réelles de marque.

## Files allowed

- `mobile/shared.jsx`
- `mobile/screens-main.jsx`
- `mobile/screens-item-steps.jsx`
- `mobile/styles.css`
- `mobile/data/menu.js`
- `mobile/tests/menu.spec.js`
- `mobile/tests/navigation.spec.js`
- `config/menu_images.php`
- `reports/antigravity/latest.md`
- `reports/planning/QA_MENU_IMAGES_SCROLL_2026-10-16.md`

## Invariants

- Backend remains pricing SSOT; no frontend price changes.
- Existing item slugs and IDs remain stable.
- No branch_id or order lifecycle behavior changes.
- No frozen POS/payment/order service zones touched.
- Mobile mirror stays aligned with the existing backend catalog until owner decisions unblock new SKUs.

## Plan review / execution trace

PLAN_REVIEW_CHANNEL: pending
PLAN_REVIEW_VERDICT: pending
EXECUTE_DELEGATION: codex-extension (required by repository contract)
