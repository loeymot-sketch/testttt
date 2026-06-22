# T07 — Idempotency branch-scoped (kiosk + POS)

**Date** : 2026-04-20  **Statut** : PENDING  **Subagent** : `explore`

## Objectif unique

Vérifier que **l'idempotency_key** des commandes kiosk + POS est scopée
`(branch_id, idempotency_key)` côté Cache::lock **et** côté index DB. Aucun risque de
collision cross-branch ni de double-débit.

## Subagent à lancer (prompt prêt à coller)

```
Tu es un sous-agent `explore`. Racine : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93.

Étapes :
1) Lire app/Services/FrontendOrderService.php → recherche `Cache::lock` + clé.
   Doit contenir `branch_id` ET `idempotency_key` (réf P9.5.5).
2) Lire app/Services/OrderService.php (POS) — même logique ?
3) Migration : database/migrations/<TS>_scope_idempotency_key_to_branch.php
   - Colonne / index unique composite ?
   - `php artisan migrate:status` (si shell dispo).
4) Recherche payload TPE / paiement : `rg -n "transaction_id|tpe.*idempot|payment_intent_id"`
   → idempotency carte bancaire OK (K-4 TPE UUIDv4) ?
5) Tests :
   - tests/Feature/Orders/IdempotencyBranchScopedTest.php
   - tests/Feature/OrderPipeline/KioskFullFlowE2ETest.php (P9.5.5)
6) Cross-check audit : `reports/review/AUDIT_KIOSK_110_ISOLATION_STATE_2026-04-19.md`,
   `AUDIT_POS_110_ISOLATION_PERMS_SM_2026-04-19.md`.
7) Trace BLOCKER P9.5.5 historique : tasks/phase9/P9_5_BLOCKER_9.5.5_*.md (si présent).

Sortie : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit-orchestration/REPORT_TASK07_IDEMPOTENCY_BRANCH_2026-04-20.md
```

## Lecture obligatoire

- `app/Services/FrontendOrderService.php`, `OrderService.php`
- `database/migrations/*idempotency*`
- `tests/Feature/Orders/IdempotencyBranchScopedTest.php`
- `tasks/phase9-sync/LOCK_A_P9_5_idempotency_key_migration_2026-04-18.md`

## Checklist multi-points

- [ ] V1. `Cache::lock` clé inclut `branch_id|idempotency_key` (kiosk)
- [ ] V2. POS même pattern (`Cache::lock` ou row-lock DB)
- [ ] V3. Index unique DB composite présent + migré
- [ ] V4. TPE idempotency UUIDv4 distincte (K-4)
- [ ] V5. Test PHPUnit branch-scoped passe
- [ ] V6. E2E kiosk full-flow couvre re-soumission idempotente
- [ ] V7. Aucune réécriture POS pendant P9.5 hors LOCK_A

## Critères PASS / FAIL

- **PASS** : 7 V cochées.
- **FAIL** : lock global sans `branch_id`, ou index manquant → collision multi-branch
  possible.

## Output

`reports/audit-orchestration/REPORT_TASK07_IDEMPOTENCY_BRANCH_2026-04-20.md`

## Si FAIL → action

→ T07b `generalPurpose` : patch lock + migration manquante + test rouge → vert.
