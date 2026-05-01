# Gate Brief - E2E Hardware Composer Signoff

Gate ID: `HG-E2E-HARDWARE-COMPOSER-SIGNOFF`
Date drafted: 2026-04-27
Status: `PENDING_HUMAN_GATE`

## Decision Needed

Approve the release/UAT checklist for Product Composer across real surfaces:

- dashboard;
- POS;
- kiosk;
- KDS;
- OSS;
- printer/cash drawer/payment simulation if present in the flow.

## Required Proof Before Commercial Release

- Admin creates a composed product with photo.
- POS and kiosk both see it.
- POS and kiosk calculate the same backend quote.
- Kiosk remains locked with no admin/caisse path.
- Stock rupture is visible on both POS and kiosk.
- KDS lifecycle still works.
- Queue number remains unique.
- Payment simulation and handover path are verified.

## Invariants

- No release PASS from tests alone; hardware/UAT proof is required.
- Claude/GPT final audit report must reference exact commands, screenshots or Playwright traces, and known residual risks.

## Human Approval

Decision: `PENDING_HUMAN_GATE`
Approver:
Date:
Notes:
