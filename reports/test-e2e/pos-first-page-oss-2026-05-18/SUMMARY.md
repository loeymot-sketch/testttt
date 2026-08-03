# Wave 2 — Architect + Security + DBA audit (read-only)
**Date** 2026-05-18 · **Branch** `v1-0-1-hardening-2026-05-17` · **Scope** Plan `PLAN_POS_FIRST_PAGE_AND_OSS_FILTER_2026-05-18.md`
**Findings** 9 (1 P0, 1 P1, 7 INFO) — see `wave-2-architect-dba-security.json`

---

## Implementation order (recommended)

Ship **§2 (OSS DELIVERY exclusion) before §1 (POS first-page filter)**, even though the plan presents them in 1→2 order. Rationale: §2 is a single P0 finding (F-5) with a 2-line code change on a service already exercised by `OssPolishClusterTest`; the new `OssCustomerScreenFilterTest` reuses its `setUp(seedSpatieRoles + seedMinimalSettings + RefreshDatabase)` harness verbatim. §1 introduces a new config knob + controller response-shape change + Vue computed prop + Playwright spec — larger surface, larger blast radius, no production-defect urgency. Shipping §2 first delivers customer-visible value (no PII-ish DELIVERY tiles on the wall) in ~30 min wall-clock and clears the only P0 in the audit before the bigger §1 work begins. Convergence filter for the §2 sprint: `php artisan test --filter='OssCustomerScreenFilter|OssPolish'` (covers new + regression).

Three implementer notes: (1) keep `list()` and `listForBranch()` query bodies byte-identical per the service docstring line 144 — add the `where('order_type','!=', OrderType::DELIVERY)` at the same relative position in both; (2) the new OSS test should include a delivery-with-token case (the exact silent-pass shape today), a takeaway-with-queue case (must still appear), and a PREPARED→DELIVERED transition assertion (T-2.1.2); (3) for §1, the new `PosFeaturedCategoriesConfigTest` should mirror the `PosCategoryBranchScopeTest` factory shape — that test already seeds `item_categories` rows with `channels=['pos']`, exactly the pattern the new test needs.

---

## §1.1 insertion point — **BACKEND** (PosCategoryController response shape)

**Decision** Add a `featured` boolean field per category in `PosCategoryController::index` at `app/Http/Controllers/Admin/PosCategoryController.php:122-130`, sourced from `(array) config('pos.featured_category_ids', [])`. Order the array featured-first, then by `sort/pos_sort`. Frontend (`PosComponent.vue:1415-1419` computed `categories`) consumes the field, renders featured-only by default + a `Toutes` toggle. ~3 LOC backend + ~15 LOC frontend.

**Why backend, not frontend computed prop** Three constraints discriminate:
1. **Single source of truth for the future admin UI.** Plan G1 anticipates owner editing the allowlist. If knowledge lives in Vue, the admin UI must duplicate it; if in config (read by the controller), it's already canonical and ready for a future DB-backed table without UI rewrite.
2. **No multi-consumer drift.** `/admin/pos-category` is consumed only by the POS today, but the same endpoint could feed a future mobile cashier app or a tablet OSS UI; backend filter applies uniformly.
3. **Additive, non-breaking response shape.** Adding `'featured' => bool` to the array literal at lines 122-130 is byte-additive — existing clients ignore unknown fields. The controller is not in the frozen-zone list (verified F-9).

**Why NOT a new DB column** (`item_categories.is_visible_on_pos` or similar) The plan's G1 default of 6 category IDs is unstable owner-editable config, not a schema-worthy invariant. A migration adds rollback complexity for a knob that should change weekly. F-2 confirms no existing column fits the bill (`pos_sort` is null for all 13 sampled rows). Config-first is the correct progressive-enhancement path; DB column is a V1.0.2+ refactor once the allowlist stabilizes.

**Anti-pattern avoided** Do NOT repurpose `items.is_featured` (F-3) — it's a Yes/No flag at the wrong layer (item, not category) and the brief's mischaracterization ("misnamed Status") was factually off (it's `Ask::YES`=5, a homepage flag). G1 needs category-level discrimination.

---

## §2.1 delivery-elsewhere — **G2 GO**

**Verdict** DELIVERY orders remain visible+actionable to staff after OSS-wall exclusion. PosOrdersTrackerComponent (`resources/js/components/admin/pos/PosOrdersTrackerComponent.vue:595-612`) calls `posOrder/lists` → `admin/pos-order` → `PosOrderController::index` → `OrderService::list`, which excludes ONLY `OrderType::POS` (not DELIVERY) at `app/Services/OrderService.php:198`. DELIVERY orders flow into the tracker's `online` source-tab via the `source_surface` heuristic at lines 738-750, and cashier actions (mark delivered, cancel, refund) work normally at lines 620-632.

**No additional plumbing needed** Auto-dispatch / driver-side workflow is explicitly V1.0.2 backlog per `memory/project_v1_0_1_hardening_2026-05-17.md:54-55` (DEL-9 doc-deferred, see `docs/decisions/DEFERRED_AUTO_DISPATCH_V1_0_2.md`). V1.0.1 baseline = manual staff acknowledgement through PosOrdersTracker, which already works.

**DBA verdict** (F-7) Adding `where('order_type','!=', 5)` does not hurt the index plan — `idx_orders_branch_status` + `idx_orders_datetime` already narrow to a small (PREPARING|PREPARED, today, branch) row-set; order_type becomes a cheap post-filter. No schema change.

**Frozen-zone scan** 0 hits (F-9). config/pos.php is explicitly NOT frozen per `memory/reference_frozen_zones.md:50`.
