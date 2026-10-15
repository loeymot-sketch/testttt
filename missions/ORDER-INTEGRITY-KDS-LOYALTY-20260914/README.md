Mission **ORDER-INTEGRITY-KDS-LOYALTY-20260914**

1. Remplir `input.json` + fichiers de contexte optionnels (Graphiti, plan, brief).
2. Lancer d’abord : `npm run codex:plan-review -- ORDER-INTEGRITY-KDS-LOYALTY-20260914` et attendre `PLAN_REVIEW_VERDICT: PASS`.
3. Lancer : `npm run codex:complex -- ORDER-INTEGRITY-KDS-LOYALTY-20260914` (CLI `codex` + compte ChatGPT Pro, GPT-5.5-pro/xhigh par défaut).
4. Appliquer le JSON produit : `output_codex.json` + lire `reports/audit/GPT_SELF_AUDIT_ORDER-INTEGRITY-KDS-LOYALTY-20260914.md` ; tracer `EXECUTE_DELEGATION: codex-extension` dans le rapport.
5. Après audit Claude PASS, lancer : `npm run codex:final-audit -- ORDER-INTEGRITY-KDS-LOYALTY-20260914`.
6. Voir : `docs/orchestration/CODEX_API_DELEGATION.md` ; instructions Codex : `agents/codex-extension-instructions.md`.
