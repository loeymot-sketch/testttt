# PROPOSAL — Admin terminal-state reset to PENDING allowed without reason (asymmetric audit invariant)

**ID** : PROP-OSM-004
**Date** : 2026-05-23
**Phase** : B.5 — PROPOSAL AGENT for `app/Domain/Order/OrderStateMachine.php`
**Frozen file** : `app/Domain/Order/OrderStateMachine.php` (CLAUDE.md §7)
**Severity** : P2 — audit-completeness invariant gap ; not exploitable today (Admin-only path)
**Touch** : ZERO (read-only audit, proposal only)

---

## 1. Finding (read-only)

`OrderStateMachine::allows()` at lines 63-70 :

```php
case OrderStatus::CANCELED:
case OrderStatus::REJECTED:
case OrderStatus::RETURNED:
    if ($user && method_exists($user, 'hasRole') && $user->hasRole('Admin')) {
        return true;
    }
    return false;
```

This block permits an **Admin** user (Spatie role `'Admin'`) to transition
**FROM** any terminal state (CANCELED / REJECTED / RETURNED) to **ANY OTHER**
state — including back to PENDING / ACCEPT / PREPARING / PREPARED / etc. The
test `test_admin_can_transition_from_terminal_states` at
`OrderStateMachineTest.php` :141-148 asserts this for `→ PENDING` specifically.

The asymmetry is :

- Transition **INTO** a terminal state requires a reason (`requiresReason(CANCELED)`,
  `requiresReason(REJECTED)`, `requiresReason(RETURNED)` all return `true`).
- Transition **OUT** of a terminal state by Admin requires **NO reason**
  (`requiresReason(PENDING)` etc. all return `false`).

Combined with PROP-OSM-002, an Admin **un-cancelling** an order writes :

- `order_status_transitions.from_status` = 16 (CANCELED)
- `order_status_transitions.to_status` = 1 (PENDING)
- `order_status_transitions.reason` = **NULL**

There is no forensic record of WHY the cancellation was reversed. Under NF525,
once an order has been issued a `fiscal_sequence_no` and consolidated in a Z
report, this kind of state-machine inversion is potentially **fiscal-chain
breaking** — but the SM's `allows()` does not even hint at the constraint.

---

## 2. Why this is P2, not P0

Four mitigations exist :

1. **Admin role is rare**. Per `CLAUDE.md` §9, branch_id=0 admins are deployment-
   level identities, not day-to-day staff. The probability of an unintended
   terminal-reset is low.
2. **Sealed-Z guard exists upstream**. `app/Services/Order/SealedOrderGuard.php`
   (referenced at `OrderService.php` :1899) refuses to mutate orders contained
   in a closed Z window — RETURNED specifically is gated. So an Admin trying
   to un-RETURN a fiscally-sealed order is blocked at the service layer (NOT
   at the SM layer — the SM `allows(RETURNED, PENDING)` returns `true` but the
   service throws `OrderSealedException` first).
3. **AuditLogService captures the action separately**. Any controller path
   that surfaces this transition writes an `audit_logs` row (HMAC chain),
   so forensic recovery is possible by joining `audit_logs` + `order_status_transitions`
   on `resource_id`.
4. **No production controller currently exposes the path**. Searching for
   `RETURNED → PENDING` API surfaces yields ZERO routes. The capability is
   latent ; activating it would require a new admin endpoint.

But these mitigations are all **layers ABOVE the SM**. The SM itself is silent.

---

## 3. The specific invariant gap

`requiresReason()` at lines 260-267 :

```php
public static function requiresReason(int $to): bool
{
    return in_array($to, [
        OrderStatus::CANCELED,
        OrderStatus::REJECTED,
        OrderStatus::RETURNED,
    ], true);
}
```

The function is keyed only on `$to` — the destination state — not on `$from`.
A transition like `CANCELED → PENDING` therefore returns `false` from
`requiresReason()` even though the transition logically **erases a
cancellation** and deserves an audit reason at LEAST as much as the original
cancellation did.

Compare with the NF525 audit-chain invariant : every fiscal mutation that
changes the legal characterisation of an order MUST carry a forensic anchor.
A cancellation reversal is exactly that.

---

## 4. Three resolution paths

### Option A — Make `requiresReason()` aware of `$from` AND `$to`

```diff
-public static function requiresReason(int $to): bool
+public static function requiresReason(int $from, int $to): bool
 {
-    return in_array($to, [
-        OrderStatus::CANCELED,
-        OrderStatus::REJECTED,
-        OrderStatus::RETURNED,
-    ], true);
+    // Transition INTO a terminal state.
+    $terminalIn = in_array($to, [
+        OrderStatus::CANCELED,
+        OrderStatus::REJECTED,
+        OrderStatus::RETURNED,
+    ], true);
+    // Transition OUT of a terminal state (admin un-cancel / un-reject / un-return).
+    $terminalOut = in_array($from, [
+        OrderStatus::CANCELED,
+        OrderStatus::REJECTED,
+        OrderStatus::RETURNED,
+    ], true);
+    return $terminalIn || $terminalOut;
 }
```

And update `apply()` :

```diff
-if (self::requiresReason($next) && (!is_string($reason) || trim($reason) === '')) {
+if (self::requiresReason($from, $next) && (!is_string($reason) || trim($reason) === '')) {
     throw new IllegalTransitionException(
-        sprintf('Transition to status %d requires a non-empty reason.', $next)
+        sprintf('Transition %d → %d requires a non-empty reason.', $from, $next)
     );
 }
```

**Pros:**
- Symmetric audit invariant : every terminal-state crossing requires a reason.
- Cron job is unaffected — it goes INTO terminal (REJECTED) which already had
  the requirement.

**Cons:**
- **BREAKING CHANGE** to `requiresReason()` signature. Any external caller
  must update.
- The `legalTransitions()` helper at line 274-293 emits `requires_reason`
  per (from, to) pair, so it would naturally pick up the new logic without
  signature change — actually it uses `self::requiresReason($to)` (line 286),
  which would also need updating.
- All `OrderStateMachineApplyTest` cases that test admin un-cancel would
  need to provide a reason now.

### Option B — Keep `requiresReason()` signature, add stricter helper

```diff
+/**
+ * Stronger variant : terminal CROSSING in either direction requires a reason.
+ */
+public static function requiresReasonStrict(int $from, int $to): bool
+{
+    $terminals = [OrderStatus::CANCELED, OrderStatus::REJECTED, OrderStatus::RETURNED];
+    return in_array($to, $terminals, true) || in_array($from, $terminals, true);
+}
```

And use `requiresReasonStrict()` in `apply()` only ; keep `requiresReason()`
backwards-compatible for legacy callsites that use it.

**Pros:**
- No breaking change.
- `apply()` enforces the stronger invariant ; legacy paths can opt in.

**Cons:**
- Two functions doing similar things — naming clash.
- Legacy callsites continue to be lax.

### Option C — Document + sentinel (no code change)

Add a docblock paragraph on `requiresReason()` describing the asymmetry, then
add a sentinel test that DOCUMENTS the gap explicitly :

```php
public function test_admin_terminal_reset_does_not_require_reason_known_gap(): void {
    $this->assertFalse(OrderStateMachine::requiresReason(OrderStatus::PENDING));
    // BACKLOG V1.0.2 : symmetric terminal-crossing reason requirement.
    // See PROP-OSM-004.
}
```

**Pros:**
- Zero behaviour change.
- Documents the decision.

**Cons:**
- The gap remains exploitable IF a future admin endpoint exposes the path.

---

## 5. Recommendation

**Option C** for V1. **Option B as V1.0.2 backlog item.**

Reasoning :

1. No production controller exposes this path today. The gap is latent.
2. Touching `OrderStateMachine.php` for a P2 latent risk is high-cost low-benefit
   in V1.
3. SealedOrderGuard + AuditLogService HMAC chain provide defence-in-depth.
4. The sentinel test crystallises the decision so future maintainers don't
   accidentally fix it without recognising the breaking-change cost.
5. V1.0.2 Option B is preferable to A because it preserves backwards compat
   while letting `apply()` enforce the stricter discipline.

---

## 6. Risk if NOT acted upon

| Scenario | Likelihood today | Impact |
|----------|------------------|--------|
| Admin creates terminal-reset endpoint without external review | LOW (no admin demand) | MEDIUM (silent reason loss) |
| NF525 fiscal-chain reversal documented post-incident | LOW | HIGH (regulatory exposure) |
| Cancellation reversal during normal operations | NONE in V1 | LOW |

---

## 7. LOCK feasibility (if Option B pursued V1.0.2)

- ≤15 LOC change ? **YES** (new method + apply() callsite)
- Architectural redesign ? **NO** (additive)
- Frozen file ? **YES**
- Owner gate ? **REQUIRED**

---

## 8. Owner sign-off

- [ ] APPLY-OPTION-A (breaking-change signature ; comprehensive sweep)
- [ ] APPLY-OPTION-B (additive strict helper ; LOCK required)
- [x] **APPLY-OPTION-C (document + sentinel) recommended for V1**
- [ ] DEFER-NO-DOCS (NOT recommended)

**Signed-off-by-owner** : ___________  **Date** : ___________

---

## 9. References

- `app/Domain/Order/OrderStateMachine.php` :63-70 (admin terminal-exit allows)
- `app/Domain/Order/OrderStateMachine.php` :260-267 (`requiresReason` keyed on `$to` only)
- `tests/Unit/Domain/Order/OrderStateMachineTest.php` :141-148 (admin terminal-exit test)
- `app/Services/Order/SealedOrderGuard.php` (defence-in-depth above SM)
- `CLAUDE.md` §8 NF525 Fiscal Invariants — Audit Chain
- PROP-OSM-002 (related : reason silently dropped on model row)
