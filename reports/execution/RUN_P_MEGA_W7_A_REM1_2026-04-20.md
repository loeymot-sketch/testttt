# RUN_P_MEGA_W7_A_REM1_2026-04-20

EXECUTE_DELEGATION: foodking-complex-implementer
REMEDIATION_ATTEMPT: 1
ROOT_CYCLE: W7.A
TASK_ID: P_MEGA_W7_RESILIENCE_HARDWARE_BRANCH_2026-04-20
SUBCYCLE: W7.A post-verify remediation
PRIMARY_MODEL: foodking-complex-implementer (GPT-5.4)
OUTCOME: PASSED

## bug_signatures
- B4_stale_branch
- B2_backoff_premature
- B3_lock_no_heartbeat
- B10_toast_no_debounce
- fix5_report_doc

## Scope executed
- `resources/js/helpers/kioskOfflineQueue.js`
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
- `resources/js/store/modules/kioskCart.js` (strictly necessary: persist kiosk branch metadata in offline entries without altering backend payload)
- `tests/js/kioskOfflineQueueV2.spec.js`
- `resources/js/languages/fr.json`
- `resources/js/languages/en.json`
- `resources/js/languages/ar.json`
- `resources/js/languages/de.json`
- `resources/js/languages/bn.json`
- `reports/execution/RUN_P_MEGA_W7_A2_OFFLINE_QUEUE_V2_EXECUTE_2026-04-20.md`

## Remediation summary
- Branch-scoped stale invalidation now ignores cross-branch availability events in `KioskAppComponent` and matches queue entries against stored kiosk branch metadata when present.
- Replay backoff no longer delays the first sync: `saveOrder()` persists `lastFailedAt: null`, and the retry window starts only after a failed POST.
- Cross-tab lock ownership is now refreshed every 20 seconds while `syncQueue()` is still running, preventing a second tab from taking the lock after the 60-second TTL.
- Repeated stale availability events are aggregated into one warning toast after an 800 ms debounce window using the new `kiosk.offline.stale_*` i18n keys.
- The original A2 execute report now reflects the delivered HEAD `f1e0d6119`, the `9c8f9e202..f1e0d6119` diff stat, and links to this remediation report.

## Validation
- `git log -1 --oneline` confirmed baseline delivery HEAD: `f1e0d6119`
- Baseline Vitest before remediation: `649/649`
- Targeted queue remediation spec: `20/20`
- Impacted offline queue compatibility specs: `34/34`
- Full Vitest after remediation: `659/659`

## Invariant-sensitive checks
- Pricing SSOT: untouched, no frontend price or discount computation added.
- Order status enum usage: untouched.
- `branch_id` isolation: preserved. The backend payload remains unchanged; only offline queue metadata stores kiosk branch scope for stale invalidation.
- Dispatch-after-commit: untouched, no backend dispatch path edited.
- Symmetry review: not applicable (`OrderService` / `FrontendOrderService` untouched).

## Scope / gate notes
- No `app/**`, `database/**`, or `routes/**` file was edited.
- No W5 gated kiosk component was edited.
- No POS / V14 worktree file was edited.
- No `ESCALATION` or `SYMMETRY_NOTE` added.
