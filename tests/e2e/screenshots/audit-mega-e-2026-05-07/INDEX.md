# AUDIT MEGA-E — Multi-surface E2E 2026-05-07

Total findings: 9

| Step | Slug | State | Sev | Note | Screenshot |
| --- | --- | --- | --- | --- | --- |
| E-01 | pos-surface | rendered | OK | POS V5 tokens: bgApp=#FFFBF5, brand=#E8001C | `tests/e2e/screenshots/audit-mega-e-2026-05-07/01-pos-surface.png` |
| E-02 | kiosk-surface | rendered | OK | Kiosk tokens: primary=#E8001C | `tests/e2e/screenshots/audit-mega-e-2026-05-07/02-kiosk-surface.png` |
| E-03 | kds-surface | rendered | OK | KDS body length=893, hasError=false | `tests/e2e/screenshots/audit-mega-e-2026-05-07/03-kds-surface.png` |
| E-04 | outbox-event-types | empty | INFO | Found event types in domain_events: 0, matching expected: 0/6 | `tests/e2e/screenshots/audit-mega-e-2026-05-07/04-outbox-events-0-of-6.png` |
| E-05 | listeners-wired | all-câblés | OK | Listeners Outbox dans EventServiceProvider: {"OrderCreated":true,"OrderStatusChanged":true,"OrderPaymentStatusChanged":true,"OrderPaidAtCounter":true,"ItemAvailabilityChanged":true,"CouponChanged":tru | `tests/e2e/screenshots/audit-mega-e-2026-05-07/05-listeners-wired-all.png` |
| E-06 | channel-auth | enforced | OK | Channel branch.{id}=true, kioskAbility check=true, KioskMachine lookup=true, admin bypass=true | `tests/e2e/screenshots/audit-mega-e-2026-05-07/06-channel-auth-enforced.png` |
| E-07 | idempotency-routes | all-protected | OK | Routes protected by idempotency: {"pos_create":true,"pos_change_payment":true,"frontend_order":true,"payment_confirm":true,"refund_counter":true} | `tests/e2e/screenshots/audit-mega-e-2026-05-07/07-idempotency-routes-all.png` |
| E-08 | cycle7-alignment | all-done | OK | Cycle 7 + KR + K11 deliverables present: {"cycle_7A_idempotency":true,"cycle_7B_payment_event":true,"cycle_7C_split_payment":true,"cycle_7D_chain_validator":true,"cycle_7D_sealed_guard":true,"cycle_7D | `tests/e2e/screenshots/audit-mega-e-2026-05-07/08-cycle7-alignment-all-done.png` |
| E-09 | frozen-services-wired | partial | P1 | Frozen services callsites: {"payment_state_machine_wired":true,"sealed_guard_wired":false,"sealed_guard_refunded":false,"chain_validator_wired":true,"split_payment_wired":true} | `tests/e2e/screenshots/audit-mega-e-2026-05-07/09-frozen-services-wired-partial.png` |