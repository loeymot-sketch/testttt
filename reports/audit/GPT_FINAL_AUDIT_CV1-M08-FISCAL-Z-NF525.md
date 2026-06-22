GPT_FINAL_AUDIT_CHANNEL: codex-extension  
FOODKING_GPT_ONLY: 1  
GPT_FINAL_AUDIT_MODEL: gpt-5.5  
GPT_FINAL_AUDIT_REASONING_EFFORT: xhigh  
GPT_FINAL_AUDIT_VERDICT: PASS

Audit final scoped `CV1-M08-FISCAL-Z-NF525`: PASS. Le rework précédent est traité: allowlist M-08 mise à jour, `FOODKING_GPT_ONLY: 1` tracé, `SYMMETRY_NOTE` présent, et je n’exige pas d’audit Claude pour ce run.

Validations relancées: 21 tests M-08/NF525 passent. `git diff --check` limité au scope M-08 passe. Invariants vérifiés: pricing backend SSOT OK, `OrderStatus::*` OK, `branch_id` exact OK, dispatch après commit OK, frozen gates approuvés, symétrie OrderService/FrontendOrderService documentée.

Note: ce PASS vaut pour M-08 scoped, pas pour la clôture globale `P_EXEC_CLOSEOUT_*`, dont le worktree contient encore beaucoup de changements hors scope.