# AGENT 08 — SYSTÈME ADMIN / DASHBOARD — ROUND 1 REPORT
**Date:** 2026-06-07 · **Scope:** Admin/Dashboard (KPI truth, historique, catalogue CRUD, stock, customers/loyalty, users/permissions, every button)
**Harness:** DB `foodking_e2e` :8766 (disposable clone). READ-ONLY round — no source edits. All mutations through the app's own admin endpoints (the CRUD test), persistence verified via SQL.
**Spec authored:** `tests/e2e/zz-admin-dashboard-crud-2026-06-07.spec.js` (drives full admin API stack via authed page `fetch` + x-api-key + Bearer).

---

## VERDICT
**PASS for the admin/dashboard surface; tax-correctness axis GATED (owner G7).** Dashboard KPIs are DB-truth at same-instant (sales match independent hand-written SQL to the cent); full CRUD lifecycle (item/category/customer/staff) persists & is abuse-resistant; historique pagination+filters+detail work; RBAC per-verb gating present; staff-create + role-assign enforced; exports produce real XLSX; stock toggle write persists; EOD PDF works; loyalty consult works.

**F-08-1 (P1) = the GOAL's pre-existing owner gate G7** ("Politique TVA emporter/sur place + statut 6 items NULL", PENDING). NULL `tax_id` → silent 0% VAT (no tax name) in `PricingService`. On the e2e clone the 6 NULL-tax items are all soft-deleted (they don't reach the live 45-item catalogue) but the OPERATING catalogue + the code path are real for production. Surfaced as P1, **non-blocking because it is a documented owner-gate (G7)** — per the GOAL DONE criteria 🔒 owner-gates are acceptable. Resolution = agents 02/09 + owner G7.

---

## EVIDENCE BY AXIS

### AXE A — TECHNIQUE
- **A1 endpoints** PASS. Dashboard KPI XHR captured 200 JSON (not HTML-masquerade): `total-sales`, `total-orders`, `total-customers`, `total-menu-items`, `realtime-report`. Item/category/customer/order-history/stock all 200 JSON.
- **A2 authz on mutations** PASS. `ItemController.__construct` gates per-verb: `items_create` (store/import/duplicate), `items_edit` (update/changeImage), `items_delete` (destroy), `items_show` (show). `CustomerController` gates `customers_create/edit/delete/show`. `ItemRequest::authorize()` = `can('items_create')||can('items_edit')` defense-in-depth. (`PaginateRequest`/`CustomerRequest` `return true` are the documented §9 baseline-66 pattern, route-middleware-gated.)
- **A3 idempotency** PASS (correct scope). `config/idempotency.php` required_routes covers all fiscal/payment/cash/order-status POSTs (NF525 §9). Admin catalogue CRUD intentionally excluded → double-submit guarded by DB UNIQUE (duplicate item name → 422, no dup row). Correct design.
- **A4/A5 pricing SSOT / snapshot** — not exercised here (order-creation path = agents 04/05/02). Catalogue only feeds id/price/tax to PricingService.
- **A6 regression** — not re-run full suite this round (agent 02 owns); my new spec is additive and green.

### AXE B — INTERFACE / chaque bouton
- **B1 buttons** PASS. Drove: catalogue index (45 items), category index (11), CREATE item→201, UPDATE item→200, DELETE item→202, CREATE/UPDATE/DELETE category (201/200/202), CREATE/UPDATE customer (201/200), historique paginate/filter/voir, EOD-PDF→200 application/pdf, stock catalog-overview→200 JSON, users/roles/tax indexes. **10+ CRUD ops persisted** (verified in DB, see below).
- **B2 states** PASS. Empty/invalid handled: 6/6 abuse cases → 422 with FR messages.
- **B3 raw labels** PASS. Dashboard text scan: `RAW_LABEL_HITS=[]`, 7 KPI cards. Screenshot `shots/admin-dashboard.png` shows clean FR labels (Tableau De Bord, Catalogue, Historique, Encaissement, Écran Cuisine, Suivi Client), Cayenne branding (#F4501E), correct FR currency `32 473,40 €`.
- **B4 navigation** PARTIAL. Accès-rapides tiles all render & label correctly (visual); per-tile click-through routing = agent 03 visual scope. Deep-link/refresh not individually driven this round.
- **B5 forms / double-submit** PASS. Validation errors return cleanly; duplicate name → 422 (no dup row).

### AXE C — VISUEL (agent 03 pilote)
- C2/C4 PASS from my one capture: layout intact, Cayenne palette, light. Full per-screen capture = agent 03.

### AXE D — FLUIDITÉ
- D1 PASS (subjective): all API calls < ~300ms in-spec; dashboard renders after KPI XHRs.
- D2/D3 — order lifecycle = agents 04/05; not my surface.

### AXE E — SYNCHRONISATION (agent 01)
- Stock toggle real-time propagation = agent 01. I confirmed stock data endpoint (`/stock/catalog-overview`) returns live JSON (branch_id, categories, extra_groups, variation_groups, fetched_at).

### AXE F — DONNÉES (agent 02)
- **F3 historique** PASS (my surface). `/order-history` paginate=1&per_page=10 → meta_total 3443, last_page 345, per_page 10. status filter (5→1, 10→0), source_surface=kiosk→1972, date today→13 (matches realtime daily_orders). Detail `voir` show/4177 → 200 with 5 order_items. Columns present: order_serial_no, order_datetime, total, payment_status, source, source_surface, **fiscal_sequence_no**, status, status_name.
  - NOTE (→02/09): a POS order (serial KDS6-11) showed `fiscal_sequence_no=null` in the list — expected for POS-cash pre-close, but 02 should confirm gap-free allocation after close.
- F1/F2/F4/F5/F6 fiscal chain depth = agent 02 (out of lane).

### AXE G — PERSPECTIVE OPÉRATEUR
- **G2/G3** PASS. Dashboard is a clear gérant view: KPIs (Total ventes / commandes / articles), Suivi en direct (CA jour / commandes jour / ticket moyen), Accès rapides, PDF Clôture du jour. Numbers are accurate & live.

---

## KPI = DB TRUTH (same-instant cross-check — the core deliverable)
Driven via `DashboardService` + raw SQL replicating each scope predicate at the SAME instant (`APP_ENV=e2e`):
| KPI | Service | SQL (predicate) | Match |
|-----|---------|-----------------|-------|
| total_sales | 32 363.40 | `Order::realizedRevenue()->sum(total)` = 32 363.40 | ✅ |
| total_orders | 3443 | `Order::whereNull(parent_order_id)->count()` = 3443 | ✅ |
| total_customers | 2 | `User::role('Customer')->count()` = 2 | ✅ |
| total_menu_items | 45 | `Item::where(status,ACTIVE)->count()` = 45 | ✅ |
Also through full HTTP stack (auth+permission:dashboard) the SPA XHRs returned the same values in FR format (`32 358,40 €`, `3441`, `45`, realtime `daily_orders:13 / 79,50 € / 6,12 €`). Values drift upward across runs because **parallel agents (04/05) create orders on the shared clone** — KPIs track the live DB exactly (live-consistent), confirming truth, not staleness.

**45-vs-59 discrepancy RESOLVED:** raw `items` table = 59 rows = 45 live + 14 soft-deleted (leftover prior-CRUD on the disposable clone). `Item::count()` honors SoftDeletes → 45. KPI `totalMenuItems` (status=ACTIVE, live) = 45 = V1 SSOT. Correct.

**Sales cross-check AIRTIGHT (same process, independent hand-written SQL):** `SELECT SUM(total) WHERE deleted_at IS NULL AND ((payment_status=5 AND status NOT IN (16,19,22)) OR (status=22 AND parent_order_id IS NOT NULL))` = **32 363.40** = service `totalSales()` **32 363.40** — EXACT MATCH. (First attempt mismatched by 101.50; root cause = 7 soft-deleted "realized" rows €101.50 that raw SQL counted but the service's SoftDeletes global scope correctly excludes — clone artifact, not a defect. Order uses SoftDeletes confirmed.)

## CRUD PERSISTENCE (verified in DB)
- Items `E2E-ADMIN-*`: created → UPDATE persisted (price 14.50, tax_id 1) → DELETE persisted (deleted_at set). 3 full lifecycles.
- Categories `E2E-CAT-*`: created (id 13/14) → name "-EDIT" persisted → deleted_at set.
- Customer id 28: created → name "E2E Client EDIT" persisted.
- **Staff** id 30: `POST /employee` role_id=7 → created → SQL `model_has_roles` JOIN shows role **"POS Operator"**, branch_id 1. Abuse: create without role_id → **422** "The role field is required" (role-assign enforced).
- **Stock toggle**: `POST /menu/availability/toggle` item 59 → OFF `{ok:true,is_available:false,unavailable_reason:"e2e-test"}` then ON; DB `item_branch_availability` item 59 = is_available 1 (persisted both ways).
- **Exports**: `item/export`, `customer/export`, `employee/export` → 200 `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` (real XLSX, NOT SPA-catchall HTML).
Total ≫ 10 mutations + 3 exports + toggle, all persisted/verified.

## ABUSE (try-to-break) — all correctly rejected 422
negative price, zero price, missing name, duplicate name, non-numeric price, category_id=0 → each 422 with proper FR validation message. **Orphan/FK history guard:** `DELETE /item/1?force=1` → **409** `errors.item.cannot_force_delete_with_history` "Cet item est référencé par 3142 commandes historiques. Suppression douce uniquement." (force-delete of order-referenced item correctly blocked — soft-delete only).

## FISCAL-GATED BUTTON
`POST /api/admin/dashboard/eod-pdf` → 200 `application/pdf` (PDF Clôture du jour). Gated `permission:pos-manage-fiscal` (separate from :dashboard) — admin has it. (Reject-for-non-fiscal-user = cross-ref agent 10.)

## LOYALTY CONSULT
`/api/frontend/loyalty/config` → 200 `{points_per_euro:10, points_for_1_euro_discount:100, min_redeem_points:50, tiers:[100,250,500,1000,2000]}`. Consult works. (Earn/redeem flows = agents 04/05.)

---

## FINDINGS

### F-08-1 [P1] — NULL `tax_id` resolves to silent 0% VAT (no tax name) — §4 "6 items tax_id NULL" cible
- **Location:** `app/Services/Pricing/PricingService.php:241-244` ; data: 6 items `tax_id IS NULL` (5 Bols Gourmands €10.50–12.50 + 1 Supplément "Bacon" €1.00). `ItemRequest.php:48` allows `tax_id` nullable.
- **Reproduction (proven, e2e clone tinker):** `resolveTax(null) -> rate=0.0 name=NULL (SILENT 0% VAT)` vs `tax_id=3 -> rate=10.0 name=VAT` vs `tax_id=1 -> rate=0.0 name=No-VAT`. So a NULL-tax line lands in `order_items.tax_rate=0, tax_name=NULL` (vs an explicit No-VAT line which at least has name "No-VAT").
- **Evidence:** `mysql foodking_e2e "SELECT id,name,price,tax_id FROM items WHERE tax_id IS NULL"` (withTrashed) → ids 16,28,29,30,31,32. `PricingService.php:241` `$taxId=(int)($dbItem->tax_id ?? 0)`; `:242` `$taxes[0] ?? null`; `:244` `$taxRate = $taxObj ? ... : 0.0`.
- **Impact:** For a VAT-registered restaurant (owner G1 = VAT-registered), Bols Gourmands would be priced/receipted at 0% VAT with a missing tax label — wrong fiscal rate + missing NF525 tax line. On the e2e clone these 6 items are soft-deleted (deleted_at 2026-05-28) so they don't reach the live menu; the OPERATING catalogue is where they bite (out-of-my-DB-lane — agents 02/09 + owner gate G7).
- **Recommendation:** Either (a) backfill `tax_id` on the 6 items to the correct French takeaway rate (likely id=3 / 10%, owner G7 to confirm sur-place vs emporter), and/or (b) make PricingService fail-loud or fall back to a configured default rate when `tax_id` is NULL instead of silent 0%. Cross-ref agents 02 (data) + 09 (receipt/fiscal). Owner gate G7.

---

## TEST-ARTIFACT NON-FINDINGS (verified NOT product bugs — anti-hallucination)
- "CAT_CREATE 405" → my wrong path; `item-category` is under `setting/` prefix → corrected → 201/200/202 work.
- "ORDER_HIST returned all 3442 ignoring per_page/filter" → my wrong params; OrderService::list paginates on `paginate=1`+`per_page` and filters on `status`/`source_surface`/`from_date` (the SPA sends these; HistoriqueListComponent.vue:230-242). Corrected → pagination+filters WORK.
- "item 28 404 / DELETE_HIST 404" → item 28 (Bol Curry) is soft-deleted on the clone; route-model-binding correctly excludes it.
- "CUSTOMER_CREATE 422 password required" → `CustomerRequest` requires password+confirmation on create (correct); admin form sends them; my first call omitted them → corrected → 201.
- "LOYALTY_BALANCE/CONFIG body=null" → my wrong path; real route is `/api/frontend/loyalty/config` → 200 JSON works (silent-HTML-masquerade caught: wrong path hit SPA catchall HTML, json()=null).

---

## OPEN / DEFERRED (cross-lane, not my deliverable)
- Per-tile click-through routing of Accès-rapides (agent 03 visual).
- Stock toggle real-time propagation caisse/borne/wizard (agent 01).
- Fiscal-gate rejection of EOD-PDF for non-fiscal user (agent 10).
- Historique fiscal_sequence_no gap-free / chain depth (agent 02).
- 6 NULL-tax items in OPERATING DB + receipt consequence (agents 02/09 + owner G7).
