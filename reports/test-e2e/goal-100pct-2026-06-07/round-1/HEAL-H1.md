# HEAL-H1 — Order-history invoice NF525 compliance (P1, the ticket)
**Status: PASS ✅** · 2026-06-07 · non-frozen, scope-minimal

## Defect (Round-1 agent 09, proven live)
`PosOrderReceiptComponent.vue` (printed by "Imprimer La Facture" on `/admin/pos-orders/show/:id` via `PosOrderShowComponent.vue:172` v-print → `#print`) was MISSING the entire NF525 block. Live #4160 DOM: hasSiret=false, hasTva=false, hasFiscalNo=false, hasOperator=false → non-compliant reprinted invoice, divergent from the compliant `ReceiptComponent.vue`.

## Fix
Aligned `PosOrderReceiptComponent.vue` with `ReceiptComponent.vue`, reusing the SAME `OrderDetailsResource` fields (no divergent logic):
- NF525 header: `pos_siret`, `pos_vat_intra`, `pos_register_id`, `operator_name`.
- Per-rate TVA ventilation: `tax_lines` loop (`tax_name (tax_rate%)` + amounts) + `subtotal_without_tax` + `total_tax`.
- NF525 footer: `fiscal_sequence_no` (N° ticket NF525), `audit_chain_fingerprint`, `legal_mentions` (`pos_legal_footer`).
- `ReceiptDuplicataMarker` + `ReceiptRemboursementMarker`.
- `OrderDetailsResource.php` (+6): `receipt_print_count` projection for the duplicata marker.

Files: `resources/js/components/admin/posOrders/PosOrderReceiptComponent.vue`, `app/Http/Resources/OrderDetailsResource.php`. Frontend bundle rebuilt (mix). 0 frozen touched.

## Verification (live, clone :8766, branch legal set)
Spec `tests/e2e/zz-heal-h1-invoice-nf525-2026-06-07.spec.js` → **1 passed (15.4s)**. Captured #print DOM of #4160:
`SIRET: 10417050100019 · TVA intra: FR19104170501 · Opérateur: Admin Le Cayenne · VAT (10%) · Base HT 1,36 € · 0,14 € · Sous-total 1,36 € · Total taxes 0,14 € · Total 1,50 € · N°A0001 · N° ticket NF525: 2001 · Empreinte audit: dec613b10811 · Mentions légales: TVA intracommunautaire - Merci de votre visite`.
Booleans: hasSiret/hasTva/hasFiscalNo/hasOperator/hasSiretValue/hasVatValue/hasTaxRate/hasFiscalNoValue = ALL true.
(Footer corrected by HEAL-H5; the earlier "non applicable art.293B" was the pre-H5 clone value.)
Spec hang (element screenshot under contention) bounded with a 7s race guard — assertions use the captured `data`, unaffected.

## Result
The reprinted order-history invoice is now NF525-compliant, identical fiscal content to the live POS receipt. P1 CLOSED.
