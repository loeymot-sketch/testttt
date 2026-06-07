# AGENT 09 — FISCAL / NF525 / TICKET — Round 1 Report
**Date:** 2026-06-07 · **Scope:** Ticket content (POS+Kiosk origins), NF525 chain/sequence, Z/X report, auto-print SAGA path, non-CASH/refund/duplicata markers, 6 NULL-tax items, legal config.
**Environment:** clone `foodking_e2e` @ :8766 (mutations), operating `foodking` READ-ONLY for parity. **No product code touched** (only added 2 `zz-*` specs + this report).

---

## TL;DR VERDICT
The fiscal **engine** (sequence, HMAC chain, Z/X close, refund counter-entry, tax_lines, operator identity, SSOT ReceiptDataService) is **solid and well-tested** (209 fiscal/receipt feature tests green). The **blockers are configuration + an unmerged path + a divergent receipt component**, not engine logic:

- **P1 G3** — `legal_footer` says "TVA non applicable art.293B CGI" on a **VAT-registered** business that bills VAT-10 (self-contradictory ticket). Prints on every NEW ticket.
- **P1 G4** — operating DB `foodking` has **SIRET / vat_intra / legal_footer / register_id ALL NULL** → production tickets would render NO fiscal header at all. No `set-branch-legal` command exists.
- **P1** — `PosOrderReceiptComponent.vue` (the order-history/show receipt) is **missing the entire NF525 header + tax_lines + fiscal footer + DUPLICATA marker** that `ReceiptComponent.vue` has. Two divergent receipts.
- **P1 G5** — auto-print SAGA ESC/POS path (`feat/pos-printer-saga-autoprint` e446a2084) is **unmerged + behind owner gate** → cannot be sim-proven this round. Static review = sound.
- **P2** — `register_id` NULL on BOTH DBs → "Caisse" line never prints.
- **P2** — `PosOrderReceiptComponent` payment-label map missing codes 5 (TR) & 6 (counter-deferred) → blank "Type de paiement" for those tenders on the history receipt.
- **P3 (DOWNGRADED from §4 P1)** — "6 items tax_id NULL": all 6 are **soft-deleted** (`deleted_at` set, both DBs), never referenced by any order, not sellable. ALL 45 live items are on VAT-10. NOT a live defect; latent only if un-deleted without VAT reassignment.

`blocking = true` (P1s present).

---

## EVIDENCE PER CHECKLIST

### TICKET CONTENT (resolved via `OrderDetailsResource` tinker, ground-truth payload feeding the receipt)
POS-CASH #4160 (counter, creator=1):
```
siret=10417050100019  vat=FR19104170501  fiscalseq=2001  register=NULL
operator=Admin Le Cayenne   <-- cashier, NOT "Client passage" (M11-01 heal works)
tax_lines=[{VAT,10%,base_ht 1.36, tax 0.14}]   payments=[{method:1=Espèces,1.50}]
audit_fingerprint=dec613b10811   legal_footer="...TVA non applicable art.293B..."  <-- WRONG
```
SIRET ✅, TVA intra ✅, operator=cashier ✅, N° fiscal ✅, HT+désignation+qté ✅, ventilation TVA par taux ✅, totaux HT/TVA/TTC ✅, tendered ✅. **Footer ❌ + register ❌.**

### Kiosk origin — discriminating cases
- Pure self-service **#4133 / #4128** (creator_id NULL + editor_id NULL): `operator=NULL, register=NULL`. Ticket shows SIRET/VAT/N°fiscal but **no operator and no caisse id** — automatic sale, no human operator. The header block still renders (gated on siret OR vat). This is arguably acceptable for NF525 self-service (the machine is the "operator"), BUT with `register_id` also NULL there is **zero device identifier** on the fiscal ticket of an automatic sale → recommend printing the kiosk machine id as the caisse/register. (Owner judgement — flagged, not auto-fixed.)
- Counter-collected **#4161 / #101** (editor_id=1): `operator=Admin Le Cayenne` ✅ — the editor_id (counter cashier) correctly surfaces.

### Non-CASH (resolved)
`#101 CARD(2)`, `#110 MOBILE(3)`, `#106 TR(5)` → `payments_breakdown` carries the right numeric method (mapped to FR labels Espèces/Carte/Paiement mobile/Titre-resto in `ReceiptComponent.paymentMethodLabel` and in the ESC/POS renderer `paymentLabel`). **But `reference=null` on all non-CASH** — no SumUp ref / TR number captured on the ticket. (§4 target wanted "CARD/SumUp ref, Ticket-Resto" — reference is empty; encaissement plumbing owned by agent 04.)

### Refund (#227 RTN, parent=226)
Own gap-free `fiscal_sequence_no=39`, negative total -11€, negative tax -1€, status=22, parent_order_id=226 → NF525-correct counter-entry (not a deletion). `ReceiptRemboursementMarker` triggers on `parent_order_id>0` ✅. `RefundWithCounterEntryService` writes `order.refund.counter_entry` audit + allocates fresh seq in current Z window. `RefundCashMovementRecordedSentinel` (5 tests) + `StockReleaseOnRefundTest` green.

### Duplicata
`ReceiptDuplicataMarker` fires on `receipt_print_count >= 2`, label `DUPLICATA #{count-1}`. No order with count>1 in clone to render live, but logic + i18n (`label.duplicata = "DUPLICATA #{n}"`) verified. The unmerged ESC/POS renderer also emits "DUPLICATA".

### Tax ventilation correctness
`OrderDetailsResource::buildTaxLines()` groups by (tax_name, tax_rate, tax_type), TTC line→HT base extraction. Per-item line gated `v-if="item.tax_rate > 0"`. Clone tax distribution: VAT-10 = 3124 lines/3068 orders (dominant/correct); No-VAT(0%) = 189 lines + NULL = 147 lines = **frozen historical test artifacts** captured BEFORE `fiscal:assign-menu-vat` ran. **ALL 45 live sellable items now on VAT-10** → NEW tickets correct. Historical 0-VAT snapshots are immutable (NF525-correct to preserve).

### 6 NULL-tax items — DOWNGRADED
items 16(Bacon),28-32(Bols Gourmands): `status=5` but `deleted_at=2026-05-28 19:32:39` on **both** DBs → **soft-deleted**, excluded by SoftDelete scope, never on the active menu. `fiscal:assign-menu-vat --dry-run` = "would re-point 0 items" (correctly ignores soft-deleted). `SELECT … order_items WHERE item_id IN (16,28-32)` = **0 rows** (never ordered). NOT a sellable/ticket defect. Latent P3 only.

### Sequence / Chain / Z
- Sequence **gap-free + no duplicates**: branch 1, cnt=2019, min=1, max=2019, span=2019, 0 dup. ✅
- HMAC chain: `fiscal:verify-chain --all` → **CHAIN OK** (every active branch). ✅
- Z open/close cycle on clone: `fiscal:open-all-active-branches` + `fiscal:close-all-active-branches` → opened/closed/signed (report #8 seq6), chain still OK after. `ZReportController::pdf()` exists. X-report controller + service exist (`XReportController`, `XReportService`). ✅
- Z-membership orphan detector: `fiscal:verify-z-membership` → "no numbered order flagged as cross-Z-window orphan". ✅

### Auto-print SAGA (G5, unmerged — STATIC review only, NOT sim-proven)
`feat/pos-printer-saga-autoprint` e446a2084 (+1435 lines, 10 files, 18 tests). Static assessment:
- `PosReceiptEscPosRenderer` (431L) reuses `ReceiptDataService` SSOT for the 6 header fields, mirrors `buildTaxLines`, prints SIRET/TVA/fiscalseq/Caisse/Operateur/per-rate-TVA/legal_footer/DUPLICATA/payment-label. **Sound** — BUT inherits the same wrong `legal_footer` (prints whatever is in DB).
- `PosReceiptAutoPrinter` (178L): atomic claim `COALESCE(receipt_print_count,0)=0 → 1` (single print), release-on-send-fail (manual reprint still = original), best-effort wrapped (never throws into committed order flow), NF525 `pos.receipt.print` audit emit, BranchScope-bypass justified (post-commit no auth user). **Architecturally correct.**
- 2 post-commit listeners (OrderCreated source=pos+PAID, OrderPaidAtCounter) cover all caisse encaissement flows; atomic claim prevents double print.
- **CANNOT sim-prove this round**: read-only + branch unmerged + G5 PENDING. Renderer tests ABSENT from current tree (confirmed). Recommend: G5 owner sign-off → merge → run the 18 tests + NullPrinterTransport sim → only paper output remains on hardware.

### Two divergent receipt components (NEW P1)
- `resources/js/components/admin/pos/ReceiptComponent.vue` (fresh-payment + tracker reprint): FULL NF525 header + tax_lines + footer + duplicata + remboursement markers. ✅
- `resources/js/components/admin/posOrders/PosOrderReceiptComponent.vue` (order-show `/admin/pos-orders/show/{id}` "Imprimer La Facture"): **NO fiscal header (no SIRET/TVA/register/operator), NO tax_lines per-rate, NO fiscal footer (no N° ticket NF525 / audit fingerprint / legal mentions), NO DUPLICATA marker**. Only has remboursement marker + company + items + totals + payment. Lines 1-155: confirmed absent. Payment map (L172-177) covers only codes 1-4 (missing 5 TR, 6 counter-deferred → blank label). **A ticket reprinted from order history is NOT NF525-conformant.**

---

## FINDINGS (severity-ordered)

**P1 — legal_footer wrong for VAT-registered** · `branches.legal_footer` (DB) rendered via `ReceiptComponent.vue:121-124` + `PosReceiptEscPosRenderer:175`. Repro: tinker any order → `legal_footer="E.DELICE SAS - TVA non applicable art.293B CGI"`; same order carries VAT-10 tax_lines. Owner gate G3 (correct mention text). Reco: set legal_footer to a VAT-registered mention (no "non applicable art.293B").

**P1 — production legal fields ALL NULL + no set-branch-legal command** · operating `foodking.branches` siret/vat_intra/legal_footer/register_id = NULL. `php artisan list | grep legal` = empty. Repro: `mysql -u root foodking -e "SELECT siret,vat_intra,legal_footer FROM branches"`. Consequence: prod ticket header block `v-if` evaluates false → NO SIRET on the printed fiscal ticket. Owner gate G4 + needs a device-level set mechanism (command/seeder/admin). 

**P1 — order-history receipt (`PosOrderReceiptComponent.vue`) omits all NF525 fields** · file lines 1-155: template contains NO fiscal-header block (no `pos_siret`/`pos_vat_intra`/`pos_register_id`/`operator_name`), NO `tax_lines` per-rate loop, NO nf525 footer (no fiscal_sequence_no / audit_fingerprint / legal_mentions), NO `ReceiptDuplicataMarker` — contrast `ReceiptComponent.vue:67-73,180-191,253-259` which has all of them. **Wiring confirmed by code**: `PosOrderShowComponent.vue:172` button "Imprimer La Facture" uses `v-print="printObj"` with `printObj.id="print"` (L429-430) → prints the `<div id="print">` which is exactly `PosOrderReceiptComponent`'s root (that component's L3). So the invoice-print button emits a ticket built from a template that lacks every NF525 fiscal field. **LIVE RENDER PROVEN** (`4160-cap.json`, `textContent` of `#print` on `/admin/pos-orders/show/4160`): rendered ticket = `Le Cayenne / 437 Rue Élie Gruyelle 62110 Hénin-Beaumont / Tel / Commande #0706264160 / 1 Coca-Cola 1,36€ VAT(10%) 0,14€ / Sous-total 1,36€ Total taxes 0,14€ Total 1,50€ / Type de paiement: Espèces / N°A0001 / Merci`. Boolean checks on that DOM: **hasSiret=false, hasTva=false, hasFiscalNo=false, hasOperator=false**. So the printed invoice from order history genuinely shows NO SIRET / NO TVA intra / NO N° ticket NF525 / NO operator. Reco: align `PosOrderReceiptComponent` with `ReceiptComponent` (add fiscal header, tax_lines loop, nf525 footer, duplicata marker, payment codes 5/6).

**P1 (GATED) — auto-print SAGA path unmerged behind G5** · `feat/pos-printer-saga-autoprint` e446a2084. Static review sound; not sim-proven (read-only + gate). Reco: owner G5 sign-off → merge → run 18 tests + NullPrinter sim.

**P2 — register_id NULL → no caisse line** · `branches.register_id` NULL on both DBs; `ReceiptComponent.vue:71` + renderer L97 gated on it. Caisse identifier never prints. Owner G4.

**P2 — history receipt payment map missing TR(5)/counter-deferred(6)** · `PosOrderReceiptComponent.vue:172-177` only maps 1-4. Repro: order #106 (pm=5) on show page → "Type de paiement:" blank. Reco: add codes 5,6 (the POS `ReceiptComponent` map at L391-397 already has 5).

**P2 — non-CASH tickets carry no payment reference** · `payments_breakdown[].reference=null` for CARD/TR/MOBILE (#101/#106/#110). SumUp ref / TR number not on ticket. Cross-owned with agent 04 (encaissement). 

**P3 — 6 soft-deleted items have NULL tax_id (latent)** · items 16,28-32 `deleted_at` set, never ordered, not sellable. Only a risk if restored without VAT reassignment. Reco: assign VAT-10 defensively or leave (non-blocking).

**P3 (info) — self-service kiosk ticket has neither operator nor register id** · #4133/#4128. Acceptable for automated sale but recommend printing kiosk machine id as the device identifier. Owner judgement.

---

## ARTIFACTS
- Specs: `zz-fiscal-ticket-audit-2026-06-07.spec.js`, `zz-fiscal-ticket-modal-2026-06-07.spec.js`, `zz-fiscal-ticket-cap-2026-06-07.spec.js`.
- **Live render capture** `__screenshots__/fiscal-ticket-2026-06-07/4160-cap.json` (textContent of `#print`, the order-history invoice) — proves the order-history ticket renders with hasSiret=false/hasTva=false/hasFiscalNo=false/hasOperator=false while correctly resolving items + payment label "Espèces" + VAT(10%) line. The POS-path ticket CONTENT (full NF525 header) proven via `OrderDetailsResource` tinker (exact payload feeding `ReceiptComponent`). Note: a pixel screenshot of the POS `ReceiptComponent` modal was not obtained (its `orderItems` come from Vuex `posOrder/orderItems`, only hydrated in the live /admin/pos reprint flow) → that surface is PARTIAL on visual.
- Show-page captures: `tests/e2e/__screenshots__/receipt-inspect-2026-06-07/{4160,4161}-*.png` (operator/customer panels + items rendered).
- Tests green: `php artisan test --filter="Receipt|TaxLine|ZReport|Refund"` = 185 passed/3 skipped; `--filter="ReceiptDataService|OperatorIdentity|FiscalSequence"` = 24 passed.

## NF525 ENGINE = GREEN; TICKET = config/merge-gated, NOT logic-broken.
