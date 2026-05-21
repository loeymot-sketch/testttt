# Wave X — V1 LE CAYENNE — Master Plan (Architect)

**Date** : 2026-05-21
**Branch** : `heal/cms-pr1-quickwins-2026-05-18`
**HEAD** : `84901e198`
**Scope** : 4 owner structural requests (X1–X4) + /test-e2e finale
**Discipline** : GStack + adversarial RED + frozen-zone strict + NF525 invariants

---

## 0. Owner mandate (distilled)

| ID | Title                                  | One-line goal                                                                                       |
|----|----------------------------------------|------------------------------------------------------------------------------------------------------|
| X1 | Encaisser flow uses PaymentComponent SSOT | Cashier "Encaisser" on cash-pending kiosk order opens the SAME payment modal as POS direct sale.    |
| X2 | POS main page shortcuts                | `/admin/pos` shows 2 compact lists (prêts à livrer, borne à encaisser) — no tracker navigation.    |
| X3 | KDS day-history button                 | "Historique du jour" drawer listing ALL bumped orders today + optional revert (PREPARED→PREPARING). |
| X4 | Cash management unifié                 | Single admin view of all transactions (POS / borne / livreur) with filters + reconciliation column. |

---

## 1. Frozen-zone matrix

| File / Module                                                  | Touched by | Verdict      | Rationale                                                                                                                                                                            |
|----------------------------------------------------------------|------------|--------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `resources/js/components/admin/pos/PaymentComponent.vue`       | X1         | NO TOUCH     | CLAUDE.md §7 frozen + `paymentComponentEmitsJsdocList.spec.js` sentinel locks emits. We REUSE atoms (`PosV5Numpad`, `PosV5TrancheRow`, mode-grid CSS classes) inside a sibling modal. |
| `app/Domain/Order/OrderStateMachine.php`                       | X3 revert  | LOCK or DEFER | §7 frozen NF525-adjacent. Current rule (L54-55) refuses PREPARED→PREPARING. Revert needs (a) LOCK adding Chef-role allowance + audit, OR (b) defer revert to V1.0.2.                  |
| `app/Models/Scopes/BranchScope.php`                            | X4 view    | NO TOUCH     | Read-only join in X4 query; global scope unchanged.                                                                                                                                  |
| `app/Http/Middleware/IdempotencyKeyMiddleware.php`             | X1         | NO TOUCH     | Reuse existing `X-Idempotency-Key` pattern from Wave W (`ab0caa985`).                                                                                                                  |
| `app/Services/Pricing/PricingService.php`                      | X1         | NO TOUCH     | Counter-collect totals come from `composition_snapshot` already frozen at kiosk-order creation.                                                                                       |
| Fiscal services (`FiscalSequenceService`, `ZReportService`, `AuditLogService`) | X4 | NO TOUCH | X4 is read-only display; NF525 sequence already allocated at order creation.                                                                                                          |
| `resources/js/components/admin/pos/PosComponent.vue`           | X1 + X2    | NOT FROZEN   | Direct edit: REMOVE Wave W modal (L1148-1221 + data L1392 + 4 methods, ~150 LOC delta), ADD 2 notification slots (template) + ADD `loadReadyOrders()` (data + 1 method, ~80 LOC).      |
| `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` | X3 | NOT FROZEN | Add header button + child drawer mount.                                                                                                                                              |
| `resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue` | X3       | NOT FROZEN (touch minimal) | Wave U `recentlyServed` stays untouched. X3 drawer is a sibling overlay.                                                                                                              |
| `app/Models/Transaction.php` + migrations                      | X4         | NO TOUCH     | No new column; `source` derived in `TransactionResource` (`source_surface` + `delivery_boy_id` heuristic).                                                                            |

---

## 2. Q-OWNER gates (BLOCKERS for Phase 1)

- **Q-OWNER-X1** : counter-collect modal — single-tender (cash OR card) only, OR multi-tranche split parity with POS direct?
  - DEFAULT (until answered) : **multi-tranche parity** — matches « un seul portail SSOT » verbatim. Modal builds with `PosV5TrancheRow` integration.
- **Q-OWNER-X3** : revert PREPARED→PREPARING — LOCK now (Chef-role allowance + audit_logs entry), or defer to V1.0.2 and ship X3 as read-only history viewer?
  - DEFAULT (until answered) : **defer revert (read-only history for V1)** — preserves §7 frozen NF525-adjacent integrity. Owner can recall via `OrderStatus::CANCELED` + manual re-create if a hot incident hits (existing path).

---

## 3. Implementation approach per request

### X1 — Counter-collect modal (PaymentComponent SSOT pattern, sibling)

**Decision: Option C** — sibling `PosCounterCollectModal.vue` reusing PaymentComponent atoms. Option B (mount with `mode` prop) is blocked by emits-list sentinel on the frozen file. Option A (phantom POS cart) bloats PosComponent create flow.

- **NEW file** `resources/js/components/admin/pos/PosCounterCollectModal.vue` (~300 LOC) :
  - Same atoms: `PosV5Numpad`, `PosV5TrancheRow`, same `posPaymentMethodEnum` (cash/card/multi).
  - Same visual hero "À encaisser {total}" (48px monospace).
  - Same CARD-mode terminal fetch (mirror PaymentComponent L397-404 + L461-477).
  - Submit hits existing `/admin/pos/counter-collect/{order}/confirm` (route already accepts `mode` int + `received` + `note` — see `routes/api.php:785-808`).
  - X-Idempotency-Key header (reuse `computeEncaisserIdempotencyKey` formula from Wave W `ab0caa985`).
- **DELETE in `PosComponent.vue`** : Wave W mode-picker template L1148-1221, `encaisserModal` data L1392, methods `openEncaisserModal` / `encaisserConfirm` / `encaisserCancel` / `computeEncaisserIdempotencyKey`. ADD: import + mount of `PosCounterCollectModal`.
- **Data trace** : NF525 sequence already allocated at kiosk-order creation (NOT here) — `PaymentService::confirmCounterPayment` writes `Transaction` with `payment_method=<picked mode>`, `type=COLLECT_KIOSK_CASH`, `order.source_surface='kiosk'` flag preserved for X4 derivation.

### X2 — POS main-page notification slots

**Approach** : 2 compact panels above products grid in `PosComponent.vue` template. Wire to existing Vuex + new poll.

- **kioskCashOrders panel** : already exists (L1025-1132). Reduce visual footprint to 3-4 max + add "Voir plus →" link to `/admin/pos-orders-tracker?filter=cash-pending`.
- **NEW readyOrders panel** : sibling slot, identical UX :
  - Data: `loadReadyOrders()` mirror of `loadKioskCashOrders` L2820 — `axios.get('admin/pos-order?status=8&branch=current&limit=4')`. Existing endpoint, throttled.
  - Each row: N° queue + items count + 1-click "Livré" button → PATCH `/admin/pos-order/{id}/change-status` with `status=DELIVERED`.
  - Real-time: piggyback existing Echo channel listeners L2454-2535 (POS already subscribes to `branch.{id}` for kiosk-cash; add `OrderStatusChanged` filter for PREPARED).
- **"Voir plus" link** : opens existing tracker (`/admin/pos-orders-tracker`) — no nav loop, owner explicitly authorized fallback path.

### X3 — KDS day-history drawer (read-only V1 default)

- **NEW component** `resources/js/components/admin/kitchenDisplaySystem/KdsHistoryDrawer.vue` (~200 LOC) :
  - Slide-in right drawer, list of all orders bumped today (status ∈ {PREPARED, OUT_FOR_DELIVERY, DELIVERED} AND `updated_at >= startOfDay()`).
  - Read-only by V1 default: each row renders compact `KdsOrderCard` props with `:readonly="true"` + "Bumped à HH:MM" timestamp.
  - If `Q-OWNER-X3 = LOCK granted` : add "Renvoyer en cours" CTA with confirmation modal → POST `/api/admin/kds/order/{id}/revert` (NEW endpoint, see backend below). Otherwise: button hidden.
- **NEW endpoint** `GET /api/admin/kds/history-today` :
  - Read-only, paginated 50/page, branch-scoped via existing global scope.
  - Returns `KdsOrderResource` with `bumped_at`, `time_to_bump_seconds`.
- **NEW endpoint (CONDITIONAL on Q-OWNER-X3=LOCK)** `POST /api/admin/kds/order/{id}/revert` :
  - Calls `OrderStateMachine::changeStatusWithRevertAllowance($order, PREPARING, $user)` — REQUIRES LOCK plan adding `hasRole('Chef')` or `can('kds.revert')` exemption at line 54-55.
  - Writes `audit_logs` entry with chain HMAC continuation (NF525 chain preserved).
- **Header button** in `KitchenDisplaySystemComponent.vue` : `<button>Historique du jour</button>` → toggles drawer. Wave U `recentlyServed` strip stays intact.

### X4 — Unified cash management view

- **Approach** : extend Wave O O4 `CashSessionReportController` with new endpoint, NEW Vue page `CashOverviewComponent.vue`. Wave O O4 LIST view stays as-is (no regression).
- **NEW endpoint** `GET /api/admin/cash-overview` :
  - Query `Transaction` joined to `Order` (left join `delivery_boy_cash_movements`).
  - Per-row `source` computed by `TransactionResource::deriveSource()` :
    - `order.source_surface='pos' AND order.order_type=POS` → `pos_direct`
    - `order.source_surface='kiosk' AND was_pending_counter` → `counter_collect`
    - `delivery_boy_cash_movements.transaction_id NOT NULL` → `delivery_boy`
    - else → `other`
  - Filters: `date_from`, `date_to`, `source`, `payment_method`, `branch_id` (admin-only override).
  - Aggregates: grand_total, per_source totals, per_method totals, cash_drawer_expected (cross-check with `cash_drawer_sessions.opening_balance + Σ cash_movements`).
- **NEW Vue page** `/admin/cash-overview` (route + view) :
  - Filter bar top.
  - Aggregate cards (3 source totals + grand total + drawer reconciliation diff).
  - Paginated table (50/page) with sort.
  - Wave O O4 link preserved in sidebar as "Cash sessions" (different scope: per-session detail).
- **POS top-bar quick-access** : add `<button>Caisse</button>` in `PosComponent.vue` header → opens `/admin/cash-overview` in new tab.

---

## 4. Risk assessment matrix

| Req | Frozen risk     | NF525 risk | Complexity      | V1 blocking? | Owner gate? |
|-----|-----------------|-----------|------------------|--------------|-------------|
| X1  | Low (sibling)   | Low (read-only on chain) | Medium (3-5h) | Yes (UX-critical) | Q-OWNER-X1 (multi-tranche?) |
| X2  | None            | None      | Small (1-2h)     | Yes (UX-critical) | No |
| X3 read-only | None | None | Medium (3h) | No (nice-to-have) | No |
| X3 revert | **HIGH** (StateMachine LOCK) | **MED** (audit chain entry) | Medium+ (4h + LOCK doc) | No | **Q-OWNER-X3 (defer/LOCK)** |
| X4  | None            | Low (read-only on chain) | Large (~1 day) | No (admin-facing) | No |

---

## 5. Parallel-vs-sequential dispatch plan

```
Phase 0  ── BLOCKER ──→ Owner answers Q-OWNER-X1 + Q-OWNER-X3
                       (without this, X1 builds default multi-tranche + X3 ships read-only)

Phase 1 (parallel — disjoint files) :
        ┌─ Agent A : X3 (KDS history drawer + endpoint, read-only V1)
        └─ Agent B : X4 (CashOverview controller + Vue + route)

Phase 2 (sequential single-agent — both write PosComponent.vue) :
        Agent C : X1 first (PosCounterCollectModal new file + delete Wave W modal block)
                → X2 second (2 notification slots + loadReadyOrders + Echo wiring)
                → atomic commit covering both (ensures PosComponent.vue stays consistent)

Phase 3 : /test-e2e skill finale
        Surfaces audited :
        - /admin/pos (X1 modal open + X2 panels visible + 1-click flows)
        - /admin/pos counter-collect modal (PaymentComponent visual parity)
        - /kds (X3 history drawer open + content)
        - /admin/cash-overview (X4 filters + aggregates + reconciliation)
        Loop until P0+P1=0 per CLAUDE.md §6.
```

Wall-clock estimate (assuming owner Q-gates answered fast) : Phase 1 ~4h parallel; Phase 2 ~5h sequential; Phase 3 ~2h. Total ~11h.

---

## 6. E2E test scenarios per sub-task

### X1 — `wave-x-x1-counter-collect.spec.js`
- Login POS, navigate `/admin/pos`, see kiosk-cash card with "Encaisser" CTA.
- Click → `PosCounterCollectModal` visible, hero shows order total, mode-grid present.
- Pick CASH, enter received > total → change displayed → confirm → POST 201 with `X-Idempotency-Key` → modal closes, card disappears from kioskCashOrders panel.
- Pick CARD, terminal dropdown populated → select → confirm → POST 201.
- (Conditional) Pick MULTI, split 50/50 cash+card → confirm → POST 201, breakdown sent.
- Assert `Transaction` row created with derived `source=counter_collect` on `/admin/cash-overview`.

### X2 — `wave-x-x2-pos-notifications.spec.js`
- Login POS, navigate `/admin/pos`.
- Assert 2 notification slots visible above grid, max 3-4 items each, "Voir plus" link if overflow.
- Seed 1 PREPARED order via API → wait ≤2s → readyOrders slot shows new row.
- Click "Livré" → PATCH 200 → row disappears from readyOrders.
- Click "Voir plus" on kiosk-cash → navigates to tracker pre-filtered.

### X3 — `wave-x-x3-kds-history.spec.js`
- Login `/kds`, click header "Historique du jour" → drawer slides in.
- Assert list contains all today's bumped orders, sorted desc.
- Each row renders compact card with items + bumped-at timestamp.
- (Conditional on LOCK) Click "Renvoyer en cours" on a PREPARED row → confirm dialog → POST 200 → row exits drawer → reappears in active grid as PREPARING.
- Assert `audit_logs` entry with `event=kds.revert` + correct HMAC chain.

### X4 — `wave-x-x4-cash-overview.spec.js`
- Login admin, navigate `/admin/cash-overview`.
- Assert 4 aggregate cards (POS direct / Counter-collect / Delivery-boy / Grand total).
- Apply filter `source=counter_collect` → table shrinks accordingly, grand total recomputes.
- Apply filter `payment_method=cash` → assert cash_drawer_expected card shows diff vs physical drawer (cross-check).
- Apply `date_from=yesterday → date_to=yesterday` → assert paginated rows.

---

## 7. Owner manual verify checklist (post-impl, pre-merge)

- [ ] POS `/admin/pos` : encaisser borne uses same modal as POS direct (visual parity, multi-tender supported per Q-OWNER-X1).
- [ ] POS `/admin/pos` : 2 small notification slots visible above grid (prêts à livrer + borne à encaisser).
- [ ] POS notification "Livré" button works without leaving page.
- [ ] POS notification "Encaisser" opens the same modal as X1.
- [ ] KDS header has "Historique du jour" button.
- [ ] KDS history drawer lists all today's bumped orders sorted desc with content visible.
- [ ] (If LOCK granted) Chef can revert a PREPARED to PREPARING via drawer CTA + audit_logs entry created.
- [ ] Admin `/admin/cash-overview` : single view shows all transactions filterable by source/method/date.
- [ ] Drawer reconciliation column shows cash_expected vs physical_collected diff.
- [ ] Wave O O4 `/admin/cash-sessions-report` still works (no regression).
- [ ] NF525 chain verify : `php artisan fiscal:verify-chain` → CHAIN OK.
- [ ] Frozen-zone diff : `git diff main -- resources/js/components/admin/pos/PaymentComponent.vue resources/js/components/admin/pos/v5/PosV5TrancheRow.vue app/Domain/Order/OrderStateMachine.php app/Services/Fiscal/` → ZERO lines (unless LOCK X3 owner-countersigned).

---

## 8. Anti-drift guards

- Wave W encaisser modal is DELETED in this wave — surface clearly in PR description so review doesn't read it as regression.
- Wave U `recentlyServed` strip stays — X3 drawer is a sibling.
- Wave O O4 `CashSessionReportListComponent` stays — X4 is a sibling overlay page.
- `PaymentComponent.vue` emits sentinel `paymentComponentEmitsJsdocList.spec.js` MUST stay green at end of Wave X (zero change to emits list).
- Counter-collect endpoint `routes/api.php:785-808` accepts `mode` + `received` + `note` ALREADY — no backend route change for X1.

---

## 9. Final dispatch instruction

1. Surface Q-OWNER-X1 + Q-OWNER-X3 to owner BEFORE dispatching agents.
2. On owner go : dispatch Phase 1 parallel (X3 + X4 disjoint), Phase 2 sequential (X1 → X2 single agent), Phase 3 /test-e2e loop.
3. Each agent : follow CLAUDE.md §5 LOOP (orchestrate → plan → execute → audit → test → visual → self-correct → BRAIN update).
4. Post-Phase-3 : Wave X final synthesis + owner manual-test handoff.

**END PLAN.**
