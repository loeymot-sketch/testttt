# T-1.2.5 — Refund + Sealed-Order Attack Surface (SECURITY, Round 3)

Specialist: SECURITY · Mindset: hostile cashier-fraud · Mode: READ-ONLY  
Anchors verified: `RefundWithCounterEntryService`, `SealedOrderGuard`, `PosOrderController::refundWithCounterEntry`, `OrderService::changeStatus|destroy`, `PaymentStateMachine`, `AuditLogService`, `FiscalSequenceService`, route `routes/api.php:867`, `RolePermissionTableSeeder`, `PermissionTableSeeder`, 5 fiscal/refund tests.

VERDICT: **AMBER / NEED-FIXES** — chain integrity is solid (HMAC + UNIQUE + DB trigger), sealed-Z guard is correctly inverted on both sides (mutation-block + mirror-require), and pricing/composition are SSOT-frozen. BUT cashier-fraud surfaces remain: **no per-amount manager threshold, no `refund_amount` partial-refund knob (full-mirror only — fine), no second-actor / four-eyes gate on counter-entry, weak threat model on void abuse via `destroy_reason`, IP/UA captured but not enforced anywhere**.

---

## 1. Refund AUTHZ — who can refund?

Surface: `POST /api/admin/pos-order/{order}/refund-with-counter-entry` (`routes/api.php:867`).

- Middleware chain (controller `__construct`, `PosOrderController.php:28-36`): **`permission:pos-orders`** + route-level `throttle:pos-order-update` (120/min/user, `RouteServiceProvider.php:101`) + `idempotency`.
- `pos-orders` is granted to **Admin** (all perms via `RolePermissionTableSeeder.php:19`), **Branch Manager** (`:31`), **POS Operator** (`:90`). Cashier = full refund authority.
- Controller adds defense-in-depth cross-branch check (`:57-61`): non-Admin role + `branch_id` mismatch → 403. Good.

### Findings
| # | Severity | Finding |
|---|----------|---------|
| S-AUTHZ-01 | **P0** | **No amount-tiered manager gate.** A POS Operator with `pos-orders` can refund any €N (€10 or €10 000) without a second-actor approval. Compare with `pos-discount-over-10-requires-manager` (which exists and gates discounts ≥10%) — **the equivalent `pos-refund-over-N-requires-manager` permission does not exist**. NF525 doesn't require it but cashier-fraud playbook absolutely does. Recommend: add `pos-refund-over-threshold` perm (Branch Manager only) + config `pos.refund.threshold_eur` default €50 + service-level check on `abs($parent->total)`. |
| S-AUTHZ-02 | **P1** | **No `pos-issue-refund` standalone permission.** Refund authority is welded onto `pos-orders` which also gates `index/show/destroy/changeStatus`. A cashier who needs to *read* the POS order list inherits *refund authority*. Recommend splitting: `pos-orders-read` (index/show), `pos-orders-mutate` (changeStatus/changePaymentStatus), `pos-refund` (counter-entry only). |
| S-AUTHZ-03 | **P1** | **Self-refund not blocked.** Nothing in `RefundWithCounterEntryService::execute()` or the controller checks that `Auth::id() !== $parent->user_id`. The mirror reuses `$parent->user_id` (`RefundWithCounterEntryService.php:97`) so the same cashier that closed the sale can refund it to themselves. The audit row captures `user_id` of the refunder (so it's traceable), but blocking is the standard control. |
| S-AUTHZ-04 | P2 | Defense-in-depth controller check (`:57-61`) only guards "cross-branch refund denied" — it bypasses if `$authUser->hasRole('Admin')`. A user with **branch_id=42** who has been mistakenly granted the `Admin` role (RBAC misconfig) becomes a cross-branch refunder. The dedicated `isGlobalAdmin()` predicate (`OrderService.php:2362`: Admin + `branch_id===0`) is **not** reused here. Drift between the two predicates is a latent grant-escalation. |

---

## 2. Refund-to-self / personal-gain routing

The mirror order persists negated `pos_payment_method` + `payment_method` + `terminal_id` (`:107-112`, `:179-181`) — refund debits the **same physical TPE** that took the sale. Good for Z-balance, neutral for fraud: a card refund always lands back on the card PAN that paid.

But for **cash + split-payment** tranches: the mirror duplicates each `OrderPayment` with negated `amount` and same `mode` (`:175-191`). For a cash tranche, the mirror credits the drawer with `-N€`, i.e. **N€ are removed from the drawer**. There's no enforcement that the cashier actually puts that cash back in the drawer.

### Findings
| # | Severity | Finding |
|---|----------|---------|
| S-SELF-01 | **P0** | **Cash-refund pulls drawer cash with no physical-counterparty proof.** Counter-entry on a 50€ cash sale ⇒ mirror `OrderPayment{mode=CASH, amount=-50, paid_at=now()}` ⇒ Z reconciliation expects 50€ less in drawer. Cashier pockets the 50€ — books balance. Mitigations should require: (a) `cash.reconcile.variance.override` perm already exists for variance, extend to "manager countersign on cash refund ≥ threshold" (b) print physical refund receipt customer must sign (NF525 already requires receipt — verify printing path) (c) require `customer_pan_last4` / `customer_signature_blob` on cash refund payload. None present in `$request->validate()` (controller `:52-54` validates `reason` only). |
| S-SELF-02 | P1 | `RefundCreated::dispatch($parent)` (`:229`) fires stock-release + availability-release on the **parent** items. Listeners (`ReleaseStockOnRefundCreated.php`) write back to `stock_levels`. A fraud cashier with a real cash refund issued for an item not actually returned silently restocks → inventory count drifts up, masking the theft if the cashier also takes the physical product. Recommend coupling refund to **return-receipt scan** (item barcode confirm) for physical-goods restocks. |

---

## 3. Ghost refund (cancelled / already-refunded / fictional order)

`RefundWithCounterEntryService::execute()` guards:

1. `$parent->fiscal_sequence_no === null` → 422 (no ghost-fiscal). ✅ (`:54-58`)
2. `SealedOrderGuard::assertSealed()` → 422 if no closed Z window covers `created_at`. ✅ (`:70-71`) — pre-Z parents rejected.
3. `OrderStatus::RETURNED` already → 422 "duplicate mirror". ✅ (`:73-77`)
4. `reason` trim non-empty min:3 max:700. ✅ (`:80-82` service + `:52-54` controller).

Route-model-binding `Order $order` enforces existence (404 on fictional id). Controller branch-mismatch (`:57-61`) blocks cross-branch refund. Route is `idempotency`-middlewared → same `X-Idempotency-Key` returns cached 201 (no double mirror).

### Findings
| # | Severity | Finding |
|---|----------|---------|
| S-GHOST-01 | **P1** | **Mirror-of-mirror not blocked.** A mirror order (`parent_order_id != null`, `total < 0`) itself has a fresh `fiscal_sequence_no` and lives in the still-open Z. If a cashier waits one Z close, the mirror becomes sealed too. Calling `refund-with-counter-entry` on the **mirror's id** is not explicitly refused — the parent-already-RETURNED guard (`:73`) catches it because mirrors are created with `status=RETURNED`, so this is *currently OK by accident*. Promote to explicit invariant: refuse if `$parent->parent_order_id !== null` (negative-of-negative-of-positive would otherwise repay the customer). |
| S-GHOST-02 | P1 | `ReleaseStockOnRefundCreated` listener fires off `RefundCreated::dispatch($parent)` **on every counter-entry call**. The existing duplicate-mirror guard prevents 2 mirrors, but if the duplicate-call attempt throws *after* event dispatch in some refactor, stock could double-release. Today the event is inside the same transaction's success path (`:229`) so safe; add a sentinel test for "double-call → exactly one stock release". |
| S-GHOST-03 | P2 | `Order::create()` (`:95`) is not wrapped with `withoutGlobalScopes()` and the auth user's `BranchScope` is generally applied at *read*, not *create*. Risk minimal because `branch_id` is set explicitly from the parent. Verify a Branch Manager (branch_id=5) refunding admin-cross-branch through the controller cross-branch guard isn't routed (Admin role bypass = OK; non-Admin = 403). Already enforced. |

---

## 4. Post-Z refund forgery (fake "pre-Z" refund row)

The single anchor for "was this order sealed?" is `SealedOrderGuard` with the predicate:  
`ZReport WHERE branch_id = ?, status=CLOSED, opened_at < created_at, closed_at >= created_at`.

`fiscal_sequence_no` is allocated atomically by `FiscalSequenceService::next()` (cache-lock + `lockForUpdate` + DB unique index `orders_branch_fiscal_seq_unique`). Three-layer race protection.

For a sealed parent, the mirror gets a **fresh** `next()` sequence into the current Z (`:90`), and the parent `fiscal_sequence_no` is never touched (`Order::create` doesn't write it on the parent; mutation goes only into the mirror). Parent immutability is preserved.

### Findings
| # | Severity | Finding |
|---|----------|---------|
| S-FORGE-01 | **P0-LATENT** | **`created_at` is the seal-anchor and is mutable on `Order` (no `updated_at`/`created_at` immutability sentinel).** A `pos-orders`-permission holder who can also run a raw `php artisan tinker` or has DB write access could update `Order::created_at` backward to slip a fresh order into a closed Z window OR forward to escape a closed Z window. The fiscal chain protects `audit_logs` and `z_reports` via DB trigger (`add_z_reports_delete_trigger_immutability.php`), but `orders` table has **no equivalent trigger** preventing UPDATE of `fiscal_sequence_no`, `branch_id`, `created_at`. NF525 requires immutability of fiscal-sequence-bearing rows. Recommend `BEFORE UPDATE` trigger on `orders` blocking changes to those 3 columns when `fiscal_sequence_no IS NOT NULL`. |
| S-FORGE-02 | **P1** | **Seal predicate uses `opened_at < created_at` (strict).** An order created at the exact second of `opened_at` (`created_at == opened_at`) is **not** considered sealed by the previous Z window (good — it belongs to the new window). But a cashier who close-Zs then immediately processes a sale will see `opened_at` of next-Z reset to `closed_at_prev + Δ`. The boundary condition between Z windows allows a 1-second gap where an order with `created_at` exactly = `prev_z.closed_at` would be sealed (covered by `<=`) but also potentially missed by the next aggregate. Verify `ZReportService::aggregate()` uses matching half-open `(opened_at, closed_at]` semantics. (Comment at `OrderService.php:2202` claims yes — confirm via test.) |
| S-FORGE-03 | P2 | `RefundWithCounterEntryService::execute()` does **not** call `FiscalChainValidator` before committing the mirror. A pre-existing tampering in `audit_logs` would not be detected at refund time. AuditLogService::write() relies on UNIQUE(branch_id, prev_hash) which catches *forking* but not *retro-tampering*. Recommend: call `verifyChain($branchId)` inside the transaction before `audit->write()` for any **negative** financial action; throw if chain is corrupted. |

---

## 5. Refund without original order

Route binding `Route::post('/{order}/refund-with-counter-entry', ...)` uses implicit Eloquent binding → 404 on fictional id (BranchScope-aware). ✅

`Order::create()` requires `branch_id` (set from `$parent->branch_id`). FK on `parent_order_id` (migration referenced at `:30`). Cannot reference a fictional parent. ✅

No finding here beyond S-FORGE-01.

---

## 6. Mass-assignment & `refund_amount` overflow

Controller validates **only `reason`** (`:52-54`). The service computes mirror financials **exclusively from `$parent`** (`:102-106`): `subtotal/total_tax/total = -1 × parent.*`. There's no caller-supplied `refund_amount`, no partial-refund, no over-refund vector.

Mass-assignment to `Order::create()` is constrained by `$fillable`. The fiscal-critical `fiscal_sequence_no` is **not** in fillable — assigned via property + `save()` (`:115-117`). ✅

### Findings
| # | Severity | Finding |
|---|----------|---------|
| S-MASS-01 | P3 | The service is full-refund-only by design. **No partial-refund support** is a *feature*, not a bug — but downstream business may demand it. If introduced later, validate `0 < refund_amount ≤ abs(parent.total)` and cumulate against previously issued partials. Today: safe. |
| S-MASS-02 | P2 | `reason` accepts up to 700 chars (`max:700` controller `:53` + service trim). The payload is stored in `audit_logs.payload` JSON. If the audit log is later surfaced in admin UI without escape, `reason` is XSS-injection territory. Recommend HTML-escape on render + add `reason` to allowlist sanitizer. |

---

## 7. Refund chain HMAC integrity

`AuditLogService::write()` (`:70-132`) enforces:
- Cache lock `audit_chain_b{branchId}` (5s wait, 10s TTL) — serialises writers.
- `DB::transaction` wraps tail-read + insert.
- `UNIQUE(branch_id, prev_hash)` index rejects forks at the DB level even on cache outage. Retry-once on unique violation (`:179-191`).
- `branch_id === null` is rejected (`:93-98`) — refund service passes `(int) $parent->branch_id` so this is never null. ✅
- HMAC-SHA256 keyed by `fiscal.audit_secret` with per-branch override via env. Production-safe sentinel + min-length checks (`:303-327`).

`verifyChain()` re-walks the entire chain (`:199-231`) and returns the first tampered/forged row id. **Not called by refund path.**

### Findings
| # | Severity | Finding |
|---|----------|---------|
| S-CHAIN-01 | P1 | **No verify-before-write on the refund path.** A pre-existing chain corruption survives a refund (which silently appends a new row on top of a broken tail). Already noted as S-FORGE-03. |
| S-CHAIN-02 | P2 | The cache lock TTL is 10s and wait 5s. Under sustained refund storms (e.g., scripted abuse), legitimate writers can race the retry path; under DoS, the lock may **succeed but fail to release** on a worker crash mid-write — chain stalls for 10s. Acceptable, but observability: there's a `[FISCAL_TIMING]` log breadcrumb (`:128`) — verify SIEM alerts on `duration_ms > 1000`. |
| S-CHAIN-03 | P2 | Per-branch secret override via `env('FISCAL_AUDIT_SECRET_BRANCH_'.$branchId)` (`:273`). An attacker with `.env` write (separate compromise vector) could rotate the secret post-tamper, then `verifyChain()` returns "intact". The dev-sentinel + min-length guards mitigate accidental misconfig but not deliberate post-tamper rotation. NF525 doesn't address — vault/HSM-stored secret is the canonical fix. |

---

## 8. Void abuse — pre-Z VOID erasing same-day revenue

Pre-Z cancellation path: `OrderService::changeStatus` with `toStatus ∈ {CANCELED, REJECTED, RETURNED}` requires `reason` (max:700, `:1747-1749`). `SealedOrderGuard::assertMutable()` runs ONLY for `RETURNED`, not for `CANCELED/REJECTED` (`:1754-1757`) — comment claims those are "operational pre-payment" so don't need sealing.

But in `aggregate()` semantics (per `VoidPreZTest`), an order with `status=CANCELED, payment_status=PAID, fiscal_sequence_no=1` is **counted as `cancel_count++` with total_ttc=0**. So a cashier can:

1. Process a 100€ cash sale → drawer +100€, `fiscal_sequence_no=N`, status=ACCEPT, payment_status=PAID.
2. Same Z, no Z close yet, change status to CANCELED with a `reason` string (cashier-controlled).
3. Z aggregate: `total_ttc=0`, `cancel_count++`, drawer expected +0€.
4. Cashier pockets 100€. Reason field says "client changed mind, refund issued in cash".

The PaymentStateMachine `assertCanTransition(PAID → REFUNDED)` is blocked (`PAID → []`, `PaymentStateMachine.php:17`). But `changeStatus` does NOT call PaymentStateMachine for the `RETURNED/CANCELED` status transitions — they're order-status, not payment-status. So `payment_status=PAID` stays `PAID` while `status=CANCELED`. The Z aggregate reads `status` (per VoidPreZTest assertion), so revenue is silently zeroed.

### Findings
| # | Severity | Finding |
|---|----------|---------|
| S-VOID-01 | **P0** | **Same-day pre-Z VOID erases revenue without manager countersign.** The `pos-orders` permission alone grants this. There is `pos-discount-over-10-requires-manager` but no `pos-void-paid-order-requires-manager`. NF525 fundamentally allows this (you can cancel before Z), but France's anti-fraud doctrine assumes a **manager-countersign + customer-receipt** workflow. Recommend: when transitioning `status=ACCEPT|other → CANCELED|REJECTED` while `payment_status === PAID`, require either `pos-manager-void-paid` permission OR a same-transaction "manager-PIN" payload. |
| S-VOID-02 | P1 | `reason` is captured but not structured (max:700 freeform). No `void_category` enum (`customer_left`, `kitchen_error`, `wrong_input`, etc.). Forensic correlation by SIEM is keyword-grep only. Recommend structured enum + free-text. |
| S-VOID-03 | P1 | `OrderService::destroy()` (`:2175-2272`) on a PAID order requires `pos-destroy-paid` permission (`:2192`). This is granted to **Admin only** (via `Permission::all()`, not in Branch Manager / POS Operator lists). Good. But `destroy_reason` request param (`:2219`) is read via `request('destroy_reason', '')` — **not validated for length/charset**. A 10 MB reason string would balloon the audit-log payload (DB MEDIUMTEXT/JSON columns). Recommend explicit `validate(['destroy_reason' => 'nullable|string|max:700'])` in controller before service. |
| S-VOID-04 | P2 | `destroy()` soft-deletes the order (`$order->delete()` on a SoftDeletes model, `:2230`) and **hard-deletes order_items + address** (`:2227-2229`). Audit log captures status snapshot (`:2252-2266`) but not the *items list* — a destroyed order's item composition is lost beyond the audit-row `payload` (which does NOT serialize items at `:2253-2265`). Recommend serializing `orderItems[]` summary (id, name, qty, price) into the audit payload. |

---

## 9. Throttle / DoS surface

`throttle:pos-order-update` = 120 req/min/user (RouteServiceProvider.php:101). For refund-counter-entry on a sealed order this is reasonable — a real cashier won't hit 2/sec. But:
- Idempotency middleware caches 2xx responses per `X-Idempotency-Key` — replay returns the cached mirror, prevents double-mirror. ✅
- An attacker spamming with **distinct** keys would hit the throttle at 120/min. Refund creates 1 mirror + 1 audit row + ~N OrderPayment rows + 1 stock-release event per call. Under throttle, max ~7200 mirror orders/hour/cashier — DB ingest manageable but `audit_chain_b{branchId}` cache lock at 5s wait means realistic ceiling is much lower.

| # | Severity | Finding |
|---|----------|---------|
| S-DOS-01 | P3 | Refund route shares its limiter with all other POS update routes. Under heavy refund-script attack, legitimate `changeStatus` calls share the budget. Recommend a dedicated `throttle:pos-refund` with tighter limit (e.g., 20/min/user). |

---

## Top-5 priorities for Round 4 / heal-implementer

1. **S-AUTHZ-01** — Add `pos-refund-over-threshold` permission + amount gate (Branch Manager only).
2. **S-SELF-01** — Manager countersign on cash refund ≥ threshold; explicit customer-signature/PAN-last4 on payload.
3. **S-VOID-01** — Manager-countersign requirement when status:PAID→CANCELED|REJECTED (pre-Z VOID).
4. **S-FORGE-01** — `BEFORE UPDATE` DB trigger on `orders` blocking column changes when `fiscal_sequence_no IS NOT NULL` (`created_at`, `branch_id`, `fiscal_sequence_no`).
5. **S-AUTHZ-03** — Refuse `RefundWithCounterEntryService::execute()` when `Auth::id() === $parent->user_id` (self-refund block).

P1 additional: S-AUTHZ-02 (split `pos-orders` permission), S-CHAIN-01 (verifyChain pre-refund), S-GHOST-01 (refuse refund-of-mirror), S-VOID-02 (structured void category).

Chain integrity (DB trigger + UNIQUE index + HMAC + per-branch secret) is **production-grade**. The remaining attack surface is **human/process** rather than cryptographic — cashier authority is too broad, manager-countersign workflow is absent.
