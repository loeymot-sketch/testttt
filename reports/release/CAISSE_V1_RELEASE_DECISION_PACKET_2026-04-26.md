# CAISSE V1 RELEASE DECISION PACKET

Date: 2026-04-26  
Current recommendation: `HOLD`  
Basis: `reports/release/CAISSE_V1_FINAL_READINESS_WAVES_2026-04-26.md`

## 1. Scope

V1 is a restricted pilot, not a full payment-ledger launch.

Enabled/accepted:

- Backend quote-first and payment guard path for selected POS/kiosk flows.
- Payment Ledger Option B restricted pilot.
- Kiosk fiscal policy Option B POS finalize.
- KDS server authority with `expected_status`.
- Offline scope Option A: read-only menu and payment disabled.
- Web payment off V1.
- Stripe inactive prod V1 guard.

Not enabled without new gate:

- Full payment ledger.
- Split tender.
- Partial refund ledger.
- Offline CB/TR payment.
- Public web Stripe payment.
- Stripe active production payment.
- Full POS cutover if HG-W2 gates remain pending.

## 2. Gates

| Gate | Decision |
| --- | --- |
| `GATE_PAYMENT_LEDGER_V1_2026-04-25` | Approved — Option B restricted pilot |
| `GATE_FROZEN_ZONES_CAISSE_V1_2026-04-25` | Approved — Option C partial allowlist |
| `GATE_FISCAL_KIOSK_SCOPE_V1_2026-04-25` | Approved — Option B POS finalize |
| `GATE_KDS_BUMP_AUTHORITY_V1_2026-04-25` | Approved — Option B server authority with `expected_status` |
| `GATE_SCHEMA_MIGRATIONS_CAISSE_V1_2026-04-25` | Approved — Option A with rehearsal + backup |
| `GATE_OFFLINE_SCOPE_V1_2026-04-25` | Approved — Option A read-only menu, payment disabled |
| `GATE_WEB_PAYMENT_SCOPE_V1_2026-04-25` | Approved — Option B web payment off V1 |
| `GATE_STRIPE_CENTS_ACTIVE_2026-04-25` | Approved — Option B Stripe inactive prod V1 guard |
| `GATE_PAYMENT_PROP_MUTATION_2026-04-26` | Approved — Option A refactor |
| `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20` | Still old `PENDING_HUMAN_GATE`; needs reconciliation note against newer CV1 frozen gate |
| `HG-W2-1` | Pending POS V4 cutover |
| `HG-W2-2` | Blocked by HG-W2-3 |
| `HG-W2-3` | Pending KPI revision / LCP evidence |

## 3. Masterplay Status

All current CV1 masterplay missions are closed except:

- `CV1-M04A-PAYMENT-LEDGER-FULL`: `BLOCKED`, expected because ledger Option B was selected.

The last status file reports:

- `current_task`: `CV1-M22-POST-LAUNCH-OBSERVABILITY`
- `current_status`: `CLOSED`
- `ts_utc`: `2026-04-25T22:50:06Z`

## 4. Test Evidence

Local targeted checks passed:

- PHP critical suites: 82 targeted tests passed after one scoped enum rework.
- Vitest targeted suite: 4 files, 11 tests passed.
- Playwright source-level suite: 2 tests passed.
- Static guards: legacy imports PASS, branch LIKE guard PASS, enum status guard PASS, POS status guard PASS.
- Bundle guard coverage reworked: active Mix outputs under `public/js` are now scanned; default mode warns on remaining `pos-wizard` cutover references and strict release mode blocks them.
- `git diff --check` PASS.
- `npm run production` PASS.

Scoped rework applied:

- `app/Http/Requests/OrderStatusRequest.php` no longer uses hardcoded `16`; it uses `OrderStatus::CANCELED`.

## 5. E2E

Passed:

- Source-level KDS multi-screen contract.
- Source-level kiosk offline CB/TR refused sentinel.

Missing:

- Full browser runtime flows against a running target app:
  - POS cash;
  - POS selected pilot payment;
  - kiosk cash/offline refusal;
  - KDS bump conflict;
  - auth refresh;
  - OSS/admin branch visibility.

## 6. Hardware

Artifacts exist:

- hardware qualification checklist;
- hardware acceptance grid;
- hardware test protocols.

Missing:

- real TPE execution;
- printer execution;
- drawer execution;
- kiosk device execution;
- scanner execution;
- KDS tablet execution;
- network-loss execution;
- signed acceptance grid.

## 7. Fiscal

Code-level fiscal tests passed:

- fiscal Z kiosk routing;
- HMAC sealing;
- refund pre-Z;
- refund post-Z;
- void pre-Z;
- archive TTL.

Missing:

- real fiscal evidence packet;
- target secrets with at least 32 characters for `FISCAL_AUDIT_SECRET` and `FISCAL_Z_REPORT_SECRET`;
- external NF525/legal signoff or explicit accepted caveat.

## 8. Runtime

Tooling exists and fails closed.

Current blockers:

- `ops-preflight` needs staging rehearsal transcript.
- `ops-preflight` needs branch leak evidence.
- `app:preflight-production --strict` fails in local env because `APP_ENV=local`, `LOG_LEVEL=debug`, and fiscal secrets are too short.
- canary drill needs preflight report, pilot branch id, and metrics.
- post-launch checker needs M14/M15/LCP/anomaly/cadence evidence.

## 9. Security

Local red-team checks passed for:

- payment-confirm ability;
- machine/branch protection;
- duplicate TPE transaction reference;
- forbidden payment methods;
- web payment disabled;
- Stripe inactive guard;
- kiosk offline payment scope;
- branch isolation;
- KDS illegal transitions;
- quote tamper/replay/expiry.

## 10. Build / Bundle

Build:

- `npm run production` PASS.

Reworked:

- `scripts/scan-bundle-legacy.sh` and `scripts/lint-fk-bundle-legacy.sh` now scan both `public/build` and `public/js`.
- `.github/workflows/legacy-guards.yml` now triggers on `public/js/**`.
- Default guard mode warns on remaining cutover references.
- Strict release mode (`FK_LEGACY_STRICT_POS_WIZARD=1`) fails on:
  - `public/js/kiosk.js`
  - `public/js/kiosk-wizard.js`

Hold:

- `resources/views/master.blade.php` and `resources/views/admin-pos-v4.blade.php` still load `pos-wizard.js`.
- POS cutover gates `HG-W2-1` / `HG-W2-3` remain pending.

## 11. Residual Risks

| Risk | Severity | Required action |
| --- | --- | --- |
| Active `pos-wizard` references fail strict bundle release guard | P0 release | Resolve HG-W2 gates or explicitly accept legacy shim with test coverage. |
| No staging migration transcript | P0 release | Run real rehearsal with backup manifest. |
| No branch leak evidence file for ops preflight | P0 release | Produce exact branch_id evidence report. |
| Fiscal secrets invalid in local strict preflight | P0 if target env same | Set target env secrets and rerun preflight. |
| No hardware signatures | P0 field readiness | Execute hardware lab. |
| No full runtime E2E | P0 release | Run browser flows against target app. |
| No operator UAT | P1/P0 release depending pilot size | Execute UAT. |
| No live runbook drills | P1 release | Drill rollback/outbox/TPE/printer/KDS/kiosk loss. |

## 12. Final Recommendation

`FINAL_RECOMMENDATION: HOLD`

Do not mark GO yet. The code-level restricted pilot proof is mostly green, but the release proof is incomplete.

Immediate next decision:

`HG-W2_CUTOVER_DECISION_OR_POS_WIZARD_SHIM_ACCEPTANCE`

Minimum scope:

- decide whether `pos-wizard.js` is accepted legacy shim or blocked cutover debt;
- if accepted, add explicit release note and test; if blocked, open product code task under a gate;
- rerun `npm run production`, strict active bundle scan, POS pricing/status guards.

After that, execute real staging/runtime/hardware/UAT evidence before final human GO gate.

## 13. Human Final Gate

Decision: `PENDING_HUMAN_GATE`

Approver: `(not signed)`

Allowed values:

- GO
- HOLD
- NO-GO

Current packet recommendation: HOLD.
