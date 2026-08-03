# GOAL — Second-Degree / Indirect Functions — Adversarial Audit Findings

Workflow `wfaxuj9ie` · 61 agents · 4.25M tokens · 9 sub-systems · read-only · ×3-skeptic verify · HEAD `47970b4b7` (audit) / `ae090a911` (post S9 delivery heal).

**37 findings**: 1 P0 (→P1 on verify), 16 P1, 12 P2, 8 P3. SALES-NET-02 **refuted** (dropped). DASH-SEM-02 double-listed (counted once).

---

## THE SPINE — refund/cancel netting is inconsistent across EVERY reporting surface

Root mechanics (verified):
- A POS order is created `payment_status=PAID` at accept/prepare. **Cancelling it (`status→CANCELED/REJECTED/RETURNED`) never flips `payment_status`** (OrderService:2122-2173) → it stays `PAID`.
- A PAID order **cannot** transition to REFUNDED (`PaymentStateMachine PAID=>[]`). Refunds go through `RefundWithCounterEntryService`: parent stays `PAID/+total` (immutable), a **separate mirror** row is created `status=RETURNED, payment_status=REFUNDED, total=−parent.total`.
- The signed **Z-report nets correctly** (LOCK_ZREPORT_REFUND_NETTING). **The admin dashboard/reports do NOT** — they filter `payment_status=PAID`, which keeps the +parent (canceled or refunded) and silently drops the −mirror → **revenue overstated, permanently, with zero offset.**

| ID | Sev | Surface | Defect | File |
|----|-----|---------|--------|------|
| **DASH-NET-01** | P1 (was P0) | Dashboard CA (`salesSummary`/`totalSales`/`realtimeReport`) | canceled-paid kept; refund mirror dropped → CA overstated | DashboardService.php:220-226,334,384-385,241-247 |
| **eodSynthesis** (critic) | P1 | **Kept 6-yr NF525 EOD PDF** `total_ca` | same netting bug, signed into a retained doc | DashboardService.php:556-610 (582,585) |
| **DASH-SEM-03** | P1 | `orderStatistics`/`orderSummary` counts | RETURN_OF counter-entry mirrors counted as placed orders (+ `realtimeReport.daily_orders`, `topCustomers` per critic) | DashboardService.php:95,103,173-177,388-391,311-324 |
| **SALES-NET-01** | P1 | Sales report PDF/Excel **Total** | sums ALL orders while on-screen card sums PAID-only → PDF ≠ card ≠ Z | sales_report.blade.php:126,141-145; OrderService.php:2758-2760,139-207 |
| **ITEMS-NET-03** | P1 | Items report qty-sold | cancelled/rejected/returned/unpaid lines counted as sold (no status constraint on `orders()`) | Item.php:158-161; OrderItem |
| DASH-SEM-04 | P2 | `channelStatistics` | refund mirror (source NULL) mis-bucketed into "Web" | DashboardService.php |

**SALES-NET-02 — REFUTED** (critic + 1 refuter): claimed a PAID row flips to REFUNDED inflating the PDF; impossible (PAID=>[]). The real refund path nets +parent/−mirror = 0 in the PDF. Not an independent survivor.

> ⚠️ **OWNER SEMANTIC DECISION (gates the whole spine).** What should "Chiffre d'affaires / Total ventes" mean on the admin dashboard + reports + EOD PDF?
> - **(Recommended, secure-default) Net realized, agreeing with the signed Z:** exclude CANCELED/REJECTED/RETURNED, net out refund mirrors. One coherent number dashboard-wide ↔ Z.
> - Gross-collected (current): keep every once-PAID total regardless of later cancel/refund.
> The fix is in **non-frozen** `DashboardService` + the PDF blade, but the *number it should produce* is the owner's call (and must match the Z). **Not healed blind.**

---

## ITEMS REPORT — semantics broken (heal as ONE coordinated `itemReport()` rewrite, per critic)
| ID | Sev | Defect | File |
|----|-----|--------|------|
| ITEMS-SEM-01 | P1 | qty-sold **ignores the date range**; date filter targets `Item.created_at` (catalog creation), not the sale | ItemService.php:607-633 |
| ITEMS-SEM-02 | P1 | "quantity" column = **count of order LINES**, not summed `OrderItem.quantity` units | ItemReportResource.php:37; items_report.blade.php:105,117 |
| ITEMS-NET-03 | P1 | cancelled/rejected/returned/unpaid lines counted as sold | Item.php:158-161 |
| ITEMS-SEM-04 | P1 | Excel export applies **no date filter at all** (diverges from screen/PDF) | ItemsReportExport.php:26; ItemService.php:49-84 |

Single heal: date-scope on the **sale** (`whereHas('order', between)`), `withSum('orders','quantity')` not `withCount`, status filter (sold set), export repoints to the same method. **Owner-intent sub-decision:** date basis (`order.created_at` vs `paid_at` vs `delivered_at`) + exact "counts as sold" status set — default to `order.created_at` + exclude CANCELED/REJECTED/RETURNED unless owner differs.

---

## CASH RECONCILIATION (CashOverviewController — non-frozen; authoritative source = CashDrawerService::reconcileSession, untouched)
| ID | Sev | Defect | File |
|----|-----|--------|------|
| CASH-JOIN-01 | P1 | `expected_cash` sums cash by DATE+BRANCH window, **never by `cash_drawer_session_id`** → mixes other sessions' cash with this session's opening float | CashOverviewController.php:228-237,339-364 |
| CASH-SEM-02 | P1 | `expected_cash` sums only positive `type=payment`; **no cash-OUT / no cash_back netting** → overstates the physical-drawer écart target | CashOverviewController.php:345-363 |

(Invariant confirmed OK: the 500-row cap does NOT understate `grand_total` — summary is computed pre-limit, F7 fix effective. TZ bounds correct. `type=payment` is always +; mirror negatives are OrderPayment rows, not transactions.)

---

## CREDIT BALANCE REPORT
| ID | Sev | Defect | File |
|----|-----|--------|------|
| **CREDBAL-NET-01** | P1 | Excel export **truncates to one page** (`paginate=1`/`per_page=10` passed from UI into the export) → liability register silently incomplete | CreditBalanceReportExport.php; UserService::list |
| CREDBAL-SEM-02 | P2 | lists ALL users (Admin/Managers/Operators/Chefs/Waiters/DeliveryBoys), not just customers | UserService::list |
| CREDBAL-SOFT-03 | P2 | soft-deleted customers with positive credit excluded → understates outstanding liability | — |
| CREDBAL-PAR-04 | P2 | reported balance = cached `users.balance`, **no ledger reconciliation** | CreditBalanceUserResource |
| CREDBAL-SEM-05 / OTHER-06 | P3 | `balance` filter is LIKE-substring over decimal; `order_column` unvalidated into orderBy() | — |

(Confirmed OK: credit sign direction correct; cash_back idempotent (no double-credit); authz gated `credit-balance-report`.)

---

## LOYALTY
| ID | Sev | Defect | File |
|----|-----|--------|------|
| LOY-JOIN-01 | P1 | kiosk **pre-redeem** creates orphan `order_id=NULL` ledger row; >10-min attach window misses it; refund clawback filters by `order_id` → orphan never reversed. **V1: only reachable via direct API / mobile WizardRedeem** (no web/kiosk JS calls the pre-redeem endpoint) → P1 not P0 | FrontendOrderService.php:864-911; LoyaltyController.php:334-350; LoyaltyService.php:27 |
| LOY-SEM-02 | P2 | kiosk/web redeem applies a **fractional-euro** discount (the "never fractional" invariant is POS-only) | — |
| LOY-SEM-03 | P2 | **partial** refund claws back FULL earned + FULL redeemed points (over-clawback) | — |

---

## DELIVERY (S9 — follow-ups to the committed heal)
| ID | Sev | Defect | File |
|----|-----|--------|------|
| DEL-ORIGIN-FEE-RESEED-01 | P1 | the S9 fix lives in `BranchTableSeeder::create`; a live already-seeded DB needs an explicit UPDATE — **VERIFIED DONE: live `branches.id=1` = 50.4215667/2.9549060, base=0/per_km=1/min=5.** No idempotent updater for other deployments (note) | BranchTableSeeder.php:25-40 |
| **DEL-FEE-DISPLAY-01** | P2 | **frontend fee preview uses a tranche/step formula that OVERSTATES vs the authoritative backend (continuous `max(5,d)`)** → customer sees a higher number than charged. This is the whole-km-vs-continuous ambiguity made visible | frontend tranche formula |
| DEL-FEE-LEGACY-INCONSISTENT-02 | P3 | legacy fallback formula (`ceil(d/5)·5`) no longer matches owner rule — dormant for branch 1 (configured), live only for unconfigured branches | DeliveryFeeService.php:45 |
| DEL-GEOCODE-DEFAULT-OK-03 | P3 | `quoteForAddress` defaults missing/NULL `geocode_status` to 'OK' (not fail-closed) | DeliveryQuoteService.php:33 |

> **Owner micro-decision (delivery):** "+1€/km" — **continuous** (8.3km→8.30€, current backend) or **whole-km/ceil** (8.3km→9€)? If whole-km, both the backend formula AND the frontend preview need a small code change; if continuous, the frontend preview (DEL-FEE-DISPLAY-01) must be aligned DOWN to the backend.

---

## Z-REPORT / NF525 — ⚠️ FROZEN (§7) — owner-gate ONLY, zero source edits proposed by me
| ID | Sev | Defect | Disposition |
|----|-----|--------|-------------|
| ZRPT-SEM-01 | P1 | post-Z counter-entry refund of a **discounted** order over-reverses TVA → signed Z understates per-rate VAT. Root-cause fix is in non-frozen `RefundWithCounterEntryService` (carry parent discount-ratio onto mirror) + a two-window test | **owner-gate + LOCK** (fiscal) |
| ZRPT-SEM-02 | P2 | cross-Z-window orphan can land in NO signed Z when fiscal-alloc retry assigns a sequence after close | known (detect-only `fiscal:verify-z-membership`) |
| ZRPT-SEM-03 | P3 | delivery charge enters `total_ttc` but no order_items tax line → `total_ht=total_ttc` at 0% (F1 dormant) | known dormant |

(Confirmed OK: `total_tva == Σ total_by_tax_rate` structurally holds; `total_ttc==ht+tva`; alloc-failed rows excluded; soft-deleted post-alloc INCLUDED via withTrashed.)

---

## HEAL / GATE DISPOSITION

**Heal now (unambiguous, single correct answer, TDD):**
- DASH-SEM-02 — avg_per_day divide by inclusive day count (N) not date_diff (N−1).
- CREDBAL-NET-01 — force full (non-paginated) fetch in the credit-balance export.
- ITEMS cluster (SEM-01+02+NET-03+SEM-04) — one `itemReport()` rewrite (units sold in the sale's date range, exclude cancelled, export shares the method) with documented secure-default date basis.
- DEL-GEOCODE-DEFAULT-OK-03 — fail-closed geocode gate (verify no legacy NULL-status addresses depend on it first).

**Owner-gated (semantic / fiscal / display — secure-default proposed, NOT healed blind):**
- DASH-NET-01 + eodSynthesis + SALES-NET-01 + DASH-SEM-03 + cash CASH-JOIN-01/SEM-02 → the **CA/cash netting semantic** (recommend: agree with signed Z).
- ZRPT-SEM-01 (frozen/fiscal, LOCK required).
- LOY-SEM-02 / LOY-SEM-03 (loyalty discount/clawback policy).
- DEL-FEE-DISPLAY-01 + "+1€/km continuous vs whole-km" (delivery display semantic).

**Convergence:** P0+P1=0 NEW on the healed set after ×3 verify; frozen-diff=0; NF525 CHAIN OK.
