# Super Audit Rework Orchestration — Summary — 2026-04-28

Plan source: `plans/PLAN_SUPER_AUDIT_REWORK_ORCHESTRATION_2026-04-28.md`

## Verdict

`REWORK_BEFORE_HARDWARE_UAT`

Le rapport Claude/Orcai est utile comme backlog de risques, mais il n'est pas une preuve finale: pas de `FIN_COMPLETE`, pas d'audit depot garanti, et fin tronquee a `FIX-039`. Codex doit donc executer un plan evidence-first.

## Ce qui est deja solide

- C0 kiosk auto-return rapporte PASS.
- C1 kiosk process rapporte PASS.
- C2 POS process rapporte PASS.
- D1/D2/D3 design audits PASS apres corrections Codex: 90/30/20 audits, `seriousTotal=0`.
- Build frontend PASS.
- Suites existantes PASS: stock, queue, menu partiel, Vitest sync/rupture/delivery/geocode, Playwright realtime static contracts.

## Ce qui bloque UAT

- C3 runtime multi-surface live non prouve.
- C4 stock stress non prouve.
- C5 queue stress non prouve.
- C6 fiscal/outbox/persistence complet non prouve.
- C8 payment lifecycle atomicite non prouvee completement.
- C9 dashboard gestion restaurateur non prouve.
- MySQL 8 non valide pour les tests actuellement skipped.
- Rate limit KDS/OSS et `Too Many Attempts.` a qualifier.
- Authz/branch isolation complete a prouver.

## Ordre d'execution

1. M0 — Validation machine des hypotheses Claude.
2. M1 — C3 runtime multi-surface Kiosk/POS/KDS/OSS.
3. M2 — Fiscal/outbox/persistence.
4. M3 — Payment lifecycle atomicite.
5. M4 — Stock/queue stress.
6. M5 — Delivery/maps/pricing SSOT.
7. M6 — Authz/branch/counter-collect/lockdown.
8. M7 — Dashboard management + composer propagation.
9. M8 — Design production-like / responsive / chaos.
10. M9 — Rapport final + hardware UAT brief.

## Premier prompt a executer

```text
TASK_ID: SUPER-AUDIT-M0-MACHINE-VALIDATION-2026-04-28

Lis plans/PLAN_SUPER_AUDIT_REWORK_ORCHESTRATION_2026-04-28.md puis execute uniquement M0.
Ne modifie pas le produit.
Produis reports/audit/CODEX_M0_MACHINE_VALIDATION_2026-04-28.md.
Classe chaque hypothese Claude en FACT/FALSE/PARTIAL/UNKNOWN avec fichier:ligne.
Utilise rg pour detecter mocks e2e, chaines magiques status, afterCommit/listeners, throttle, delivery fee et branch isolation.
Si tu detectes un P0 reel, stoppe et ecris un sous-plan de correction minimal M0-P0-<slug>.
Respecte les invariants FoodKing: backend pricing SSOT, enums status, branch_id isolation, dispatch after commit, frozen zones.
```

