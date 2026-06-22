# Axis A9 — Admin CRUD FINAL Verdict

**Date** : 2026-05-13 04:30 CEST
**Verdict** : FAIL-DEFERRED (P0 known backlog V1.0.1)
**Score** : 5 PASS, 1 P0 confirmed (BRAIN/V1.0.1), 2 P1, 2 P2, 3 P3

---

## §1 Findings

| ID | Severity | Title | Status | Action |
|----|----------|-------|--------|--------|
| A9-P0-01 | P0 | RBAC FormRequest authz stubs : 75/92 (81.5%) classes return true unconditionally | **Defer V1.0.1** | BRAIN V1.0.1 hardening sprint already scopes "FormRequest authz refactor 88 endpoints". Cross-validated A2 adversarial (80/90). Scope-confirmed by A9. |
| A9-P1-01 | P1 | Categories CRUD missing soft-delete "show archived" toggle | **Defer V1.0.1** | ItemCategory model has SoftDeletes trait but ItemCategoryService::list() does NOT filter by deleted_at nor support archived toggle. 8 archived cats invisible from admin. |
| A9-P1-02 | P1 | SimpleOrderResource missing composition_snapshot fallback for item_name | **Defer V1.0.1** | P1-3 BRAIN backlog (187 order_items NULL composition_snapshot.name). Admin order list displays empty item names. |
| A9-P2-01 | P2 | Stock dashboard backend READY, missing pagination | **Defer V1.0.1** | StockRuptureDashboardController returns rows correctly with hardcoded limit(100/200). UI 5-7d V1.x. |
| A9-P2-02 | P2 | Fiscal Z report authorization | **PASS** | Requires pos-manage-fiscal permission. resolveBranchId() enforces branch pinning. Never trusts payload branch_id. |
| A9-P2-03 | P2 | CSP migration meta→header | **PASS** (HEALED already) | master.blade.php marks meta CSP as fallback-only (lines 10-18). Kernel.php registers ContentSecurityPolicyHeader middleware. Vitest sentinel cspMigratedToHttpHeader confirms transition guard. |
| A9-P3-01 | P3 | RBAC Spatie roles structure | **PASS** | Roles registered: Admin, Branch Manager, POS Operator, Chef, Customer, Delivery Boy, Waiter, Staff. SpatieRoleLookup resolves legacy IDs. |
| A9-P3-02 | P3 | Audit log viewer UI missing | **Defer V1.x** | Backend AuditLog model + AuditLogService exist. Dashboard has auditTrail() method. No admin UI viewer. |
| A9-P3-03 | P3 | Excel export/import | **PASS functional** | ItemCategoryExport/Import + ItemController parallel routes. Follows stub authorization pattern (P0 scope). |

## §2 Heals applied in this axis : 0

A9 axis findings are all V1.0.1 backlog items. No scope-minimal heals within A9 scope. The CSP migration was already healed (PASS verified). The 1 P0 (RBAC stubs) is the SAME issue as A2-P1-02, scope-broadened from 4/92 sample to 75/92 confirmed. Documented in FINAL_VERDICT.

## §3 JSON FINAL verdict

```json
{
  "axis": "A9",
  "verdict": "FAIL-DEFERRED-V1.0.1",
  "final_score": 60,
  "p0_remaining_defer_V1_0_1": ["RBAC FormRequest 75/92 stubs"],
  "p1_deferred_V1_0_1": ["Categories archived toggle", "SimpleOrderResource composition_snapshot fallback"],
  "p2_deferred": ["Stock dashboard pagination"],
  "passing_checks": ["Fiscal Z auth", "CSP migration verified", "Spatie roles", "Excel functional"],
  "heals_applied_in_this_axis": 0,
  "frozen_zones_diff_lines": 0
}
```

## §4 RESUME_TOKEN_AXIS_A9_FINAL_20260513-0430
