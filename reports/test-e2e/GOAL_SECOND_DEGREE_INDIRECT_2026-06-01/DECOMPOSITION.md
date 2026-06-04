# GOAL — Second-Degree / Indirect Functions — System Decomposition

> Owner goal (2026-06-01, supervisor mode): work on things **never tested or not tested in depth** — the
> **indirect / hidden / second-degree** functions: historical **summarization & calculation** (all business
> numbers, all products historical, all orders/commands historical, everything historical), PLUS the
> **loyalty card** and the **delivery address**. Concrete business rules given:
> - Restaurant location: **437 Rue Élie Gruyelle, 62110 Hénin-Beaumont** (geocoded → lat **50.4215667**, lng **2.9549060**, Nominatim rooftop).
> - Delivery fee: **starts at 5 € for ≤ 5 km (à vol d'oiseau / straight-line), +1 € per additional km.**
>
> Method: decompose FIRST (this doc), then parallel adversarial audit (GStack + adversary, ×3-skeptic verify),
> heal safe defaults with TDD, surface owner-gated. READ-ONLY audit (no PHPUnit / Playwright / dev-server load —
> single-process `php artisan serve` crashes under concurrent load; serialize any test execution after audit).

HEAD at decomposition: `47970b4b7` · branch `heal/cms-pr1-quickwins-2026-05-18`

---

## Why "second-degree": the failure-mode lens (per advisor)

A *first-degree* function returns a value the user typed/picked. A *second-degree* function **derives** a
business number from many rows over time. Those derivations fail in four characteristic ways — every agent
hunts these explicitly, not "audit the page":

| FM | Failure mode | Concrete probe |
|----|--------------|----------------|
| **FM-TZ** | Timezone day-boundary bucketing | An order at **23:30 Europe/Paris on the 31st** — which day's totals does it land in? Off-by-one day at month/Z boundary. UTC-vs-Paris drift. |
| **FM-NET** | Refund / cancel / return netting | Are refunded/cancelled/returned/rejected orders correctly **excluded from or netted out of** historical revenue & counts? Or double-counted / silently included? |
| **FM-SOFT** | Soft-delete leakage | Do soft-deleted items/orders/users **leak into or out of** aggregates? (cf. CAT-DATA-02 just healed on this exact pattern.) |
| **FM-JOIN** | Double-count on joins | order↔order_items / order↔payments joins **inflating** order counts or revenue (cartesian/fan-out). |
| **FM-SEM** | Semantic / label mismatch | The number computed ≠ the number the label promises (cf. DASH-01: `totalOrders` counts DELIVERED-only but labelled "Total commandes"). Date filter on wrong column (cf. REP-ITEMS-01: `Item.created_at` vs order date). |
| **FM-PAR** | Cross-view parity | index vs export vs pdf vs dashboard widget — do the SAME inputs yield the SAME number across surfaces? |

---

## Sub-systems (anchored — verified file:line, anti-fiction)

### S1 — Dashboard order/sales statistics
**Anchor:** `app/Services/DashboardService.php` — `orderStatistics()`:59, `orderSummary()`:143, `salesSummary()`:199, `resolveDayBoundaryParis()`:127.
**Computes:** status-bucketed order counts (`->pending()/accept()/preparing()/delivered()/canceled()/returned()/rejected()`:96-104), `salesSummary` `->sum('total')`:225, Paris-local day bounds.
**Hunt:** FM-TZ (resolveDayBoundaryParis edge), FM-NET (does `salesSummary.sum('total')` include canceled/refunded?), FM-SEM (DASH-01 known: "total_order" semantics), FM-SOFT.

### S2 — Sales report (historical revenue)
**Anchor:** `app/Http/Controllers/Admin/SalesReportController.php` index:43 / export:52 / pdf:61 (+ its repository/resource — trace it). REP-AUTHZ-01 (overview gate) already healed.
**Hunt:** FM-SEM (date filter column = order date or created_at?), FM-NET (refund/cancel rows in the revenue sum), FM-PAR (index vs export vs pdf same totals), FM-TZ (date-range bounds inclusive/exclusive), pagination `total` vs revenue `sum`.

### S3 — Items report (products historical)
**Anchor:** `app/Http/Controllers/Admin/ItemsReportController.php` index:34 / export:43 / pdf:52 (+ repository). REP-ITEMS-01 OPEN (date filter on `Item.created_at` not order date — owner-intent).
**Hunt:** FM-SEM (REP-ITEMS-01: "items created in range" vs "items sold in range"), FM-JOIN (qty-sold = `sum(order_items.quantity)` — double count?), FM-NET (units from cancelled/refunded orders counted as sold?), FM-SOFT (soft-deleted items), FM-TZ.

### S4 — Analytics
**Anchor:** `app/Services/AnalyticService.php` show:116, `app/Services/AnalyticSectionService.php`, `app/Http/Controllers/Admin/AnalyticController.php` (+ `AnalyticSectionController.php`). REP-ANALYTIC-01 OPEN (index/show ungated — consumer-check needed before gating).
**Hunt:** what does it aggregate (time-series revenue/orders?), FM-TZ bucketing, FM-NET, FM-SEM, and confirm the dashboard analytics widget consumer (does gating break it? — feeds the REP-ANALYTIC-01 decision).

### S5 — Credit-balance report (customer wallet historical)
**Anchor:** `app/Http/Controllers/Admin/CreditBalanceReportController.php` index:25 / export:34 (+ repository, `customer.balance`/wallet ledger).
**Hunt:** FM-SEM (sign: credit vs debit), reconcile reported balance to the underlying ledger (sum of movements == stored balance?), FM-NET (refund credits), FM-SOFT (deleted customers), negative-balance handling.

### S6 — Cash overview / reconciliation (3-store)
**Anchor:** `app/Http/Controllers/Admin/CashOverviewController.php` index:80, `whereBetween('created_at',[start,end])`:112/348, `sum('amount')`:362, open-session `STATUS_OPEN`:304, MAX_ROWS cap:257-258. Also `CashSessionReportController.php`, `ZReportCashEnrichmentService.php`.
**Hunt:** FM-TZ (TZ-aware bounds inclusive on both ends?), sum **sign** (cash-in vs cash-out direction), open-drawer-session handling (counted or excluded?), MAX_ROWS truncation (does a capped row-set understate the sum? — silent cap), 3-store reconciliation (drawer ↔ transactions ↔ Z agree?).

### S7 — Z-report fiscal aggregates  ⚠️ FROZEN (CLAUDE.md §7) — **READ-ONLY audit, ZERO edits**
**Anchor:** `app/Services/Fiscal/ZReportService.php` `aggregate()`:297, refund netting LOCK:368-374, `total_ttc/total_ht/total_tva`:258-260, `order/cancel/refund_count`:261-263. `XReportService.php`.
**Hunt (report-only, NO heal — needs LOCK + owner gate):** does `total_tva == Σ total_by_tax_rate` (F1 history)? cross-Z-window orphan (P0 #1 detect-only `fiscal:verify-z-membership`), 0%-VAT HT/TVA split (F1 dormant), discount netting into signed total, fiscal-alloc-failed rows excluded (302). Confirm invariants hold; **do not propose source edits** — surface as owner-gated only.

### S8 — Loyalty card (earn / redeem / balance / refund-clawback)
**Anchor:** `app/Services/Loyalty/PosRedemptionService.php` applyToOrder:64, balance decrement:168-172, "never fractional euro":95; `app/Services/LoyaltyService.php` (refund/clawback); `DiscountCalculator::kioskLoyaltyRedemption`; `app/Services/Loyalty/LoyaltyQrSigner.php` sign:50/verifyAndConsume:94. Existing tests: KioskLoyaltyDoubleRedeemRefused, KioskLoyaltyLedgerAtomic, LoyaltyClawbackOnRefundSentinel, PosRedemptionTtcTaxDoubleCountSentinel, LoyaltyRefundPointsIdempotent, PosLoyaltyRedeem.
**Hunt:** earn/redeem **rounding** (fractional-euro / fractional-point), balance **underflow** (redeem > balance → INSUFFICIENT_BALANCE), double-redeem (ALREADY_REDEEMED 409), refund **clawback** correctness + idempotency (refund returns the right points, once), TTC tax double-count on the redeem discount, ledger ↔ `customer.loyalty_points` reconciliation (sum of transactions == stored balance), QR token replay/expiry.

### S9 — Delivery address + fee  ✅ already scouted (findings below — agent CONFIRMS/refutes)
**Anchor:** `app/Services/Delivery/DeliveryQuoteService.php` quoteForAddress:31, `distanceKm()` haversine (earthRadius 6371, great-circle = straight-line):; `app/Services/Delivery/DeliveryFeeService.php` fromDistanceKm:26, branch formula `max(minimum, base + per_km·d)`:38-40, legacy fallback `max(5, ceil(d/5)·5)`:45. Seeder `database/seeders/BranchTableSeeder.php:23-29`. Tests: DeliveryFeeConfigurable, DeliveryFeeBranchWireupSentinel, GeocodeFailureBlocksOrder, DeliveryFeeForgeWeb/Pos.
**Pre-found (orchestrator-verified, agent to double-check + extend):**
- **DEL-ORIGIN-01** — seeded branch origin = **Paris (48.8566/2.3522)**, not Hénin-Beaumont → every distance/fee computed from the wrong city. DATA fix (seeder + DB) to 50.4215667/2.9549060.
- **DEL-FEE-01** — seeded branch config base=2.00 / per_km=0.50 / min=3.50 → fee(5km)=4.50, fee(8km)=6.00. Owner rule wants fee(5km)=5, fee(8km)=8. **Owner rule ≡ `max(5, d)` ≡ existing formula with base=0 / per_km=1 / min=5** (NO code/migration change). DATA fix.
**Hunt (extend):** geocode-failure blocks order (GeocodeUnavailableException), forged `delivery_charge` rejected (backend recompute), same-coords distance=0 → min floor=5, whole-km vs continuous "+1€/km" (continuous reading needs no code; whole-km/ceil would need code — flag for owner), customer seeded addresses also Paris (coherence — relocate with branch).

---

## Heal policy this cycle (owner secure-defaults mandate)
- **Heal (TDD, non-frozen):** S9 delivery data fix; any S1–S6/S8 defect that is a safe, scope-minimal, clearly-correct fix with a RED→GREEN sentinel.
- **Surface owner-gated (no blind heal):** S7 (frozen NF525); REP-ITEMS-01 / REP-ANALYTIC-01 / DASH-01 (semantic/intent or bundle-rebuild); anything changing signed fiscal totals or requiring a migration.
- **Convergence:** P0+P1 = 0 NEW after ×3-skeptic verify, two stable passes; frozen-diff = 0; NF525 CHAIN OK.
