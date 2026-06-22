# Plan Excerpt — CV1-M14-OPS-PREFLIGHT

Source: `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md`

M-14 — `CAISSE_V1_OPS_PREFLIGHT_2026-04-25` (NO-GATE apres M-13)

But: preflight queue/scheduler/workers/broadcast/cache/outbox/fiscal archive; dashboards/checklists for payment success rate, KDS latency, fiscal Z, branch leak counter, queue depth, worker errors; alerting and on-call.

Allowlist: `scripts/ops-preflight-caisse-v1.sh` (NEW), `app/Console/Commands/PreflightProductionCommand.php`, `config/horizon.php`, dashboards configs, tests `OpsPreflightCaisseV1Test.php`, `AfterCommitDispatchTest.php`, `OutboxRescueTest.php`.

Important inherited risk from M-13: real staging/full-volume migration rehearsal was not executed locally. M-14 must keep that as a production-GO blocker unless a transcript/evidence path is supplied and verified.
