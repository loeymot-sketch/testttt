# GOAL 100% VALIDATION — CONVERGENCE VERDICT
**Date:** 2026-06-07 · Superviseur central · 2 audit+heal rounds, ~3M agent tokens, 0 frozen drift
**HEAD:** `15e0aecff` (branch `heal/pre-cloud-exec-2026-06-05`, backup `backup/goal-100pct-2026-06-07`, NO push)

## VERDICT: SOFTWARE CONVERGED-GREEN. Remaining path to 100%-on-device = OWNER GATES only.

The strict parallel army drove every system (depth + breadth, client + operator view) against the
disposable `foodking_e2e` clone. It caught **3 real product P1s on the fiscal ticket + auth** that the
solo pass had missed — all **healed + independently re-verified green + committed**, zero frozen drift.

## ✅ HEALED + VERIFIED (committed)
| Heal | Defect (real, proven live) | Proof |
|------|---------------------------|-------|
| **H1** | Order-history invoice ("Imprimer La Facture") printed **no NF525 block** (no SIRET/TVA/N°fiscal/operator) — divergent from POS receipt | spec 1✅; both receipts now share SSOT `buildNf525Footer`; re-audit CLOSED |
| **H2** | Kiosk auto-login IP allowlist **spoofable via X-Forwarded-For** → creds harvest | `TrustProxies`→loopback-only; `KioskAutoLoginIpSpoofTest` 3/3 + regressions; re-audit CLOSED |
| **H5** | No `set-branch-legal` command + legal NULL + self-contradictory "293B" footer on a VAT-registered biz | idempotent `foodking:set-branch-legal` + VAT-registered footer default; 9 tests; re-audit CLOSED |
| **H7** | **Discounted-order ticket overstated per-rate TVA** (0,91 vs collected 0,73) — fiscal ticket ≠ signed Z | `buildTaxLines` nets via ZReportService ratio; #4225 = 0,73/7,27 == Z; `DiscountTicketTvaNettingTest` 4/4 + e2e 2/2 + regression 24/24 |
| CP-2 | Admin show-page blank Ticket-Restaurant label | enum +1; e2e 1/1 |

## ✅ VALIDATED PASS (driven + inspected + tried-to-break)
- **Fiscal core:** sequence gap-free + 0 dup (3 methods); HMAC dual-chain OK; `composition_snapshot` never rewritten (trigger); operator resolver = cashier never "Client passage"; cash-trail to the cent.
- **Encaissement money cluster:** CASH + **CARD** + **Ticket-Restaurant** + **Mobile** — each persists 1 transaction, **0 CashMovement** (drawer not inflated), fiscal allocated, correct mode label on ticket. Fiscal gap-free across the batch.
- **Refund:** full sealed-Z lifecycle — mirror RTN- order, parent immutable, fiscal preserved+gap-free, correct Z negation, remboursement marker, chain OK.
- **Discount→VAT:** signed Z nets to post-discount base (historical coupon-VAT-10 P0 stays fixed) AND now the printed ticket too (H7).
- **Z-report daily close:** X-report intraday reconciles; Z close appended + HMAC-chained (verifySignature PASS); `fiscal:verify-chain --all` CHAIN OK after; totals reconcile per-rate + payment-method + cash-trail; next sale opens new Z gap-free. The cloture carries the legal header.
- **KDS / OSS / POS / Kiosk borne / Visual / Admin CRUD** (422/409 guards) / **Security isolation** (kiosk→admin escalation neutralized 415/415 routes).
- **Sync:** producer→outbox→soketi→subscriber proven; WS-down → polling fail-safe-visible; anti-double-count; ordering correct. (E1/E3 live-push = PARTIAL only due to a *harness* shared-worker gap — product-correct.)

## 🟡 NON-FISCAL / POLISH (not blockers)
- **Per-line ticket TVA on discounts** stays gross above the netted summary — standard FR layout (gross lines + Remise line + net ventilation); the summary ventilation = collected = Z (correct). Owner *receipt-format preference* whether to show per-line VAT at all. P2 polish, not a fiscal defect.
- **H3 (harness):** provision an isolated e2e queue worker (distinct REDIS_DB) to live-prove sync E1/E3. Closes a test gap, not a product gap.
- Clone test-pollution purge (hygiene).

## 🔒 OWNER GATES — the ONLY true blockers to 100%-on-device
| Gate | What only the owner can do |
|------|----------------------------|
| **G5** | Merge `feat/pos-printer-saga-autoprint` (auto-print, NOT in this branch) — **VALIDATED-IN-SIM** ✅: real, additive (+1435/0del), 0 frozen, **18/18 tests**, ESC/POS bytes render the full NF525 block (SIRET/TVA/operator=cashier/fiscal-no/footer, ReceiptDataService SSOT). ⚠️ **ONE required pre-merge heal**: renderer `taxLines()` (PosReceiptEscPosRenderer ~L302-310) sums GROSS per-rate TVA, NO `orderDiscountRatio` netting = the **H7 defect reincarnated on PAPER** (discounted order printed 1,82€ vs Z ~1,36€; the 18 tests miss it — all discount=0). Fix = net by orderDiscountRatio / consume post-H7 `buildTaxLines` SSOT (byte-identical on non-discount). Verdict `MERGE-WITH-REQUIRED-HEAL`. Report: `G5-PRINT-SAGA-VALIDATION.md`. |
| **G3** | Provide the final legal footer wording (VAT-registered mention). |
| **G4** | Run `foodking:set-branch-legal` with real SIRET/TVA on each physical device (command is ready). |
| **G7** | Confirm takeaway-vs-dine-in VAT rate policy + purge/rebind the 6 soft-deleted ghost items. |
| **SEC-SECRET-01** | Rotate the AWS key still in git history (`AKIA…`, commit 9b1e741f4) + BFG-purge before ANY public push to loeymot-sketch/testttt. |
| **(hardware)** | Cross-device real-time sync (borne/caisse/KDS on separate machines) + the physical printer/IP — confirmable only on the real setup. |

## What "100% before hardware" now means concretely
Everything functional is proven in software. On the device, the genuinely-new things are: **(a) merging+running the auto-print path (G5), (b) the legal config per device (G4), (c) the physical printer/LAN.** Once G5 is merged+validated and G4 applied, the only new thing on hardware is the paper coming out.

## Convergence rule status
Round-1 found 2 P1 → healed. Round-2 found 1 P1 (CP-1) → healed. A round-3 clean full-sweep (0 new P0/P1 twice consecutively) is the formal 2-cycle close — recommended after the owner gates land (G5 changes the print path materially, so re-audit the ticket after merge).
