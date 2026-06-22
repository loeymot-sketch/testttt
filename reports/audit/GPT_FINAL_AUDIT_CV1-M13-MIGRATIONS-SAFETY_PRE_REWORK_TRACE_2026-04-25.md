GPT_FINAL_AUDIT_CHANNEL: codex-extension
FOODKING_GPT_ONLY: 1
GPT_FINAL_AUDIT_MODEL: gpt-5.5
GPT_FINAL_AUDIT_REASONING_EFFORT: xhigh
GPT_FINAL_AUDIT_VERDICT: REWORK

Corrections requises :
- Ajouter une trace M13 explicite dans `reports/post_execute_latest.log` : `TASK_ID: CV1-M13-MIGRATIONS-SAFETY`, `EXECUTE_DELEGATION: codex-extension`, `FOODKING_GPT_ONLY: 1`, validations M13, puis ce verdict final. Les traces GPT-only actuelles existent mais concernent M05/M08/M07/M17, pas M13.
- Réconcilier l’état Masterplay : `reports/masterplay/status.json` indique encore `RUNNING`, tandis que `plans/masterplay/MASTERPLAY_QUEUE.md` indique `EXECUTED`. Pas de close possible avec cet état incohérent.
- Documenter le risque non clos : le rehearsal staging/full-volume n’a pas été exécuté. Soit produire le transcript/preuve, soit marquer explicitement ce risque comme reporté/accepté avant close.
- Vu le diff courant massif hors périmètre M13, ajouter une preuve de scope M13 dédiée. Le scoped check est OK pour les 7 fichiers M13 et les tests déclarés passent, mais le working tree global ne permet pas un PASS sans trace de périmètre.
