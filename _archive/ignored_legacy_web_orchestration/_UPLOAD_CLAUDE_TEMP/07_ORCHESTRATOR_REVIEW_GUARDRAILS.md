# FoodKing — Orchestrator Review Guardrails

> How Claude should evaluate evidence and detect weak work.
> Complements: `docs/ops/CLAUDE_SCORING_RUBRIC.md` (scoring axes).
> This file teaches Claude to be skeptical in the right places.

---

## 1. Evidence Classification

### Strong evidence (score ≥ 85 on Evidence axis)

| Type | What makes it strong |
|------|---------------------|
| PHPUnit test with specific assertions | Asserts the exact behavior changed — e.g., `assertEquals(13, $order->fresh()->status)` after calling `changeStatus` with `DELIVERED` |
| Playwright flow with screenshot | Shows actual UI state after action — e.g., order appears on KDS after POS creation |
| Console/network log clean run | No errors, no warnings, no unexpected requests during the flow |
| DB assertion after transaction | `assertDatabaseHas('orders', ['total' => $expectedTotal, 'branch_id' => $branchId])` |
| Event dispatch verification | `Event::assertDispatched(OrderCreated::class)` with argument checks |
| Regression test on existing bug | Test that would have caught the previous bug now passes |

### Weak evidence (score ≤ 69 on Evidence axis)

| Type | Why it's weak |
|------|--------------|
| "Tests pass" without showing which tests | Unknown scope — could be unrelated tests |
| Test file exists for the affected area | File existence ≠ relevant assertions. See `BranchIsolationTest` (placeholder) |
| Unit test that reads source code as text | `FrontendOrderServiceTest` and `OrderServiceSecurityTest` grep PHP source — they verify string presence, not behavior |
| `assertTrue(true)` | Zero evidence value — always passes |
| Screenshot of a page without the specific state change visible | Proves the page loads, not that the logic is correct |
| "Works when I try it manually" | Not reproducible, not recorded, not regression-safe |
| Lint passes | Lint checks syntax and style, not business logic |
| Build succeeds | Build checks compilation, not correctness |

### Medium evidence (score 70–84 on Evidence axis)

| Type | Limitation |
|------|-----------|
| Feature test hitting HTTP endpoint | Tests the route, but may not assert the specific invariant (e.g., tests order creation but doesn't verify server-side price recalculation) |
| Test on one path when 4 exist | Covers POS but not kiosk/table/online — partial coverage |
| Static inspection (code read) | Verifies code structure but not runtime behavior |
| Test passes but doesn't test the actual change | Pre-existing test covers the area but wasn't modified to cover the new behavior |

---

## 2. When Passing Tests Are Not Enough

### Scenario 1: Tests pass but don't test the change

**Pattern**: Developer changes `posOrderStore` pricing logic. `POSComprehensiveTest` passes. But `POSComprehensiveTest` tests order creation, not the specific pricing edge case that was fixed.
**Rule**: Evidence must assert the specific behavior that changed, not just adjacent behavior.

### Scenario 2: Tests pass but only on one path

**Pattern**: Coupon validation fix applied to `FrontendOrderService::myOrderStore`. Tests on `/api/frontend/order` pass. But `OrderService::posOrderStore`, `OrderService::tableOrderStore`, and `OrderService::myOrderStore` have the same coupon logic and weren't tested.
**Rule**: If the same logic exists in multiple paths, evidence must cover all paths or explicitly state which are deferred.

### Scenario 3: Tests pass but are placeholders

**Pattern**: `BranchIsolationTest` passes. Developer claims "branch isolation is tested." The test contains only `$this->assertTrue(true)`.
**Rule**: Read the test file. Verify assertions are relevant. Placeholder tests are zero evidence.

### Scenario 4: Tests pass but test the wrong model

**Pattern**: Fix applied to `FrontendOrder`. Test asserts against `Order::where(...)`. Both use the `orders` table so the query succeeds, but the test doesn't verify `FrontendOrder`-specific behavior (different `$casts`, missing scopes).
**Rule**: Verify the test uses the correct model class for its assertions.

### Scenario 5: Tests pass but don't cover event dispatch timing

**Pattern**: Order creation works. `OrderCreated` is dispatched. But the test doesn't verify whether the event fires before or after the DB commit. If it fires inside the transaction and the transaction rolls back, KDS gets a phantom order.
**Rule**: For event dispatch changes, require `Event::assertDispatched` with timing verification or explicit code review of dispatch position.

---

## 3. When Static Inspection Is Not Enough

### Must go beyond static inspection when:

| Condition | Why |
|-----------|-----|
| Change affects cross-surface sync | Code review can't verify WebSocket delivery or timing |
| Change affects kiosk auto-accept | The PENDING→ACCEPT transition happens post-commit; behavior depends on runtime state |
| Change affects `queue_number` generation | Concurrency behavior can't be verified by reading code alone |
| Change affects coupon + loyalty interaction | The combined discount stored in `OrderCoupon.discount` creates ledger ambiguity only visible in data |
| Change affects `ValidStatusTransition` with admin bypass | Admin's ability to recover from terminal states has cascading effects on loyalty, notifications |
| Change affects broadcast payloads | Payload changes may break Vue `Echo` listeners silently |

### Static inspection IS sufficient when:

| Condition | Example |
|-----------|---------|
| Doc-only change | Updating status values in `BUSINESS_RULES.md` |
| Dead code removal | Removing unused `NotificationType` enum |
| Import cleanup | Removing dead `AmountType` import from `AppLibrary` |
| Route to missing method | Removing or adding `destroy` route/method |
| Comment improvement | Adding clarity to existing code without changing logic |

---

## 4. Skepticism Triggers

When Claude encounters any of these, skepticism must increase and scoring must be conservative.

### Red flags in execution reports

| Flag | What it suggests |
|------|-----------------|
| "All tests pass" without test output | Evidence not shown = evidence not present |
| "No changes needed to tests" | Change has zero test coverage — was it verified at all? |
| "Tested manually" | Not reproducible, will regress |
| Report references files outside `files_allowed` | Scope creep — verify if authorized |
| Report claims "minor change" on a critical path | Pricing, status, auth, branch isolation are never minor |
| Large diff with no test additions | Proportionality problem — big change needs big evidence |
| Execution time < 5 minutes on a complex task | Either shortcuts were taken or scope was smaller than declared |

### Red flags in plans

| Flag | What it suggests |
|------|-----------------|
| Plan touches `OrderService` but doesn't mention `FrontendOrderService` | Incomplete blast radius |
| Plan says "update Order model" without mentioning `FrontendOrder` | Single-model trap |
| Plan uses status value 5, 10, 14, or 17 | Wrong enum values from stale docs |
| Plan says "kiosk is blocked by abilities" | Overstated — Spatie middleware is the real barrier |
| Plan says "OSS uses api-key only" | Wrong — Sanctum + Spatie permission required |
| Plan says `local-validation` but change affects UI flow | Behavioral proof needed — should be `playwright-*` |
| Plan allows modification in frozen zone without `architecture_exception` | Frozen zone violation |

### Red flags in code changes

| Flag | What it suggests |
|------|-----------------|
| `$request->all()` passed to `Order::create()` | Client price leak — SSOT violation |
| `Event::dispatch()` inside `DB::transaction()` | Phantom notification risk |
| `withoutGlobalScope(BranchScope::class)` on non-admin query | Branch isolation leak |
| `createToken('...', ['kiosk:order', 'admin:*'])` | Kiosk privilege escalation |
| `$order->status = $request->status` without `ValidStatusTransition` | Status guard bypass |
| `OrderItem::create($request->input('items'))` | Mass assignment of pricing fields |

---

## 5. Evidence Requirements by Change Type

| Change type | Minimum evidence | Strong evidence |
|-------------|-----------------|-----------------|
| Pricing logic | Unit test asserting server-recalculated total ≠ client total | Test on all 4 store methods + edge cases (zero discount, max cap, loyalty + coupon) |
| Status transition | Unit test with specific before/after status pairs | Full 9×9 matrix test + event dispatch assertion |
| Branch isolation | Feature test creating data in branch A, querying from branch B user | Cross-branch test + `BranchScope` bypass grep |
| Auth/authz | Feature test verifying 401/403 for unauthorized actors | Kiosk token → admin endpoint test, refresh token test, ability enforcement test |
| KDS/OSS sync | Static inspection of dispatch position | Playwright flow: create order → verify KDS visibility → change status → verify OSS update |
| Coupon logic | Unit test of `couponChecking` with valid/expired/early/over-limit cases | Full integration test: order with coupon → verify `OrderCoupon.discount` → verify `Order.total` |
| Queue number | Unit test of lock + increment | Concurrent test (2 simultaneous orders, verify unique queue numbers) |
| Event dispatch | `Event::assertDispatched` in test | Dispatch position verified (after `DB::transaction` closure) + listener execution verified |
| UI/UX change | Screenshot | Playwright flow testing the affected surface with interaction |

---

## 6. Scoring Calibration: FoodKing-Specific Anchors

### Evidence axis calibration

| Score | What it means in FoodKing |
|-------|---------------------------|
| 95–100 | PHPUnit tests with specific assertions on the changed behavior + Playwright on affected surface + clean console/network |
| 85–94 | PHPUnit tests covering the change + static inspection confirming no side effects |
| 70–84 | Feature tests exist and pass but don't specifically assert the changed behavior, or only cover 1 of 4 paths |
| 50–69 | Tests exist but are placeholders, or evidence covers a different area than the change |
| 0–49 | No test output, or tests are source-code scanners, or "tested manually" |

### Business logic axis calibration

| Score | What it means in FoodKing |
|-------|---------------------------|
| 95–100 | All pricing paths verified, all status transitions intact, coupon rules complete, branch isolation confirmed |
| 85–94 | Core path verified, minor paths acknowledged as out of scope with justification |
| 70–84 | Main path correct but secondary paths not verified (e.g., POS fixed but table not checked) |
| 50–69 | Logic partially correct but a known gap exists (e.g., `start_date` not checked on coupons) |
| 0–49 | SSOT violated, or status transition broken, or branch isolation bypassed |

---

## 7. The Anti-Complacency Rule

After 3+ cycles of `APPROVED` verdicts in a row, Claude must:

1. Increase scrutiny on the next cycle
2. Read test assertions directly (not trust "tests pass")
3. Verify at least one invariant end-to-end (pick from: pricing SSOT, branch isolation, status transitions)
4. Check if any doc/code mismatch from the risk brief (ORB-025 through ORB-029) has been fixed

Complacency after a streak of approvals is how hidden regressions accumulate.
