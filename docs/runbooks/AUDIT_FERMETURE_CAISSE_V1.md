# Audit Fermeture Caisse V1

Purpose: replayable closure checklist for Caisse V1 Wave 1 / Wave 2 Option B after git persistence and human gates are complete.

Do not use this runbook to self-approve human gates.

## Preconditions

- `git status --porcelain` reviewed by a human.
- Untracked buckets persisted or explicitly discarded by a human.
- Frozen/schema/fiscal/cutover gates decided in `docs/gates/GATE_LOG.md`.
- Option B still active unless a new human gate explicitly changes it.

## Commands

```bash
git status --porcelain
php artisan test --filter='EventContractTest|AfterCommitDispatchTest|KioskRealtimeBroadcastTest'
php artisan test
npm run vitest
npx playwright test tests/Playwright/kiosk-errors.spec.js
npx playwright test tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts
npx playwright test
bash scripts/lint-fk-bundle-legacy.sh strict
```

For the staff-only routing flake gate:

```bash
for i in 1 2 3 4 5; do
  npx playwright test tests/e2e/06-staff-only-routing.spec.js --retries=0 || exit 1
done
```

## Binary Checklist

| # | Item | OUI/NON |
|---|---|---|
| 1 | `git status --porcelain \| grep -cE '^\?\?'` is <= 5, and leftovers are explicitly accepted. | |
| 2 | Modified tracked files match only the expected current release diff. | |
| 3 | `reports/audit/UNTRACKED_AUDIT_2026-04-26.txt` is tracked and human-reviewed. | |
| 4 | `reports/audit/MISSIONS_CLOSED_VS_GIT_2026-04-26.md` has 0 `REWORK_NOT_PERSISTED` rows. | |
| 5 | All non-frozen `missions/CV1-LOT-*/output_codex.json` files are tracked with `status=CLOSED`. | |
| 6 | Event/outbox/K09 backend filter passes. | |
| 7 | `ORDER_CREATED` requires `_origin`, `payment_method`, and `queue_number`. | |
| 8 | `ORDER_STATUS_CHANGED` requires `_origin`, `payment_method`, and `queue_number`. | |
| 9 | `npx playwright test tests/Playwright/kiosk-errors.spec.js` passes without config override. | |
| 10 | Tacos POS E2E passes locally. | |
| 11 | Root Playwright has 0 failed and 0 flaky tests across 5 consecutive closure runs if required by release owner. | |
| 12 | Full `php artisan test` passes. | |
| 13 | `npm run vitest` passes. | |
| 14 | Legacy bundle strict lint passes or shim is signed in `GATE_LOG.md`. | |
| 15 | `MASTERPLAY_QUEUE.md` marks Wave 1 and Wave 2 as CLOSED or human-blocked with reason. | |
| 16 | M-04A remains BLOCKED unless a new human gate explicitly changes Option B. | |
| 17 | `HG-W2_CUTOVER_DECISION_OR_POS_WIZARD_SHIM_ACCEPTANCE` is signed or explicitly refused. | |
| 18 | Each W2 commit diff stays within the relevant mission allowlist. | |
| 19 | `memory/episodes/caisse_v1_*.jsonl` updates are tracked and ingested or queued for ingest. | |
| 20 | No new TODO/FIXME was introduced beyond baseline. | |

## Closure Verdict

Closure is allowed only when every applicable item is OUI and the final independent audit returns PASS.
