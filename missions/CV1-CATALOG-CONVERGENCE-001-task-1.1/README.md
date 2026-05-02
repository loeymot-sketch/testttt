Mission **CV1-CATALOG-CONVERGENCE-001-task-1.1**

1. Remplir `input.json` + fichiers de contexte optionnels (Graphiti, plan, brief).
2. Lancer d’abord : `npm run codex:plan-review -- CV1-CATALOG-CONVERGENCE-001-task-1.1` et attendre `PLAN_REVIEW_VERDICT: PASS`.
3. Lancer : `npm run codex:complex -- CV1-CATALOG-CONVERGENCE-001-task-1.1` (CLI `codex` + compte ChatGPT Pro, GPT-5.5-pro/xhigh par défaut).
4. Appliquer le JSON produit : `output_codex.json` + lire `reports/audit/GPT_SELF_AUDIT_CV1-CATALOG-CONVERGENCE-001-task-1.1.md` ; tracer `EXECUTE_DELEGATION: codex-extension` dans le rapport.
5. Après audit Claude PASS, lancer : `npm run codex:final-audit -- CV1-CATALOG-CONVERGENCE-001-task-1.1`.
6. Voir : `docs/orchestration/CODEX_API_DELEGATION.md` ; instructions Codex : `agents/codex-extension-instructions.md`.
