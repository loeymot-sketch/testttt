# Hardware UAT - Product Composer / POS-Kiosk-KDS Sync - 2026-04-27

Status: LOCAL_E2E_PASS__PHYSICAL_HARDWARE_PENDING

This checklist is the human signoff packet for gate `HG-E2E-HARDWARE-COMPOSER-SIGNOFF`.
Codex cannot sign the physical hardware rows. The local browser and backend evidence is green; the restaurant release remains on HOLD until the rows below are tested on real devices.

## Local Evidence Already Passed

- Playwright full pack: 40 passed.
- PHPUnit full suite: 1167 passed, 8 skipped.
- Vitest full suite: 899 passed.
- Cash-at-counter E2E:
  - Kiosk cash order appears on KDS with `PAIEMENT COMPTOIR - NON REGLE`.
  - POS pending kiosk cash panel shows the order.
  - POS collect confirms payment and allocates `fiscal_sequence_no`.
  - POS cancel path leaves `fiscal_sequence_no` null and moves the order to canceled/refunded.
- Kiosk lockdown E2E:
  - `/kiosk/admin` is blocked to a customer-safe screen.
  - public admin bundle is not served.
  - customer screens expose no POS/admin escape controls.
- POS/KDS smoke:
  - POS auth refresh survives F5.
  - POS cash and card surfaces load without critical JavaScript crash.
  - KDS chef surface loads and displays orders.

## Physical Device Checklist

| Area | Scenario | Expected Result | Human Initials | Result |
|---|---|---|---|---|
| Kiosk touchscreen | Browse categories, open a composed product, select choices, return before submit | Touch targets work, no admin/POS escape appears |  | PENDING |
| Kiosk payment | Choose cash-at-counter | Customer sees clear instruction to pay at counter; order goes to KDS as pending payment |  | PENDING |
| KDS display | Receive pending counter order | Badge `PAIEMENT COMPTOIR - NON REGLE` is visible and readable from kitchen distance |  | PENDING |
| POS counter | Collect kiosk cash order | Order disappears from pending panel, fiscal receipt is available only after collect |  | PENDING |
| Fiscal printer | Print fiscal ticket after collect | Ticket prints with fiscal sequence and no duplicate unless reprint flow is used |  | PENDING |
| Non-fiscal slip | Print/reprint counter slip before collect | Slip is labelled as order slip, not fiscal receipt |  | PENDING |
| Cancel path | Cancel pending counter order at POS | Order leaves KDS/pending panel; no fiscal sequence is consumed |  | PENDING |
| Network loss | Disconnect kiosk after menu load | Kiosk remains locked, shows stale/connectivity state, no staff escape |  | PENDING |
| Reconnect | Restore network | Kiosk/POS/KDS recover without duplicate order or duplicate queue number |  | PENDING |
| Stock rupture | Set stock to out on managed item | Kiosk/POS show rupture state consistently; unavailable item cannot be sold from kiosk |  | PENDING |
| Product photo | Change product image in dashboard | Kiosk catalog refreshes with new image after sync/reload |  | PENDING |

## Release Rule

Commercial PASS requires all physical rows to be PASS or explicitly waived by the human owner with a dated note. Until then, the technical verdict is local PASS and the release verdict is HOLD_HARDWARE_SIGNOFF_PENDING.
