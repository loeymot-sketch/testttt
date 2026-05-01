# Plan d’orchestration Master Play — **exécuté** (2026-04-25)

**Orchestrateur d’exécution** : session agent (outillage) suivant le plan numéroté.  
**Orchestrateur cerveau (audit final)** : Claude Code terminal (Opus 4.7 + `effort high` via `foodking-claude-orchestrate.sh`).

| Étape | Nom | Objectif | Sortie / statut |
|-------|-----|----------|-----------------|
| 0 | Préambule | Verrouiller les artefacts (V0, R2, R3, V1) | 4 fichiers + `SIM_MASTERPLAY_BREAKDOWN_SYNTH_V1_2026-04-25.md` |
| 1 | **Conflit n°1** | Doc `DEVICE_FLOW` vs `PosComponent` (Firebase vs Echo) | Preuve : `docs/DEVICE_FLOW.md` L16, `PosComponent.vue` `onEvents` + `_subscribeEcho` ~1173 — **dérive doc** |
| 2 | **Conflit n°2** | Idempotence `(branch_id, idempotency_key)` vs requête PHP | Preuve : `FrontendOrderService.php` 126–145 + migration composite `2026_04_18_140003_*` — **lookup global P0** ; lock cache scopé |
| 3 | **Conflit n°3** | TPE : montant = total backend ? | Preuve : `KioskPaymentComponent.vue` 279–324, 408 — **SSOT serveur** hors offline ; R2 §A.2 **nuancé** |
| 4 | **Challenge / audit double (GPT vs code)** | Chaque P0–P2 R2 recoupé code ou marqué « confiance V0+GPT » | Tableau dans Round4 + section comparaison final |
| 5 | **Vérification en chaîne** | Passe 1 (agent) → Passe 2 (Claude `audit` custom) | `SIM_MASTERPLAY_CLAUDE_TERMINAL_ROUND4_2026-04-25.md` (~73s) |
| 6 | **Rapport maître** | Toutes les tables, tous les conflits, un seul document | `SIM_MASTERPLAY_FINAL_CONSOLIDATED_2026-04-25.md` (ce lot) |

**Verdict terminal (étape 5)** : `AUDIT_VERDICT: REWORK` (simulation — P0 idempotence + test `OrderTableChanged` + gouvernance KDS + doc).

**Règle suivie** : pas de `start` / `run-cycle` produit (hors `app/`/`resources/`) — uniquement audits et rapports ; pas d’iCloud, pas d’orchestrateur bloqué.
