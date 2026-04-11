# CYCLE-002 — Execution report (classification only)

**Cycle:** 2026-04-11 — CYCLE-002  
**Executor:** Cursor  
**Mode:** Read-only on source; this file only write  
**Scope:** `OrderService::changeStatus` + Admin callers + `OrderStatusRequest` + `routes/api.php` excerpts per plan  

**Explicit non-inspection (per plan):** `FrontendOrderService.php` and `KitchenDisplaySystemOrderService.php` were **not** re-opened in this cycle. `KitchenDisplaySystemOrderService::changeStatus` transaction usage is cited only from CYCLE-001 / prior evidence.

---

## Method map (TASK-01–04) — `OrderService::changeStatus`

**File:** `app/Services/OrderService.php`  
**Signature:** `public function changeStatus(Order $order, OrderStatusRequest $request, bool $auth = false): Order|array`

| Region | Lines | Summary |
|--------|-------|---------|
| Method open / outer try | 1229–1231 | `try {` |
| Status transition gate | 1232–1234 | `ValidStatusTransition` on `$order->status` → `$request->status`; throws `Exception` with 422 if invalid |
| `$auth === true` branch | 1236–1259 | Comment: customer self-cancellation path; owner check; optional reason; cashback if REJECTED/CANCELED + transaction; SendOrderMail/Sms/Push; assign status; `save()`; else `abort(403)` |
| `$auth === false` branch | 1260–1310 | Branch isolation for non-Admin; optional reason validation + cashback for REJECTED/CANCELED; `$oldStatus`; SendOrder*; assign; `save()`; `OrderStatusChanged` + `ActionLog` |
| Return / catch | 1311–1316 | `return $order`; catch rethrows via `QueryExceptionLibrary` |

**`$auth` source:** Third parameter `bool $auth = false`. No caller in the inspected codebase passes `true` (all `OrderService::changeStatus` invocations use two arguments only).

**Comments in method (TASK-06):** Inline comments include `[FIX-54-7]`, `[AUDIT-FIX P0-2]`, `[PHASE-E]`; **none** explain omitting `DB::transaction` or ordering notifications before `save()`. **Verbatim:** no explanatory comment tying “no transaction” or “pre-save dispatch” to an intentional design.

---

## Classification target: `OrderService::changeStatus`

**Method boundaries:**  
- Opens at line: **1229**  
- Closes at line: **1316**

---

## Q1 — Pre-save dispatch assessment

**Pre-save dispatch classes (admin / `$auth === false` branch):**

- `SendOrderMail::dispatch([...])` at line **1286** — before `save()` at line **1290**
- `SendOrderSms::dispatch([...])` at line **1287** — before `save()` at line **1290**
- `SendOrderPush::dispatch([...])` at line **1288** — before `save()` at line **1290**

(`$auth === true` branch: same pattern at **1251–1253** before `save()` at **1255**.)

**Guard or safeguard present:** **no** — no rollback of queued jobs, no compensating saga, no ordering of notifications after successful `save()` in this method.

**Save() failure impact on prior dispatches:** **notifications already fired (queued) and cannot be recalled** by this method; outer `catch` (1312–1314) runs only after dispatches have been issued on the happy path toward `save()`.

**Q1 verdict:** **RISK** — If `save()` fails after dispatches, notifications can describe a status not persisted; there is no positive structural safeguard in this code proving that case is impossible or self-healing.

---

## Q2 — No DB::transaction assessment

**Transaction present in method:** **no**

**Structural reason transaction may be omitted (single model write, etc.):** The core mutation is primarily `$order->status` + `$order->save()`, but the method also performs **additional side effects** on the staff path: `OrderStatusChanged::dispatch`, `ActionLog::create`, and (for REJECTED/CANCELED) `PaymentService::cashBack`. That is **not** a single atomic write; no comment justifies skipping a transaction.

**Explanatory comment in code:** **none found** (for no-transaction or dispatch ordering).

**Comparable methods with transaction (per plan / prior knowledge):**

- `myOrderStore`: yes (L256–467) — per plan
- `tableOrderStore`: yes (L864–1069) — per plan
- `posOrderStore`: yes (L508–820) — per plan
- `KitchenDisplaySystemOrderService::changeStatus`: yes (L115–118) — **not re-read**; CYCLE-001 evidence

**Q2 verdict:** **ASYMMETRY** — **no evidence either way** that omitting `DB::transaction` here is intentional; asymmetry vs other flows is real and undocumented.

---

## Q3 — `$auth === true` branch assessment

**Branch entry condition:** Third parameter `$auth === true` on `changeStatus(...)`.

**Actor and surface that reaches this branch:** **No in-repo caller** passes `true`; all inspected Admin controllers call `changeStatus($order, $request)` → **`$auth` is always `false`**.

**Reachable in production:** **no** (given current static call graph in `app/`).

**OrderStatusChanged absent — impact if reachable:** If it were reachable, real-time listeners (e.g. KDS/OSS) would **not** receive `OrderStatusChanged` for that path; **not applicable** to live Admin routes today.

**Q3 verdict:** **UNREACHABLE** — dead path for current `OrderService::changeStatus` usage; **documentation / legacy-code debt** if the parameter was meant for a future or removed customer API.

---

## Q4 — Status transition validation

**Validation location:** **line 1232–1234** in `OrderService::changeStatus` — `(new \App\Rules\ValidStatusTransition($order->status))->passes('status', $request->status)` before either branch.

**Additional request rules:** `OrderStatusRequest::rules()` — `'status' => ['required', 'numeric']` only (no transition list in FormRequest).

**Transitions enforced:** **yes** — via `ValidStatusTransition` before any notification dispatch or branch-specific logic.

**Invalid transition possible without rejection:** **no** — failure throws at 1232–1234 before dispatches.

**Q4 verdict:** **VALIDATED**

---

## Q5 — Branch isolation

**Branch isolation mechanism:** **manual comparison** of `Auth::user()->branch_id` to `$order->branch_id` for authenticated users who **do not** have role `Admin`.

**Line:** **1262–1266** (`$auth === false` branch).

**Bypass risk:** **no** for the staff path as written, unless `Auth::user()` lacks `branch_id` while not Admin (then the inner `if ($userBranch && ...)` may skip the check — edge case dependent on user model integrity). Admins explicitly skip this block.

**Controller enforcement:** Controllers inspected **do not** add an extra branch check before the service call; isolation is **in the service** for the `$auth === false` path.

**Q5 verdict:** **CONFIRMED**

---

## Controller caller(s)

`OrderService::changeStatus` is invoked from **three** Admin controllers with the **same** pattern (two arguments → `$auth = false`):

| File | Method | Middleware (constructor `only`) | Route (from `routes/api.php`) |
|------|--------|-----------------------------------|-------------------------------|
| `app/Http/Controllers/Admin/PosOrderController.php` | `changeStatus` | `permission:pos-orders` | `POST .../pos-order/change-status/{order}` (L620) |
| `app/Http/Controllers/Admin/OnlineOrderController.php` | `changeStatus` | `permission:online-orders` | `POST .../online-order/change-status/{order}` (L633) |
| `app/Http/Controllers/Admin/TableOrderController.php` | `changeStatus` | `permission:table-orders` | `POST .../table-order/change-status/{order}` (L643) |

**Route group middleware** (applies to all above): `Route::prefix('admin')->middleware(['installed', 'apiKey', 'auth:sanctum', 'localization'])` at **line 222** in `routes/api.php`.

**`$request->status` validated before service call:** **yes** — `OrderStatusRequest` runs first (`status` required numeric); transition validation runs **inside** the service at L1232–1234.

**`$auth` derivation:** **not passed**; remains default **`false`**.

---

## FINAL CLASSIFICATION VERDICT

**Pattern classification:** **GAP_REQUIRES_FIX**

**Reasoning:**  
Q1 is **RISK** (notifications before `save()` with no recall/transaction). Q2 shows **ASYMMETRY** with **no positive intentional documentation**. Q3 is **UNREACHABLE**, so missing `OrderStatusChanged` on the `$auth === true` branch is **not** a current production sync hole, but it does **not** negate the pre-save notification risk on the live staff path. Q4 **VALIDATED** and Q5 **CONFIRMED** show guards exist for transitions and branch isolation, yet the **ordering of side effects** relative to persistence remains a concrete coherence gap if `save()` fails.

**Next cycle recommendation:** **CYCLE-002b: fix plan required** — address (1) dispatch ordering vs `save()` / failure semantics, (2) whether `DB::transaction` (or narrower guarantees) is appropriate for cashback + log + broadcast, (3) either remove or wire/document the `$auth === true` branch if it is legacy. **playwright-critical-flow** or **playwright-full-e2e** after a fix, per ops vocabulary, if behavioral proof of OSS/KDS coherence is required.

**Scope respected:** **yes**  
**Files outside files_allowed modified:** **none**

---

## Definition of done checklist

| ID | Status |
|----|--------|
| DOD-01 | All sections Q1–Q5 + controller(s) present |
| DOD-02 | Each Q ends with required verdict label |
| DOD-03 | Final verdict = `GAP_REQUIRES_FIX` (single, non-composite) |
| DOD-04 | `$auth` source = third parameter, default false; call sites documented |
| DOD-05 | Branch isolation lines **1262–1266** or stated absent — **confirmed** |
| DOD-06 | Controller paths + method names documented |
| DOD-07 | No source files modified |
| DOD-08 | `FrontendOrderService` / KDS service **not** re-inspected |
