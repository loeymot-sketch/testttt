# FoodKing — Orchestrator Decision Rules

> How Claude should think when reaching a verdict.
> Complements: `docs/ops/CLAUDE_SCORING_RUBRIC.md` (scoring mechanics), `docs/ops/CLAUDE_CYCLE_OUTPUT.md` (output format).
> This file adds FoodKing-specific decision intelligence that the rubric cannot capture.

---

## 1. The Five Verdicts

| Verdict | When to use | Score range |
|---------|-------------|-------------|
| `APPROVED` | Work is correct, complete, and proven | Global ≥ 85, no axis < 70 |
| `NEEDS_FIX` | Work is directionally correct but incomplete or partially weak | Global 70–84 |
| `NEEDS_PLAYWRIGHT` | Logic seems acceptable but behavioral proof is missing | Any score — evidence gap is behavioral |
| `BLOCKED` | Critical invariant threatened, or evidence too weak to judge | Global < 70, or any axis < 50 |
| `MANUAL_GATE` | Decision requires human judgment — not a code quality issue | Any score — the question is non-technical |

---

## 2. Decision Logic: `APPROVED`

### Required conditions (ALL must be true)

1. Global score ≥ 85 (5-axis average after adjustments)
2. No individual axis below 70
3. The test strategy declared in the plan was actually executed
4. No critical invariant is threatened (pricing SSOT, branch isolation, status transitions, kiosk abilities, OSS read-only)
5. If the change touches order models: both `Order` and `FrontendOrder` were addressed
6. If the change touches pricing: all 4 store methods were verified
7. If the change touches status transitions: all 5 change paths were verified
8. No open doc/code contradiction remains on the affected area
9. Test assertions were read — not just "tests pass"

### FoodKing-specific approval traps

| Trap | How it manifests | What to do |
|------|-----------------|------------|
| Placeholder test passes | `BranchIsolationTest` → `assertTrue(true)` | Read the assertion. Zero-assertion tests = zero evidence |
| Source-scanning unit test | `FrontendOrderServiceTest` reads PHP as text, doesn't call service | Treat as `static-inspection`, not `local-validation` |
| Doc-stated status values used | Plan uses PENDING=5 instead of PENDING=1 | Verify against `app/Enums/OrderStatus.php`. Never trust doc integers |
| Single model updated | `Order.$fillable` changed but `FrontendOrder` not checked | Reject until both models addressed |
| Single store method fixed | Pricing fix in `posOrderStore` but not in other 3 | Reject until all 4 verified |
| "Tests pass" on wrong scope | KDS test passes but the change was on `FrontendOrderService` | Evidence must match the blast radius |

### Approval confidence levels

- **High**: All 5 axes ≥ 85, evidence includes test output + relevant assertions on the changed code
- **Medium**: Global ≥ 85 but one axis at 70–84, or evidence is indirect but sufficient
- **Low**: Should not approve at low confidence — downgrade to `NEEDS_FIX`

---

## 3. Decision Logic: `NEEDS_FIX`

### When to use

- Work is mostly correct but something is missing
- A non-critical axis is below 70 (but no critical invariant broken)
- Evidence exists but doesn't fully cover the blast radius
- A minor inconsistency between `Order` and `FrontendOrder` was introduced
- A doc/code mismatch was introduced but doesn't affect runtime behavior

### FoodKing-specific `NEEDS_FIX` scenarios

| Scenario | Fix scope |
|----------|-----------|
| Pricing logic fixed in `posOrderStore` only | Extend to all 4 store methods |
| New `$fillable` field on `Order` but not `FrontendOrder` | Add to both or justify exclusion |
| Event dispatch moved but not verified post/pre-commit | Add dispatch position test |
| Branch check added in one `changeStatus` but not others | Extend to all relevant paths |
| Test written but assertions don't cover the actual change | Strengthen assertions |
| `CouponService` fix without `start_date` check | Add the check |

### NEEDS_FIX rules

1. Maximum 3 consecutive `NEEDS_FIX` on the same cycle without escalation to `BLOCKED` or `MANUAL_GATE`
2. Each `NEEDS_FIX` must specify exact fix actions (not "improve tests")
3. Fix scope must be achievable in one Cursor cycle

---

## 4. Decision Logic: `NEEDS_PLAYWRIGHT`

### When to use

- Backend logic is correct based on code review and unit tests
- But the user-facing behavior hasn't been verified
- The change affects a cross-surface flow (POS→KDS→OSS)
- The change affects kiosk auto-accept or wizard navigation
- The change affects realtime updates (WebSocket/Pusher)

### FoodKing flows that always need Playwright

| Flow | Why | Surfaces |
|------|-----|----------|
| Kiosk order creation → KDS visibility | Auto-accept gap (ORB-010), broadcast reliability | Kiosk → KDS |
| POS order → KDS → OSS queue number | Full lifecycle, cross-surface consistency | POS → KDS → OSS |
| Customer cancel → KDS/OSS update | Missing `OrderStatusChanged` on `$auth=true` path (ORB-011) | Frontend → KDS/OSS |
| Status change → FCM notification | `ShouldBroadcastNow` no retry (ORB-015) | All |
| Kiosk wizard navigation | Wizard step flow, garniture selection, upsell | Kiosk |
| POS wizard payment | Cash check, discount application, receipt | POS |

### NEEDS_PLAYWRIGHT output requirements

Must include:
1. Specific flows to test (not "test the app")
2. Pass condition (observable outcome)
3. Fail condition (what invalidates the flow)
4. Expected proof type (screenshot, console cleanliness, state transition confirmation)

---

## 5. Decision Logic: `BLOCKED`

### When to use

- A critical invariant is directly threatened
- Evidence is too weak to judge a business-critical zone
- A contradiction between CLAUDE.md/MEMORY.md/docs and the implementation exists
- The plan itself is flawed (wrong status values, missing paths, frozen zone violation)

### Automatic `BLOCKED` triggers in FoodKing

| Trigger | Why |
|---------|-----|
| Plan uses status values not matching `app/Enums/OrderStatus.php` | Wrong status integers = wrong implementation |
| Plan touches frozen zone without explicit human exception | Payment gateways, `PushNotificationService`, analytics, delivery boy |
| Client-supplied price could leak into DB | SSOT pricing violation |
| `OrderStatusChanged` dispatched inside `DB::transaction` | Phantom broadcast risk |
| `BranchScope` removed or bypassed on non-admin route | Branch isolation violation |
| New ability added to kiosk token beyond `kiosk:order` | Kiosk privilege escalation |
| `ValidStatusTransition` modified without exhaustive matrix test | Lifecycle integrity risk |
| Execution report shows no test output but claims "tests pass" | Unverifiable evidence |

### BLOCKED rules

1. `BLOCKED` requires a specific reason — not "it feels unsafe"
2. `BLOCKED` must specify what would unblock (information, fix, human decision)
3. If the same cycle is `BLOCKED` twice for different reasons, escalate to `MANUAL_GATE`

---

## 6. Decision Logic: `MANUAL_GATE`

### When to use

- The decision requires human judgment, not code analysis
- The question is about intent, not correctness
- Architecture direction is uncertain
- A stable project rule needs to be changed or an exception granted

### FoodKing-specific `MANUAL_GATE` scenarios

| Scenario | Human question |
|----------|----------------|
| Pre-save notification dispatch in `OrderService::changeStatus` | Is this intentional (performance pattern) or a bug? |
| `OrderService::changeStatus($auth=true)` reachability | Is this path used in production? If yes, missing `OrderStatusChanged` is a real gap |
| Production `.env` broadcast/queue driver | What are the actual values? |
| Frozen zone modification request | Should the zone be unfrozen for this change? |
| Sanctum token expiration policy | What should the expiration be? |
| `ShouldBroadcastNow` → `ShouldBroadcast` migration | Is the queue infrastructure ready for queued broadcasts? |
| Order/FrontendOrder trait extraction | Is this the right time for this refactor? |

### MANUAL_GATE rules

1. Clearly state the question for the human (not "needs review")
2. Provide options with trade-offs if possible
3. Do not attempt to answer the human question — present evidence and wait

---

## 7. Decision Shortcuts (Fast Paths)

### Instant `APPROVED` (no full scoring needed)

- Doc-only changes that don't affect code behavior, in non-critical docs
- Test-only additions that don't modify app code
- `bot/` directory changes that don't affect app logic
- Comment cleanup with zero functional change

### Instant `BLOCKED`

- Any `Order::create()` or `FrontendOrder::create()` from raw `$request->all()`
- Any `withoutGlobalScope(BranchScope::class)` on a customer-facing route
- Any `DB::transaction` containing `OrderCreated::dispatch` or `OrderStatusChanged::dispatch`
- Any route adding write capability to OSS endpoints

### Instant `NEEDS_FIX`

- `Order.$fillable` changed without corresponding `FrontendOrder` check
- Pricing method changed in one `*Store()` without mentioning the other three
- Test file created with only `assertTrue(true)` assertions

---

## 8. Post-Decision Checklist

After every verdict, verify:

- [ ] Was `MEMORY.md` update considered? (new risk, closed question, new decision)
- [ ] Were residual risks documented?
- [ ] Was the next actor specified? (Human / Cursor / Playwright / stop)
- [ ] Was confidence level stated?
- [ ] If `APPROVED`: will this hold up under re-review in a future cycle?
