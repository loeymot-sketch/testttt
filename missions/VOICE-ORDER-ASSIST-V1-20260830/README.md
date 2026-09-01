Mission **VOICE-ORDER-ASSIST-V1-20260830**

1. Remplir `input.json` + fichiers de contexte optionnels (Graphiti, plan, brief).
2. Lancer d’abord : `npm run codex:plan-review -- VOICE-ORDER-ASSIST-V1-20260830` et attendre `PLAN_REVIEW_VERDICT: PASS`.
3. Lancer : `npm run codex:complex -- VOICE-ORDER-ASSIST-V1-20260830` (CLI `codex` + compte ChatGPT Pro, GPT-5.5-pro/xhigh par défaut).
4. Appliquer le JSON produit : `output_codex.json` + lire `reports/audit/GPT_SELF_AUDIT_VOICE-ORDER-ASSIST-V1-20260830.md` ; tracer `EXECUTE_DELEGATION: codex-extension` dans le rapport.
5. Après audit Claude PASS, lancer : `npm run codex:final-audit -- VOICE-ORDER-ASSIST-V1-20260830`.
6. Voir : `docs/orchestration/CODEX_API_DELEGATION.md` ; instructions Codex : `agents/codex-extension-instructions.md`.
