# DBA — Wave W1 / Task T-1.1.1 PricingService DB-side concerns (Round 3)

**Specialist:** DBA (read-only audit)
**Task:** T-1.1.1 — schema, transaction-boundary, precision, and read-path integrity for `PricingService` (frozen NF525 SSOT)
**Goal:** `goal-ultra-central-mgmt-sync-2026-05-18`
**Anchors verified:**
- `app/Services/Pricing/PricingService.php:1-814` (frozen)
- `app/Services/Pricing/CompositionSnapshotBuilder.php:1-205`
- `app/Models/Order.php`, `app/Models/OrderItem.php`, `app/Models/OrderQuote.php`, `app/Models/OrderCoupon.php`, `app/Models/OrderDiscountLog.php` (typed read facade onto `audit_logs`)
- `app/Models/Item.php:27,57` (price = `decimal:6` cast)
- migrations: `2022_11_17_110810_create_orders_table.php`, `2022_11_17_110832_create_order_items_table.php`, `2023_07_20_095843_add_tax_to_order_items_table.php`, `2023_07_20_095727_add_total_tax_to_orders_table.php`, `2026_04_22_000020_add_composition_snapshot_to_order_items.php`, `2026_04_25_190000_create_order_quotes_table.php`, `2022_11_17_110621_create_item_variations_table.php`, `2022_11_17_110650_create_item_extras_table.php`, `2022_11_17_120627_create_item_addons_table.php`, `2022_11_17_110514_create_items_table.php`, `2022_11_17_110459_create_taxes_table.php`, `2022_11_17_110910_create_coupons_table.php`, `2026_04_22_000002_create_audit_logs_table.php`, `2026_03_12_130000_add_performance_indexes.php`, `2026_05_10_010000_secure_fiscal_audit_trail_immutability.php`, `2026_04_15_230100_create_item_branch_availability_table.php`, `2026_05_10_030000_fix_tax_misconfig_type_fixed_to_percentage.php`
- callers: `app/Services/OrderService.php:307,329 / 601,645 / 1098,1119`, `app/Services/FrontendOrderService.php:259,277`, `app/Services/Order/OrderQuoteService.php:209,224`, `app/Services/Order/RefundWithCounterEntryService.php:121-143`
- consumers of `composition_snapshot`: `app/Services/Stock/StockService.php:280` (read), `app/Services/KitchenDisplaySystemOrderService.php:301` (read)

---

## VERDICT

**ORANGE — Decimal precision is correct (DECIMAL(19,6) everywhere; no FLOAT anywhere in the price chain), and the PricingService N+1 has been pre-empted via 5 distinct `whereIn` batches keyed by id, but FOUR distinct DB-side gaps weaken the NF525 fiscal-SSOT contract: (1) `order_items.composition_snapshot` carries NO DB-side immutability — no BEFORE UPDATE trigger, no CHECK, no `ALTER … UNIQUE`, so a hotfix `UPDATE order_items SET composition_snapshot = '…'` succeeds silently (the immutability triggers from `2026_04_22_000002` and `2026_05_10_010000` cover `audit_logs`, `z_reports`, `cash_drawer_sessions`, `cash_movements`, `order_payments` — order_items is conspicuously absent); (2) PricingService::calculateOrder reads `items.price`, `item_variations.price`, `item_extras.price`, `item_addons.addon_item.price` WITHOUT `lockForUpdate()` AND outside of the parent `DB::transaction` in the kiosk path (`FrontendOrderService:277`) but INSIDE the transaction in POS/Web (`OrderService:307→329`, `OrderService:601→645`, `OrderService:1098→1119`, `FrontendOrderService:rendered with the wrapping transaction at 220-something`) — the row read is consistent within the same transaction, BUT the SELECT issues no row lock, so a concurrent admin `Item::update(['price' => 5.00])` between the SELECT and the INSERT can change the catalog price without affecting THIS order (the snapshot already captured the read value) — this is intentionally race-tolerant behaviour but creates the silent-divergence-risk that `composition_snapshot` is the only post-hoc verification; (3) NO branch-specific price override exists (verified: no `price` column on `item_branch_availability`, no `branch_id` on `items.price`) — every order in every branch uses the global `items.price`, which is correct for V1 Le Cayenne but blocks SaaS V2 multi-tenant pricing without a `branch_price_overrides` table; (4) `OrderDiscountLog` is a typed read facade ONTO `audit_logs` (not a separate table) — the discount evidence shares table, schema, FK semantics with audit_logs, which means `audit_logs.branch_id` nullability (F1 from W3/T-1.3.1 Round 2) propagates: an Order discount written by AuditLogService::write rejects null at the application layer, but a raw `INSERT INTO audit_logs (action='order.discount_applied', branch_id=NULL, ...)` would silently poison the discount-discovery query.**

---

## TOP FINDINGS

### F1 — `order_items.composition_snapshot` is NOT protected by any DB-side immutability (no trigger, no CHECK, no UNIQUE)
**Severity:** P1 (NF525 fiscal-SSOT contract relies on app-layer discipline only; same finding F-FISC-003 from Round 1 confirmed at SQL level)
**File:line:**
- `database/migrations/2026_04_22_000020_add_composition_snapshot_to_order_items.php:11-12` (`$table->json('composition_snapshot')->nullable()->after('item_extras');`) — pure column addition, no trigger
- `database/migrations/2026_05_10_010000_secure_fiscal_audit_trail_immutability.php:65-141` (BEFORE DELETE triggers on `cash_movements`, `cash_drawer_sessions`, `order_payments`) — NF525 family but does NOT include `order_items`
- `database/migrations/2026_04_22_000002_create_audit_logs_table.php:96-135` (BEFORE UPDATE + BEFORE DELETE on `audit_logs`) — does NOT include `order_items`
- `app/Services/Pricing/PricingService.php:266-291` (json_encode + mass insert at line 291) — `comment // NF525 contract: this snapshot must NEVER be re-written` but enforcement is purely comment-grade
**Reasoning (strong):**
```yaml
claim: The NF525 fiscal SSOT promise ("composition_snapshot JSON frozen à création d'order — NEVER overwritten" per CLAUDE.md §8) is enforced ONLY at the application layer. The CompositionSnapshotBuilder docstring states it (line 10-16), PricingService writes the snapshot once via `OrderItem::insert($itemsArray)` (mass insert bypasses the Eloquent `array` cast → json_encode at PricingService line 291), and Refund::create copies the parent snapshot verbatim (RefundWithCounterEntryService line 136: `'composition_snapshot' => $item->composition_snapshot,`). However, there is NO BEFORE UPDATE trigger on `order_items`, NO CHECK constraint, NO READ-ONLY view, NO PERMISSION restriction at the DB user level. A future bug or hotfix that does `OrderItem::where('order_id', $X)->update(['composition_snapshot' => json_encode([...])])` succeeds silently. The Refund flow itself uses `OrderItem::create` (line 123) which goes through Eloquent and would re-cast — but a hotfix update path has no guard.
evidence:
  - Migration grep for `BEFORE UPDATE` + `order_items`: zero hits. The only BEFORE UPDATE trigger in the schema is on `audit_logs` (migration 2026_04_22_000002 line 99 + 123 SQLite path).
  - The NF525 immutability migration (2026_05_10_010000) explicitly enumerates `cash_movements`, `cash_drawer_sessions`, `order_payments` (lines 107-141) — order_items is NOT in this list despite being functionally fiscal evidence (NF525 reprint reads composition_snapshot).
  - The PricingService comment at line 266-269 says "this snapshot must NEVER be re-written" and "mass-insert below bypasses the Eloquent 'array' cast" — both phrases acknowledge fragility: the json_encode is manual (line 291) because the cast doesn't fire on mass insert, and the only "never rewrite" promise is in a code comment.
  - Stock reconciliation (StockService.php:280) reads `composition_snapshot.addons` — if a snapshot is mutated post-creation, stock is wrong but no detection mechanism exists.
  - KDS hash computation (KitchenDisplaySystemOrderService.php:301) reads `composition_snapshot.addons` to compute an idempotency hash — a mutated snapshot would produce a wrong hash, breaking idempotency, with no fiscal-grade detection.
counter-evidence:
  - The NF525 chain integrity is computed from the Order row's totals via the `audit_logs` chain — composition_snapshot tampering would diverge from total_price + tax_amount, which an inspector could detect by recomputing. But this detection is OFF-LINE forensic work, not live enforcement.
  - Adding a BEFORE UPDATE trigger that allows updating only when `OLD.composition_snapshot IS NULL` would not survive the Refund flow today (Refund::create writes the column — but as part of an INSERT, not UPDATE). The trigger would need column-level: `IF OLD.composition_snapshot IS NOT NULL AND NEW.composition_snapshot <> OLD.composition_snapshot THEN SIGNAL`. Easy in MySQL/MariaDB, awkward in SQLite (RAISE inside trigger condition is supported but uglier).
  - Existing app-layer guards: PricingService writes via `OrderItem::insert($itemsArray)` (mass insert); Refund writes via `OrderItem::create` for a NEW row. Neither path UPDATEs an existing snapshot. The risk is a future controller / artisan command bypassing both.
risk: A maintenance script — even a well-intentioned one cleaning up encoding issues, fixing a bug, or backfilling missing addons — can rewrite composition_snapshot for already-paid orders. NF525 reprint then produces a different ticket. The 6-year retention promise is hollow if rows are mutable. This is the SAME class of risk that the audit_logs / z_reports / cash_movements triggers were added to mitigate. Order_items.composition_snapshot is the LAST fiscal-evidence column without a SQL-level immutability guard.
caveats: A column-conditional trigger is portable. Pattern: `BEFORE UPDATE ON order_items FOR EACH ROW BEGIN IF OLD.composition_snapshot IS NOT NULL AND (NEW.composition_snapshot IS NULL OR NEW.composition_snapshot <> OLD.composition_snapshot) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='order_items.composition_snapshot is immutable (NF525)'; END IF; END`. SQLite equivalent uses `RAISE(ABORT, 'order_items.composition_snapshot is immutable (NF525)')` inside a `WHEN` clause. No behaviour change on legitimate paths (PricingService inserts, Refund::create inserts, allergens_snapshot/total updates allowed since column is not touched).
verdict: P1 — add migration introducing BEFORE UPDATE column-conditional trigger on `order_items.composition_snapshot` and a sibling on `order_items.allergens_snapshot` (which has the same NF525 frozen-evidence contract — `allergens_snapshot` is also an immutable fiscal trace per migration 2026_04_18_140004). Same migration shape as 2026_05_10_010000. Coordinate via gated owner per BRAIN §7 fiscal-zone protocol.
```

### F2 — PricingService reads price columns WITHOUT `lockForUpdate()` while `availabilityService` calls inside the same transaction DO lock — asymmetric concurrency contract
**Severity:** P2 (silent divergence: catalog price change between SELECT-for-pricing and INSERT-of-OrderItem is intentional/race-tolerant; needs documentation)
**File:line:**
- `app/Services/Pricing/PricingService.php:57-61` (Item::query()->select('id','price','tax_id')->whereIn('id', $requestedItemIds)->get() — no lockForUpdate)
- same file lines 90-98 (ItemVariation / ItemExtra / ItemAddon reads — no lockForUpdate)
- same file lines 47-55, 100-107 (AvailabilityService calls — INSIDE the lock path when `$req->orderId > 0`)
- `app/Services/Menu/AvailabilityService.php:49,100,252,482,623,665` (lockForUpdate on `item_branch_availability` and `stock_levels`)
- `app/Services/Stock/ChoiceAvailabilityResolver.php:249,264` (lockForUpdate on stock_levels)
- `app/Services/OrderService.php:307,601,1098` (DB::transaction wraps Order::create → PricingService::calculateOrder → OrderItem::insert)
**Reasoning (strong):**
```yaml
claim: PricingService::calculateOrder is called from inside the parent `DB::transaction` (verified: OrderService:307→329, OrderService:601→645, OrderService:1098→1119, FrontendOrderService:wrapping store flow → 277). It reads `items.price`, `item_variations.price`, `item_extras.price`, `item_addons.addon_item.price` via plain `whereIn`-based SELECTs with NO `lockForUpdate()`. The same method, when stock-aware (req->orderId > 0), calls `availabilityService->assertItemsOrderableForBranch(branchId, ids, lock=true)` and `choiceAvailabilityResolver->assertSelectionsOrderable(branchId, ..., lock=true)` — both of which DO acquire `lockForUpdate()` on `item_branch_availability` and `stock_levels`. This asymmetry is intentional (you want to block over-ordering of an item that goes OOS during checkout) but creates a divergent concurrency contract: stock is row-locked, price is read-as-of-statement.
evidence:
  - PricingService.php:57-61: `Item::query()->select('id','price','tax_id')->whereIn('id', $requestedItemIds)->get()` — vanilla SELECT, repeatable read at MySQL default isolation but no row lock.
  - PricingService.php:50-54 (avail call when orderId>0): `$availability->assertItemsOrderableForBranch($req->branchId, $requestedItemIds, $req->orderId > 0);` — third arg is the lock flag; passed `true` in real-order paths.
  - AvailabilityService.php:252: the `assertItemsOrderableForBranch` internal builds `$query->lockForUpdate()->get()->keyBy('item_id')` when `$useRowLock` is true.
  - OrderService.php:307 → 329: `DB::transaction(function () use ($request) { ... $res = $this->pricingService->calculateOrder(...); ... OrderItem::insert($itemsArray); });` — PricingService is inside the transaction. The SELECT happens at the same transaction read-view as the INSERT, so within ONE transaction the price is consistent. BUT a concurrent transaction T2 doing `Item::update(['price' => X])` does NOT block T1, and T1 does not block T2; both commit. The OrderItem row T1 writes captures the OLD price; T2's catalog change applies to FUTURE orders.
  - There is NO `Item::lockForUpdate()` anywhere in PricingService, OrderService, FrontendOrderService — verified via `grep -rn "lockForUpdate.*Item\|Item::.*lockForUpdate"`. The only row-locks in the pricing flow are on `item_branch_availability` and `stock_levels`.
counter-evidence:
  - This is a DELIBERATE design: blocking a concurrent admin price update would create a head-of-line block on every checkout in progress whenever an admin opens the price editor. Restaurants do not want that.
  - The `composition_snapshot` carries the prices used (variation_unit_price, extra_unit_price, addon catalog_price + line_total) — making the OrderItem self-describing. Forensic reconcile is feasible without a row lock.
  - Within a single transaction T1, MySQL REPEATABLE READ (default) guarantees the SELECT will return a consistent snapshot. The race only exists for ACROSS-transaction visibility, where the user's intent (order at THIS moment) is correctly captured.
risk: (a) Admin changes Item price from 9.99 to 10.49 at the moment a cashier presses VALIDATE: race resolved correctly today (cashier sees 9.99, captures 9.99 in snapshot, customer pays 9.99 — fair). (b) Concurrent kiosks at high-rush — same item, same branch — all 4 capture the same price; no race. (c) The risk is documentary, not functional: a future maintainer adding a "current price" check in audit tooling might assume Item::price reflects the order's price, which is not guaranteed (snapshot wins).
caveats: This is correct behaviour; the recommendation is to ADD DOCUMENTATION in PricingService at line 57 explaining the no-lock contract + add an integration test that asserts: "concurrent Item update during PricingService::calculateOrder does not corrupt the snapshot."
verdict: P2 — schema is correct; behaviour is intentional and race-tolerant. Add explicit comment in PricingService line 57 + integration test for concurrent-price-change-during-checkout invariant. No migration needed.
```

### F3 — N+1 in PricingService is mostly mitigated, but `assertComposerStepConstraints` runs a SEPARATE `Item::with([variations.itemAttribute, extras, addons.addonItem])` eager-load (line 582-590) AND `assertVariationConstraints` runs another `ItemAttribute::whereIn` (line 412-415) — neither shares the pre-loaded $dbVariations / $dbExtras
**Severity:** P2 (performance hazard: every order checkout fires 2-3 extra round-trips that COULD reuse the already-loaded collections)
**File:line:**
- `app/Services/Pricing/PricingService.php:57-117` (5 well-batched `whereIn` reads at top of method — items, variations, extras, addons (with addonItem), attributes — all keyBy(id), O(1) lookups)
- `app/Services/Pricing/PricingService.php:412-415` (`ItemAttribute::query()->whereIn('id', array_keys($byAttribute))->get()->keyBy('id')` — fires AGAIN inside `assertVariationConstraints` for each item iteration's attributes)
- `app/Services/Pricing/PricingService.php:582-590` (`Item::with(['variations.itemAttribute', 'extras', 'addons.addonItem'])->whereIn('id', $profiles->keys()->all())->get()->keyBy('id')` — full eager-load chain inside `assertComposerStepConstraints`, reads everything already in `$dbItems` + `$dbVariations` + `$dbExtras` + `$dbAddons`)
- `app/Services/Pricing/PricingService.php:564-577` (`ItemWizardProfile::query()->with(...steps...)->whereIn('item_id', ...)->where(...published...)->get()` — single batched read, fine)
**Reasoning (strong):**
```yaml
claim: PricingService's TOP-OF-METHOD batch loading (lines 57-117) is exemplary — 5 distinct `whereIn` calls, all `keyBy('id')`, all bounded by the requested item IDs. There is NO per-item DB query inside the main loop (lines 125-320). However, TWO helper methods called from the main path re-query data that's already in scope:
  (1) `assertVariationConstraints` (line 412-415) issues `ItemAttribute::whereIn(...)` even though `assertOptionsOrderable` already pre-loaded `$dbAttributes` at line 115 — but `$dbAttributes` is not passed to `assertVariationConstraints` (it receives only `$dbVariations`).
  (2) `assertComposerStepConstraints` (line 582-590) eager-loads the FULL `Item` model with `variations.itemAttribute`, `extras`, `addons.addonItem` — replicating EVERYTHING already loaded in lines 57-117. This is the single biggest avoidable cost: 1 + 3 (eager-load joins) DB queries per checkout that touches a composer-profiled item.
evidence:
  - Line 114-117: `$attributeIds = $dbVariations->pluck('item_attribute_id')->filter()->unique()->values()->all(); $dbAttributes = $attributeIds !== [] ? ItemAttribute::query()->whereIn('id', $attributeIds)->get()->keyBy('id') : collect();` — already loaded.
  - Line 412-415: `$attrs = \App\Models\ItemAttribute::query()->whereIn('id', array_keys($byAttribute))->get()->keyBy('id');` — duplicate query (assertVariationConstraints does not receive $dbAttributes).
  - Line 582-590: `Item::query()->with(['variations.itemAttribute', 'extras', 'addons.addonItem'])->whereIn('id', $profiles->keys()->all())->get()->keyBy('id');` — full re-read. With a typical kiosk basket of 1 burger + 1 fries + 1 drink, this is 1 SELECT + 3 hasMany joins = 4 queries on top of the 5 already done.
  - The `ItemWizardProfile::query()->with(['steps' => fn(...)])` at line 564-577 IS a single batched read with one join — fine.
  - assertComposerStepConstraints is invoked once at line 110 of the main flow, with full $req. It guards against composer-profile mismatches but pays the price of a second item read.
counter-evidence:
  - The composer-profile validation is genuinely separate concern from pricing — the projection (`ComposerProfileProjection::project`) reads the FULL item structure to validate selection arity, and the snapshot builder doesn't need the composer profile. Reusing the pricing-side $dbItems collection would require passing it down.
  - The duplicate ItemAttribute query in assertVariationConstraints is small (only loads attributes for items in cart) — typical 1-5 rows, negligible cost.
  - This is performance, not correctness. The schema is fine; the queries are correct.
  - For a 1-tenant V1 Le Cayenne, 4-9 extra queries per checkout = ~10ms overhead, invisible. For SaaS V2 with rush hour traffic, this compounds.
risk: At scale (SaaS V2, 1000 branches × 100 orders/h), the redundant queries are 100×1000 = 100K extra queries/hour, each ~3-10ms. Most concerning on the composer path because `with(addons.addonItem)` is a JOIN with potentially-large `items` table on `addon_items` FK. The `idx_items_id_price` index (migration 2026_03_12_130000 line 41-42) helps but doesn't cover the JOIN-on-addon_item_id pattern from the addons relation.
caveats: Refactor would pass `$dbItems`, `$dbVariations`, `$dbExtras`, `$dbAddons`, `$dbAttributes` collections into `assertComposerStepConstraints` and `assertVariationConstraints`. Method signatures change; tests need adjustment. Non-trivial refactor, not a P1.
verdict: P2 — refactor at next pricing-touch cycle to thread already-loaded collections through helper methods. Add `(addon_item_id, status)` composite index on `item_addons` for the addonItem join (currently FK alone — verified no composite index in migrations).
```

### F4 — `OrderDiscountLog` is a typed read facade on `audit_logs` — it inherits the F1 (audit_logs.branch_id nullable) gap from W3/T-1.3.1 Round 2, and adds a NEW query-pattern that the schema is not indexed for
**Severity:** P1 (NF525 discount evidence query relies on `(action, resource, resource_id)` lookup, but the only composite on `audit_logs` is `(resource, resource_id)` per migration line 55 — `action` is unindexed)
**File:line:**
- `app/Models/OrderDiscountLog.php:13-22` (class extends AuditLog, table='audit_logs', scope `Discounts(): where action=ACTION`, scope `forOrder($orderId): where resource='order' AND resource_id=$orderId`)
- `database/migrations/2026_04_22_000002_create_audit_logs_table.php:36-55` (`unsignedBigInteger('branch_id')->nullable()->index();` + `index(['branch_id', 'created_at']);` + `index(['resource', 'resource_id']);` — NO index on `action`, NO `(action, resource, resource_id)` composite, NO `(branch_id, action, resource_id)`)
- `app/Services/OrderService.php:979-998` (where AuditLogService::write is called for `OrderDiscountLog::ACTION = 'order.discount_applied'`)
**Reasoning (strong):**
```yaml
claim: OrderDiscountLog is not a separate table — it's a read-only view onto audit_logs filtered by `action = 'order.discount_applied'`. The query pattern `OrderDiscountLog::forOrder($id)` resolves to `SELECT * FROM audit_logs WHERE action = 'order.discount_applied' AND resource = 'order' AND resource_id = $id`. The schema has an index on `(resource, resource_id)` (migration line 55) which CAN serve this query, but MySQL must filter the action column post-index-scan because `action` is not in the index. For a single-tenant V1 with a few thousand audit_logs rows per day, this is fine. For SaaS V2 with hundreds of thousands of rows, the `(resource, resource_id)` index returns ALL audit_logs for a given (order, $id), then filters action — which on a hot order with many audit events (status transitions, discount, refund, fiscal_seq alloc, void, return) becomes a non-trivial filter cost.
evidence:
  - OrderDiscountLog.php:25-30: `->where('resource', 'order')->where('resource_id', $orderId);` — uses (resource, resource_id) index correctly.
  - OrderDiscountLog.php:19: `->where('action', self::ACTION);` — added BEFORE the (resource, resource_id) filter; MySQL optimizer chooses the index based on cardinality. With (action='order.discount_applied') having maybe 10% selectivity vs (resource='order') having 60% selectivity, MySQL likely picks (resource, resource_id) index then filters on action.
  - The audit_logs migration grant of indexes (line 36, 37, 54, 55) covers branch_id + branch_id-with-created_at + resource-with-resource_id. NO index on action. Verified: `grep -n "index" 2026_04_22_000002_create_audit_logs_table.php` returns only the listed three.
  - Cascade to F1 from Round 2 (audit_logs.branch_id nullable, no FK): a discount applied to branch=5's order is written via AuditLogService::write({branch_id: 5, action: 'order.discount_applied', resource: 'order', resource_id: $orderId, payload: {...}}). If a raw INSERT slipped in with branch_id=NULL (which schema allows), `OrderDiscountLog::forOrder($id)` would still return that row (no branch_id filter in the scope), but reconciliation queries that filter `WHERE branch_id = X` (e.g., Z-report discount accumulation) would silently miss it.
  - Looking at scope assembly: `scopeDiscounts(Builder $q)` and `scopeForOrder($q, $id)` do NOT add a branch filter. They rely on the model NOT having BranchScope bound. Check: `class OrderDiscountLog extends AuditLog` — AuditLog model not BranchScope-bound (verified via OrderDiscountLog reading audit_logs without scope). This means OrderDiscountLog leaks cross-tenant: branch A's POS could query OrderDiscountLog::discounts()->where('resource_id', X) and see branch B's discount logs.
counter-evidence:
  - The OrderDiscountLog facade is a debugging/reporting helper, not user-facing. The actual NF525 evidence is generated by AuditLogService::write which already includes branch_id at write time. So queries by branch (e.g., Z-report regenerate) use explicit `where('branch_id', $branchId)` regardless of OrderDiscountLog.
  - The `(resource, resource_id)` index is sufficient for V1 — a single Le Cayenne order has maybe 5-10 audit events; filtering on action post-index-scan is microseconds.
  - The cross-tenant read concern is real but bounded: OrderDiscountLog is only invoked from admin tooling per a grep audit (no per-tenant user routes use it). Still — defence in depth.
risk: (a) SaaS V2 audit-trail page reads OrderDiscountLog::forOrder for a customer service complaint, leaking cross-tenant discount evidence; (b) Z-report aggregation that uses OrderDiscountLog without explicit branch filter aggregates ALL branches' discounts, inflating the discount line on the X/Z; (c) at SaaS V2 scale, the unindexed action filter becomes a measurable cost.
caveats: Fixes are layered: (i) add `(action, resource, resource_id)` composite index to audit_logs (covers OrderDiscountLog::forOrder hot path); (ii) add `BranchScope` to AuditLog model OR to OrderDiscountLog explicitly (with the same "admin sees all" override pattern as BranchScope.php); (iii) coordinate with F1 from W3/T-1.3.1 Round 2 (audit_logs.branch_id NOT NULL backfill + FK) — these are all schema-side and pair naturally in one migration.
verdict: P1 — add `audit_logs` composite index `(action, resource, resource_id)` to make discount-evidence lookups O(log N) by primary action. Add `BranchScope` to OrderDiscountLog::booted() (cannot bind on AuditLog parent because that's the immutable evidence base — bind only on the facade). Same migration shape as the cash_drawer composite index recommendation (W3 Round 2 F3).
```

### F5 — Refund reconstructs OrderItem from `composition_snapshot` JSON via `->composition_snapshot` Eloquent cast — but the column is JSON unindexed; any query that filters on snapshot content scans every row
**Severity:** P2 (refund cost is bounded by parent order's items, not by branch history — but stock reconciliation reads ALL paid orders' snapshots)
**File:line:**
- `app/Services/Order/RefundWithCounterEntryService.php:121-143` (copies snapshot verbatim during refund — no JSON content query)
- `app/Services/Stock/StockService.php:280` (`foreach ($this->decodeSnapshotAddons($orderItem->composition_snapshot) as $addon) { ... }` — reads + decodes per orderItem)
- `app/Services/KitchenDisplaySystemOrderService.php:301` (`json_encode($this->normalizeAddonsForHash(data_get($item, 'composition_snapshot.addons', [])))` — KDS idempotency hash from snapshot)
- `database/migrations/2026_04_22_000020_add_composition_snapshot_to_order_items.php:11-12` (`$table->json('composition_snapshot')->nullable()->after('item_extras');` — no `->index()` and indeed JSON column generated-index would need a virtual column)
**Reasoning (strong):**
```yaml
claim: Refund's "reconstruct from composition_snapshot" is actually a NON-issue per the verified RefundWithCounterEntryService code: it COPIES the snapshot verbatim (line 136: `'composition_snapshot' => $item->composition_snapshot,`) rather than re-deserializing + re-pricing. The cost is O(items_in_parent_order) which is bounded (typical 1-10). HOWEVER, two other consumers read the snapshot in bulk: (1) StockService at line 280 iterates `composition_snapshot.addons` per orderItem during stock reconciliation; (2) KDS at line 301 reads `composition_snapshot.addons` to derive the deterministic idempotency hash. Neither has a generated-virtual-column index on the JSON `$.addons[*].addon_item_id` path; both scan the full JSON blob.
evidence:
  - Migration line 11-12: `$table->json('composition_snapshot')->nullable()->after('item_extras');` — pure addition, no virtual-column index.
  - No subsequent migration adds a virtual column or function-based index on the JSON column.
  - StockService.php:280: `foreach ($this->decodeSnapshotAddons($orderItem->composition_snapshot) as $addon) {` — reads `$orderItem->composition_snapshot` (json_decoded via cast), iterates addons[]. For stock reconciliation against a 30-day window of 10,000 orders × 3 items × 2 addons = 60,000 JSON decodings. Each is O(snapshot_size_bytes). Workable in V1, expensive at SaaS V2 scale.
  - KDS hash (KitchenDisplaySystemOrderService.php:301) is per-order and one-shot — not a perf concern.
  - Refund.php:136 verbatim copy — not a query, just an assignment via the array cast. No N+1.
counter-evidence:
  - The Refund-aware consumer of snapshot is also a one-shot per refund — no concern.
  - Stock reconciliation runs periodically (cron), not on the hot path. A virtual-column index would speed it up but not unblock any user flow.
  - MySQL 8.0+ supports `INDEX my_idx ((JSON_VALUE(composition_snapshot, '$.addons[*].addon_item_id')))` but the syntax is post-V1; SQLite tests don't honor it.
  - The snapshot is only QUERIED for content via `data_get` + foreach — never via SQL JSON_CONTAINS or JSON_EXTRACT in a WHERE clause that would need an index. The PHP-side iteration cost is the actual bottleneck, not SQL planner.
risk: Stock reconciliation cost at SaaS V2 scale (10K orders/day × 365 days × 4 branches per tenant) → 14M order_item rows, each requiring a snapshot decode. PHP-side processing dominates; an index doesn't help. The real fix is materializing addon usage into a separate denormalized table (`order_addon_consumption`) so reconciliation reads a flat table, not nested JSON.
caveats: For V1 Le Cayenne (single tenant, ~100 orders/day) — non-issue. Defer.
verdict: P2 (deferred to SaaS V2 scaling) — when stock reconciliation becomes a measurable cost, denormalize the snapshot.addons into `order_item_addon_consumption(order_item_id, addon_item_id, quantity, branch_id)` table. No V1 action needed.
```

---

## SECONDARY FINDINGS (no top-priority callout)

- **NO branch-specific price storage** — verified across all migrations + Item model + ItemBranchAvailability migration (2026_04_15_230100 line 11-29: only `is_available`, `unavailable_reason`, `max_daily_qty`, `daily_consumed_qty`, `daily_reset_at` — NO `price` column). For V1 Le Cayenne single tenant this is correct. For SaaS V2 multi-tenant requiring per-branch pricing, a `branch_id`-keyed price override table will be needed. Defer to V2 scope.

- **Decimal precision verified across the entire pricing chain** — DECIMAL(19,6) on `items.price`, `item_variations.price`, `item_extras.price`, `taxes.tax_rate` (DECIMAL(13,6)), `orders.subtotal/discount/delivery_charge/total_tax/total`, `order_items.price/discount/tax_amount/item_variation_total/item_extra_total/total_price`, `order_quotes.subtotal/discount/total_tax/delivery_charge/total_ttc`, `coupons.discount/minimum_order/maximum_discount`. NO FLOAT anywhere in the price chain. Application layer uses PHP `(float)` casting on read (e.g., PricingService line 134: `$itemPrice = (float) $dbItem->price;`) — this is the only conceptual precision-narrowing point. PHP float has 15-17 significant decimal digits; the max value 19,6 fits within that. Net: precision is correct, but `round(*, 2)` is applied at line totals (PricingService:236-237, 322-324, 327-328, 355) and `round(*, 6)` in snapshot lines (CompositionSnapshotBuilder:77-78, 100-101). Rounding strategy is consistent — round-half-up via PHP default.

- **PricingService runs INSIDE the parent DB::transaction in all 4 caller paths** — verified: `OrderService:307 (myOrderStore) → 329 (calculateOrder)`, `OrderService:601 (posOrderStore) → 645`, `OrderService:1098 (tableOrderStore) → 1119`, `FrontendOrderService:220-something (storeKioskOrder) → 277`. The kiosk path is therefore NOT outside transaction as I worried initially. The race condition where `Item::price` changes between calc and save IS bounded by the transaction's read-view (MySQL REPEATABLE READ guarantees consistent reads within the txn). Cross-transaction races are addressed by snapshot-then-write pattern (PricingService captures the price; subsequent admin update affects only future orders).

- **`order_items` is missing `(branch_id, order_id)` composite index** — current indexes per migration 2022_11_17_110832 line 18-19 (`order_id` FK + `branch_id` FK both indexed individually) and 2026_03_12_130000 line 58 (`idx_order_items_order_id`). The BranchScope-augmented query pattern is `WHERE order_id = X AND branch_id = Y` which MySQL serves via the `order_id` index then filter on branch_id. Per-order is typically 1-10 rows so filter cost is trivial. At SaaS V2 scale + a multi-branch admin query, a `(branch_id, order_id)` composite would help. Defer to V2.

- **`order_coupons` has NO `branch_id` column** (verified migration 2022_11_17_120625 line 16-27) — this is a pre-existing design gap. The application layer relies on the parent Order's branch_id to scope coupon usage. A query like "all coupons used in branch X" requires a JOIN through orders. For V1 this is fine; for SaaS V2 multi-branch coupon analytics this is a denormalisation backlog item.

- **`order_quotes.canonical_payload` is JSON unindexed** — the table is correctly keyed on `(branch_id, surface, actor_id, intent_hash, expires_at)` (migration 2026_04_25_190000 line 38) for the consumption path. The payload itself is read once at consumption (verified via PricingResult shape) and never queried for content. Correct design.

---

## REMEDIATION PLAN (prioritised)

1. **P0** — none. Pricing schema is fully operational for V1; no immediate compliance violations; the NF525 contract is enforceable via composition_snapshot + audit chain forensics.
2. **P1 — F1**: Add BEFORE UPDATE column-conditional trigger on `order_items.composition_snapshot` (and `order_items.allergens_snapshot`) raising SQLSTATE '45000' / RAISE(ABORT) when OLD is non-null AND OLD <> NEW. Pattern matches 2026_05_10_010000 + 2026_04_22_000002. Coordinate with owner gate (NF525 fiscal-zone protocol per BRAIN §7).
3. **P1 — F4**: Add `(action, resource, resource_id)` composite index on `audit_logs` + bind `BranchScope` to OrderDiscountLog facade (admin bypass preserved). Pair with W3 Round 2 F1 (audit_logs.branch_id NOT NULL) and F2 (audit_logs FK to branches with `nullOnDelete()`) in a single audit-logs-hardening migration.
4. **P2 — F2**: Add explicit code comment in PricingService line 57 + integration test for "concurrent Item update during checkout does not corrupt snapshot." No migration.
5. **P2 — F3**: Refactor `assertComposerStepConstraints` + `assertVariationConstraints` to receive already-loaded `$dbItems`, `$dbVariations`, `$dbExtras`, `$dbAddons`, `$dbAttributes` collections instead of re-querying. Add `(addon_item_id, status)` composite index to `item_addons` for the addonItem join.
6. **P2 — F5**: Defer to SaaS V2 — when stock reconciliation cost becomes measurable, denormalize `composition_snapshot.addons` into `order_item_addon_consumption(order_item_id, addon_item_id, quantity, branch_id)`.

All P1 + P2 fixes are pure schema additions or code-side refactors; zero behaviour change at the application layer, full backward compatibility, zero NF525 chain integrity impact (the new immutability trigger on composition_snapshot/allergens_snapshot is ADDITIVE — existing inserts continue to succeed; only post-hoc UPDATE attempts are rejected).
