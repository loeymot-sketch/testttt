Mission **POS_V4_DESIGN_AUDIT_001**

1. Remplir `input.json` + fichiers de contexte optionnels (Graphiti, plan, brief).
2. Lancer : `npm run codex:complex -- POS_V4_DESIGN_AUDIT_001` (recommandé: `CODEX_DISABLE_STREAM=1` si 503 en stream).
3. Appliquer le JSON produit : `output_codex.json` ; tracer `EXECUTE_DELEGATION: codex-terminal` dans le rapport.
4. Voir : `docs/orchestration/CODEX_API_DELEGATION.md`.
