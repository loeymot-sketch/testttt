# Wave 4 — Commandes + Caisse — CONSOLIDATED (static + visual + verified)
**Verdict: GREEN** (all 7 pages functionally complete, 0 console errors, 0 broken renders, all redirects resolve, no P0/P1). Encaissement NF525-correct (fiscal_sequence_no 2072 allocated + audit-chained on clone). Refund flow exemplary.

## Clone mutation + reseed note
W4 committed on :8766: order 4312 collected CASH (fiscal 2072) + 1 reprint + status transition (+7 audit rows). **Reseed via `mysql < snapshot` FAILED — blocked by NF525 immutability triggers (SIGNAL 45000 on audit_logs/z_reports)**; proper reset = full re-clone. Not blocking: W5/W6/W7 don't depend on order 4312's absence; W7 is last browser wave. **Operating `foodking` tripwire INTACT (2673/daf60671…).**

## Coverage (7 pages, DEPTH CONTRACT)
pos-orders ✅ · historique ✅ (2226 entries, N° fiscal col, read-only) · pos-orders-tracker ✅ (kanban, encaisser/reprint/cancel) · encaissement ✅ (fiscal proof) · cash-overview ✅ (réconciliation + "espèces sans session" warning) · cash-sessions-report ✅ · delivery-boy-cash-sessions ✅.

## FINDINGS
- **[P2] Empty "Type de paiement" on paid order-detail header** — show/{id} detail doesn't resolve payment type though the receipt does. Gérant reads detail → ambiguous.
- **[P2] Money dot-decimal without € on cash-sessions-report** ("50.00") — same root class as transactions raw amount (`flatAmountFormat`). FR regression vs "50,00 €".
- **[P2] Time-format inconsistency** — 12h AM/PM (pos-orders/historique/receipt) vs 24h (cash-overview/sessions-report). FR=24h. (Cross-confirms W2.)
- **[P2] cash-overview physical-till-count "à venir"** — réconciliation can't compute écart (espèces attendues shown, but no counted-input to diff).
- **[P3] DUPLICATA marker didn't render on observed reprint** — CORRECTED from agent-P2: marker IS implemented (`ReceiptDuplicataMarker.vue` `v-if printCount>=2`, wired `ReceiptComponent.vue:74`). On the observed reprint it didn't show → `receipt_print_count` read pre-increment at preview render (timing/edge on reprint path). Verify count-bump-before-render ordering. NOT a missing feature.
- **[P3]×~10** — refund label raw 💸 emoji; tracker header "Historique" link → /admin/pos-orders (mislabel, should be /admin/historique); tracker À-encaisser card price-row wrap; "Heure de livraison" slot shown on À-emporter; delivery livreur shown as raw ID "10" not name; delivery movement Sens "IN"/Notes raw English tokens; action icons label-less; kiosk-operator-identity "Admin Le Cayenne" on Borne orders (stale clone, KNOWN).

## CONFIRM-KNOWN
- Encaissement single-tender only (no split). Cash-sessions-report no Z-link/export. Reorder-items route orphaned (not encountered). Catalog Variante-500 cross-confirmed in session console (W3-P1-01).

## IMPROVEMENT LIST (gérant lens)
(a) Fix DUPLICATA count-increment-before-render on reprint; (b) populate Type de paiement on order detail; (c) unify time → FR 24h everywhere; (d) FR-format money on sessions-report (+ transactions — shared `flatAmountFormat` fix); (e) ship physical-till count input; (f) Z-link/export on sessions-report; (g) Export on historique; (h) split-tender; (i) tracker card price-row wrap; (j) localize delivery Sens/Notes + livreur name; (k) fix tracker "Historique" link target.

Counts (W4): P0=0 · P1=0 · P2=4 · P3=~11.
