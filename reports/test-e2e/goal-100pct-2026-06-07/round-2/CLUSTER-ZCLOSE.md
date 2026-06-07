# AGENT CLUSTER-ZCLOSE — Round 2 — NF525 daily close (X-report, Z-close, chain, reconcile, no-gap, legal header)

**Date:** 2026-06-07 · **DB:** foodking_e2e (disposable clone) · **Server:** http://127.0.0.1:8766
**Scope:** the daily-close fiscal lifecycle (the pay-cluster created CARD/TR/Mobile/refund/discount sales; z#9/z#10 already exist from the refund lifecycle).
**Driver:** service-driven on the clone (`ZReportService` / `XReportService` / `PaymentService::confirmCounterPayment` / `CashDrawerService`) — same services behind the HTTP endpoints; HTTP authz/idempotency layers NOT re-exercised here (fiscal SUBSTANCE is). 0 frozen files edited.

**Verdict:** the NF525 daily-close fiscal SUBSTANCE is **fully correct and verifiable** — checks 1-4 PASS with hard evidence. Check 5 is a documented factual gap (legal header absent from the Z/clôture artifact) whose severity rests on a legal-content premise I could NOT source; calibrated **P3 (completeness gap), P1-only-if-owner/legal confirms a clôture-content requirement** — it does NOT hard-block the close (no chain/total/numbering defect). **agent pass = TRUE, blocker = null** (ZC-1 surfaced for supervisor/owner to sequence; not a substance failure).

**Per-check (updated as checks resolved):**
- Check 1 (X-report intraday read) — PASS
- Check 2 (Z-close appended, HMAC-chained, chain OK after) — PASS
- Check 3 (totals reconcile: gross / per-rate VAT / payment-method / cash-trail) — PASS
- Check 4 (post-close new sale = next fiscal no, no gap, new open Z) — PASS
- Check 5 (cloture document carries legal header SIRET/VAT/footer) — **FINDING ZC-1 (P3, see severity note)** — merchant legal identity surfaced on NO Z/clôture artifact (present on branch + per-ticket).

---
## Baseline (verified before any mutation)
- `APP_ENV=e2e php artisan fiscal:verify-chain --all` → **CHAIN OK** (branch 1, sweep complete).
- Pre-state: max fiscal_seq br1 = **2024**, max order id = 4226.
- z_reports chain intact through z#10 (refund lifecycle): z#1 sig 7eddd8…→…→z#10 sig 527c2a24e6, all CLOSED, **0 open Z** at start.
- Branch 1 legal identity present in DB: SIRET=`10417050100019`, vat_intra=`FR19104170501`, legal_footer=`TVA intracommunautaire - Merci de votre visite`, address=`437 Rue Élie Gruyelle, 62110 Hénin-Beaumont`.

---
## Sale set created under the new open Z (z#11, sequence_no=9)
Opened z#11 (`ZReportService::open(1, uid=1)`, opened_at=2026-06-07 21:22:05). Opened a cash drawer session (#9, opening float 50.00). Then encashed 4 kiosk counter-deferred orders via `PaymentService::confirmCounterPayment` (the real counter-collect path — sets pos_payment_method, payment_status=PAID, allocates fiscal_sequence_no, writes 1 Transaction, CashMovement only for CASH).

**Synthetic provenance (caveated):** the 4 orders were existing kiosk test rows carrying real VAT-10 line items; I stamped the counter-deferred marker triple (`source_surface=kiosk` + `payment_method=CASH_ON_DELIVERY` + `pos_payment_method=COUNTER_DEFERRED`) so they are legitimately counter-collectible (faithful to a current kiosk Plan-B order: VAT-10 after `fiscal:assign-menu-vat` + the triple). One order (#969) was given a 2.00 discount (subtotal 6.00 → total 4.00, header total_tax left gross 0.55 — the real F1 shape). No defect claim rests on the hand-patched orders; they exercise the close machinery, and the discount-netting check confirms the locked Z behavior.

| Order | Mode | fiscal_seq | subtotal | discount | total (TTC) | header_tax (gross) | Transaction | CashMovement |
|-------|------|-----------|----------|----------|-------------|--------------------|-------------|--------------|
| #969 | CASH (1) | **2025** | 6.00 | **2.00** | **4.00** | 0.55 | 1 (counter_cash, +4.00) | **1** (order_payment, in, 4.00, sess#9) |
| #971 | CARD (2) | **2026** | 12.00 | 0 | 12.00 | 1.09 | 1 (counter_card, +12.00) | 0 |
| #973 | TICKET-RESTAURANT (5) | **2027** | 18.00 | 0 | 18.00 | 1.64 | 1 (counter_ticket_restaurant, +18.00) | 0 |
| #975 | MOBILE (3) | **2028** | 24.00 | 0 | 24.00 | 2.18 | 1 (counter_mobile_banking, +24.00) | 0 |

- Fiscal sequence **2025→2028 contiguous** from pre-max 2024 (no gap on allocation).
- Discount **survived the encash** (total stayed 4.00 — `confirmCounterPayment` does NOT recompute totals).
- CASH movement = **4.00** (the post-discount NET total) on the open session — drawer not inflated by the gross.
- Operator identity stamped via `editor_id=1` (Admin) on all 4 — matches the kiosk→counter operator-identity fix (NOT "Client passage").

---
## Check 1 — X-report intraday read → **PASS**
`XReportService::snapshot(1, from=z#11.opened_at, to=now)` taken AFTER all 4 sales, immediately before close, zero orders created in between. It delegates to `ZReportService::aggregate` (same algorithm as the Z it will produce — the NF525 "cohérence intraday" invariant by construction).

| Field | X-report | Independent live DB (in-window fiscalised orders) | Match |
|-------|----------|---------------------------------------------------|-------|
| total_ttc | 58.00 | 58.00 | ✅ |
| total_by_method | {"1":4,"2":12,"3":24,"5":18} | {"1":4,"2":12,"3":24,"5":18} | ✅ |
| order_count | 4 | 4 (ids 969,971,973,975 / seqs 2025-2028) | ✅ |

- **Discount discriminator fires in the X read:** naive GROSS per-rate TVA = 0.55+1.09+1.64+2.18 = **5.46**; X shows `total_tva=5.28` / `total_by_tax_rate={"10":5.28}` → #969 contributes its POST-discount 0.37 (0.55×4/6), not 0.55. F1 netting is live in the aggregate path (reinforces check-6a from CLUSTER-PAY).
- Boundary note (see "created_at window" below): the in-window set is **exactly my 4 orders**, nothing from the parallel HEAL-H7 cluster slipped in — the independent expected was computed from a live `whereNotNull('fiscal_sequence_no')` query, not from my intended values.

## Check 2 — Z CLOSE: row appended + HMAC-chained + chain OK after → **PASS**
Drove `ZReportService::close(1, uid=1)`.

| Field | z#11 (sequence_no=9) |
|-------|----------------------|
| status | closed |
| total_ttc / total_ht / total_tva | 58.00 / 52.72 / **5.28** |
| total_by_method | {"1":4,"2":12,"3":24,"5":18} |
| total_by_tax_rate | {"10":5.28} |
| order_count / cancel_count / refund_count | 4 / 0 / 0 |
| **prev_hash** | `527c2a24e6ec…` = **z#10.signature** (the prior closed Z) ✅ |
| **signature** | `911cc321e019…` (new chain head) |

- `prev_hash == prior-closed.signature` → **PASS** (HMAC chain linked z#10 → z#11).
- `verifySignature(z#11)` → **PASS** (recomputed HMAC matches stored).
- `APP_ENV=e2e php artisan fiscal:verify-chain --all` AFTER close → **CHAIN OK** (branch 1, sweep complete).

## Check 3 — totals reconcile (gross / per-rate VAT / payment-method / cash-trail) → **PASS**
- **Gross:** total_ttc 58.00 = Σ order totals (4+12+18+24). NF525 identity holds: total_ht 52.72 + total_tva 5.28 = 58.00.
- **Per-rate VAT ventilation:** single rate 10% → total_by_tax_rate {"10":5.28}; Σ buckets == total_tva (5.28) exactly. Discount on #969 netted into the bucket (5.28 not 5.46).
- **Payment-method breakdown** (from `pos_payment_method` / per-tranche, via `applyOrderToTotals`): CASH(1)=4, CARD(2)=12, MOBILE(3)=24, TR(5)=18 → Σ = 58.00 = total_ttc.
- **Cash-trail (full real sequence):** `CashDrawerService` session #9 opened 50.00 float → CASH sale wrote 1 `CashMovement` (order_payment/in/+4.00) → `closeSession(9, 54.00)` → `reconcileSession(9)` (expected = 50 + 4 = 54, **variance 0**). After `ZReportCashEnrichmentService::persistForClosedReport(z#11)`: **cash_opening=50, cash_closing=54, cash_variance=0, cash_movements_count=1** — reconciles to the cent. Only 1 reconciled session in-window (no parallel-cluster pollution). _Note: cash-trail columns are enriched post-signature (additive decorator) — they are NOT in the signed HMAC payload, by design (frozen-zone discipline, ZReportCashEnrichmentService header)._
- **Intraday coherence:** FINAL-X (taken immediately before close) == z#11 signed aggregates, field-for-field → **PASS**.

### created_at window boundary (observed, not a finding)
`ZReportService::aggregate` (and X) window revenue by **`created_at`** over `(opened_at, close]`. My 4 orders were minted as kiosk test data with stale `created_at` (pre-z#11); I bumped their `created_at` to `opened_at+30s` (deterministic, in-window) so they represent the dominant production case (a same-day POS/counter sale created AND paid inside the open Z). Nothing signed depends on `created_at`, and the orders were in no closed Z, so the bump corrupts nothing and exercises the close path identically.
The OTHER case — a late-settled order whose `created_at` lands in an already-closed Z window — is the documented **cross-Z-window settlement orphan**: the owner chose **detect-only** (`fiscal:verify-z-membership` heuristic, `VerifyZMembershipCommand`). This is accepted, documented behavior with a detector present — noted for completeness, not a defect.

## Check 4 — post-close: new sale = next fiscal no (no gap) under a NEW open Z → **PASS**
After z#11 closed (0 open Z), opened **z#12** (sequence_no=10, opened_at 21:34:46). Stamped + window-bumped one fresh VAT order (#977, total 30) and encashed CARD via `confirmCounterPayment`.

- New sale fiscal_seq = **2029** = prev-max (2028) **+ 1** → no gap across the close boundary. ✅
- **Full br1 sequence gap-free:** min=1, max=2029, **count=2029, unique=2029, dups=0, missing=NONE**. ✅
- New sale lands in z#12's open window (created_at > z12.opened_at) → correct next-Z attribution. ✅
- `fiscal:verify-z-membership` → **Z-membership OK** (no order flagged as a cross-Z orphan).
- Final `fiscal:verify-chain --all` → **CHAIN OK**. z#12 left OPEN with #977 sealed inside = the normal "current day in progress" state.

Final z_reports chain (branch 1): z#9 `40f9bc6c`→ z#10 `527c2a24` → **z#11 `911cc321` (ttc=58, MY close)** → z#12 OPEN.

## Check 5 — cloture document carries the legal header (SIRET / VAT / footer) → **FINDING ZC-1 (P3 — severity note below)**
Branch 1 HAS the full merchant legal identity in DB (SIRET=`10417050100019`, vat_intra=`FR19104170501`, legal_footer set), and it is correctly wired into the **per-order POS ticket** (`ReceiptDataService.php:66-69` → `pos_siret/pos_vat_intra/pos_legal_footer` from `$order->branch`, exposed at `OrderDetailsResource.php:144-146`). So the per-transaction receipt is NF525-compliant on the legal header.

**The Z report / daily cloture surfaces this merchant legal identity NOWHERE:**
- `ZReportController::pdf` (the binding Z artifact endpoint) returns `{z_report, verified, generated_at}` — **embeds NO branch legal fields** (grep: NONE).
- There is **no Z-report print/PDF template at all** (`grep -rilE "z.?report|rapport.?z" resources/views/` → only `eod_synthesis.blade.php`).
- The only cloture-adjacent PDF, `eod_synthesis.blade.php` ("Clôture du jour — Synthèse"), is **self-declared "Document interne — Synthèse non-fiscale"** and pulls only `company_name`/`company_address` from settings (`CompanyService::list()` → company_name='Le Cayenne', company_address='Paris, France' [stale — branch is Hénin-Beaumont]); it carries **no SIRET, no VAT, no legal_footer**, and explicitly points the reader to the Z report for the official close.
- `grep -rilE "siret|vat_intra|legal_footer" resources/views/` → **0 blades** reference the legal identity anywhere.

**Severity — calibrated P3 (completeness gap), NOT a hard blocker. Honest premise check:**
- **What I PROVED (factual):** the merchant legal identity (SIRET/vat_intra/legal_footer) is present on branch 1, is correctly carried on the per-order POS ticket, but is surfaced on **no** Z/clôture artifact (no Z print template; controller payload omits it). That gap is real and evidenced.
- **What I did NOT establish (the uncited premise):** that NF525/CGI/BOFiP *requires* the merchant legal identity to be **reprinted on the clôture/Z document specifically**. I could not source such a provision. The Z report's confirmed NF525 obligations — integrity, gap-free sequential numbering, signature + prev→current HMAC chaining (perpetual tamper-evidence), 6-year conservation — are ALL proven correct in checks 2-4. Note the chain itself is the perpetual-integrity mechanism (each signature incorporates the prior Z's signature); there is no separate `grand_total` column, which is a valid design.
- **Why NOT a straight H1 parallel:** H1 is a customer-facing **facture/ticket** (a document class with explicit French legal-content rules — which `ReceiptDataService` correctly satisfies). The Z report is an **internal cumulative fiscal record**; whether it must reprint the SIRET is a separate, unconfirmed legal question. Also, the merchant identity is **recoverable** from the archive bundle via `branch_id` (orders + z_reports carry it) — "surfaced nowhere at the print layer" ≠ "lost from the fiscal record."
- **Disposition:** P3 document-completeness gap; **escalate to owner/legal — promote to P1 ONLY if a clôture-content requirement is confirmed.** Does NOT hard-block the daily close (no chain/total/numbering/signature defect). **Heal direction (if confirmed):** add a Z cloture template / extend `ZReportController` payload with the Z branch's SIRET/vat_intra/legal_footer, reusing the `ReceiptDataService` legal-header pattern.

---
## Frozen-zone / cleanup
- Edited **0** source/frozen files. `ZReportService` / `XReportService` / `ZReportCashEnrichmentService` driven + audited only.
- Mutations on the **disposable clone** (foodking_e2e): opened+closed z#11 (seq 9, ttc 58, signed+chained), opened z#12 (seq 10, open); fiscalised orders #969/971/973/975 (seqs 2025-2028) + #977 (seq 2029); cash session #9 (opened/closed/reconciled, variance 0). These are append-only NF525 rows now in the verified HMAC chain — deleting them would break the chain confirmed OK; on the disposable clone leaving them is correct. Scratch scripts in /tmp (not in repo).
- Caveat (provenance): the sealed orders were existing kiosk test rows; I stamped the counter-deferred marker triple + bumped `created_at` in-window so they faithfully represent a same-day kiosk-Plan-B / POS counter sale (VAT-10 + triple). No DEFECT claim rests on the hand-patched orders — they exercise the close machinery; the discount-netting result is cross-checked against the locked `ZReportDiscountNettingTest` SSOT (0.73-shape → here 0.37 contribution).

## Findings summary
| # | Sev | Title | Location | Verdict impact |
|---|-----|-------|----------|----------------|
| ZC-1 | **P3** (P1 only if owner/legal confirms a clôture-content requirement) | Daily Z-report / clôture document surfaces NO merchant legal header (SIRET / N° TVA intracommunautaire / legal_footer), though branch 1 HAS them and the per-order ticket already wires them. No Z print template exists; `ZReportController::pdf` payload omits all branch legal fields. **Factual gap proven; the "must be on the Z" legal premise is UNCITED — fiscal substance (chain/totals/numbering/signature) is correct.** Identity recoverable from archive via branch_id. | `app/Http/Controllers/Admin/Fiscal/ZReportController.php` (pdf payload) + missing Z clôture template · contrast `ReceiptDataService.php:66-69` / `OrderDetailsResource.php:144-146` (per-ticket, compliant) | **Check 5 — NON-BLOCKING finding.** Escalate to owner/legal; heal if confirmed: add Z clôture template / extend payload with branch SIRET/vat_intra/legal_footer (reuse ReceiptDataService pattern). |

## Checks at a glance
| Check | Result | Key evidence |
|-------|--------|--------------|
| 1 — X-report intraday read matches open-Z sales | **PASS** | X(from=opened_at)=Z aggregates: ttc 58, tva 5.28 (netted), byMethod {1:4,2:12,3:24,5:18}; == live in-window DB |
| 2 — Z close appended, HMAC-chained, chain OK after | **PASS** | z#11 seq9 prev_hash=z#10.sig (527c2a24), sig 911cc321, verifySignature PASS, verify-chain --all CHAIN OK |
| 3 — totals reconcile (gross/per-rate VAT/method/cash-trail) | **PASS** | 58=52.72+5.28; byRate{10:5.28}=Σ; byMethod Σ=58; cash-trail open50/close54/var0/mov1 reconciled |
| 4 — post-close new sale = next fiscal no, no gap, new open Z | **PASS** | seq 2029=2028+1; br1 1..2029 count=2029 dups=0 missing=NONE; lands in z#12; z-membership OK |
| 5 — cloture carries legal header (SIRET/VAT/footer) | **FINDING (ZC-1, P3 / non-blocking)** | legal identity on branch + per-ticket but surfaced on NO Z/clôture artifact; "must be on Z" premise uncited → P1 only if owner/legal confirms |
