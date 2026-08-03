# Matrice — routage qualité maximale FoodKing

> **SSOT** : `.cursor/routing.md` + `.cursor/commands/run-cycle.md`. Ce document est un aide-mémoire court pour les nouvelles sessions.

## Boucle obligatoire

```text
PLAN Claude
  -> PLAN_REVIEW GPT-5.5-pro / xhigh
  -> EXECUTE GPT-5.5-pro / xhigh
  -> VALIDATE
  -> AUDIT Claude
  -> GPT_FINAL_AUDIT GPT-5.5-pro / xhigh
  -> CLOSE seulement si les deux audits finaux PASS
```

## Routage par phase

| Phase | Route primaire | Repli autorisé | Notes |
|---|---|---|---|
| PLAN | Claude orchestrateur | Aucun repli automatique pour décider le scope | Claude définit périmètre, invariants, gates et stratégie de test. |
| PLAN_REVIEW | `codex-extension` via `npm run codex:plan-review -- <TASK_ID>` | `foodking-complex-implementer` seulement si `codex` est indisponible et tracé | Modèle par défaut : `gpt-5.5-pro`, effort `xhigh`. Aucun EXECUTE sans `PLAN_REVIEW_VERDICT: PASS`. |
| EXECUTE produit | `codex-extension` via `npm run codex:complex -- <TASK_ID>` | `foodking-complex-implementer (codex-extension-fallback)` après échec documenté du CLI | Toutes les implémentations produit passent par GPT, même les petites corrections. |
| VALIDATE | Outils locaux / Composer pour rapport et exécution de tests | N/A | Composer peut valider et résumer, pas corriger du code produit. Toute correction repart en EXECUTE GPT. |
| AUDIT final 1 | Claude terminal via `foodking-claude-orchestrate.sh` | `foodking-planner-orchestrator` ou session Cursor Claude si quota/rate-limit/terminal HS | Tracer `AUDIT_CHANNEL` et `AUDIT_FALLBACK_REASON` en cas de repli. |
| AUDIT final 2 | `codex-extension` via `npm run codex:final-audit -- <TASK_ID>` | `foodking-complex-implementer` seulement si `codex` est indisponible et tracé | Close interdit sans `GPT_FINAL_AUDIT_VERDICT: PASS`. |

## Décision rapide

- Toute modification produit FoodKing : GPT-5.5-pro / `xhigh`.
- Aucune implémentation produit par Composer / `foodking-routine-implementer` pendant les cycles de finition.
- Les zones frozen, migrations, auth, pricing, dispatch, `branch_id`, `OrderService`, `FrontendOrderService`, NF525 restent soumises aux gates et invariants existants.
- Si Claude terminal échoue par quota, rate limit, auth, réseau ou binaire absent, la boucle continue via le repli Cursor Claude documenté ; elle ne saute pas l’audit Claude.
