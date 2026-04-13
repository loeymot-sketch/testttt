# Rapports, plans et audits récents

Les documents sous `reports/` complètent la doc `docs/` : ils capturent **l’état à une date** et les **décisions**. Le code et Git restent la référence finale en cas de divergence.

## Planning

| Document | Contenu indicatif |
|----------|-------------------|
| [`../../reports/planning/latest.md`](../../reports/planning/latest.md) | Point d’entrée courant + lien audit massif |
| [`../../reports/planning/AUDIT_PROFOND_PLAN_MASSIF_2026-03-31.md`](../../reports/planning/AUDIT_PROFOND_PLAN_MASSIF_2026-03-31.md) | Audit global, phases A–E, diagrammes Mermaid, inventaire tests |
| [`../../reports/planning/PLAN_AUDIT_GLOBAL_KIOSK_2026-03-31.md`](../../reports/planning/PLAN_AUDIT_GLOBAL_KIOSK_2026-03-31.md) | Plan audit kiosk historique |
| [`../../reports/planning/SPLASH_FOODKING_GAP_ANALYSIS_2026-03-27.md`](../../reports/planning/SPLASH_FOODKING_GAP_ANALYSIS_2026-03-27.md) | Parité produit vs Splash |
| [`../../reports/planning/KIOSK_SPLASH_BACKLOG_DEEP_PLAN_2026-03-27.md`](../../reports/planning/KIOSK_SPLASH_BACKLOG_DEEP_PLAN_2026-03-27.md) | Backlog détaillé borne |

## Review / architecture synchro

| Document | Contenu |
|----------|---------|
| [`../../reports/review/AUDIT_SYNC_BROADCAST_ARCHITECTURE_2026-03-31.md`](../../reports/review/AUDIT_SYNC_BROADCAST_ARCHITECTURE_2026-03-31.md) | OrderCreated / OrderStatusChanged, listeners, gaps |
| [`../../reports/review/latest.md`](../../reports/review/latest.md) | Verdict global / readiness (si à jour) |

## Exécution & QA

| Document | Contenu |
|----------|---------|
| [`../../reports/execution/latest.md`](../../reports/execution/latest.md) | Derniers résultats de tests / synthèse |
| [`../../reports/antigravity/latest.md`](../../reports/antigravity/latest.md) | E2E / limitations headless |

## Workflows (format des rapports)

- `workflows/report-format.md`
- `workflows/task-routing.md`
- `workflows/qa-loop.md`

---

**Conseil** : pour une nouvelle feature, créer un plan daté dans `reports/planning/` plutôt que d’écraser l’historique sans lien.
