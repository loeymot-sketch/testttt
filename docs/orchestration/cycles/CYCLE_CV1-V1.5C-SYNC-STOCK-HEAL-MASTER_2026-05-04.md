# Cycle archive — `CV1-V1.5C-SYNC-STOCK-HEAL-MASTER` — 2026-05-04

## Goal

Heal ultra-audit sync/stock findings (R1 re-validation submit path, R2 WS stale menu after reconnect, R3 silent `BROADCAST_DRIVER` in prod-like env).

## Delivered

| Step | What | Evidence |
| --- | --- | --- |
| R3 | `BroadcastDriverConfiguredTest` — prod-like env must not use `log` silently; positive case accepts real drivers via `Config::set` | `tests/Feature/Config/BroadcastDriverConfiguredTest.php` |
| R1 | TRACE: `PricingService::calculateOrder` → `assertOptionsOrderable` → `ChoiceAvailabilityResolver::assertSelectionsOrderable`. NO patch to frozen `OrderService` / `FrontendOrderService`; sentinel test freezes contract | `tests/Feature/Order/SubmitRevalidatesChoiceAvailabilityThroughPricingTest.php` |
| R2 | On WS reconnect: kiosk `fetchMenu` forced; POS `itemList` refresh | `KioskAppComponent.vue`, `PosComponent.vue` |
| Baselines | Full PHPUnit + Vitest | **1433 passed** (24 skipped), **1162 passed** (2 skipped) |

## Artifacts

- Plan: `plans/PLAN_CV1-V1.5C-SYNC-STOCK-HEAL-MASTER_2026-05-04.md`
- Run report: `reports/execution/RUN_CV1-V1.5C-SYNC-STOCK-HEAL-MASTER_2026-05-04.md`
- Memory: `memory/episodes/12_decisions_log.jsonl` (episode appended)

## Notes

- Formal Claude-terminal `AUDIT_VERDICT` + Codex `GPT_FINAL_AUDIT` not executed in this Cursor session; orchestration should run per `run-cycle` Step 5 if strict close required.
