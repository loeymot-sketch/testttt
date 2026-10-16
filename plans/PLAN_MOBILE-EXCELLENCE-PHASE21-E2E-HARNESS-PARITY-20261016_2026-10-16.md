# Plan – MOBILE-EXCELLENCE-PHASE21-E2E-HARNESS-PARITY-20261016 – 2026-10-16

## TASK_ID
MOBILE-EXCELLENCE-PHASE21-E2E-HARNESS-PARITY-20261016

## PRIMARY_EXECUTION_MODEL
gpt-5.5-pro

## REASONING_EFFORT
xhigh

## PLAN_REVIEW
PLAN_REVIEW_CHANNEL: foodking-complex-implementer (codex-extension-fallback)
PLAN_REVIEW_MODEL: gpt-5.5-pro
PLAN_REVIEW_REASONING_EFFORT: xhigh
PLAN_REVIEW_VERDICT: PASS
FALLBACK_REASON: codex CLI rejected gpt-5.5-pro on the signed-in ChatGPT account after two attempts; fallback reviewer used.

### Review history

- Initial fallback review: `REWORK` — the mobile project still discovered unrelated historical suites,
  lacked an explicit reporter artifact, described the wrong `playwright-full-e2e` vocabulary, and needed
  the current D redemption contract plus explicit Wave E routing.
- Corrections applied: exact mobile test allowlist, configurable 8087 URL, current loyalty assertions,
  reporter output at `reports/antigravity/latest.md`, manual tracing removed, and separate kiosk command
  documented below.
- Re-review: `PASS` after the corrections were applied.
- Second review: `REWORK` — correct the Wave E root config path, add Surfaces and the
  Markdown reporter to the touched-set, make `MOBILE_URL` disable the local webServer,
  and remove remaining stale Wave D comments.
- Final re-review: `PASS` after the remaining Wave D comment was removed.

## PRIOR_CONTEXT

- Le code mobile actuel est servi par `tests/mobile-e2e/playwright.config.js` sur 8087 ; le serveur 8088 est l’aperçu manuel.
- Le dernier run global a observé des vagues A–D en 8081, des assertions D sur un profil/QR/historique historique et un conflit `Tracing has been already started`.
- Wave E vise le runtime kiosk Laravel et ne doit pas être interprétée comme une preuve de l’application mobile statique.

## SUBSYSTEMS_TOUCHED

| Subsystem | Scope | Read/Write | branch_id affected | Dispatch involved |
|---|---|---|---|---|
| Playwright mobile config | discovery, server contract, project partition | Write | No | No |
| Mobile design E2E waves A–D | canonical URL and current data-contract assertions | Write | No | No |
| Mobile Fluidity E2E | trace lifecycle only | Write | No | No |
| Mobile Surfaces E2E | mobile-only discovery allowlist | Read/Write | No | No |
| Antigravity Markdown reporter | latest QA evidence | Write | No | No |
| QA planning/report | evidence and next routing | Write | No | No |

## SUBSYSTEMS_OFF_LIMITS

- `mobile/` application code and assets — no product implementation in this phase.
- PHP backend, routes, database, migrations, pricing, `OrderService`, `FrontendOrderService`, payment/Mollie, loyalty policy, POS/caisse/KDS/kiosk runtime and iOS packaging.

## INVARIANTS_AT_RISK

- None in product runtime; the cycle must not alter pricing, order state, branch isolation or dispatch.
- Test assertions must not recreate fictitious customer data or claim kiosk parity from the mobile server.

## GATE_CONDITIONS

- None anticipated. If a test correction requires product/backend changes, stop and open a new bounded product phase.

## Test strategy

`playwright-critical-flow` for the mobile-only project (the allowlisted mobile specs in this plan).
Kiosk Wave E remains a separately routed follow-up; it is not evidence from the mobile project.

## Execution Steps

1. Replace hardcoded 8081 mobile URLs with `process.env.MOBILE_URL` and the canonical 8087 default.
2. Remove Wave E from the mobile-only discovery list; document the separate kiosk command/limitation.
3. Align Wave D with the current profile, loyalty configuration and history contract without hardcoded fictional identities.
4. Remove manual tracing start/stop that conflicts with Playwright’s configured trace lifecycle.
5. Run syntax/static checks, targeted waves, then the mobile-only suite; record exact counts and unrelated failures.

### Separate kiosk follow-up

Wave E must be run with the Laravel/kiosk Playwright configuration and its own runtime, for example:

`npx playwright test --config=playwright.config.js tests/e2e/test-e2e-mobile-design-full-wave-E.spec.js`

That command is intentionally not part of this mobile-only cycle.

## SYMMETRY_NOTE

N/A — no order service or frontend order service is touched.

## SCOPE_PRESSURE


## ESCALATION


## Audit Status

[ ] Pending
[x] PLAN_REVIEW_VERDICT: PASS
[ ] AUDIT_VERDICT: PASS
[ ] GPT_FINAL_AUDIT_VERDICT: PASS
[ ] Passed — cycle closed
[ ] Gate opened
