GPT_FINAL_AUDIT_CHANNEL: codex-extension  
FOODKING_GPT_ONLY: 1  
GPT_FINAL_AUDIT_MODEL: gpt-5.5  
GPT_FINAL_AUDIT_REASONING_EFFORT: xhigh  
GPT_FINAL_AUDIT_VERDICT: ESCALATE

Corrections requises avant close :

- Plan/artifacts incohérents : le plan lu est `P_EXEC_CLOSEOUT_GRAPHITI_CI_PROD_2026-04-22`, mais l’auto-audit et la livraison auditée sont `CV1-M06-POS-REVENUE-GUARDS`.
- `reports/post_execute_latest.log` ne trace pas proprement cette livraison et aucune trace `FOODKING_GPT_ONLY=1` n’a été trouvée dans les artefacts lus.
- La mission `CV1-M06` n’est pas close : `reports/masterplay/status.json` indique `RUNNING`, et la queue indique `EXECUTED`, pas `FINAL_PASS` / `CLOSED`.
- L’auto-audit GPT existant conclut déjà `VERDICT: NEEDS_FIX` : `PosOrderRequest` garde une décision discount basée sur `subtotal` client, et `paymentConfirm` a des risques de faux succès / idempotence TPE.
- Le diff git courant est massif et dépasse le périmètre M-06 / closeout, donc impossible de certifier “strictement dans le plan” sans séparation de scope.

Aucun close possible dans cet état.