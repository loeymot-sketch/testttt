# ADVERSARY re-verification — dimension cross-surface-sync (borne->caisse->KDS + frozen NF525)
Verdict under attack: PASS. Result of independent re-run: **PASS HOLDS (could not refute).**

## Independently reproduced with REAL commands (not the prior agent's scripts):
1. DB row: Order 5622 exists — order_type=10(TAKEAWAY), source_surface=kiosk, payment_method=1,
   payment_status=15(PENDING_COUNTER), status=4(ACCEPT), branch_id=1, total=8.30,
   fiscal_sequence_no=NULL, created_at=2026-07-09 13:21:36. (php artisan tinker direct query)
2. Enum meanings confirmed at source: OrderType::TAKEAWAY=10, PaymentStatus::PENDING_COUNTER=15,
   OrderStatus::ACCEPT=4/PREPARING=7/PREPARED=8, visibleStatuses=[4,7,8].
3. KDS: ran the REAL KitchenDisplaySystemOrderService::list() as authenticated admin (id=1, branch_id=0,
   can(pos)=Y) — full production path INCLUDING the sliding date window. 5622 = PRESENT. count=26.
   (adv_crosssurface_out.txt)
4. Caisse: ran the byte-identical counter-collect/pending query (routes/api.php:816-857) against live DB.
   5622 = PRESENT. count=24. (adv_crosssurface_out.txt)
5. Composition FROZEN: order_item 5395 (item 22, 7.40) snapshot = Sauce Algérienne(281) + Type de Pain(450),
   extras=[Oignons frits(329) unit 0.90 line_total 0.90], schema_version=1, captured_at=2026-07-09T13:21:36.
   (adv_compo_out.txt)
6. Immutability guard is NOT a no-op: tampered extras line_total 0.90->99.99 inside a rolled-back txn ->
   RuntimeException "NF525: composition_snapshot is immutable after creation. OrderItem #5395". Post-rollback
   value still 0.90. (adv_guard_out.txt) — OrderItem.php:50-58.
7. Price integrity identical @ 8.30: quote2_resp total_ttc=8.3/subtotal=8.3 (HTTP 200) == order2 total 8.3
   (HTTP 201) == DB total 8.30 == 7.40 base + 0.90 Oignons.
8. Gate: order 5621 (payment_method=5 TICKET_RESTAURANT, payment_status=10 UNPAID, status=1) — released=NO,
   ABSENT from KDS. Confirmed.

## Nuance (non-refuting, informational)
- The prior agent attributes 5621's KDS absence to the payment gate (admits only PAID|PENDING_COUNTER).
  True, but 5621 is ALSO excluded because status=1(PENDING) is not in visibleStatuses[4,7,8]. Over-attribution
  in a BONUS evidence item; the conclusion (absent + payment gate excludes it, released=NO) is factually correct.
  Does not affect the core dimension verdict.

CONCLUSION: refuted=false. Core cross-surface sync + frozen NF525 composition + triple-identical pricing all
independently reproduced via real production code paths. High confidence.
