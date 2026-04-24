## Contexte plan maître (SSOT) — `plans/PLAN_POS_V4_IMPL_MASTER_2026-04-26.md`

- W0–W4 avec micro-exits 1–2j ; workstreams A–D (shell, catalogue, panier, paiement+QA).
- Gates G0–G8 ; KPI time-to-cash, error recovery, a11y ; red-team 8 modes ; matrice Vitest+PHPUnit.
- Invariants: pricing SSOT, OrderStatus, branch_id, commit_before_dispatch.
- §15 Claude: parallèle ADR + BINDING_MAP + JOIN ; namespace `.fk-pos-v4` ; ordre SFC Receipt→…→Payment.
- Périmètre: template + style + pos-v4.css, script gelé, hors refonte OrderService salvo symétrie.

## Ta mission
Re-auditer ce plan comme GPT-5.5 Pro: tensions, risques cachés, P0, recommandations, ligne de passage à l’orchestrateur terminal Claude.
