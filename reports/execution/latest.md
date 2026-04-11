# CYCLE-001 — Execution report (read-only)

**Cycle:** CYCLE-001  
**Execution class:** inspection_readonly_fast  
**Scope:** Authorized read paths only; **no source edits**; **FrontendOrderService explicitly not inspected** (not in authorized scope).

**Documented corrections reference:** `docs/PROJECT_CONTINUITY_AND_VISION.md` §5 items **1–4** (quoted below in each correction block).

---

Correction ID:
#1

Expected documented state:
> « **Notifications hors transaction** : pour `myOrderStore` / `tableOrderStore` (et chemins analogues), dispatch notifications / jobs **après** `DB::transaction()` pour éviter des notifications « fantômes » si rollback. »  
> — `docs/PROJECT_CONTINUITY_AND_VISION.md` L126

Actual code finding:
- **`OrderService::myOrderStore`**: a single `DB::transaction(function () use ($request) { ... });` wraps persistence; **all** `SendOrder*` / `SendOrderGot*` / `OrderCreated::dispatch` calls occur **after** the closing `});` of that transaction (lines **471–478** follow line **467**).
- **`OrderService::tableOrderStore`**: a single `DB::transaction(function () use ($request) { ... });` wraps persistence; **all** `SendOrderGot*` / `OrderCreated::dispatch` calls occur **after** the closing `});` of that transaction (lines **1072–1077** follow line **1069**).

Primary file:
`app/Services/OrderService.php`

Method:
`myOrderStore` **and** `tableOrderStore`

Transaction boundary:

**`myOrderStore`**

- Opens at line: **256** (`DB::transaction(function () use ($request) {`)
- Closes at line: **467** (`});` closing the `DB::transaction` callback)
- No transaction present: **no**

**`tableOrderStore`**

- Opens at line: **864** (`DB::transaction(function () use ($request) {`)
- Closes at line: **1069** (`});` closing the `DB::transaction` callback)
- No transaction present: **no**

Dispatch call(s):

**Inside `myOrderStore` transaction closure (lines 256–467)**

- *(none — no `::dispatch(` and no `SendOrder*::dispatch` inside the closure)*

**After `myOrderStore` transaction (lines 471–478)**

| Class | Line | Inside or after transaction |
|-------|------|----------------------------|
| `SendOrderMail` | **471** | **after** |
| `SendOrderSms` | **472** | **after** |
| `SendOrderPush` | **473** | **after** |
| `SendOrderGotMail` | **474** | **after** |
| `SendOrderGotSms` | **475** | **after** |
| `SendOrderGotPush` | **476** | **after** |
| `\App\Events\OrderCreated` | **478** | **after** |

**Inside `tableOrderStore` transaction closure (lines 864–1069)**

- *(none — no `::dispatch(` inside the closure)*

**After `tableOrderStore` transaction (lines 1073–1077)**

| Class | Line | Inside or after transaction |
|-------|------|----------------------------|
| `SendOrderGotMail` | **1073** | **after** |
| `SendOrderGotSms` | **1074** | **after** |
| `SendOrderGotPush` | **1075** | **after** |
| `\App\Events\OrderCreated` | **1077** | **after** |

Exact line numbers:

**`myOrderStore`**

- Transaction open: **256**
- Transaction close: **467**
- Dispatch(es): **471**, **472**, **473**, **474**, **475**, **476**, **478**

**`tableOrderStore`**

- Transaction open: **864**
- Transaction close: **1069**
- Dispatch(es): **1073**, **1074**, **1075**, **1077**

Verdict:
**CONFIRMED** — for both `myOrderStore` and `tableOrderStore`, every dispatch listed above is **after** the `DB::transaction` closure; there are **zero** dispatches inside those transaction closures.

Notes:
- `SendOrderGotPush` resolves to `App\Events\SendOrderGotPush` (import L29); **`app/Jobs/SendOrderGotPush.php` does not exist** in this repo (glob search found `app/Events/SendOrderGotPush.php` only).

---

Correction ID:
#2

Expected documented state:
> « **`posOrderStore`** : notifications KDS / événements **après** transaction ; **`OrderCreated::dispatch`** sur les flux concernés. »  
> — `docs/PROJECT_CONTINUITY_AND_VISION.md` L127

Actual code finding:
- **`OrderService::posOrderStore`**: `DB::transaction(function () use ($request, &$order, $idempotencyKey) { ... });` ends at line **820** (`});`).
- Immediately after, when `$order` is truthy, a `try` block dispatches **`SendOrderGotMail`**, **`SendOrderGotSms`**, **`SendOrderGotPush`**, and **`\App\Events\OrderCreated::dispatch($order)`** at lines **825–829**, i.e. **after** the transaction closure.

Primary file:
`app/Services/OrderService.php`

Method:
`posOrderStore`

Transaction boundary:
- Opens at line: **508** (`DB::transaction(function () use ($request, &$order, $idempotencyKey) {`)
- Closes at line: **820** (`});` closing the `DB::transaction` callback)
- No transaction present: **no**

Dispatch call(s):

**Inside `posOrderStore` transaction closure (lines 508–820)**

- *(none — no `::dispatch(` inside the closure)*

**After `posOrderStore` transaction (lines 825–829)**

| Class | Line | Inside or after transaction |
|-------|------|----------------------------|
| `SendOrderGotMail` | **825** | **after** |
| `SendOrderGotSms` | **826** | **after** |
| `SendOrderGotPush` | **827** | **after** |
| `\App\Events\OrderCreated` | **829** | **after** |

Exact line numbers:
- Transaction open: **508**
- Transaction close: **820**
- Dispatch(es): **825**, **826**, **827**, **829**

Verdict:
**CONFIRMED** — KDS-related “got order” notifications and `OrderCreated::dispatch` are **after** the `DB::transaction` closure; **`OrderCreated::dispatch` is present** on the POS path at line **829**.

Notes:
- This method also contains **no** `OrderStatusChanged::dispatch` (not required by correction #2 text).
- **`FrontendOrderService` was not inspected** (out of scope).

---

Correction ID:
#3

Expected documented state:
> « **`changeStatus` (admin)** : **`OrderStatusChanged::dispatch`** avec ancien et nouveau statut. »  
> — `docs/PROJECT_CONTINUITY_AND_VISION.md` L128

Actual code finding:
- **`OrderService::changeStatus`**: there is **no** `DB::transaction(...)` wrapper in this method (full method body inspected approximately **L1229–L1316**).
- In the **`$auth === false`** branch (non-customer / staff & admin path), after branch isolation and optional cashback handling:
  - `$oldStatus = $order->status;` at line **1285**
  - `SendOrderMail::dispatch`, `SendOrderSms::dispatch`, `SendOrderPush::dispatch` at lines **1286–1288** (**before** `$order->status = $request->status;` at **1289** and `$order->save();` at **1290**)
  - `\App\Events\OrderStatusChanged::dispatch($order, $oldStatus, (int) $request->status);` at lines **1293–1294** (**after** `$order->save();` at **1290**)
- In the **`$auth === true`** branch (customer self-service path), there is **`OrderStatusChanged::dispatch` absent**; only `SendOrderMail` / `SendOrderSms` / `SendOrderPush` at **1251–1253**, then `$order->save()` at **1254–1255**.

Primary file:
`app/Services/OrderService.php`

Method:
`changeStatus`

Transaction boundary:
- Opens at line: **UNCLEAR — no `DB::transaction` in `changeStatus`**
- Closes at line: **UNCLEAR — no `DB::transaction` in `changeStatus`**
- No transaction present: **yes** (no `DB::transaction` in this method)

Dispatch call(s):

**`$auth === false` branch (staff / admin path)**

| Class | Line | Inside or after transaction |
|-------|------|----------------------------|
| `SendOrderMail` | **1286** | **after** *(relative to any DB transaction: none in method)* |
| `SendOrderSms` | **1287** | **after** |
| `SendOrderPush` | **1288** | **after** |
| `\App\Events\OrderStatusChanged` | **1294** | **after** *(occurs after `save()` at **1290**)* |

**`$auth === true` branch (customer path)**

| Class | Line | Inside or after transaction |
|-------|------|----------------------------|
| `SendOrderMail` | **1251** | **after** *(no transaction in method)* |
| `SendOrderSms` | **1252** | **after** |
| `SendOrderPush` | **1253** | **after** |
| `\App\Events\OrderStatusChanged` | **—** | **absent in this branch** |

Exact line numbers:
- Transaction open: **N/A**
- Transaction close: **N/A**
- Dispatch(es): **1286**, **1287**, **1288**, **1294** (admin/staff path); **1251**, **1252**, **1253** (customer path)

Verdict:
**CONFIRMED for the documented “admin” / non-`$auth` path** — `\App\Events\OrderStatusChanged::dispatch($order, $oldStatus, (int) $request->status)` is present at **1293–1294** with **`$oldStatus` captured at 1285** and **new status `(int) $request->status`**.

Notes:
- The documentation label **« (admin) »** is interpreted here as the **`else` branch (`$auth === false`)**, not the customer `$auth === true` branch.
- **Potential inconsistency (not classified as correction #1–#4 failure without doc expansion):** in the **`$auth === false`** branch, `SendOrderMail` / `SendOrderSms` / `SendOrderPush` run **before** `$order->save()` (**1286–1288** vs **1289–1290**), while `OrderStatusChanged` runs **after** `save()` (**1293–1294**). This is **not** a `DB::transaction` boundary issue because **there is no transaction** in this method.
- **`FrontendOrderService` was not inspected** (out of scope).

---

Correction ID:
#4

Expected documented state:
> « **KDS `changeStatus`** : dispatch **`OrderStatusChanged`** pour l’OSS (correctif « OSS ne voyait pas les changements depuis KDS »). »  
> — `docs/PROJECT_CONTINUITY_AND_VISION.md` L129

Actual code finding:
- **`KitchenDisplaySystemOrderService::changeStatus`** wraps **`$order->status = $request->status; $order->save();`** inside `\Illuminate\Support\Facades\DB::transaction(function () use ($order, $request) { ... });` at lines **115–118**.
- **After** that transaction, the service dispatches:
  - `SendOrderMail::dispatch`, `SendOrderSms::dispatch`, `SendOrderPush::dispatch` at lines **121–123**
  - `OrderStatusChanged::dispatch($order, $oldStatus, (int) $request->status);` at lines **127–127** (statement spans **127**; `try` opens **126**)

Primary file:
`app/Services/KitchenDisplaySystemOrderService.php`

Method:
`changeStatus`

Transaction boundary:
- Opens at line: **115** (`\Illuminate\Support\Facades\DB::transaction(function () use ($order, $request) {`)
- Closes at line: **118** (`});` closing the `DB::transaction` callback)
- No transaction present: **no**

Dispatch call(s):

**Inside `DB::transaction` closure (lines 115–118)**

- *(none — no `::dispatch(` inside the closure)*

**After transaction (lines 121–127)**

| Class | Line | Inside or after transaction |
|-------|------|----------------------------|
| `SendOrderMail` | **121** | **after** |
| `SendOrderSms` | **122** | **after** |
| `SendOrderPush` | **123** | **after** |
| `OrderStatusChanged` | **127** | **after** |

Exact line numbers:
- Transaction open: **115**
- Transaction close: **118**
- Dispatch(es): **121**, **122**, **123**, **127**

Verdict:
**CONFIRMED** — `OrderStatusChanged::dispatch($order, $oldStatus, (int) $request->status)` is executed **after** the `DB::transaction` block (**127** is after **118**), matching the documented “post-commit / post-transaction dispatch” intent described in-file at **L111–L114** and **L120–L123**.

Notes:
- `OrderStatusChanged` is imported as `App\Events\OrderStatusChanged` (file L14) and invoked unqualified at **127**.
- Verified event class file exists: `app/Events/OrderStatusChanged.php` (constructor `(BroadcastableOrder $order, int $oldStatus, int $newStatus)` — L28–32).

---

## Event / job class names (task 9)

| Symbol as used in inspected services | Resolved path / kind |
|----------------------------------------|----------------------|
| `\App\Events\OrderCreated` | `app/Events/OrderCreated.php` — `class OrderCreated implements ShouldBroadcastNow` |
| `\App\Events\OrderStatusChanged` | `app/Events/OrderStatusChanged.php` — `class OrderStatusChanged implements ShouldBroadcastNow` |
| `SendOrderGotPush::dispatch(...)` | `App\Events\SendOrderGotPush` — `app/Events/SendOrderGotPush.php` |
| **`app/Jobs/SendOrderGotPush.php`** | **Not found** (no such job file in repo) |

---

## Explicit non-inspection

- **`App\Services\FrontendOrderService`** (and any methods therein): **not inspected** — not in authorized scope for CYCLE-001.

---

## Definition of done checklist

- [x] `reports/execution/latest.md` exists (this file)
- [x] Corrections **#1–#4** each have a full structured entry using the required template
- [x] Every `::dispatch(` in the authorized methods is listed and classified vs `DB::transaction` (or marked **N/A** when no transaction exists)
- [x] Exact line numbers are present
- [x] **No source code modified** (read-only inspection)
- [x] No commit / no push performed in this cycle
