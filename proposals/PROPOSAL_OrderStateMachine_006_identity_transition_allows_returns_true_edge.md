# PROPOSAL — Identity transition (`$from === $to`) returns `true` from `allows()` — semantic edge case

**ID** : PROP-OSM-006
**Date** : 2026-05-23
**Phase** : B.5 — PROPOSAL AGENT for `app/Domain/Order/OrderStateMachine.php`
**Frozen file** : `app/Domain/Order/OrderStateMachine.php` (CLAUDE.md §7)
**Severity** : P3 — semantic edge case ; benign in practice ; documentation candidate
**Touch** : ZERO (read-only audit, proposal only)

---

## 1. Finding (read-only)

`OrderStateMachine::allows()` at lines 32-34 :

```php
public static function allows(int $from, int $to, ?Authenticatable $user = null): bool
{
    if ($from === $to) {
        return true;
    }
    // ... transition table ...
}
```

The identity transition (`$from === $to`) is **unconditionally allowed**, even
when :

- `$from === $to === CANCELED` (re-cancel a cancelled order)
- `$from === $to === REJECTED` (re-reject a rejected order)
- `$from === $to === RETURNED` (re-return a returned order)
- `$from === $to === DELIVERED` (re-deliver a delivered order)

This bypasses the `requiresReason()` check that would normally apply on the
target state (lines 260-267). `apply()` short-circuits before the
`requiresReason()` check anyway because the identity branch returns early
at line 215-220 :

```php
if ($from === $next) {
    // Idempotent: another concurrent transaction already applied the
    // same target. Bail out without re-writing or re-auditing.
    $order->setRawAttributes($locked->getAttributes(), true);
    return;
}
```

So `apply()` is safe — it no-ops on identity transitions and writes neither
the model nor the audit row.

But the **11 legacy callsites** that call `OrderStateMachine::allows()`
explicitly (e.g. via `ValidStatusTransition::passes()` in form validation,
`KitchenDisplaySystemOrderService::changeStatus` at line 285) handle
identity differently :

- `OrderService::deliveryBoyOrderChangeStatus` at line 1625-1632 : explicit
  idempotent check via `if ((int) $locked->status === (int) $newStatus)
  return;`. ✅
- `KitchenDisplaySystemOrderService::changeStatus` at line 285 : `if
  ($fromLocked === $newStatus) return [...changed=false]`. ✅
- `OrderService::changeStatus` at line 1814 : explicit idempotent check via
  the locked re-read. ✅
- `FrontendOrderService::changeStatus` at line 693 : `if ((int)
  $frontendOrder->status === $targetStatus) return $frontendOrder;`. ✅

Each legacy callsite **independently re-implements** the identity short-
circuit. None of them rely on `allows()` to reject identity.

---

## 2. Is this a bug?

It depends on the **contract** of `allows()` :

**Interpretation A** : `allows()` answers "given a current state X, is the
proposed target Y a legal NEXT state?" Under this interpretation, identity
should return `false` — Y is not a "next state" of X if it equals X.

**Interpretation B** : `allows()` answers "would persisting `status=Y`
violate the state machine if the current value is X?" Under this
interpretation, identity should return `true` — writing X over X is
trivially safe.

The current implementation honours **Interpretation B**.

The risk surfaces if a future caller assumes Interpretation A. For example,
`ValidStatusTransition::passes()` at `app/Rules/ValidStatusTransition.php` :30 :

```php
public function passes($attribute, $value)
{
    $newStatus = (int) $value;
    $user = auth()->check() ? auth()->user() : null;
    return OrderStateMachine::allows($this->currentStatus, $newStatus, $user);
}
```

If an admin form submits `status=CANCELED` for an order that is already
`CANCELED`, the rule returns `true` and the controller proceeds. Whether the
controller then writes a redundant row is up to the controller's idempotent
check (each does its own, as shown above).

If a future controller author **forgets** the idempotent check and trusts
`allows()` alone to gate the mutation, the user could trigger a no-op DB
write storm by re-submitting the same status N times. Not a correctness bug,
but a performance / log-noise issue.

---

## 3. Test coverage of identity transition

`OrderStateMachineTest::test_identity_transition_is_always_allowed` at
line 98-106 :

```php
public function test_identity_transition_is_always_allowed(): void
{
    foreach (OrderStateMachine::allStatuses() as $status) {
        $this->assertTrue(
            OrderStateMachine::allows($status, $status, null),
            "Identity transition {$status} → {$status} must be allowed"
        );
    }
}
```

The test ASSERTS Interpretation B is the design intent. So this is NOT a
hidden bug — it is a documented contract.

---

## 4. Two resolution paths

### Option A — Add a docblock paragraph clarifying the contract

```diff
 /**
  * @param  Authenticatable|null  $user  Authenticated user for POS shortcut / Admin override checks
+ *
+ * Identity transition contract: returns TRUE when $from === $to, regardless of
+ * the value of either. The state machine treats identity as a trivially-safe
+ * no-op. Callers responsible for state mutation MUST implement their own
+ * idempotent guard (e.g. early-return when current_status === target_status)
+ * — this method does NOT short-circuit on identity for them. apply() does
+ * implement that guard internally (line 215-220).
  */
 public static function allows(int $from, int $to, ?Authenticatable $user = null): bool
```

**Pros:**
- Zero behaviour change.
- Documents the existing test contract.
- Helps future controller authors avoid the no-op-storm trap.

**Cons:**
- Touches frozen file for a docblock. LOCK required.

### Option B — Add a separate `allowsActual()` helper (strict)

```diff
+/**
+ * Stricter variant: returns true ONLY for non-identity, legal transitions.
+ * Use when the caller needs to know whether the proposed transition would
+ * ACTUALLY mutate state. Identity transitions return false.
+ */
+public static function allowsActual(int $from, int $to, ?Authenticatable $user = null): bool
+{
+    if ($from === $to) {
+        return false;
+    }
+    return self::allows($from, $to, $user);
+}
```

**Pros:**
- Lets controllers / rules opt into Interpretation A explicitly.
- No change to existing `allows()` semantics.

**Cons:**
- API surface widens.
- Naming is awkward (`allowsActual` vs `allows`).

### Option C — No action

Accept that the 11 legacy callsites already correctly implement their own
idempotent guard, and that no future controller has been observed to forget
it.

**Pros:**
- Zero touch. Zero risk.

**Cons:**
- A future contributor reading `allows()` could misinterpret the contract.

---

## 5. Recommendation

**Option A** (docblock) for V1, as part of an aggregated LOCK doc for this
file (combining PROP-OSM-001 docblock, PROP-OSM-006 docblock, PROP-OSM-003
docblock = three doc clarifications in one LOCK).

Reasoning :

1. The semantic is intentional ; the gap is observability, not correctness.
2. A single docblock LOCK that addresses 3 proposal docblock changes is
   cheaper than 3 separate LOCKs.
3. No production callsite is broken today.
4. Option B is over-engineering for a hypothetical future case.

---

## 6. Risk if NOT acted upon

| Scenario | Likelihood | Impact |
|----------|-----------|--------|
| Future controller assumes Interpretation A and skips idempotent check | LOW | LOW (no-op DB writes, log noise) |
| Audit-row written for identity transition | NONE (`apply()` + `recordTransition()` both guard against this) | NONE |
| New maintainer adds `allows(X, X) === false` test by mistake | LOW (existing positive test would catch it) | LOW |

---

## 7. LOCK feasibility

- ≤15 LOC docblock change ? **YES**
- Architectural redesign ? **NO**
- Frozen file ? **YES**
- Owner gate ? **REQUIRED** — bundle into a single `LOCK_OSM_DOCBLOCK_<date>.md`
  with PROP-OSM-001 and PROP-OSM-003 docblock additions.

---

## 8. Owner sign-off

- [ ] APPLY-OPTION-A (docblock ; bundled LOCK)
- [ ] APPLY-OPTION-B (`allowsActual` helper)
- [x] **APPLY-OPTION-C (no action) recommended for V1, defer doc-bundle to V1.0.2**

**Signed-off-by-owner** : ___________  **Date** : ___________

---

## 9. References

- `app/Domain/Order/OrderStateMachine.php` :30-34, :215-220
- `tests/Unit/Domain/Order/OrderStateMachineTest.php` :98-106
- `app/Rules/ValidStatusTransition.php` :30-36 (callsite)
- 11 legacy callsites each implement their own identity guard (see PROP-OSM-001 table)
