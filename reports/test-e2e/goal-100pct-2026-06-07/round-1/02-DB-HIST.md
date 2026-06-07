# AGENT 02 — DB + HISTORICAL DATA CONTROLLER — Round 1 Report
**Date:** 2026-06-07 · **Scope:** AXE F (DB / fiscal / historique) · **Target:** `foodking_e2e` :8766 (clone, read-only)
**Verdict:** F1/F2/F4/F5/F6/F7-live = PASS · F3 = PARTIAL · **No P0/P1 blocker** (the §4 "tax_id NULL P1" is DISPROVEN as a live violation — see finding DBH-04)

---

## Infrastructure baseline (W0 confirm)
- `:8766/login` → HTTP 200. DB `foodking_e2e` present (MySQL 9.6.0).
- `fiscal:verify-chain --all` → **SWEEP COMPLETE — CHAIN OK** (branch 1, only active branch).
- `fiscal:verify-z-membership` → **Z-membership OK — no numbered order flagged as cross-Z orphan**.
- Orders: 3445 total · 2013 fiscal-numbered · 1 branch.

---

## F1 — Fiscal sequence GAP-FREE — ✅ PASS (3 independent methods)
```
branch_id  min_no  max_no  cnt   span  gap_count
1          1       2013    2013  2013  0
```
- MIN/MAX/COUNT method: span 2013 == count 2013 → gap_count = **0**.
- Duplicate scan `GROUP BY branch_id, fiscal_sequence_no HAVING c>1` → **empty** (0 duplicates).
- Explicit LEFT-JOIN n+1 successor hunt → **empty** (0 internal gaps).
- `fiscal_alloc_error_at IS NOT NULL` → **0** (no allocation failures flagged).
**Evidence:** direct mysql queries above. Conclusive — gap-free + dup-free + 0 alloc errors.

## F2 — HMAC chain append-only — ✅ PASS (with a clone-harness caveat, see DBH-01)
- `audit_logs` = 2724 rows, `z_reports` = 5 rows.
- Manual chain walk: `prev_hash == prior current_hash` per branch → **0 broken links** across all rows.
- `fiscal:verify-chain --all` = CHAIN OK; `verify-z-membership` = OK.
- **Operating DB `foodking` has all 9 immutability triggers** (audit_logs_no_update/no_delete, z_reports_no_delete, order_items_composition_snapshot_no_update, cash/stock/payment no-delete). Production append-only enforced.
- **Clone `foodking_e2e` has 0 triggers** — the dump that built the clone dropped triggers (mysqldump without `--triggers`). Migrations are recorded as run, but `information_schema.TRIGGERS` is empty on the clone. HMAC chain still protects integrity → DBH-01 = P3 harness-hygiene, NOT a product defect.

## F3 — Historique exhaustif — ⚠️ PARTIAL
- Drove `/admin/historique` live (spec `tests/e2e/zz-db-hist-audit-2026-06-07.spec.js`, PASS).
- Headers all resolved FR (`N° COMMANDE / ORIGINE / N° FILE / CLIENT / MONTANT / PAIEMENT / N° FISCAL / DATE / STATUT / ACTION`) — **0 raw `label.` key**. Pagination OK (345 pages, 3442 total, per_page 10).
- API payload (`/api/admin/order-history`) carries `fiscal_sequence_no`, `source`, `source_surface`, `parent_order_id`, `customer_name`, `status_name`. Refund link rendered (`↩ #parent`). Fiscal chip renders when present, `—` when null (`HistoriqueListComponent.vue:121`).
- **Two PARTIAL gaps:**
  1. **No operator/cashier column in the list view** — operator is only on the receipt/detail (`OrderDetailsResource.php:122` → `operator_name`). The list cannot show it; not a defect.
  2. **PAID-no-fiscal orders render as PAID with `—` fiscal** (DBH-02): page 1 today shows 10 such (KDS6-01..10). They are test pollution (see DBH-02), but an operator scanning history sees "paid, no fiscal #". Cosmetically correct (truly no number allocated), but the data is dirty.
- **`OrderDetailsResource`/receipt operator trace (my anchor, line 7) — DONE:** `ReceiptDataService.php:90-99 resolveOperatorName` = `editor_id ?: creator_id`, else **null** — the comment (:71-73, :87-88) documents the old "Opérateur: Client passage" customer-printing bug is FIXED: "never the customer." So the historical recurring finding is RESOLVED in code. **BUT** the DB shows 638 fiscal-numbered kiosk orders with BOTH editor_id AND creator_id NULL → those receipts print a BLANK operator → see DBH-05.

## F4 — Cash-trail NF525 — ✅ PASS
- 7 cash_drawer_sessions, **all with a real `opened_by_user_id`** (0 NULL operator). 2 left `open` (sessions 4, 7) — operational state, not a defect.
- Variances computed & coherent (reconciled sessions show +3 / −5 / +10 / 0 against opening 50/100).
- 167 cash_movements: 166 `order_payment/in` (€2190.40) + **1 `cashback/out` €11.00** — reconciles exactly with the single refund order (id 227, total −11, fno 39). No NULL-operator movement.

## F5 — Referential integrity — ✅ PASS (1 P3 noise)
- FK orphan scans: order_items→orders = **0**, order_payments→orders = **0**, order_items→items = **0**, cash_movements→sessions = **0**.
- **order_status_transitions: 10 rows reference order_ids that don't exist in `orders` (4119-4123, 4129-4132)** — polymorphic log table, NO FK constraint; parent orders were deleted/rolled-back, transition rows persisted. DBH-03 = P3 (operational log, not fiscal; does NOT touch the fiscal chain).
- `composition_snapshot`: 3415/3441 lines valid JSON (26 NULL = May-28 legacy seed). **0 fiscal-order lines updated >5min after creation** → snapshot never rewritten. Operating DB enforces this with `order_items_composition_snapshot_no_update` trigger.
- BranchScope: sentinel test `BranchScopeCoverageSentinelTest` exists; could NOT be executed against the clone (RefreshDatabase would risk the clone + AppServiceProvider DB error under `DB_DATABASE` override; DEVDB-GUARD). Verified by file presence + scope baseline in CLAUDE.md §9 (20 models) — TODO: run in CI-isolated DB.

## F6 — Reports coherent — ✅ PASS (divergence is test pollution, not logic)
- All-paid revenue = €32515.90 · fiscal-paid = €31732.40 · **paid-no-fiscal = €783.50**.
- The €783.50 gap = the paid-no-fiscal seed/test orders (DBH-02). They are correctly EXCLUDED from Z (fiscal-only). Z #4 = total_ttc 11.00, order_count 1 == exactly 1 fiscal order in window. Other Zs are empty test-close cycles (coherent).
- **Risk note:** if a dashboard KPI counts `payment_status=5` (not fiscal-only) it would over-report by €783.50 on this dirty clone. On a clean prod DB this divergence is 0. DashboardService has source-based filtering (read `DashboardService.php:548-582`); **numeric KPI reconciliation against the €783.50 not pulled here — handed to agent 08** (I did not run the live dashboard number).

## F7 — VAT regime — ✅ PASS (live catalog), and DBH-04 disproves the §4 P1
- **LIVE catalog (non-deleted, active): 45/45 items on VAT-10%. 0 active non-deleted items with NULL/0% tax.**
- Sold-line tax distribution: 3103 lines @ 10% (€6590.70 tax). 0%-lines + NULL-tax-lines are ALL May-28 pre-VAT-binding seed orders (item HAS tax_id=3 but snapshot predates `fiscal:assign-menu-vat`); all non-fiscal (never in a Z).
- `PricingService.php:241-244`: `tax_id ?? 0` → `taxes->get(0)` → null → **0.0% silently** for an item with NULL tax_id. BUT this path is unreachable for the 6 NULL-tax items because they are soft-deleted (DBH-04).

---

## FINDINGS

### DBH-04 — §4 "P1: 6 items tax_id NULL" is DISPROVEN as a live NF525 violation → DOWNGRADE to P3
- **Location:** `items` (ids 16,28,29,30,31,32) · `PricingService.php:241-244` · `AssignMenuVatCommand.php:56-61`.
- **Repro:** `SELECT id,name,tax_id,status,deleted_at FROM items WHERE tax_id IS NULL` → 6 rows (Bacon + 5 Bols Gourmands), **all `deleted_at='2026-05-28 19:32:39'`** (soft-deleted). `Item::where('status',5)->whereNull('tax_id')->count()` (Eloquent, SoftDeletes scope) = **0**. `fiscal:assign-menu-vat --dry-run` = "would re-point **0** items". `order_items` where `item_id IN (16,28,29,30,31,32)` = **empty (never sold)**.
- **Evidence:** PricingService loads items via `Item::query()->whereIn('id',...)` (`PricingService.php:57-60`) with NO `withTrashed()` → the SoftDeletes global scope excludes all 6 soft-deleted rows; they cannot be resolved or priced. (I did NOT separately read `assertItemsOrderableForBranch` — the SoftDeletes scope on the pricing query is sufficient and verified.)
- **Why it matters:** the §4 dashboard designates this a P1 NF525 blocker. The evidence does not support a *live* violation — the live 45-item menu is 100% VAT-10%, the 6 NULL-tax rows are soft-deleted and unsellable, and `assign-menu-vat --dry-run` = 0-to-fix. **I am a sub-agent presenting evidence to RECLASSIFY the supervisor's P1 to P3, not closing it unilaterally.**
- **Residual risk (the real P3):** the standard remediation `fiscal:assign-menu-vat` **cannot see these rows** (dry-run = 0, because SoftDeletes hides them) — so if an owner RESTORES a Bol Gourmand from trash, it returns with `tax_id=NULL` and would sell at 0% VAT unless binding is re-run *after* restore. That asymmetry is the residual.
- **Reco:** Reclassify §4 line P1→P3. Purge the 6 stale rows OR set their tax_id=VAT-10 defensively so a restore is safe. Note for owner G7: `AssignMenuVatCommand` (`AssignMenuVatCommand.php:56-61`) would also force the 8 *intentional* 0%-suppléments to 10% if they were ever active+visible — confirm the intentional-0% policy before any broad re-bind.

### DBH-05 — 638 fiscal-numbered kiosk orders have NO operator → receipt prints blank operator (P2)
- **Location:** `orders` (source_surface='kiosk', fiscal_sequence_no NOT NULL, editor_id NULL, creator_id NULL) · receipt path `ReceiptDataService.php:90-99`.
- **Repro:** `SELECT COUNT(*) FROM orders WHERE fiscal_sequence_no IS NOT NULL AND editor_id IS NULL AND creator_id IS NULL AND source_surface='kiosk'` → **638** (spread May 28→Jun 3; min fno 5, max 2000). Only 18/1973 kiosk orders have editor_id set. Samples: ids 4126/4127/4128 (fno 1995-1997), `pos_payment_method=NULL`, payment_status=5.
- **Evidence:** code is CORRECT — `resolveOperatorName` returns `editor_id ?: creator_id` else **null**, never the customer (the old "Client passage" bug IS fixed; comment :71-73). The S16-01 fix sets `editor_id` only at counter-collect when `Auth::check()` (`PaymentService.php:336-338`). These 638 were fiscalized WITHOUT going through that path (pos_payment_method NULL ≠ normal confirmCounterPayment), so operator_name resolves to null → a fiscal receipt with no cashier identified.
- **Assessment:** the bulk are prior-campaign E2E test orders force-fiscalized via factory/direct path (not the live counter flow). The OPEN question — does a **live** Le Cayenne kiosk-paid flow (Plan B → counter encashment) ALWAYS set an operator before fiscalizing? — requires the receipt/payment-flow audit that is **agent 09's scope**. If a live path fiscalizes without an operator, this is P1 (NF525 receipt must identify the operator); if it's only test data, it's P3 clone hygiene. Conservatively filed **P2** pending agent 09's live-path confirmation.
- **Reco:** Agent 09 confirm the live kiosk-fiscalize path sets editor_id/creator_id before allocating fiscal. Purge the test-fiscalized kiosk orders from the clone regardless.

### DBH-02 — Paid-no-fiscal test pollution on the clone (P3, non-blocking)
- **Location:** `orders` (44 rows: 41 source=APP/WEB seed €16.50, 2 source="POS" €9.50, 10 KDS6-01..10 €7 created today 18:11:56 by a parallel agent).
- **Repro:** `SELECT ... WHERE fiscal_sequence_no IS NULL AND payment_status=5` → 44 rows; KDS6 rows have `creator_id/editor_id NULL`, no real payment path.
- **Evidence:** real V1 POS path allocates fiscal at creation (`OrderService.php:1124-1127`) or counter-collect (`PaymentService.php:321-323`); these rows bypassed both (direct factory/seed insert). `verify-z-membership` = OK (not at fiscal risk).
- **Why it matters:** inflates the F6 revenue divergence (€783.50) and renders as PAID-no-fiscal in historique. Not a code defect — clone data hygiene.
- **Reco:** clean seed pollution before convergence (`iter15:cleanup-test-orders`); not a product issue.

### DBH-01 — Clone `foodking_e2e` has 0 NF525 immutability triggers (P3, harness hygiene)
- **Location:** clone DB; vs operating `foodking` which has all 9.
- **Repro:** `SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='foodking_e2e'` = 0; `='foodking'` = 9. Migrations recorded as run in `migrations` table.
- **Evidence:** clone built by mysqldump without `--triggers`. HMAC chain (verified OK) still protects integrity.
- **Why it matters:** any E2E test asserting DB-level append-only immutability would not be exercised on the clone (false confidence). Production protection is INTACT.
- **Reco:** re-import the clone with `--triggers --routines`, or re-run the trigger migrations on the clone.

### DBH-03 — 10 orphan order_status_transitions (P3, operational log)
- **Location:** `order_status_transitions` (ids 4973-4988 → order_ids 4119-4123, 4129-4132).
- **Repro:** `SELECT ... LEFT JOIN orders o ON o.id=t.order_id WHERE o.id IS NULL` → 10 rows; those order_ids absent from `orders` even with-trashed.
- **Evidence:** polymorphic transition log, no FK constraint; parent orders were hard-deleted/rolled-back.
- **Why it matters:** referential dangle in an operational (non-fiscal) log. Fiscal chain/sequence UNAFFECTED (proven gap-free).
- **Reco:** add a cleanup job or a guarded FK; P3.

---

## PASS SUMMARY
The fiscal core is solid: sequence is gap-free and dup-free by three independent methods, the HMAC dual-chain verifies OK with 0 broken links and Z-membership clean, cash-trail reconciles to the cent (the single €11 refund matches the single €11 cashback-out), and `composition_snapshot` is never rewritten (0 late updates; the prod `order_items_composition_snapshot_no_update` trigger enforces it). The historique view renders fully-localized with working pagination and correct fiscal chips, and the receipt operator resolver (`ReceiptDataService.php:90-99`) correctly returns editor→creator→null and NEVER the customer — the recurring "Opérateur: Client passage" bug is FIXED in code. The headline §4 "P1: 6 items tax_id NULL" does NOT hold as a live NF525 violation — all 6 are **soft-deleted ghosts** that the PricingService item query cannot resolve (no `withTrashed`), the live 45-item catalog is 100% on VAT-10%, and the binding command's dry-run confirms 0 to fix; I present this as evidence to RECLASSIFY P1→P3 (the residual is restore-safety). **No P0/P1 confirmed in scope F.** The one finding that could escalate is DBH-05: 638 fiscal-numbered kiosk orders carry no operator (editor+creator both NULL) → blank-operator receipts; the bulk are prior-campaign force-fiscalized test orders, but whether a LIVE kiosk-paid flow can fiscalize without an operator is agent 09's call — filed P2 pending that confirmation. F3 is PARTIAL (clone carries test paid-no-fiscal + operator-less rows to purge before W7); everything else (F1/F2/F4/F5/F6/F7-live) is well-evidenced PASS. The rest (paid-no-fiscal seed, missing clone triggers, orphan status-log rows) are clone/test artifacts that never touch the fiscal chain.
