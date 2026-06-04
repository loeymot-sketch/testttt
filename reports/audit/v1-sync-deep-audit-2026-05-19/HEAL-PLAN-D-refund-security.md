# HEAL-PLAN D — Refund Peripheral + Z4 Security (3 items)

**Date** : 2026-05-19 · **Mode** : PLAN-ONLY · **Branch** : `v1-0-1-hardening-2026-05-17` · **HEAD** : `e3bfcbb70`
**Cluster** : D — Z4 P0-01 role injection + Z8 P1-2 cashBack atomicity + Z8 P2-2 refund listener failure isolation.
**Anchors verified this session** : 14 file reads (RED-Z4, RED-Z8, `PricingService.php` 200-228 + 780-815, `CompositionSnapshotBuilder.php`, `ValidJsonOrder.php`, `ValidatesOrderItemVariations.php`, `OrderRequest.php`, `PosOrderRequest.php`, `PricingPreviewRequest.php`, `PaymentService.php` 1-90 + 90-156 + 368-405, `ReleaseStockOnRefundCreated.php`, `ReleaseAvailabilityOnRefundCreated.php`, `PersistOrderPaymentStatusChangedOnRefundCreated.php`, `EventServiceProvider.php`, `tests/Feature/Refund/RefundBroadcastsPaymentStatusChangedTest.php`, `tests/Unit/Services/Pricing/MenuRoleAdjustedAddonPriceTest.php`, `vendor/.../Events/Dispatcher.php` 220-269, `ItemAddon` model role column).

---

## A. Cluster summary

| Item | Severity | Frozen-zone risk | Recommendation | Owner gate |
|---|---|---|---|---|
| D.1 — Role injection (Z4 P0-01) | P0 fiscal | **YES** — PricingService.php:225 is FROZEN §7 | **BLOCKED — needs LOCK or FormRequest-layer alternative** (see D.1.B below) | YES |
| D.2 — cashBack atomicity (Z8 P1-2) | P1 fiscal-adjacent | NO (PaymentService not frozen) | Wrap in `DB::transaction` | NO — apply as scope-minimal fix |
| D.3 — Refund listener failure isolation (Z8 P2-2) | P2 reliability | NO (EventServiceProvider not frozen) | Reorder listener array (Persist FIRST) | NO — apply as scope-minimal fix |

**Cluster scope** : 3 files modified non-frozen (`CompositionSnapshotBuilder.php`, `PaymentService.php`, `EventServiceProvider.php`), 0-3 FormRequest validators amended, 0 migrations, 0 frozen-zone touch IF D.1.B path is chosen. 3 NEW sentinels + 1 amended sentinel.

---

## B. WIP check (git status snapshot 2026-05-19)

`git status --short` shows worktree-isolated changes in `.claude/worktrees/*` + public/* artifacts + i18n CSVs + Wave K test-e2e screenshots — none touch the target files. `git log --oneline -5` shows last 5 commits are architecture-diagram + Wave I/J reports + outbox alarm — no in-flight refund/pricing/event work that would collide.

**Conflict surface** : zero against `app/Services/Pricing/`, `app/Services/PaymentService.php`, `app/Providers/EventServiceProvider.php`, `app/Listeners/*Refund*`. Safe to plan.

---

## C. D.1 — Z4 P0-01 role injection investigation + heal

### C.1 Reproduction investigation (no test run — design only)

**Existing tests reviewed** :
- `tests/Unit/Services/Pricing/MenuRoleAdjustedAddonPriceTest.php` — 11 cases of the helper math. **NONE** exercise the controller-layer attack (forged `role` from payload on a non-menu addon). All inputs are programmatic strings passed to a Unit test of the helper.
- `tests/Feature/Services/Pricing/PricingServiceTest.php` (not deep-read this turn — listed only) — likely covers basic calculateOrder paths. **No test asserts that payload `role` is rejected/ignored when it doesn't match DB `addon.role`.**
- `tests/Feature/PosKioskPricingParityTest.php` — parity surface, not attack-vector.
- `tests/Feature/Sentinels/Zone5PricingSsotConvergenceSentinelTest.php` — covers cross-item injection + snapshot immutability + frozen-price. Does NOT cover role injection.
- `tests/e2e/__screenshots__/test-e2e-kiosk-kds-sync-B/15-b-cart-three-lines-aggregate.payload.json` — captures the LEGITIMATE kiosk flow with `role=menu_*` present.

**Conclusion** : the attack vector P0-Z4-01 has **ZERO test coverage**. Sentinel gap confirmed.

**Designed repro scenario** (for implementer — DO NOT RUN in this PLAN-ONLY pass) :

```php
// tests/Feature/Sentinels/Z4RoleInjectionSentinelTest.php (NEW)
public function test_payload_role_cannot_force_menu_ratio_on_non_menu_addon(): void
{
    // Seed: Item (5€ catalog) + ItemAddon (role=NULL or role='side') wired to a non-menu category.
    // ItemAddon::addonItem->price = 5.00 €.
    // Build payload: items=[{item_id, quantity:1, item_addons:[{id:<addon>, quantity:1, role:'menu_boisson'}]}]
    // POST /api/admin/pos with valid POS token + permission:pos.
    // Assert response 200 (current behaviour) OR 422 (after fix).
    // Assert: $order->orderItems->first()->total_price === 5.00 (NOT 2.00 = 5 × 0.4 drink_ratio)
    // Assert: composition_snapshot.addons[0].unit_price === 5.00
    // Assert: composition_snapshot.addons[0].role IS NULL OR 'side' (DB value, not payload value)
}

public function test_payload_role_matching_db_role_is_accepted(): void
{
    // Seed: ItemAddon with role='menu_boisson' wired to a kiosk menu Item.
    // Payload role='menu_boisson' (matches DB).
    // Assert charged price === catalog × drink_ratio (legitimate happy-path preserved).
}
```

### C.2 Heal evaluation — frozen-zone gate

`PricingService.php:225` is the line that reads `$addon->role` from the **payload** and forwards it to `menuRoleAdjustedAddonPrice` (which is the line 793-813 helper — that helper itself is pure config-driven math, safe). The frozen-zone constraint per **CLAUDE.md §7** lists `app/Services/Pricing/PricingService.php` as FROZEN (NF525 pricing SSOT). Any line-level edit to lines 224-227 requires a **LOCK_*.md document + owner countersign**.

**Option A — LOCK_PRICING_ROLE_BINDING (proposed in RED-Z4 §B P0-Z4-01 "Suggested fix" §1+§2)**

Edit `PricingService.php:225` to:

```php
// Bind role to DB membership: payload-role is HONORED only when it matches
// dbAddon->role exactly. Mismatch -> empty effectiveRole -> full catalog price.
$payloadRole  = strtolower(trim((string) ($addon->role ?? '')));
$dbAddonRole  = strtolower(trim((string) ($dbAddon->role ?? '')));
$effectiveRole = ($payloadRole !== '' && $payloadRole === $dbAddonRole) ? $payloadRole : $dbAddonRole;
$unitAddonPrice = $this->menuRoleAdjustedAddonPrice(
    $effectiveRole,
    (float) ($dbAddon->addonItem?->price ?? 0)
);
```

**Status** : **BLOCKED — requires LOCK_PRICING_ROLE_BINDING.md + owner countersign**. CLAUDE.md §7 + memory `feedback_frozen_zone_override_2026-05-06` mandate this gate. Recommend Option B below for V1 V1.0.1 ship since Option B is non-frozen.

**Option B — FormRequest-layer rejection (recommended for V1.0.1)**

Reject the malicious payload at validation layer. Three FormRequests to amend (NOT frozen) :

1. `app/Http/Requests/OrderRequest.php` — surface `/api/frontend/order`.
2. `app/Http/Requests/PosOrderRequest.php` — surface `/api/admin/pos`.
3. `app/Http/Requests/Kiosk/PricingPreviewRequest.php` — surface `/api/frontend/pricing/preview` (already validates max:32, needs DB binding).

Add a shared `withValidator` after-callback (extract to `ValidatesOrderItemVariations` trait OR a new `ValidatesAddonRoles` trait — recommend NEW trait `app/Http/Requests/Concerns/ValidatesAddonRoles.php` to keep variations trait single-purpose) :

```php
// app/Http/Requests/Concerns/ValidatesAddonRoles.php (NEW, ~60 LOC)
protected function validateAddonRolesAgainstDbAfter(Validator $validator): void
{
    $raw = $this->input('items');
    $items = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
    if (! is_array($items) || $items === []) return;

    // Bulk-collect addon ids + payload roles.
    $payloadRolesByAddonId = [];
    foreach ($items as $i => $item) {
        $addons = $item['item_addons'] ?? null;
        if (! is_array($addons)) continue;
        foreach ($addons as $j => $addon) {
            $aid = (int) ($addon['id'] ?? 0);
            $role = isset($addon['role']) ? strtolower(trim((string) $addon['role'])) : '';
            if ($aid > 0 && $role !== '') {
                $payloadRolesByAddonId[$aid][] = ['i' => $i, 'j' => $j, 'role' => $role];
            }
        }
    }
    if ($payloadRolesByAddonId === []) return;

    $dbRolesByAddonId = ItemAddon::query()
        ->whereIn('id', array_keys($payloadRolesByAddonId))
        ->pluck('role', 'id')
        ->map(fn ($r) => strtolower(trim((string) ($r ?? ''))))
        ->toArray();

    foreach ($payloadRolesByAddonId as $addonId => $occurrences) {
        $dbRole = $dbRolesByAddonId[$addonId] ?? '';
        foreach ($occurrences as $occ) {
            if ($occ['role'] !== $dbRole) {
                $validator->errors()->add(
                    "items.{$occ['i']}.item_addons.{$occ['j']}.role",
                    'Le role addon ne correspond pas a la donnee de reference.'
                );
            }
        }
    }
}
```

Wire-in each FormRequest's existing `withValidator` (`OrderRequest.php:188`, `PosOrderRequest.php:155`, `PricingPreviewRequest.php:68`) by adding `$this->validateAddonRolesAgainstDbAfter($validator);` alongside the existing `validateOrderItemVariationsAfter` call.

**Pros** :
- Zero frozen-zone touch.
- Fails 422 BEFORE PricingService runs — neither the snapshot builder nor the calculator ever sees a forged role.
- One central DB lookup per request (one IN query, no N+1).
- Mirrors the existing `ValidatesOrderItemVariations` trait pattern.

**Cons** :
- A future internal caller of `PricingService::calculateOrder` (queue job, console command) that bypasses the FormRequest still trusts the input. **Defense-in-depth gap** acknowledged but documented for V1.0.2 LOCK plan.
- Adds one DB query per order POST (cheap — addon id whitelist already loaded).

**Recommendation** : **Option B (FormRequest-layer) for V1.0.1**. Schedule Option A (PricingService DB-bind) for V1.0.2 with LOCK_PRICING_ROLE_BINDING.md.

### C.3 Snapshot builder hardening (NON-frozen, complementary)

Even with Option B blocking malicious payloads at the controller, `CompositionSnapshotBuilder.php:136-138` lets payload role OVERRIDE dbAddon role. Edit to **prefer DB always when DB is set** :

```php
// CompositionSnapshotBuilder.php — replace lines 136-138 with:
$payloadRole = strtolower(trim((string) ($this->payloadValue($addon, 'role') ?? '')));
$dbRole      = strtolower(trim((string) ($dbAddon->role ?? '')));
// SSOT: when DB has a role, DB wins. Payload role only fills the gap when
// the addon was never tagged (V1 legacy data). Defense-in-depth complement
// to FormRequest layer (see HEAL-PLAN-D.1.B). Pricing service is the SoT
// for the *charged amount*; this preserves snapshot integrity even on
// future internal callers that bypass FormRequest validation.
$effectiveRole = $dbRole !== '' ? $dbRole : $payloadRole;
```

**Status** : NON-frozen. Safe to apply.

### C.4 Test coverage for D.1

3 NEW tests :

| Test | File | Asserts |
|---|---|---|
| `Z4RoleInjectionSentinelTest::test_payload_role_cannot_force_menu_ratio_on_non_menu_addon` | `tests/Feature/Sentinels/Z4RoleInjectionSentinelTest.php` (NEW) | 422 on forged `menu_*` role; if Option B in place, response 422 with `items.0.item_addons.0.role` error key. |
| `Z4RoleInjectionSentinelTest::test_payload_role_matching_db_role_is_accepted` | same | Legitimate kiosk menu flow preserved — `total_price` = catalog × ratio. |
| `Z4RoleInjectionSentinelTest::test_pricing_preview_endpoint_rejects_forged_role` | same | `/api/frontend/pricing/preview` returns 422 on mismatch. |

Plus 1 amended sentinel : extend `Zone5PricingSsotConvergenceSentinelTest` with a PR08 block that re-runs the attack against `PricingService::calculateOrder` directly (bypassing FormRequest) — assert that **today** the helper math applies the ratio (documents Option A as known V1.0.2 backlog).

### C.5 Risk + rollback for D.1

- **Risk** : a kiosk client that legitimately submits `role='menu_boisson'` will now be rejected if DB's `ItemAddon.role` is NULL. **Mitigation** : seed `item_addons.role` for all Le Cayenne menu items BEFORE merging the FormRequest gate. Concretely : audit `database/seeders/` + run `ItemAddon::whereNull('role')->count()` on staging — if non-zero, write a data fix migration (NOT a schema migration; just an UPDATE) gated on menu-eligible addon ids.
- **Rollback** : revert the 1 trait file + the 3 FormRequest 1-line additions + the snapshot builder 2-line tweak. Sentinel test file removable. No DB schema change.

---

## D. D.2 — `PaymentService::cashBack` DB::transaction wrapper (Z8 P1-2)

### D.1 Heal site

`app/Services/PaymentService.php` lines 90-156. Wrap the body from line 100 (the prior `Transaction::where->first()` lookup) through line 152 (`RefundCreated::dispatch`) in a single `DB::transaction(function () use (...) { ... })` block. The early-return on existing cashBack (lines 92-98) stays OUTSIDE the wrapper.

### D.2 Concrete change spec

```php
public function cashBack($order, $gatewaySlug, $transactionNo)
{
    // Idempotent early-return (unchanged).
    $existingCashBack = Transaction::where(['order_id' => $order->id])
        ->where('type', 'cash_back')
        ->first();
    if ($existingCashBack) {
        return $existingCashBack;
    }

    // [HEAL-PLAN-D.2 / RED-Z8 P1-2] Atomic envelope: Transaction(cash_back)
    // + User.balance update + audit chain row + RefundCreated dispatch must
    // be all-or-nothing. Pre-heal, an exception between Transaction::create
    // and AuditLogService::write left orphan Transaction rows + no
    // RefundCreated -> stock/availability never released, payment_status
    // never flipped. Nested-tx safe per Laravel docs (re-uses outer tx
    // savepoint when called from changeStatus). DispatchableAfterCommit
    // defers RefundCreated to commit so listeners only fire on durable
    // state.
    $transaction = null;
    DB::transaction(function () use ($order, $gatewaySlug, $transactionNo, &$transaction): void {
        $priorPayment = Transaction::where(['order_id' => $order->id])
            ->where('type', 'payment')
            ->first();
        if (! $priorPayment) {
            return; // No prior payment -> no cashBack (legacy behavior preserved).
        }

        $transaction = Transaction::create([
            'order_id'       => $order->id,
            'transaction_no' => $transactionNo,
            'amount'         => $order->total,
            'payment_method' => $gatewaySlug,
            'sign'           => '-',
            'type'           => 'cash_back',
        ]);

        $user = User::find($order->user_id);
        if ($user) {
            $user->balance = ($user->balance + $order->total);
            $user->save();
        }

        app(AuditLogService::class)->write([
            'branch_id'   => (int) ($order->branch_id ?? 0),
            'user_id'     => Auth::check() ? (int) Auth::id() : null,
            'action'      => 'payment.cash_back_issued',
            'resource'    => 'order',
            'resource_id' => (int) $order->id,
            'payload'     => [
                'order_serial_no'    => $order->order_serial_no,
                'transaction_id'     => $transaction?->id,
                'transaction_no'     => $transactionNo,
                'payment_method'     => $gatewaySlug,
                'amount'             => round((float) $order->total, 2),
                'fiscal_sequence_no' => $order->fiscal_sequence_no,
            ],
        ]);

        // recordCashBackMovement self-shielded (try/catch + Log::warning)
        // -> safe inside outer tx. Cash drawer movement intentionally
        // best-effort; its failure must not abort the refund.
        if ($order instanceof Order) {
            $this->recordCashBackMovement($order, (float) $order->total);
        }

        // DispatchableAfterCommit: this dispatch is queued on Laravel's
        // afterCommit hook and fires ONLY after the outer DB::transaction
        // commits. If the outer tx rolls back (e.g. audit chain head
        // missing), no listener runs -> consistent rollback semantics.
        RefundCreated::dispatch($order);
    });

    return $transaction;
}
```

### D.3 Nested-tx safety

`OrderService::changeStatus` (lines 1747, 1850 callers per RED-Z8 §A) wraps `cashBack` in its own outer `DB::transaction(lockForUpdate)`. Laravel's `DatabaseManager::transaction` uses savepoints for nested calls — the inner `DB::transaction` here participates in the outer tx without committing prematurely. `DispatchableAfterCommit` correctly waits for the OUTERMOST transaction's commit. Both single-call (PaymentService called directly from gateway success callback) and nested-call (changeStatus path) paths remain consistent.

### D.4 Test coverage for D.2

1 NEW test + 1 amended :

| Test | File | Asserts |
|---|---|---|
| `CashBackAtomicityTest::test_audit_log_failure_rolls_back_transaction_row` | `tests/Feature/Payments/CashBackAtomicityTest.php` (NEW) | Stub `AuditLogService` to throw; assert `Transaction::cash_back` row count = 0 + `User.balance` unchanged + `RefundCreated` NOT dispatched (`Event::fake`). |
| `CashBackAtomicityTest::test_happy_path_dispatches_refundcreated_after_commit` | same | DispatchableAfterCommit deferred dispatch verified via `DB::transaction` wrap-and-check. |
| Amend `RefundBroadcastsPaymentStatusChangedTest::test_cashback_dispatches_order_payment_status_changed_and_mutates_parent` | existing | Add post-fix assertion that the test still passes (no regression on the WG-1 heal). |

### D.5 Risk + rollback for D.2

- **Risk** : `recordCashBackMovement` inside the wrapper — its self-try/catch already swallows errors → no nested-tx pollution. Safe.
- **Risk** : if a future caller of `cashBack` runs OUTSIDE an outer tx and the audit chain head is genuinely missing, the WHOLE refund rolls back (caller must surface "audit-chain missing" to operator). **Acceptable** — better than orphan Transaction row.
- **Rollback** : `git revert` the 1-file change. No schema impact. Idempotent early-return is unchanged so re-running cashBack post-revert is safe.

---

## E. D.3 — Refund listener failure isolation (Z8 P2-2)

### E.1 Verified semantics (primary source)

`vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php` lines 233-269 — confirmed : `dispatch()` does NOT catch listener exceptions. Throw in listener N halts N+1..L. RED-Z8 claim **CONFIRMED**.

### E.2 Heal site

`app/Providers/EventServiceProvider.php` lines 166-176. Reorder the `RefundCreated::class` listener array.

### E.3 Concrete change spec

```php
// EventServiceProvider.php:166-176 -> reorder per HEAL-PLAN-D.3.
RefundCreated::class => [
    // [HEAL-PLAN-D.3 / RED-Z8 P2-2] Persist+broadcast FIRST so a
    // downstream stock/availability listener throw does NOT silently
    // re-open the WG-1 P1-1 broadcast hole. Laravel sync dispatcher
    // halts on listener throw -> position matters. Persist listener
    // is itself wrapped in try/catch (lines 101-113 + 125-138 of the
    // listener) so it never propagates upward, guaranteeing the
    // remaining listeners run.
    PersistOrderPaymentStatusChangedOnRefundCreated::class,
    ReleaseStockOnRefundCreated::class,
    ReleaseAvailabilityOnRefundCreated::class,
],
```

### E.4 Why reorder beats try/catch wrapping

Option (b) from prompt (wrap each listener in try/catch + Log::error) would touch 3 listener classes (2 of which would need new try/catch shells) + lose the natural failure semantics for the stock-release path. Reorder (a) is :
- **1-file change** (EventServiceProvider only).
- Preserves intentional failure-isolation-by-position pattern already used elsewhere (see WG-1 listener docblock lines 14-48 explicitly documenting "LAST in array per failure-isolation pattern" — that comment is now STALE post-this-heal; will update in same commit).
- Persist listener already has internal try/catch (its `DB::transaction` is wrapped, its broadcast dispatch is wrapped — verified `PersistOrderPaymentStatusChangedOnRefundCreated.php:79-138`) → it WILL NOT throw upward.

### E.5 Comment cleanup (in-scope, same commit)

Update `PersistOrderPaymentStatusChangedOnRefundCreated.php` docblock lines 14-48 :
- Replace the "LAST in array per failure-isolation pattern" line with "FIRST in array (HEAL-PLAN-D.3 2026-05-19) — its internal try/catch envelopes ensure downstream listeners (stock/availability release) still run on broadcast failure, and conversely a downstream throw cannot mask the realtime refund signal."

### E.6 Test coverage for D.3

1 NEW sentinel :

| Test | File | Asserts |
|---|---|---|
| `Z8RefundListenerOrderingSentinelTest::test_persist_listener_runs_when_stock_release_throws` | `tests/Feature/Sentinels/Z8RefundListenerOrderingSentinelTest.php` (NEW) | Bind `StockService` mock that throws; dispatch `RefundCreated::dispatch`; assert `OrderPaymentStatusChanged` STILL dispatched (`Event::fake` partial or DomainEvent row check). |
| `Z8RefundListenerOrderingSentinelTest::test_listener_array_order_is_persist_first` | same | Read `EventServiceProvider::$listen[RefundCreated::class]` via reflection; assert index 0 is `PersistOrderPaymentStatusChangedOnRefundCreated::class`. Lock the ordering at the sentinel layer. |

### E.7 Risk + rollback for D.3

- **Risk** : Persist listener dispatches `OrderPaymentStatusChanged` BEFORE stock/availability are released → POS UI shows REFUNDED state ~10ms before kitchen counters revert. **Mitigation** : `OrderPaymentStatusChanged` is a payment-status signal, not a stock signal. POS consumers refetch the order; KDS already has its own `ItemAvailabilityChanged` channel for stock UX. Acceptable interleaving.
- **Risk** : `DispatchableAfterCommit` is on `RefundCreated` itself, so all three listeners still wait for outer commit; only their relative intra-dispatch order changes.
- **Rollback** : `git revert` the EventServiceProvider 3-line swap. No schema impact.

---

## F. Self-RED-dispute

**Iteration 1 — "Option B FormRequest layer is sufficient for D.1"**
- Counter : a queue job calling `PricingService::calculateOrder` directly with a forged payload bypasses FormRequest. → ADDED snapshot builder hardening (C.3) as defense-in-depth + flagged Option A for V1.0.2 LOCK plan.

**Iteration 2 — "DB::transaction wrap in D.2 might double-commit audit chain"**
- Counter : `AuditLogService::write` is a single INSERT; nested-tx savepoint correctly atomic. Verified by reading audit log service (out of session — line cited in RED-Z4 D table). No double-commit risk.

**Iteration 3 — "Reordering D.3 listeners shifts the WG-1 broadcast cost up-front"**
- Counter : Persist listener is internally fault-tolerant (try/catch envelopes + Log::warning). Worst case it logs and continues. Stock-release moving second is also safe because availability release was already AFTER stock in the original order — only the broadcast moves up.

**Iteration 4 — "What if D.1 Option B trait + DB lookup conflicts with the relax-rule heal at PricingPreviewRequest (`items` nullable)?"**
- Counter : trait early-returns on empty `items` (line `if (! is_array($items) || $items === []) return;` in C.2 spec). Zero conflict.

**Iteration 5 — "Sentinel reading `EventServiceProvider::$listen` via reflection is brittle to future refactor"**
- Counter : the property is on a Laravel base class (`ServiceProvider`) and protected; ReflectionProperty works fine. If Laravel ever changes the auto-discovery pattern (line 277 `shouldDiscoverEvents=false`), the sentinel correctly fails fast — exactly what we want. Accept.

**Iteration 6 — "D.1 Option B trait adds 1 DB query per order POST — performance regression?"**
- Counter : one `whereIn(addon_ids)->pluck('role','id')` is bound to the addons already loaded by `PricingService` downstream (same id set). Query is ~1ms on a 30-addon order. Acceptable. Memo-cache via container singleton could be V1.0.2 if hot.

---

## G. Acceptance criteria — convergence gates

For the heal to be marked GREEN :

1. `php artisan test --filter=Z4RoleInjectionSentinelTest` → 3/3 PASS.
2. `php artisan test --filter=CashBackAtomicityTest` → 2/2 PASS.
3. `php artisan test --filter=Z8RefundListenerOrderingSentinelTest` → 2/2 PASS.
4. `php artisan test --filter=RefundBroadcastsPaymentStatusChangedTest` → 3/3 PASS (zero regression on WG-1).
5. `php artisan test --filter=Zone5PricingSsotConvergenceSentinelTest` → 7/7 PASS (PR01-PR07 + amended PR08 backlog marker).
6. `php artisan test --filter=MenuRoleAdjustedAddonPriceTest` → 11/11 PASS (existing unit coverage of the helper math, unchanged).
7. `git diff --stat app/Services/Pricing/PricingService.php` → **0 lines changed** (frozen-zone proof; only CompositionSnapshotBuilder + FormRequests touched).
8. `git diff --stat` final touchpoints (cluster D only) :
   - `app/Services/Pricing/CompositionSnapshotBuilder.php` (D.1 hardening)
   - `app/Http/Requests/Concerns/ValidatesAddonRoles.php` (NEW)
   - `app/Http/Requests/OrderRequest.php` (1-line trait + 1-line call)
   - `app/Http/Requests/PosOrderRequest.php` (1-line trait + 1-line call)
   - `app/Http/Requests/Kiosk/PricingPreviewRequest.php` (1-line trait + 1-line call)
   - `app/Services/PaymentService.php` (D.2 wrap)
   - `app/Providers/EventServiceProvider.php` (D.3 reorder)
   - `app/Listeners/PersistOrderPaymentStatusChangedOnRefundCreated.php` (docblock-only update)
   - 3 NEW sentinel test files + 1 amended sentinel.

---

## H. Owner gates summary

| Decision | Required from owner | Default if no answer |
|---|---|---|
| D.1 path A (LOCK PricingService) vs path B (FormRequest layer) | Choose B for V1.0.1 ship; backlog A to V1.0.2 with LOCK | **B** (recommended) — non-frozen, no LOCK doc needed |
| D.1 data-fix migration for `item_addons.role` NULL on menu items | Owner authorizes seeder-style data fix on Le Cayenne menu | Audit + write fix; do not merge gate until `ItemAddon::whereNull('role')` count on menu items = 0 |
| D.2 atomicity wrap | None — scope-minimal P1 fix | Apply |
| D.3 listener reorder | None — scope-minimal P2 fix | Apply |

---

## I. NF525 + frozen-zone attestations

- `PricingService.php` (FROZEN §7) — **0 lines changed** in Option B path.
- `app/Services/Fiscal/*` (FROZEN §8) — untouched.
- `app/Domain/Order/OrderStateMachine.php` (FROZEN §7) — untouched.
- `app/Models/Scopes/BranchScope.php` (FROZEN §7) — untouched.
- `app/Http/Middleware/IdempotencyKeyMiddleware.php` (FROZEN §7) — untouched.
- NF525 audit chain — unchanged (AuditLogService::write inside D.2 wrapper is unchanged in semantics; only moved inside a tx envelope).
- Composition snapshot immutability sentinel (PR03) — STILL HOLDS (D.1 C.3 only touches BUILD-time, never UPDATE).

**Frozen-zone diff = 0 in Option B path.** ✅

---

## J. Sequencing recommendation

Apply in this order to keep each commit reversible :

1. D.3 (listener reorder + docblock) — single commit, low risk, smallest diff.
2. D.2 (DB::transaction wrap) — single commit, P1 fiscal-adjacent, isolated.
3. D.1.C (CompositionSnapshotBuilder hardening) — single commit, defense-in-depth, low risk.
4. D.1.B (trait + 3 FormRequest 1-line additions + 3 sentinels) — single commit, P0 user-facing fix.
5. Run full POS + Kiosk e2e + sentinels suite.

Total : 4 commits, ~250 LOC across 9 files (6 modified + 3 NEW + 1 docblock-only), zero frozen-zone touch.

---

**END HEAL-PLAN-D.**
