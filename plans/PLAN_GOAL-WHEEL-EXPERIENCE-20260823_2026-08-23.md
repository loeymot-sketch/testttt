# Plan – GOAL-WHEEL-EXPERIENCE-20260823 – 2026-08-23

## TASK_ID
GOAL-WHEEL-EXPERIENCE-20260823

## PRIMARY_EXECUTION_MODEL
gpt-5.5-pro

## REASONING_EFFORT
xhigh

## EXECUTION_TIER
complex

## PRIOR_CONTEXT
- Graphiti MCP is not loaded in this session; local task boundary is the source of planning context.
- The existing Wheel backend, probabilities, claim/delivery flow, stock and loyalty state remain read-only.
- The client may only animate an already server-authorized result; it must never calculate or alter a prize.

## PLAN_REVIEW
PLAN_REVIEW_CHANNEL: foodking-complex-implementer (codex-extension-fallback)
PLAN_REVIEW_MODEL: gpt-5.5-pro
PLAN_REVIEW_REASONING_EFFORT: xhigh
PLAN_REVIEW_VERDICT: PASS

## REPLAN_1 — 2026-08-23 (audit remediation)

- The companion tablet was inspected and its scoped PHPUnit suite is retained as a regression guard. No concrete tablet defect was found and the user explicitly described the wheel placement and motion as already at a high quality level; `borne.blade.php` is therefore **read-only for this cycle**, not an implied cosmetic rewrite.
- The public-wheel E2E must exercise real `Tab`/`Enter` activation for recovery, start and launch, verify focus return after recovery and verify the reward visual under `prefers-reduced-motion: reduce`.

## SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne/roue.html` | Public customer journey: entry, server-driven spin, reward reveal, claim/recovery, responsive and accessible motion | Write | No | No |
| `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne/assets/wheel/` | New optimized, non-critical celebratory visual asset if the existing client assets cannot fulfil the brief | Write | No | No |
| `/Users/1millnonstop/Downloads/lecayenne-web-deploy/Site lecayenne/tests-e2e/` | Existing or new focused browser journey for the public wheel, including reduced motion | Write | No | No |
| `resources/views/admin/wheel/borne.blade.php` | Companion tablet attraction scene: inspected only; no concrete defect in this bounded cycle | Read | No | No |
| `tests/Feature/Wheel/WheelKioskScreenTest.php` | Scoped tablet regression contract | Read/Run | No | No |

## SUBSYSTEMS_OFF_LIMITS
- `app/Services/Wheel/`, `app/Http/Controllers/`, `config/wheel.php`, routes and all migrations — no semantic wheel-rule, probability, access or delivery changes.
- Loyalty, stock, order, payment, price, fiscal, dispatch and `branch_id` implementation.
- Any frozen zone and all non-wheel surfaces.

## INVARIANTS_AT_RISK
- Server-authoritative reward: a visual animation must consume the existing result only and cannot generate, substitute or infer a prize.
- Accessibility and performance: no motion-induced loss of information, keyboard trap, layout shift or blocking of core claim actions.
- Scope isolation: visual changes cannot alter access, redemption, inventory or branch behavior.

## GATE_CONDITIONS
- `GATE-WHEEL-EXPERIENCE-UX-SIGNOFF-2026-08-23` is mandatory before CLOSE. It is an acceptance gate only and remains `PENDING_HUMAN_GATE` until a person approves the verified desktop/mobile, keyboard, reduced-motion, loading/error and server-authoritative prize states.
- ESCALATE immediately if the visual audit reveals that a service/controller/config/route/migration, probabilities, stock, loyalty or a frozen zone must change.

## Execution Steps
1. Audit the public `roue.html`, its API contract and the tablet companion; keep only rendering changes compatible with server-owned state and preserve unrelated dirty files in the public-site repository.
2. Establish a unified premium fairground visual system across the public entry, spin, reward, claim and recovery states: deliberate hierarchy, high contrast, touch targets, empty/error states, responsive composition and `prefers-reduced-motion` alternatives.
3. Upgrade the public spin interaction without changing its outcome: precise phase transitions, deterministic stop on the server-provided selection, disabled repeat controls while pending, a calm reveal delay, live-region announcement and safe restart/claim actions.
4. Generate and add one original project-bound celebratory background asset only if existing brand assets cannot fulfil the brief; use it as a performant decorative layer with a CSS fallback and no essential information in the bitmap.
5. Extend focused browser coverage for the public journey, including real keyboard activation/focus return, ARIA and reduced motion; run the tablet's scoped PHPUnit and the focused public-site E2E checks. No public-site build command exists in this static-site repository.

## SYMMETRY_NOTE
N/A — neither `OrderService` nor `FrontendOrderService` is in scope.

## SCOPE_PRESSURE

## ESCALATION

## Test Strategy
`playwright-mcp` plus `human-verification` — customer-facing animation and gain flow require automated browser verification and the pending human sign-off gate. Run `php artisan test tests/Feature/Wheel/WheelKioskScreenTest.php`, the focused public-site wheel E2E test, and the public-site production build command declared by that repository.

## Audit Status
[ ] Pending
[x] PLAN_REVIEW_VERDICT: PASS
[x] AUDIT_VERDICT: PASS — `foodking-planner-orchestrator` fallback after two empty terminal audit attempts
[x] GPT_FINAL_AUDIT_VERDICT: PASS
[ ] Passed — cycle closed
[x] Gate opened — `docs/gates/GATE_WHEEL_EXPERIENCE_UX_SIGNOFF_2026-08-23.md`
