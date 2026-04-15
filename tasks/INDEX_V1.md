# Index V1 — FoodKing MVP

Référence autoritative des 12 tâches V1. Lire `FoodKing_Roadmap_V1.docx` (workspace `audits/`) pour la stratégie complète.

## Règle d'engagement

- Exécution **séquentielle par vague**. Dans une vague, les tâches indépendantes peuvent tourner en parallèle.
- Chaque tâche suit `.cursor/commands/run-cycle.md` : `run-cycle TASK_V1_XXX_001`.
- Rien hors périmètre V1 (cf. section 7 du roadmap). Stop-gate automatique si un agent propose 2FA, RGPD, plateformes livraison, fidélité, mobile, site web, BI, inventaire, scheduling, WYSIWYG riche, thème avancé, multi-langue dynamique complet.

## Vague 1 — Synchro foundation (séquentielle, ~8 j-h)

| # | TASK_ID | PRIMARY_MODEL | J-H | Bloqué par |
|---|---|---|---|---|
| 1 | [TASK_V1_SYNC_BACKBONE_001](TASK_V1_SYNC_BACKBONE_001.md) | Composer | 2 | — |
| 2 | [TASK_V1_OUTBOX_001](TASK_V1_OUTBOX_001.md) | GPT-5.4 | 4 | SYNC_BACKBONE |
| 3 | [TASK_V1_EVENT_CONTRACT_001](TASK_V1_EVENT_CONTRACT_001.md) | GPT-5.4 | 2 | OUTBOX |

## Vague 2 — Domaine SSOT (partiellement parallèle, ~9 j-h)

| # | TASK_ID | PRIMARY_MODEL | J-H | Bloqué par | Gate |
|---|---|---|---|---|---|
| 4 | [TASK_V1_PRICING_SSOT_001](TASK_V1_PRICING_SSOT_001.md) | GPT-5.4 | 3 | — | **OUI** (frozen zone) |
| 5 | [TASK_V1_STATUS_MACHINE_001](TASK_V1_STATUS_MACHINE_001.md) | GPT-5.4 | 3 | — | non |
| 6 | [TASK_V1_MENU_86_001](TASK_V1_MENU_86_001.md) | GPT-5.4 | 3 | EVENT_CONTRACT | non |

## Vague 3 — Sécurité base (parallèle, ~3 j-h)

| # | TASK_ID | PRIMARY_MODEL | J-H | Bloqué par |
|---|---|---|---|---|
| 7 | [TASK_V1_SEC_XSS_001](TASK_V1_SEC_XSS_001.md) | Composer | 1 | — |
| 8 | [TASK_V1_SEC_CORS_RATELIMIT_001](TASK_V1_SEC_CORS_RATELIMIT_001.md) | Composer | 2 | — |

## Vague 4 — Data, observabilité, tests (~7 j-h)

| # | TASK_ID | PRIMARY_MODEL | J-H | Bloqué par |
|---|---|---|---|---|
| 9 | [TASK_V1_DATA_SOFTDELETE_001](TASK_V1_DATA_SOFTDELETE_001.md) | GPT-5.4 | 2 | — |
| 10 | [TASK_V1_OBS_HEALTH_CORR_001](TASK_V1_OBS_HEALTH_CORR_001.md) | Composer | 2 | SYNC_BACKBONE |
| 11 | [TASK_V1_TEST_PW_5FLOWS_001](TASK_V1_TEST_PW_5FLOWS_001.md) | Composer | 2 | EVENT_CONTRACT, PRICING_SSOT, STATUS_MACHINE, MENU_86 |
| 12 | [TASK_V1_TEST_PRICING_STATE_001](TASK_V1_TEST_PRICING_STATE_001.md) | Composer | 1 | PRICING_SSOT, STATUS_MACHINE |

**Total : ~27 j-h · ~5 à 7 semaines calendaires avec cycles Claude/Cursor.**

## Définition du succès V1 (synthèse)

Fonctionnel :
- POS cash + POS carte + Kiosk + KDS + OSS : cycles complets sans erreur.
- Rupture admin reflète sur les 3 autres surfaces en < 2s.

Technique :
- 0 calcul prix hors `PricingService` (grep CI).
- 0 transition `OrderStatus` hors `StateMachine` (grep CI).
- 0 `ShouldBroadcastNow` (grep CI) — tout passe par outbox.
- 12 specs Playwright + tests PHPUnit verts en CI.
- Coverage PricingService 100%, StateMachine ≥ 95% branches.

Sécu base :
- 0 `v-html` non sanitisé.
- CORS whitelist active (pas de `*`).
- Rate limit sur tous endpoints mutables.

Opérabilité :
- `/health`, `/health/live`, `/health/ready` opérationnels.
- Logs JSON structurés avec `correlation_id`.
- Docs livrées : `PRODUCTION_SETUP.md`, `EVENT_CONTRACT.md`, `RATE_LIMITS_MATRIX.md`, `SECURITY_NOTES.md`, `OUTBOX_PATTERN.md`, `MENU_AVAILABILITY.md`, `SOFT_DELETE_POLICY.md`, `OBSERVABILITY.md`, `PLAYWRIGHT_SUITE.md`.

## Hors V1 — phases ultérieures

- **V1.5** : 2FA admin, BI / dashboards, audit log complet, backup auto.
- **V2** : site web ordering, app mobile, fidélité, inventaire complet, multi-langue, thème avancé.
- **V3** : intégrations UberEats / Deliveroo / Wolt, drivers internes, orchestration multi-canal.
- **Pré-GO-LIVE** : RGPD complet, pentest externe, load test production-like.
