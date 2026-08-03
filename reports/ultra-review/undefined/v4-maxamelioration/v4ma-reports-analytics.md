# v4ma-reports-analytics — Reports / Analytics / Dashboard authz + cross-branch leak attack

HEAD 61e9ea7b7 + working-tree. Live server 127.0.0.1:8766 (DB foodking_e2e, 4 branches seeded:
1 Le Cayenne, 7 Shields, 8 Brekke, 9 Skiles ; staff pinned to each).

## Verdict: IMPROVABLE (1 x P3 defense-in-depth). No confirmed P0/P1/P2.

The claim "les rapports sont scopés par branche" **HOLDS in HTTP context.** Confirmed live.

## Attacks run

1. **Dashboard cross-branch (authz)** — `DashboardController` gates every revenue method on
   `permission:dashboard` (+ `pos-manage-fiscal` on `eodPdf`). `DashboardService` uses
   BELT-AND-SUSPENDERS: `orderQuery()` adds an EXPLICIT `where('branch_id', N)` for staff
   (`dashboardBranchId()`) ON TOP of BranchScope. Live proof (tinker, setUser):
   - admin(1) `totalSales`=38 362,42 € / 2801 orders
   - branch-7 staff(130) `totalSales`=**27,50 €** / 1 order  → correctly scoped.
   `realtimeReport / slaAlerts / channelStatistics / topCustomers / totalOrders / totalCustomers /
   salesSummary / orderSummary / customerStates` all route through scoped `orderQuery()/customerQuery()`.

2. **`auditTrail` cross-branch** — AuditLog is BranchScope-EXEMPTED (V1.0.2 backlog) but
   `DashboardService::auditTrail()` manually filters `where('branch_id', $actorBranchId)` for staff
   (DashboardService.php:816). Safe.

3. **Sales-report / items-report / credit-balance / cash-sessions authz** — all controllers gate
   `index/export/pdf/overview` on their permission (`sales-report`, `items-report`,
   `credit-balance-report`, `cash-sessions-report`). The `overview` gap (REP-AUTHZ-01) is already healed.

4. **Z-report IDOR** — `ZReportController::show/pdf` carry explicit
   `abort_if((int)$zReport->branch_id !== $branchId, 403)` (lines 68, 84) + `resolveBranchId` aborts
   422 for unpinned admin. Cross-branch Z read blocked. Index: admin cross-branch (intended), staff scoped.

5. **X-report** — requires a pinned branch (`abort_if($branchId<=0, 422)`) + `pos-manage-fiscal`. Scoped.

6. **`branch_id` param abuse** — branch-7 staff passing `?branch_id=1` to sales-report/overview:
   `applyOrderFilter` adds `where branch_id=1` but BranchScope (HTTP) pins to 7 → empty set. No leak.
   `CashSessionReportController` honors `branch_id` filter for admin only; staff hint silently ignored.

7. **analytic / analytic-section** — mutations gated on `permission:settings`; GET index intentionally
   open (dashboard-widget CONFIG metadata, not revenue) — documented, not a leak.

8. **Queue/console report path** — grepped `app/Console`, `app/Jobs`, `Kernel::schedule`: no scheduled
   or queued command generates a per-branch revenue report for a branch user. `SalesReportExport` is
   SYNC (`Excel::download`, not `ShouldQueue`) → runs in HTTP → BranchScope applies.

## Finding (P3, defense-in-depth / latent)

**OrderService::list() and salesReportOverview() rely SOLELY on BranchScope with no explicit branch
filter**, unlike DashboardService/auditTrail which double-guard. BranchScope is disabled in console
context by design (`BranchScope.php:27` — `(!App::runningInConsole() || App::runningUnitTests())`).

Live repro of the latent exposure (console = BranchScope OFF, mirrors what a queued export / artisan
report would see):
```
tinker> App::runningInConsole()=true, runningUnitTests()=false  → BranchScope OFF
tinker> OrderService::salesReportOverview() as branch-7 staff → total_earnings = 38 362,42 €  (ALL 4 branches)
tinker> Order::query()->where('branch_id',7)->count() = 1   (what HTTP correctly returns)
```
Currently NOT reachable (export sync, no console report job) so no live HTTP leak — hence P3. Risk
materializes the day someone (a) adds `implements ShouldQueue` to `SalesReportExport` for large-export
perf, or (b) wires an artisan/scheduled EOD sales email. Then branch staff / a queued job would
silently emit whole-company CA.

Fix proposal: mirror `DashboardService::orderQuery()` — add an explicit
`when(($b=(int)(Auth::user()?->branch_id ?? 0))>0, fn($q)=>$q->where('branch_id',$b))` guard inside
`OrderService::list()` and `salesReportOverview()` so the branch pin no longer depends on the
console/HTTP context of the global scope. Belt-and-suspenders parity across all revenue readers.
