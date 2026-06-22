# AUDIT SYNCHRONISATION RUPTURE — MEGA-D 2026-05-07

Total findings: 9

| Step | Slug | State | Sev | Note | Screenshot |
| --- | --- | --- | --- | --- | --- |
| D-01 | src-dispatchers | all-wired | OK | 4 services dispatch ItemAvailabilityChanged: {"app/Services/ItemService.php":{"imports":true,"dispatches":2},"app/Services/ItemExtraService.php":{"imports":true,"dispatches":1},"app/Services/ItemAddon | `tests/e2e/screenshots/audit-sync-rupture-2026-05-07/01-src-dispatchers-wired.png` |
| D-02 | src-subscribers | all-wired | OK | POS subscribed=true, Kiosk=true, KDS=true | `tests/e2e/screenshots/audit-sync-rupture-2026-05-07/02-src-subscribers-all-wired.png` |
| D-03 | event-persistence | persisted | OK | Item 363 (Tacos M (1 Viande)): domain_events count 4→5 (delta=1). Latest event: {"id":254,"event_type":"menu.item_availability_changed","aggregate_id":363,"branch_id":null,"occurred_at":"2026-05-07T01 | `tests/e2e/screenshots/audit-sync-rupture-2026-05-07/03-dispatch-trace-persisted.png` |
| D-04 | listener-outbox | wired | OK | PersistItemAvailabilityChangedToOutbox exists=true, EventServiceProvider câblé=true | `tests/e2e/screenshots/audit-sync-rupture-2026-05-07/04-listener-wired.png` |
| D-05 | event-contract | wired | OK | BROADCAST_MAP=true, REQUIRED_PAYLOAD_KEYS=true | `tests/e2e/screenshots/audit-sync-rupture-2026-05-07/05-event-contract-wired.png` |
| D-06 | sync-tables | all-present | OK | Tables: {"item_branch_availability":true,"stock_levels":true,"item_extras":true,"item_addons":true} | `tests/e2e/screenshots/audit-sync-rupture-2026-05-07/06-sync-tables-all-present.png` |
| D-07 | kiosk-handler | no-refresh | P2 | Kiosk _handleItemAvailabilityChanged triggers fetchMenu=false | `tests/e2e/screenshots/audit-sync-rupture-2026-05-07/07-kiosk-handler-no-refresh.png` |
| D-08 | pos-86-badge | present | OK | POS ItemComponent has 86 badge logic=true | `tests/e2e/screenshots/audit-sync-rupture-2026-05-07/08-pos-86-badge-present.png` |
| D-09 | kds-sync001 | wired | OK | KDS subscribed=true, triggers refresh=true | `tests/e2e/screenshots/audit-sync-rupture-2026-05-07/09-kds-sync001-wired.png` |