# Plan excerpt — T-LOOP-HELPER-001

## PRIOR_CONTEXT
- Boucle de validation `codex-terminal` (PRIMARY) — exec via API GPT-5.4, audit terminal Claude.
- Pas de scope produit metier — helper pur arithmetique en cents.
- SUBSYSTEMS_TOUCHED: `resources/js/helpers/`, `tests/js/`

## Hard constraints (recall)
- Pricing SSOT: helper expose addition/sum, ne calcule jamais une regle de prix.
- Pas de touch a OrderStatus, branch_id, dispatch.
- ESM helpers, vitest tests.
