GPT_FINAL_AUDIT_CHANNEL: codex-extension
FOODKING_GPT_ONLY: 1
GPT_FINAL_AUDIT_MODEL: gpt-5.5
GPT_FINAL_AUDIT_REASONING_EFFORT: xhigh
GPT_FINAL_AUDIT_VERDICT: REWORK

**Corrections requises**
- Corriger le scope M-08: `tests/Feature/Sentinels/FiscalZBranchExactnessSentinelTest.php` a été inclus dans la rework et dans la réservation, mais il n’est pas dans `missions/CV1-M08-FISCAL-Z-NF525/input.json.allowlist` ni dans l’allowlist M-08 du plan masterplay. Masterplay dit hors allowlist = REWORK.
- Mettre le plan/mission à jour avec autorisation explicite, ou retirer cette modification et obtenir le PASS sans éditer ce sentinel.
- Refaire l’audit final après correction de scope. Les tests ciblés M-08 relancés ici passent: 21 passed.
