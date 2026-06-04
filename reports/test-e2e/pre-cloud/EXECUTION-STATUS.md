# Pre-Cloud Remediation — EXECUTION STATUS (live)

**Updated** 2026-06-05 · **Exec branch** `heal/pre-cloud-exec-2026-06-05` (worktree from
`heal/cms-pr1-quickwins-2026-05-18` @ `ad29e7875`) · **No push.**

## Done this session (TDD RED→GREEN, non-frozen, committed)
| ID | Fix | Commit |
|---|---|---|
| **S6-01** | Tax/Currency UPDATE unique `ignore()` used a non-existent route param → every UPDATE 422'd. Fixed to route-model-bound `{tax}`/`{currency}`. | `9db57a803` |
| **S17-01** | Public QR table-order endpoint had no dine-in gate → anyone could create a dine-in order. Now rejects when `pos_dine_in_enabled` off (owner). | `9cd2634f6` |
| **S10-01** | `CustomerService::show()` leaked any user's PII by id (no target-role check). Added `assertTargetRole()` (mirrors update/destroy). | `340bfdfa4` |

W1 baseline + reconcile: `50df7c9ed`. **All new tests green; frozen-diff = 0; full PHPUnit
regression running to confirm 2844+8.**

## Remaining active P1 = 16 (19 gate − 3 healed)

### A. NON-FROZEN backend — next session, PHPUnit-verifiable
- **W2 operator-identity (NF525 headline)**: M11-01, S11-02, S16-01. `ReceiptDataService.php:70`
  prints `optional($order->user)->name` = the **customer**, not the cashier. Need: resolve the
  cashier (creator for POS; record the collecting cashier in `confirmCounterPayment` for kiosk-
  counter-collect, then read it). **Design note**: Order has `creator_id` (cashier, captured) but
  no `creator()` relation; counter-collect cashier is only in the audit log today (S16-01). Decide:
  add `creator()`/`editor()` relations + set collector on counter-collect (no migration needed —
  columns exist) vs audit-chain resolution. NF525-sensitive — careful TDD.
- **W3 money**: M6-001 (OrderService:1071 split-cash guard — hoist `$splitActive` per verdict §4),
  M8-01 (PosOrderController two refund paths), M10-01 (PaymentService cash-no-drawer backend trail;
  the modal already surfaces the warning — backend row still missing).

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
