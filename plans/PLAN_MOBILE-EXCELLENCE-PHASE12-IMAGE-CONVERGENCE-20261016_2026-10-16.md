# Plan – MOBILE-EXCELLENCE-PHASE12-IMAGE-CONVERGENCE-20261016 – 2026-10-16

## TASK_ID
MOBILE-EXCELLENCE-PHASE12-IMAGE-CONVERGENCE-20261016

## PRIMARY_EXECUTION_MODEL
gpt-5.5-pro

## REASONING_EFFORT
xhigh

## EXECUTION_TIER
complex

## PLAN_REVIEW
PLAN_REVIEW_CHANNEL: foodking-complex-implementer (codex-extension-fallback)
PLAN_REVIEW_MODEL: gpt-5.5-pro
PLAN_REVIEW_REASONING_EFFORT: xhigh
PLAN_REVIEW_FALLBACK_REASON: codex CLI rejects gpt-5.5-pro for the authenticated ChatGPT account after two attempts
PLAN_REVIEW_VERDICT: REWORK — revise allowlist and evidence requirements, then rerun fallback review

## PRIOR_CONTEXT

- Phase 10 established that the mobile catalog is structurally aligned but that the
  sandwich, taco and galette visuals still diverge from the canonical public assets.
- `config/menu_images.php` maps the live sandwich/taco products to the public
  `sandwich-*.png` and `tacos-cayenne.png` files; the mobile pack still contains old
  `signature/*` and `tacos.png` references.
- The mobile catalog's non-visual snapshot and order-line builder are protected and
  must remain unchanged.

## SUBSYSTEMS_TOUCHED

| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| Mobile catalog image mapping | `mobile/data/menu.js` product/category image paths only | Write | No | No |
| Mobile canonical asset pack | Exact copies of these nine files only: `mobile/assets/menu/sandwich-cayenne.png`, `sandwich-supreme.png`, `sandwich-mega.png`, `sandwich-terminator.png`, `tacos-cayenne.png`, `galette.png`, `cat-sandwich-cayenne.png`, `cat-galette.png`, `cat-tacos.png` | Write | No | No |
| Mobile visual regression tests | Static mapping/binary assertions and targeted catalog E2E | Write | No | No |
| Cycle governance | Plan/task/report/active cycle/activity trace | Write | No | No |

### Exact file allowlist

- `mobile/data/menu.js`
- `mobile/tests/mobile-catalog-visual.spec.js`
- `tests/mobile-e2e/catalog-visual-critical.spec.js`
- `mobile/assets/menu/sandwich-cayenne.png`
- `mobile/assets/menu/sandwich-supreme.png`
- `mobile/assets/menu/sandwich-mega.png`
- `mobile/assets/menu/sandwich-terminator.png`
- `mobile/assets/menu/tacos-cayenne.png`
- `mobile/assets/menu/galette.png`
- `mobile/assets/menu/cat-sandwich-cayenne.png`
- `mobile/assets/menu/cat-galette.png`
- `mobile/assets/menu/cat-tacos.png`
- `tasks/MOBILE-EXCELLENCE-PHASE12-IMAGE-CONVERGENCE-20261016.md`
- `plans/PLAN_MOBILE-EXCELLENCE-PHASE12-IMAGE-CONVERGENCE-20261016_2026-10-16.md`
- `reports/execution/MOBILE-EXCELLENCE-PHASE12-IMAGE-CONVERGENCE-20261016/report.md`
- `.cursor/ACTIVE_CYCLE.md`
- `reports/post_execute_latest.log`
- `reports/AGENT_ACTIVITY_LOG.md`

## SUBSYSTEMS_OFF_LIMITS

- Backend PHP/config and database/migrations — canonical files are read-only inputs.
- Pricing, order composition/payloads, payment, authentication/SMS and loyalty.
- POS, caisse, kiosk runtime, native iOS packaging/signing and frozen zones.

## INVARIANTS_AT_RISK

- Backend pricing remains the only pricing authority; this cycle does not modify price
  or formula metadata.
- `branch_id` isolation is unchanged; no API or branch data is touched.
- Order payload and `OrderStatus` behavior are unchanged.
- No dispatch or service symmetry is in scope.

## GATE_CONDITIONS

- None anticipated. No frozen zone, schema, auth, pricing, branch, order-service or
  dispatch code is in scope.

## Execution Steps

1. Confirm the plan review and reserve the declared mobile mapping/assets/test scope.
2. Replace obsolete mobile product/category image mappings with the canonical public
   filenames and copy the exact canonical binaries into `mobile/assets/menu/`.
3. Extend the existing visual regression test and targeted E2E contract to assert the
   canonical product paths, category paths, exact binary equality and unchanged
   non-visual/order invariants. E2E remains read-only and makes no write requests.
4. Run the post-execute hook, static checks, targeted catalog E2E, diff checks and
   record the evidence in the execution report.

### Required evidence

- `bash .cursor/hooks/safety-check.sh` before EXECUTE.
- `scripts/agent-activity-log.sh start` reservation and `preflight-execute.sh` for
  the exact allowlist.
- `node mobile/tests/mobile-catalog-visual.spec.js`.
- `npx playwright test tests/mobile-e2e/catalog-visual-critical.spec.js --config=tests/mobile-e2e/playwright.config.js`.
- `post-execute.sh`, `post-execute-guard.sh`, and `git diff --check`.
- The unchanged non-visual catalog SHA, prices, and `buildLineItem` SHA remain asserted;
  no price, option, order payload or write-flow code is edited.

## SYMMETRY_NOTE

N/A — `OrderService` and `FrontendOrderService` are not touched.

## SCOPE_PRESSURE


## ESCALATION


## Audit Status

[ ] Pending
[ ] PLAN_REVIEW_VERDICT: PASS
[ ] AUDIT_VERDICT: PASS
[ ] GPT_FINAL_AUDIT_VERDICT: PASS
[ ] Passed — cycle closed
[ ] Gate opened
