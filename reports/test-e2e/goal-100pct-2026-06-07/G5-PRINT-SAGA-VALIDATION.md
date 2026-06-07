# G5 — PRINT-SAGA VALIDATION (server-side ESC/POS auto-print)

**Date:** 2026-06-07 · G5 validation agent (isolated throwaway worktree, READ-ONLY + sim) · NO push / NO merge
**Branch under test:** `feat/pos-printer-saga-autoprint` @ commit **`e446a2084`** (verified clean, no uncommitted mods)
**Merge target (where the H1/H7 fiscal heals live):** `heal/pre-cloud-exec-2026-06-05`
**Sim DB:** sqlite `:memory:` (phpunit.xml override) — **`foodking` operating DB never touched**

---

## VERDICT: `MERGE-WITH-REQUIRED-HEAL` — branch is structurally sound, plumbing works in sim, but it silently re-opens the H7 fiscal defect on the printed paper for **discounted** orders.

| Question | Answer |
|----------|--------|
| (a) Is the branch SOUND? | **Mostly YES** — clean additive feature, 0 frozen-zone, sound auto-print plumbing. **One fiscal correctness gap** (H7 on paper). |
| (b) Do its print tests pass in sim? | **YES — 18/18 (62 assertions), 0 fail**, sqlite `:memory:`, `vendor/bin/phpunit` (never `php artisan test`). |
| (c) Does sim auto-print render the full NF525 ticket (H1/H5 block)? | **YES for the header/footer block** (SIRET/TVA/operator/fiscal-no/total/legal-footer all present, sourced from `ReceiptDataService` SSOT). **NO for H7** (per-rate TVA on discounts is gross, not netted). |
| (d) Conflict risk + merge rec | **No textual conflict** (zero file overlap with H1/H7). **Semantic conflict:** merging as-is reintroduces the just-healed H7 defect on the physical printout. |
| (e) Blocker | **G5 clean-merge blocker = the renderer's `taxLines()` must net per-rate TVA by `orderDiscountRatio`.** Owner-gated heal (read-only mandate here). |

---

## 1. What the branch adds (commit e446a2084 — purely additive, 1435 insertions, 0 deletions, 10 files)

| File | Role |
|------|------|
| `app/Services/Receipt/PosReceiptEscPosRenderer.php` (431 L) | Order → raw ESC/POS bytes for SAGA SGPR-200II (80mm, CP858). Pure, no DB write/network. |
| `app/Services/Receipt/PosReceiptAutoPrinter.php` (178 L) | Best-effort core: atomic claim `receipt_print_count 0→1`, send, release-on-fail, NF525 audit `pos.receipt.print`. **Never throws.** |
| `app/Listeners/PrintPosReceiptOnOrderCreated.php` | Inline POS sale (`source_surface='pos'`, PAID). Fired by `OrderCreated` (**`DispatchableAfterCommit`** — post-commit). |
| `app/Listeners/PrintPosReceiptOnOrderPaidAtCounter.php` | Deferred counter-collection + kiosk Plan-B paid at counter. Fired by `OrderPaidAtCounter`. |
| `app/Providers/EventServiceProvider.php` | Wires both listeners. |
| `app/Console/Commands/ConfigurePosReceiptPrinterCommand.php` | `artisan pos:configure-receipt-printer <ip>` — 1-command setup + test print. |
| `config/pos.php` | `auto_print_receipt` kill-switch (`POS_AUTO_PRINT_RECEIPT`, default true) + `receipt_printer_station`. **NOT a frozen file.** |
| `docs/hardware/SAGA_SGPR-200II_CAISSE_SETUP.md` (208 L) | LAN no-driver setup doc. |
| `tests/Feature/Receipt/PosReceiptEscPosRendererTest.php` (5 tests) | Renderer NF525 bytes. |
| `tests/Feature/Receipt/PosReceiptAutoPrintListenerTest.php` (13 tests) | Listener claim/idempotency/best-effort. |

The transport stack it relies on (`EscPosPrinterService`, `EscPosCommandBuilder`, `PrinterTransportInterface`, `NullPrinterTransport` via `printing.bypass`) already exists on the branch — so SIM mode (no physical printer) is fully wired.

---

## 2. Plumbing soundness — VALIDATED

- **Atomic 1-print claim:** conditional `UPDATE ... WHERE COALESCE(receipt_print_count,0)=0` → whichever of the two events arrives first claims-and-prints; replays / second event = no-op. No double-print. (`PosReceiptAutoPrinter.php:79-87`)
- **Best-effort / fail-safe:** entire path wrapped; a printer error can never escape into the already-committed order/payment flow. Failed send **releases the claim** so a manual reprint is still treated as the original. (`:102-111`, `:145-155`)
- **Post-commit ordering:** `OrderCreated` uses `DispatchableAfterCommit` (gate C9 / KI-001) — order + fiscal seq persisted before the listener reads. Correct.
- **Scope:** listener guards `source_surface==='pos'` — kiosk/web/admin orders skipped; counter path handled by the sibling listener. No cross-surface mis-print.
- **NF525 audit:** every successful auto-print writes an `AuditLogService` row (`action=pos.receipt.print`, `source=auto_print`).
- **BranchScope bypass justified:** listeners run post-commit with no authenticated branch user; target branch comes from the order itself.
- **Sim test run:** `vendor/bin/phpunit tests/Feature/Receipt` → **OK (18 tests, 62 assertions)** in 3.2s, sqlite `:memory:`.

---

## 3. Conflict risk with current heals (H1 / H7) — the headline

**Files:** zero overlap. The print commit does NOT touch `ReceiptDataService.php`, `OrderDetailsResource.php`, or `PosOrderReceiptComponent.vue`. No git merge conflict.

**Merge-base** of print-saga and the heal branch = `ad29e7875` — the commit *immediately before* `e446a2084`. So the print renderer was written **before** the H1/H7 heals landed and never saw them.

### H1 (fiscal header) — ✅ ALIGNED
The renderer pulls the 6 NF525 header fields from the **same SSOT** the H1 heal established: `ReceiptDataService::buildForOrderModel()` (`PosReceiptEscPosRenderer.php:49`). SIRET, TVA-intra, fiscal sequence no, register/caisse id, operator name, legal footer all flow from there. **Empirically confirmed** (see §4) — the operator line shows the cashier name, never "Client passage".

### H5 / G3 (legal footer) — ✅ ALIGNED
Footer printed from `header['pos_legal_footer']` (SSOT). Empirically present on both rendered tickets.

### H7 (per-rate TVA netting on discounts) — ❌ BROKEN ON PAPER
- **Post-H7** `OrderDetailsResource::buildTaxLines()` nets **both** the per-rate tax and HT base by `orderDiscountRatio() = (subtotal − discount)/subtotal` so the ticket's per-rate TVA == collected == signed Z (`OrderDetailsResource.php:269-270` on heal branch).
- **Print-saga** `PosReceiptEscPosRenderer::taxLines()` sums **gross** per-item `tax_amount` with **no ratio** (`PosReceiptEscPosRenderer.php:302-310`). Its own docblock says it "mirrors `buildTaxLines()`" — true, but the **pre-H7** version.
- The renderer's `taxLines()` is its ONLY per-rate ventilation; there is no separate netted summary block. So on a discounted order the printed fiscal ventilation is gross = overstated = inconsistent with the printed TOTAL and the signed Z. **This is the H7 defect, reincarnated on the physical paper.**

> Note vs the convergence verdict's accepted "🟡 polish": on-screen, the gross per-line VAT is *above* a netted summary ventilation (accepted FR layout). The printed ticket here has **no netted ventilation at all** — the gross line IS the summary. So this is a genuine fiscal divergence, not the accepted layout.

### Why the 18 green tests do NOT catch it
Every test order on the branch sets `'discount' => 0` (`PosReceiptEscPosRendererTest.php:49,66`; `PosReceiptAutoPrintListenerTest.php:93,110`). At discount=0 the ratio is 1.0, so gross == netted and the assertions pass. The discounted path is **uncovered** — exactly why H7 wasn't caught here originally (the GOAL army found it on the heal branch, not on this branch). **A green count on this branch is not evidence against this finding.**

---

## 4. Empirical sim evidence (throwaway dump test, since removed — worktree clean)

Rendered two orders through `PosReceiptEscPosRenderer` and decoded the ESC/POS bytes to text.

**NORMAL order (NF525 block — H1/H5 intact):**
```
LE CAYENNE
SIRET 10417050100019
TVA FR19104170501
Commande            ORD-G5-NORMAL
Ticket fiscal       #4224
Caisse              CAISSE-01
Operateur           <cashier name>      <-- H1: cashier, not "Client passage"
2x Tacos Poulet         17,00 €
Sous-total              17,00 €
TOTAL A PAYER           17,00 €
TVA
  10%  HT 15,45 €        TVA 1,55 €
Especes                 20,00 €
  Rendu                  3,00 €
TVA acquittee sur les debits - E.DELICE SAS   <-- H5/G3 legal footer
```

**DISCOUNTED order (H7 divergence — the blocker):**
```
Commande            ORD-G5-DISCOUNT
Sous-total              20,00 €
Remise                  -5,00 €
TOTAL A PAYER           15,00 €
TVA
  10%  HT 18,18 €        TVA 1,82 €    <-- GROSS. Should be ~1,36 € (netted by 15/20).
Carte bancaire          15,00 €
```
Total collected = 15,00 € ; collected/Z-signed TVA = 1,82 × (15/20) ≈ **1,36 €**. The paper prints **1,82 €** (gross pre-discount). **H7 divergence CONFIRMED on the printout.**

---

## 5. Frozen-zone check — ✅ CLEAN

The commit touches **0 of the 13 frozen files** (verified file-by-file against `CLAUDE.md §7` / `reference_frozen_zones.md`). `config/pos.php` is explicitly the documented NOT-frozen config-adjacent file. The feature is entirely additive (new services/listeners/command). No `--no-verify`, no logic change to any fiscal service.

---

## 6. Recommendation for the owner's merge decision

1. **Do NOT clean-merge as-is.** It silently re-opens H7 on the printed ticket for every discounted/coupon/loyalty order — a fiscal-document inconsistency with the signed Z (NF525).
2. **Required pre-merge heal (1 method, owner-gated — NOT done here, read-only mandate):**
   `PosReceiptEscPosRenderer::taxLines()` must net per-rate `tax` and `base_ht` by `orderDiscountRatio` — ideally by **consuming the post-H7 `buildTaxLines` SSOT** (extract a shared service/method) so the printed and on-screen ventilation can never drift again. On non-discount orders ratio=1.0 → byte-identical to today (no regression).
3. **After the heal lands:** re-run the 18 print tests **plus a discounted-order assertion** (currently absent), and re-audit the printed ticket against the signed Z (the convergence verdict already flags that G5 "changes the print path materially → re-audit the ticket after merge").
4. **Everything else is merge-ready:** atomic claim, best-effort fail-safe, post-commit wiring, NF525 audit, SIM transport, configure command, 0 frozen — all sound and sim-validated.

**Hardware caveat (unchanged, owner-only):** physical print on the real SAGA SGPR-200II was never exercised here (no device in this env). Sim path is proven; paper coming out is the one thing only the real LAN printer can confirm.

---
*Evidence basis: `git show e446a2084`, source reads of the 6 new app files + post-H7 `OrderDetailsResource` on the heal branch, `vendor/bin/phpunit tests/Feature/Receipt` = 18/18, and a throwaway render-dump of a normal + discounted order (since removed; worktree clean). No code modified under any shared/main worktree. No push, no merge.*
