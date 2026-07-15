# VERIFIER cross-surface-sync — 2026-07-10  (HEAD a693aa096, branch pos/category-first-caisse-2026-06-23)

## SYNC-OSS-ACCEPT-GATE — CONFIRMED P3
Real order 5632: type=10 TAKEAWAY, status=4 ACCEPT, payment=15 PENDING_COUNTER, queue=A0033, is_advance=NO, dt=2026-07-10 02:24, pm=6 COUNTER_DEFERRED (fresh borne Plan-B order at ACCEPT).
Cross-surface visibility (tinker):
  OSS listForBranch(1)  -> 5632 ABSENT   (twin 5631 @PREPARING PRESENT)
  KDS list()            -> 5632 PRESENT
  CAISSE counter-collect-> 5632 PRESENT
Cause: OrderStatusScreenOrderService.php:63 (list) / :218 (listForBranch) whereIn('status',[PREPARING=7,PREPARED=8]) excludes ACCEPT=4.
KitchenReleaseRule::visibleStatuses()=[ACCEPT,PREPARING,PREPARED] -> KDS shows ACCEPT.
By-design status gate (not a broken join). Customer who took A0033 cannot find their number on the wall until chef taps Start. Minor UX / product decision -> P3.

## SYNC-OSS-KDS-ZOMBIE-ADVANCE — CONFIRMED P2
Real order 5399: type=25 KIOSK, status=8 PREPARED, payment=5 PAID, queue=NULL, is_advance=5 YES, dt=2026-07-02 01:44 (age 8 days), serial CARDTEST-1782949449.
  OSS listForBranch(1) ids=[5399,5631] -> 5399 PRESENT queue=NULL status=8
  KDS list() ids include 5399 -> PRESENT status=8 queue=NULL
  Public HTTP GET /api/frontend/oss-order?branch_id=1 (x-api-key) -> {"id":5399,"queue_number":null,"status":8,"order_type":25} rendered on customer wall (blank ticket number)
Cause (two real behaviors):
  (a) Advance-order clause has NO lower bound / no aging-out: OSS list :122-124, listForBranch :252-256; KDS :146-149. Predicate = is_advance_order=YES AND order_datetime<tomorrowStart AND status NOT IN [DELIVERED,CANCELED]. PREPARED(8) is not terminal -> persists on both boards forever.
  (b) Null queue_number rendered to customers on public wall (CDSOrderDetailsResource.php:21 emits queue_number as-is).
KDS window mirrors OSS -> leak repeats on both. Real code behavior (a purge clears the instance, not the logic). Board pollution + blank customer ticket -> P2.

Neither file is in CLAUDE.md §7 frozen list. frozen:false.
