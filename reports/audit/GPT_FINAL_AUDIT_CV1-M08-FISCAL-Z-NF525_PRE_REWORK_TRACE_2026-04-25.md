GPT_FINAL_AUDIT_CHANNEL: codex-extension
FOODKING_GPT_ONLY: 1
GPT_FINAL_AUDIT_MODEL: gpt-5.5
GPT_FINAL_AUDIT_REASONING_EFFORT: xhigh
GPT_FINAL_AUDIT_VERDICT: REWORK

**Corrections requises**
- Résoudre `FiscalZBranchExactnessSentinelTest`: relancé, il échoue encore (`order_count=0`, attendu `1`). Test obligatoire non vert = pas de close.
- Ajouter la trace M08 dédiée `FOODKING_GPT_ONLY: 1` / validation / verdict dans `reports/post_execute_latest.log`; la trace actuelle trouvée concerne M05.
- Ajouter `SYMMETRY_NOTE` M08, car `FrontendOrderService` est modifié.
- Couvrir ou préserver la compatibilité HMAC des Z historiques après extraction vers `FiscalSealingService`.
- Nettoyer le diff courant ou isoler M08: `git diff --check` échoue sur les assets publics, et le working tree contient beaucoup de changements hors audit M08.
