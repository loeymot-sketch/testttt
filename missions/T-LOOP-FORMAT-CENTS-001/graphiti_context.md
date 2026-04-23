# Graphiti context — T-LOOP-FORMAT-CENTS-001

## Facts (extrait Graphiti pour cadrer EXECUTE)

- **04_pricing_ssot.jsonl** : Tout calcul de prix vit côté backend (PricingService). Le frontend ne fait JAMAIS de logique de prix — il **affiche** uniquement. Conséquence : un helper qui formate des cents en string est légitime ; un helper qui *calcule* des prix ne le serait pas.
- **05_fiscal_nf525.jsonl** : Les receipts FR doivent afficher des montants stables, déterministes, en virgule décimale. Pas d'arrondi côté display (le backend a déjà arrondi en cents int). Pas de dépendance locale runtime (Intl.NumberFormat varie selon Node, donc rejet).
- **07_pos_features.jsonl** : ESC/POS templates ont besoin d'une variante "plain" (point décimal, ASCII pur) pour les imprimantes thermiques qui n'aiment pas NBSP/UTF-8 spéciaux. D'où l'option `variant: 'plain'`.
- **10_tests_coverage.jsonl** : Les helpers JS POS ont une convention : 1 fichier helper + 1 spec Vitest avec ≥10 sentinels. Voir `posCentsArith.js` + `posCentsArith.spec.js` comme modèle.
- **14_conventions.jsonl** : ESM, single quotes, semicolons, indent 2 espaces, no default export sauf `export default { ... }` final pour compat.

## Frozen zones (NE PAS toucher)
- `resources/js/helpers/posCentsArith.js`
- `app/Services/PricingService.php`
- Tout `app/Services/Order*`, `app/Services/Payment*`
- `database/migrations/**`

## Dernier round du REPORT_FILE
N/A (premier round).
