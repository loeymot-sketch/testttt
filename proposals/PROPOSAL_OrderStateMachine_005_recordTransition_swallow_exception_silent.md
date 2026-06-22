# PROPOSAL — recordTransition() silently swallows audit-row write failures

**ID** : PROP-OSM-005
**Date** : 2026-05-23
**Phase** : B.5 — PROPOSAL AGENT for `app/Domain/Order/OrderStateMachine.php`
**Frozen file** : `app/Domain/Order/OrderStateMachine.php` (CLAUDE.md §7)
**Severity** : P1 — operational observability gap with NF525-adjacent audit risk
**Touch** : ZERO (read-only audit, proposal only)

---

## 1. Finding (read-only)

`OrderStateMachine::recordTransition()` at lines 132-159 :

```php
public static function recordTransition(
    string $orderType,
    int $orderId,
    int $fromStatus,
    int $toStatus,
    ?int $actorId = null,
    ?string $reason = null,
): void {
    if ($fromStatus === $toStatus) {
        return;
    }

    try {
        OrderStatusTransition::query()->create([
            // ...
        ]);
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::warning('[OrderStateMachine] Failed to record transition: ' . $e->getMessage());
    }
}
```

When the audit-row write fails (DB constraint, connection drop, disk full,
deadlock, …), the function logs a **WARNING** and returns successfully. The
caller has no way to detect the failure ; the order's `status` mutation has
already happened (lines 234-238 of `apply()`, or `$order->save()` in 11 legacy
callsites), but the audit chain is **silently broken** for that transition.

---

## 2. Concrete failure scenarios

### 2.1 Foreign-key violation

`order_status_transitions` does NOT declare an FK constraint on `order_id`
in the migration (`2026_04_15_230000_create_order_status_transitions_table.php`
lacks `->foreign('order_id')`). So a wrong `order_id` would not throw — but :

### 2.2 Required-field null violations

If `OrderStatus::PENDING === 0` ever (it's 1 today) and the `unsignedSmallInteger`
column accepts 0 fine — not a real risk. **HOWEVER**, `occurred_at` is `timestamp`
NOT NULL — and `now()` should always work. So real-world write failures are
RARE but not zero :

### 2.3 Deadlock during contention

`apply()`'s inner `DB::transaction` + `lockForUpdate` PLUS the audit-row
insert happen in the same transaction. A deadlock on the audit-row INSERT
(very unlikely on the orders side, but possible if `order_status_transitions`
becomes a hot table) would throw, `recordTransition()` would swallow, and
**the SURROUNDING TRANSACTION continues** because the throw is caught
inside.

Wait — let me re-read. The `apply()` method calls `recordTransition()` **inside**
the `DB::transaction(fn() => ...)` closure (line 245). If `recordTransition()`
**swallows** the exception, the transaction COMMITS — with the `orders.status`
mutation but WITHOUT the audit row.

This is the **canonical NF525 chain break** : the order is now in a new state,
the legal trail says it was never transitioned. From the regulator's
perspective, the order's status simply *changed* without a documented event.

### 2.4 Disk full / database connection lost

`OrderStatusTransition::query()->create(...)` throws `QueryException`.
`recordTransition()` swallows. The outer `apply()` transaction commits the
`orders.status` mutation. Audit row never written. Log line is the only trace.

### 2.5 The 11 legacy callsites

Every legacy callsite has the same swallow semantic, since they all delegate
to the same `recordTransition()` function. **All 11 legacy callsites are
exposed to the same silent break.**

---

## 3. Why the silent swallow exists (docblock claim)

The function's docblock (line 130-131) says :

```
* Persist an audit row for a successful transition (best-effort; failures are logged only).
```

So the silent-swallow is **intentional** : the design philosophy is that an
audit-row write failure should NOT roll back the business mutation. The
rationale is presumably that a partially-broken audit chain is preferable to
a refused state transition (i.e. "the show must go on").

This is a defensible trade-off **operationally** but it is **NOT NF525-compliant**.
NF525 requires that fiscal-relevant state changes be audited as an
atomic invariant : either both succeed or both fail.

---

## 4. The contradiction with NF525

`CLAUDE.md` §8 says :

> ### Audit Chain
> - `audit_logs` HMAC SHA-256 chain-signed (prev_hash → current_hash)
> - `z_reports` HMAC chain-signed daily clôture
> - DB trigger `BEFORE DELETE` SIGNAL SQLSTATE '45000' (MySQL prod only)
> - TRUNCATE bypass mitigated via GRANT-level REVOKE
> - 6 ans rétention obligatoire post-close

Note that `audit_logs` ≠ `order_status_transitions`. The former is the NF525-
chain table ; the latter is a more lightweight per-status audit table created
specifically for the order state machine. The HMAC chain protects `audit_logs`
specifically, not `order_status_transitions`.

So the technical NF525 exposure depends on whether `audit_logs` receives the
state-change row separately. Spot-check :

- `app/Services/PaymentService.php` :309-321 — writes `AuditLogService::write(['action' => 'order.counter_payment_confirmed', ...])` ALONGSIDE the `recordTransition()` call. ✅
- `app/Services/OrderService.php` :1948-1958 — writes `ActionLog::create(...)` AFTER `recordTransition()`. ✅ but `ActionLog` is NOT in the HMAC chain.
- `app/Services/KitchenDisplaySystemOrderService.php` :299 — calls `recordTransition()` but NO `AuditLogService::write` adjacent. ⚠️
- `app/Services/FrontendOrderService.php` :740 — calls `recordTransition()` for kiosk customer-cancel but NO `AuditLogService::write` adjacent. ⚠️
- `app/Jobs/CleanupStalePendingKioskOrders.php` :60 — calls `apply()` (→ recordTransition) but NO `AuditLogService::write` adjacent. ⚠️

The three ⚠️ paths rely on `order_status_transitions` as the SOLE audit anchor
for the transition. If `recordTransition()` swallows, those transitions vanish
silently from the forensic record. This is **NOT immediately NF525-fatal**
because `order_status_transitions` is NOT the HMAC chain ; but it IS an
operational observability gap that would make post-incident reconstruction
unreliable for chef-bump and kiosk-cancel events.

---

## 5. Three resolution paths

### Option A — Replace silent swallow with `throw` (strict)

```diff
 try {
     OrderStatusTransition::query()->create([
         // ...
     ]);
 } catch (\Throwable $e) {
-    \Illuminate\Support\Facades\Log::warning('[OrderStateMachine] Failed to record transition: ' . $e->getMessage());
+    \Illuminate\Support\Facades\Log::error('[OrderStateMachine] Failed to record transition - REROLLING BACK', [
+        'order_id' => $orderId,
+        'from_status' => $fromStatus,
+        'to_status' => $toStatus,
+        'error' => $e->getMessage(),
+    ]);
+    throw $e;
 }
```

**Pros:**
- Restores audit invariant : transition + audit row are atomic.
- `apply()`'s outer `DB::transaction` will roll back the status mutation
  cleanly on throw.

**Cons:**
- 11 legacy callsites all wrap `recordTransition()` inside their own
  `DB::transaction(...)`. Many of those transactions ALSO contain payment
  flips, cashback writes, transaction rows, AuditLogService writes — all of
  which would now roll back if the audit-row insert fails. This is the
  desired behaviour BUT it's a behavioural change that needs full regression.
- An audit-row insert failure becomes a **user-visible 5xx**. Right now it's
  silently swallowed and the user sees success.
- The cron job `CleanupStalePendingKioskOrders` would throw mid-iteration —
  it iterates via `->each()`, so one failing row would NOT abort siblings,
  but each failure would log loudly.

### Option B — Add optional `$strict` parameter (opt-in)

```diff
 public static function recordTransition(
     string $orderType,
     int $orderId,
     int $fromStatus,
     int $toStatus,
     ?int $actorId = null,
     ?string $reason = null,
+    bool $strict = false,
 ): void {
     // ...
     try {
         OrderStatusTransition::query()->create([...]);
     } catch (\Throwable $e) {
         \Illuminate\Support\Facades\Log::warning(...);
+        if ($strict) {
+            throw $e;
+        }
     }
 }
```

And `apply()` opts in :

```diff
 self::recordTransition(
     $modelClass,
     (int) $orderKey,
     $from,
     $next,
     $actor?->getAuthIdentifier() ? (int) $actor->getAuthIdentifier() : null,
     $reason,
+    strict: true,
 );
```

**Pros:**
- `apply()` is now strict — atomic.
- 11 legacy callsites preserve their best-effort semantic.
- Backwards-compatible.

**Cons:**
- The asymmetry between `apply()` (strict) and legacy callsites (best-effort)
  continues — a 12th legacy callsite added in a future Wave inherits the
  loose behaviour. (Tracks the same root issue as PROP-OSM-001.)

### Option C — Add metrics / alerting on the swallow path

Keep the swallow but add an alert. Replace `Log::warning(...)` with :

```diff
- \Illuminate\Support\Facades\Log::warning('[OrderStateMachine] Failed to record transition: ' . $e->getMessage());
+ \Illuminate\Support\Facades\Log::error('[OrderStateMachine] Failed to record transition (NF525 audit chain anomaly)', [
+     'event' => 'order.state_machine.audit_row_write_failed',
+     'order_type' => $orderType,
+     'order_id' => $orderId,
+     'from_status' => $fromStatus,
+     'to_status' => $toStatus,
+     'actor_id' => $actorId,
+     'error_class' => get_class($e),
+     'error_message' => $e->getMessage(),
+ ]);
+ // Optionally bump a Prometheus / Sentry counter here.
```

**Pros:**
- No behaviour change. Zero regression risk.
- Operationally visible — alert can be configured on the log channel.

**Cons:**
- Audit invariant still broken on failure.
- Requires log-aggregation infrastructure to act on (Le Cayenne V1 LOCAL : Loki / journald).

---

## 6. Recommendation

**Option C** for V1 (better logging). **Option B as V1.0.2 backlog item.**

Reasoning :

1. Option A is the philosophically correct fix but introduces 11 callsite
   regression surfaces and changes user-facing behaviour (status mutations
   become refusable on audit-row failure). Too risky for V1 LOCAL ship.
2. Option B preserves backwards compat for legacy paths while letting `apply()`
   adopt the strict semantic. Aligns naturally with PROP-OSM-001 Option C
   (thin-helper refactor) which would also unblock per-callsite strictness
   adoption.
3. Option C is the minimum-risk operational improvement : downgrade `warning`
   to `error` + structured context = log-aggregation alerting works without
   any behaviour change.
4. For NF525 chain integrity, the canonical anchor is `audit_logs` (HMAC
   chain), NOT `order_status_transitions`. The proposal-005 risk is therefore
   "operational forensic" not "fiscal regulatory" — Option C is sufficient.

---

## 7. Risk if NOT acted upon

| Scenario | Likelihood today | Impact |
|----------|------------------|--------|
| Audit-row write fails silently during normal ops | LOW (DB healthy) | LOW (1 row) |
| Sustained DB contention drops 10+ rows / hour | LOW in V1 LOCAL | HIGH (forensic blind spot) |
| Post-incident reconstruction needed for chef-bump events | MEDIUM | HIGH (silent gaps in `order_status_transitions`) |
| NF525 audit reveals chain integrity expected on this table | LOW (audit_logs is the chain) | MEDIUM (auditor would request clarification) |

---

## 8. LOCK feasibility (if Option B pursued V1.0.2)

- ≤10 LOC change ? **YES** (add parameter + conditional throw + apply() opt-in)
- Architectural redesign ? **NO**
- Frozen file ? **YES**
- Owner gate ? **REQUIRED**

**If Option C pursued in V1 :**

- ≤8 LOC change (structured log) — still requires LOCK doc per CLAUDE.md §7.

---

## 9. Verification plan (Option C)

- New PHPUnit test :
  ```php
  public function test_recordTransition_logs_error_when_create_fails(): void {
      Log::spy();
      // Force DB error via invalid order_id or mock the model factory to throw
      OrderStateMachine::recordTransition(/* invalid args triggering throw */);
      Log::shouldHaveReceived('error')->withArgs(function($message, $context) {
          return str_contains($message, 'NF525 audit chain anomaly')
              && isset($context['event']);
      });
  }
  ```
- Regression : full `OrderStateMachineApplyTest` + `OrderServiceCancelTest`.
- Operational : confirm log line lands in target aggregator (journald / Loki).

---

## 10. Owner sign-off

- [ ] APPLY-OPTION-A (strict throw ; 11-callsite regression sweep ; LOCK required)
- [ ] APPLY-OPTION-B (opt-in strict ; LOCK required ; V1.0.2)
- [x] **APPLY-OPTION-C (structured error log) recommended for V1**
- [ ] DEFER-V1.0.2 (no logging improvement)

**Signed-off-by-owner** : ___________  **Date** : ___________

---

## 11. References

- `app/Domain/Order/OrderStateMachine.php` :130-159 (`recordTransition`)
- `app/Domain/Order/OrderStateMachine.php` :245-252 (`apply()` calls `recordTransition`)
- `database/migrations/2026_04_15_230000_create_order_status_transitions_table.php`
- `app/Services/Fiscal/AuditLogService.php` (canonical NF525 HMAC chain — separate)
- `CLAUDE.md` §8 NF525 Audit Chain invariants
- PROP-OSM-001 (related : legacy vs apply pattern divergence)
