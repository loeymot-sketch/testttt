# Wave B — Adversarial review (rush-100 round-1)

**Verdict: NO-GO.** Three P1 defects gate this round; one P0 NF525 audit-liability row, plus six P2 quality issues.

## Drivers (block green)

- **P1 console errors** (WB-R1-01): `pos-app.js` throws unhandled-promise rejections at every POS V4 page load. Stack origin is application code (a reactive `get value` getter), 37 occurrences across 8 states.
- **P1 sidebar truncation** (WB-R1-02): Two categories render as "Sandwich..." with **no aria-label and no title** — sighted operators and screen-reader users cannot distinguish Sandwich Cayenne from Sandwich Classique. Blocks primary catalogue navigation.
- **P1 payment modal stuck** (WB-R1-03): On every receipt state the `#orderpayment` modal stays `.active` and `#receiptModal` is never opened, while a "Trop de requêtes" toast fires. Spec status=200 + DB row 1324 (fiscal_seq 294) shows the cash POST actually succeeded — a downstream 429 polluted the success chain. Cashier sees no ticket; high duplicate-click risk.

## Extra hits GStack missed

- WB-R1-04: **S4** wizard launched Sandwich Cayenne instead of Sandwich Classique — same selector bug as S10, report flagged only S10.
- WB-R1-09: **DB row 1325 has fiscal_sequence_no=NULL with status=4** — NF525 gap requiring fiscal_alloc_error_at retry cron audit.

## Confirmations / clarifications

F-W-B-01 (cart fallback): CONFIRMED + S10 actually added Sandwich Cayenne ×3, not 3 distinct items (WB-R1-08). F-W-B-02 (receipt overlay): CONFIRMED, root cause is success-chain interrupted by 429 toast (WB-R1-03). F-W-B-03 ("Toutes les" default): P3 cosmetic, no action. NF525 chain integrity for order 1324: unverifiable from file artifacts, not refuted.

Frozen zone clean (`pos-wizard.js` diff = 0). i18n leak scan: 0 hits across 32 DOM dumps.
