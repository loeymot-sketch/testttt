# T16 — Observability K-9 : audit profond

**Date** : 2026-04-20  **Statut** : PENDING  **Subagent** : `explore`

## Objectif unique

Auditer en profondeur la stack observabilité K-9 : SLO doc + collector + evaluator,
correlation chain client→API→broadcast, WS reconnect SLI, heatmap POC opt-in RGPD, CSP
report endpoint, canal Monolog observability 90j JSON, whitelists `observability.*` ×5,
PII scrub strict.

> **Lien T03/T04** : si Sentry et kioskPerf sont régressés, K-9 a un trou. Ce test couvre
> **le reste** de K-9.

## Subagent à lancer (prompt prêt à coller)

```
Tu es un sous-agent `explore`. Racine : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93.

Étapes :
1) Lire SLO doc :
   - docs/observability/SLO_KIOSK_2026-04-18.md (5 SLO documentés)
2) Code clé :
   - app/Services/Observability/SloMetricCollector.php
   - app/Jobs/Observability/SloEvaluatorJob.php (croise T02 schedule)
   - app/Http/Controllers/Frontend/KioskEventController.php
   - resources/js/observability/correlation.js
   - resources/js/observability/heatmap.js
3) Vérifier :
   - 5 SLO documentés (cibles + window + métrique)
   - SloMetricCollector calcule bien depuis ActionLog (pas double-comptage)
   - SloEvaluatorJob persiste catégorie `slo_evaluation` + alerte Slack si breach
   - Correlation chain : header X-Correlation-ID propagé client → API → broadcast → outbox
     (croise T09 AX12-02)
   - Heatmap : opt-in RGPD strict + agrégation backend
   - CSP report endpoint : throttled (rate limit) + scrub PII
   - Canal Monolog observability 90j JSON (config/logging.php)
   - Whitelists `observability.*` ×5 (dont `observability.slo_breach`)
4) PII scrub strict : `body / user / headers / cookies / extra` + heal P2
   `device_id, session_id, session_cookie, username, name`. Tester les regex.
5) Tests :
   - tests/Feature/Observability/SloEvaluatorJobTest.php
   - tests/Feature/Observability/CorrelationChainTest.php (s'il existe)
   - tests/Feature/Observability/HeatmapConsentTest.php
   - tests/js/observability/correlation*.spec.js
6) Audit cross : reports/review/AUDIT_KIOSK_110_OBSERVABILITY_PERF_2026-04-19.md.
7) Backlog K-10.1 : OTel reporté, admin UI SLO timeline, heatmap renderer, purge
   ActionLog, CSP enforce, Pusher per-branch.

Sortie : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit-orchestration/REPORT_TASK16_OBSERVABILITY_K9_DEEP_2026-04-20.md
```

## Lecture obligatoire

- `docs/observability/SLO_KIOSK_2026-04-18.md`
- `app/Jobs/Observability/SloEvaluatorJob.php`
- `app/Services/Observability/SloMetricCollector.php`
- `tasks/k-hardening/ADR_K9_OBSERVABILITY_STRATEGY_2026-04-18.md`
- `reports/execution/VERIFY_K9_OBSERVABILITY_2026-04-18.md`

## Checklist multi-points

- [ ] V1. 5 SLO documentés (latence, dispo, erreur, ws_reconnect, payment_success)
- [ ] V2. SloMetricCollector ne double-compte pas
- [ ] V3. SloEvaluatorJob → ActionLog `slo_evaluation` + Slack si breach
- [ ] V4. Correlation chain end-to-end testée
- [ ] V5. Heatmap opt-in RGPD strict
- [ ] V6. CSP report throttled + PII scrubbed
- [ ] V7. Canal `observability` 90j JSON
- [ ] V8. Whitelist `observability.*` ×5
- [ ] V9. Scrub PII heal P2 vérifié
- [ ] V10. Backlog K-10.1 documenté

## Critères PASS / FAIL

- **PASS** : 10 V cochées.
- **FAIL** : ≥ 1 SLO sans collector OU correlation cassée OU PII non scrubbé.

## Output

`reports/audit-orchestration/REPORT_TASK16_OBSERVABILITY_K9_DEEP_2026-04-20.md`

## Si FAIL → action

→ T16b `generalPurpose` : patch + test.
