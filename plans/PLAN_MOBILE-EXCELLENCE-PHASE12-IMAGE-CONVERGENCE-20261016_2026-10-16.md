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

### Generated governance artifacts (not product scope)

- `missions/MOBILE-EXCELLENCE-PHASE12-IMAGE-CONVERGENCE-20261016/input.json` and
  any fallback execution brief/output required by the execution runner.
- `reports/audit/GPT_PLAN_REVIEW_MOBILE-EXCELLENCE-PHASE12-IMAGE-CONVERGENCE-20261016.md`
  and its raw log.
- `reports/audit/GPT_SELF_AUDIT_MOBILE-EXCELLENCE-PHASE12-IMAGE-CONVERGENCE-20261016.md`
  when the Codex execution path produces it.
- `reports/test_MOBILE-EXCELLENCE-PHASE12-IMAGE-CONVERGENCE-20261016_*.log`.

These generated files are evidence only; they do not authorize any additional product
file outside the exact allowlist above.

### Pre-existing worktree baseline

At PLAN time the following unrelated files were already dirty and are explicitly not
part of this cycle: `app/Http/Controllers/Admin/LabelController.php`, `routes/api.php`,
`tests/Feature/Labels/LabelControllerTest.php`, `resources/js/helpers/dlcLabelBuilder.js`,
`resources/js/helpers/labelLayout.js`, and `tests/js/labelLayout.spec.js`. Validation must capture
`git status --short` before and after EXECUTE and fail if any new non-allowlisted file
appears; these six baseline files must not be reverted, staged or edited.

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

1. Confirm the plan review. Capture the baseline above, then reserve the exact scope with:
   `bash scripts/agent-activity-log.sh start foodking-complex-implementer MOBILE-EXCELLENCE-PHASE12-IMAGE-CONVERGENCE-20261016 execute "mobile/data/menu.js,mobile/tests/mobile-catalog-visual.spec.js,tests/mobile-e2e/catalog-visual-critical.spec.js,mobile/assets/menu/sandwich-cayenne.png,mobile/assets/menu/sandwich-supreme.png,mobile/assets/menu/sandwich-mega.png,mobile/assets/menu/sandwich-terminator.png,mobile/assets/menu/tacos-cayenne.png,mobile/assets/menu/galette.png,mobile/assets/menu/cat-sandwich-cayenne.png,mobile/assets/menu/cat-galette.png,mobile/assets/menu/cat-tacos.png" "sync mobile product/category images to public canonical assets"`.
   Then run `bash scripts/preflight-execute.sh MOBILE-EXCELLENCE-PHASE12-IMAGE-CONVERGENCE-20261016 --agent=foodking-complex-implementer --scope="mobile/data/menu.js,mobile/tests/mobile-catalog-visual.spec.js,tests/mobile-e2e/catalog-visual-critical.spec.js,mobile/assets/menu/sandwich-cayenne.png,mobile/assets/menu/sandwich-supreme.png,mobile/assets/menu/sandwich-mega.png,mobile/assets/menu/sandwich-terminator.png,mobile/assets/menu/tacos-cayenne.png,mobile/assets/menu/galette.png,mobile/assets/menu/cat-sandwich-cayenne.png,mobile/assets/menu/cat-galette.png,mobile/assets/menu/cat-tacos.png"`.
2. Replace obsolete mobile product/category image mappings with the canonical public
   filenames and copy the exact canonical binaries into `mobile/assets/menu/`.
3. Extend the existing visual regression test and targeted E2E contract to assert the
   canonical product paths, category paths, exact binary equality and unchanged
   non-visual/order invariants. E2E remains read-only and makes no write requests.
4. Run the task-scoped hook first because it resets `reports/post_execute_latest.log`:
   `FOODKING_TASK_ID=MOBILE-EXCELLENCE-PHASE12-IMAGE-CONVERGENCE-20261016 bash .cursor/hooks/post-execute.sh`.
   Then append
   `EXECUTE_DELEGATION: foodking-complex-implementer (codex-extension-fallback)` and
   `FALLBACK_REASON: codex CLI rejected gpt-5.5-pro for the authenticated ChatGPT account`
   to the stable log and report, then run
   `bash scripts/post-execute-guard.sh MOBILE-EXCELLENCE-PHASE12-IMAGE-CONVERGENCE-20261016 --agent=foodking-complex-implementer --run=reports/execution/MOBILE-EXCELLENCE-PHASE12-IMAGE-CONVERGENCE-20261016/report.md`.
   Compare the post-EXECUTE `git status --short` to the captured baseline (not a naked
   HEAD diff), then run `git diff --check` and record all evidence.

### Required evidence

- `bash .cursor/hooks/safety-check.sh` before EXECUTE.
- The exact reservation and `scripts/preflight-execute.sh` command above.
- `node mobile/tests/mobile-catalog-visual.spec.js`.
- `npx playwright test tests/mobile-e2e/catalog-visual-critical.spec.js --config=tests/mobile-e2e/playwright.config.js`.
- `FOODKING_TASK_ID=MOBILE-EXCELLENCE-PHASE12-IMAGE-CONVERGENCE-20261016 bash .cursor/hooks/post-execute.sh`, `bash scripts/post-execute-guard.sh
  MOBILE-EXCELLENCE-PHASE12-IMAGE-CONVERGENCE-20261016 --agent=foodking-complex-implementer
  --run=reports/execution/MOBILE-EXCELLENCE-PHASE12-IMAGE-CONVERGENCE-20261016/report.md`,
  a baseline-aware allowlist comparison, and `git diff --check`.
- The unchanged non-visual catalog SHA is
  `dba1d13ba410398c0429ad164d937e28cffb91336cf176b0d384c20b313ae91d` and the
  unchanged `buildLineItem` SHA is
  `92fd480da9dc71da31397f6efcd17e4bdba7f06603b790da54777271020034d1`.
  The test must show prices/options unchanged, print source/destination SHA-256 pairs
  for all nine synchronized binaries, and install a request listener that fails on any
  E2E POST/PUT/PATCH/DELETE request.

## SYMMETRY_NOTE

N/A — `OrderService` and `FrontendOrderService` are not touched.

## SCOPE_PRESSURE


## ESCALATION

EXECUTE_BLOCKED: the required post-execute guard scans the whole dirty worktree and
cannot baseline-exclude the files being actively modified by another Claude process.
Execution must resume only after that parallel work is released or an isolated clean
worktree is explicitly selected.

## Audit Status

[ ] Pending
[ ] PLAN_REVIEW_VERDICT: PASS
[ ] AUDIT_VERDICT: PASS
[ ] GPT_FINAL_AUDIT_VERDICT: PASS
[ ] Passed — cycle closed
[ ] Gate opened
