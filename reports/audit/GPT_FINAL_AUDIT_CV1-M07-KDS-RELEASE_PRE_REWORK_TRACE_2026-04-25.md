GPT_FINAL_AUDIT_CHANNEL: codex-extension
FOODKING_GPT_ONLY: 1
GPT_FINAL_AUDIT_MODEL: gpt-5.5
GPT_FINAL_AUDIT_REASONING_EFFORT: xhigh
GPT_FINAL_AUDIT_VERDICT: REWORK

Corrections requises :
- Implémenter ou retirer explicitement du plan `OrderStateMachine::isReleasedToKitchen()` : le plan M-07 demande la règle `status >= ACCEPT && payment_status == PAID`, avec exception cash POS, mais le code ne livre que `kitchenReleaseStatuses()` / `isKitchenReleaseTransition()`.
- Ajouter le test dédié paiement/cash pour ce prédicat KitchenRelease.
- Tracer proprement `FOODKING_GPT_ONLY: 1` et le verdict final M-07 dans l’artefact de cycle avant close. Les tests ciblés KDS passent, mais ce manque plan bloque la clôture.
