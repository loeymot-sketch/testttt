# Pre-Cloud Remediation — EXECUTION STATUS (live)

**Updated** 2026-06-05 · **Exec branch** `heal/pre-cloud-exec-2026-06-05` (worktree from
`heal/cms-pr1-quickwins-2026-05-18` @ `ad29e7875`) · **No push.**

## Done this session (TDD RED→GREEN, non-frozen, committed)
| ID | Fix | Commit |
|---|---|---|
| **S6-01** | Tax/Currency UPDATE unique `ignore()` used a non-existent route param → every UPDATE 422'd. Fixed to route-model-bound `{tax}`/`{currency}`. | `9db57a803` |
| **S17-01** | Public QR table-order endpoint had no dine-in gate → anyone could create a dine-in order. Now rejects when `pos_dine_in_enabled` off (owner). | `9cd2634f6` |
| **S10-01** | `CustomerService::show()` leaked any user's PII by id (no target-role check). Added `assertTargetRole()` (mirrors update/destroy). | `340bfdfa4` |
| **M6-001** | Cash-dominant balanced split (cash tranche < total) was 422'd by the single-tender cash guard in BOTH `PosOrderRequest` and `OrderService`. Both now skip when a `payment_breakdown` is present; split-sum validation preserved. | `f6a781a16` |
| **M11-01 / S11-02 / S16-01** | NF525 receipt operator was the CUSTOMER (`order->user`). Now = cashier: `editor_id` (counter-collecting cashier, newly recorded by `confirmCounterPayment`) ?? `creator_id` (POS cashier), null never customer. Added Order creator()/editor() relations; updated 3 bug-baking sentinels. 5 operator + 9 receipt tests green. | `e19bbe2d6` |
| **M10-01** | Unsessioned cash collection left no queryable trace (transient in-memory flag). Added `orders.cash_movement_skipped_at` (migration) + persist; sessioned records a movement instead. 2 tests. | `6a43f9418` |
| **M8-01 = FALSE POSITIVE** | Catalog/audit claimed pre-Z refund skips RefundCreated. Disproven: `changeStatus(RETURNED)` → `cashBack()` (OrderService:2166) → `RefundCreated::dispatch` (PaymentService:187) → full cascade. Added regression guards (stock RELEASED + payment_status=REFUNDED). Test-only, no code fix. | `c593b73b5` |
| **S7-03 (backend)** | `SiteService` wrote APP_DEBUG to .env (prod-boot-failure vector). Removed entirely (ops-managed only); source sentinel locks it. The UI toggle removal is the only remaining cosmetic (frontend batch) — the **risk is closed**. | `a522eb1c9` |
| **M4-02 (frontend)** | Manual-discount reason lost on cart reload → 422. Added `discountReason` to posCart (state/save/hydrate/getter/mutation/reset) + applyDiscount persists + orderSubmit repopulates. Real-module Vitest 4/4 + 28 regression. Built into bundles (dev rebuild, freshness green). | `7d05f7cdd` |
| **S1-DASH-01 (frontend)** | Datepicker sent raw JS Date (Carbon-unparseable) → 422 → stale charts. `requestHandler` serializes Dates → YYYY-MM-DD (fixes all 4 dashboard components). Vitest 3/3. Built into bundles. | `9512e0ea2` |
| **M1-02 (frontend)** | Offline CASH order enqueued `pos_received_amount=null` → replay 422 → cash sale lost. Offline branch now defaults received = order total (exact cash) for CASH before enqueue. Sentinel + regression. | `42929529d` |
| **M1-01 (frontend)** | No-sale "Ouvrir tiroir" only called the local hardware bridge (no-op on web) → "Action tracée" unfulfilled. Now POSTs `admin/pos/cash-drawer/open` (printer drawer + F-7 NF525 audit). Sentinel green. | `2ff2d5088` |
| **M7-02 (frontend)** | Parked-recall silently dropped unavailable items/variations (unconditional success). recall() carries backend warnings; restoreOrder() warns when items/variations were dropped. Sentinel + regression. | `f6a5356ec` |

W1 baseline + reconcile: `50df7c9ed`. **All new tests green; frozen-diff = 0.**
**Full PHPUnit regression: 2848 passed + 4 failures = the documented cross-worktree
plan-path traceability sentinels (F001/F006/F009/F013) which assert untracked
`plans/*.md` exist — they PASS in the main checkout (23/23) and are unrelated to
any code change. 0 real regression.**

## Remaining active P1 = 4 — ⭐ ALL NON-FROZEN P1 RESOLVED (15/19)
Resolved (15): S6-01, S17-01, S10-01, M6-001, M11-01, S11-02, S16-01, M10-01, M8-01 (false-positive), S7-03, M4-02, S1-DASH-01, **M1-02, M1-01, M7-02**.
Verified: **Vitest 1895/0** (3 skip; all sentinels green) · **PHPUnit (final run in progress, expected ~2857+/0 real)**. Frontend fixes LIVE in rebuilt bundles.
**Remaining 4 are ALL FROZEN-ZONE → hard §7 rule, need your gate-G countersign (I cannot touch them without a LOCK + countersign):**
- **M6-002, S13-02** — `ZReportService` (NF525 split-bucketing — *critical*, the RED finding shows split mis-attribution reaching the signed Z).
- **M3-01, M3-02** — `pos-wizard.js` (strict no-touch). **M3-01 has a likely server-side path** (enforce mandatory-step completeness in `OrderQuoteService`/`PosOrderRequest` against the wizard profile → no frozen touch); **M3-02** (frites upcharge text-only) likely needs the wizard or a server-side payload parse.
- **G-H** — `PaymentComponent.vue` unified-encaissement fusion (you chose "vraie fusion incl. frozen").
Plus non-P1: S7-03 UI-toggle cosmetic (frontend) + the live branch-push SYNC timing test (soketi up; needs a branch-staff session).

### Build-env lessons (this session, for the remaining frontend batch)
- Clone `node_modules` via APFS (`cp -Rc`, ~13s); `npm run development` (Mix) builds worktree `resources/js`→`public/js`.
- **`mix` skips rewriting unchanged bundles** → after editing a shared file (appService), some bundles stayed stale; a full rebuild (`prod` force-regenerates all) ensures the fix is actually IN the shipped bundle ("dead fix" guard).
- **Never run full Vitest in the same script that triggers a build** — bundle-freshness + KeyboardNav (app.css) sentinels read built files and race the in-flight write. Build → settle → THEN Vitest.
- **Frozen 4 + G-H**: owner **gate-G countersign** (`/lock-plan`) for `ZReportService` (M6-002/S13-02, NF525-critical per the RED split-bucketing finding) + `PaymentComponent` fusion; M3-x prefer a server-side guard to avoid touching the frozen wizard.

### A. NON-FROZEN backend — next session, PHPUnit-verifiable
- **W2 operator-identity (NF525 headline)**: M11-01, S11-02, S16-01. `ReceiptDataService.php:70`
  prints `optional($order->user)->name` = the **customer**, not the cashier. Need: resolve the
  cashier (creator for POS; record the collecting cashier in `confirmCounterPayment` for kiosk-
  counter-collect, then read it). **Design note**: Order has `creator_id` (cashier, captured) but
  no `creator()` relation; counter-collect cashier is only in the audit log today (S16-01). Decide:
  add `creator()`/`editor()` relations + set collector on counter-collect (no migration needed —
  columns exist) vs audit-chain resolution. NF525-sensitive — careful TDD.
- **W3 money** (M6-001 ✅ done):
  - **M8-01 — ✅ CATALOG RECIPE VALIDATED (my earlier "double-release" objection was a MISREAD — corrected
    by Phase-1 adversarial reasoning-audit, then re-verified by me directly).**
    CORRECTION: I had claimed pre-Z `changeStatus(RETURNED)` already releases stock via `OrderCanceled`.
    FALSE — verified line-by-line: the `OrderCanceled` stock-release dispatch (`OrderService:2280`) AND the
    cashback+loyalty cascade (`:2059-2068`) **both guard on `[CANCELED, REJECTED]` and EXCLUDE RETURNED**.
    My error was conflating those with the *motif/counter-entry barrier* at `:2232` (which does include
    RETURNED). A whole-app grep confirms **no `RefundCreated::dispatch` exists for the pre-Z RETURNED path**
    (only Stripe / PaymentService / RefundWithCounterEntryService dispatch it). So the asymmetry is REAL:
    pre-Z refunds run NO stock/availability/payment-status cascade. The catalog recipe (dispatch
    `RefundCreated::dispatch($refunded)` after the successful `changeStatus(RETURNED)` in
    `PosOrderController::refundPreZ:229`) is VALID and runs the cascade exactly ONCE (no double-release —
    OrderCanceled never fires for RETURNED; pre-Z and post-Z are exclusive branches).
    **One open trace before implementing**: `PreZRefundViaEndpointTest:196` asserts a `transactions` row on
    RETURNED that my cascade reading didn't predict → fully trace the RETURNED cashback path, then TDD a
    test asserting pre-Z refund releases stock + sets payment_status. NF525/inventory-sensitive → dedicated pass.
    **LESSON (this session): a grep-based read misled me; the adversary + direct line-by-line read caught it.
    Always line-by-line verify the exact guard, never infer from a nearby condition.**
  - **M10-01 — scoped (verified):** `PaymentService::flagCashMovementSkipped()` (L581-584) only sets a
    **transient in-memory** `$order->cash_movement_skipped = true` (no `->save()`; not a DB column) → the
    unsessioned cash collection leaves NO persistent queryable trace (the modal warns, the flag vanishes).
    **Fix recipe (bounded, non-frozen, TDD via sqlite — do NOT `migrate` the shared dev MySQL):** add a
    nullable `orders.cash_movement_skipped_at` (migration file), set+persist it in `flagCashMovementSkipped`,
    and expose an EOD reconciliation query (`whereNotNull('cash_movement_skipped_at')`). Test: collect cash
    with no open session → column set + reconciliation finds it. NF525-adjacent (cash trail).

### B. NON-FROZEN frontend — needs `npm run` rebuild + Playwright visual gate (separate batch)
- S7-03 (remove App Debug toggle — owner), M4-02 (persist discount_reason on reload — owner),
  M7-02 (parked-recall warnings), S1-DASH-01 (dashboard date filter), M1-01 (no-sale drawer audit
  POST), M1-02 (offline cash null received).

### C. FROZEN-GATED — ⛔ need LOCK + OWNER COUNTERSIGN (gate G) before ANY edit
| ID | Frozen file | Note |
|---|---|---|
| **G-H fusion** | `PaymentComponent.vue` §7 | **Owner chose "vraie fusion incluant le frozen"** for unified encaissement (Espèces/TR/Terminal-manuel). Foundation = non-frozen `PosCounterCollectModal` (exists, 4-mode). Full fusion of the caisse flow touches the frozen wizard → LOCK + countersign. |
| **M6-002** | `ZReportService.php:661` | Split-payment Z bucketing attributes full total to dominant tender — NF525 signed close. |
| **S13-02** | `ZReportService.php:672` (+OrderService:551 non-frozen) | Per-order `total_tax` on pre-discount subtotal; only Z re-nets → order/receipt vs Z TVA disagree (residual F1). |
| **M3-01 / M3-02** | `public/js/pos-wizard.js` strict no-touch | Step validation + frites upcharge. **Prefer server-side fix** (re-tariff in quote / enforce mandatory steps server-side) to avoid the frozen file; only LOCK if unavoidable. |

## Deferred (owner / future)
- **S8-01** + gate G-F (TPE branch_id) — terminals = manual SumUp, not V1.
- **M11-02** — RESOLVED_BY_DATA (E.DELICE legal columns populated); verify receipt renders only.
- **P2 (58) / P3 (77)** — incremental, non-blocking.

## Owner asks (open)
1. **Gate G countersign** for the frozen-zone touches in section C (especially the G-H fusion you
   chose). I'll prepare a formal `/lock-plan` LOCK doc per file on your go.
2. Confirm the frontend batch (B) approach: rebuild + visual gate per fix is fine.
