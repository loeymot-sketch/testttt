# RUN — V2-WIZARD-RT-REFACTOR-XL (Batch A)

Date: 2026-05-03  
TASK_ID: CV1-V2-REMAINING-MISSIONS-001  
Subtask: S04 (Wizard runtime XL) — Batch A (baseline + split strategy)

## Scope of Batch A

- Establish a safe baseline before touching `public/js/pos-wizard.js` (large legacy runtime file).
- Confirm current composer-aware path remains green under flag gates.
- Define bounded execution slices for XL refactor (A/B/C) to avoid blast radius.

## Evidence collected

### POS wizard runtime sentinels

- `npx vitest run tests/js/posWizardComposerAware.spec.js tests/js/posWizardComposerProfile.spec.js`
- Result: **10/10 PASS**

### Cross-impact status

- Studio batch remains green (previous run): `catalogStudioRouting` + global vitest pass.
- OPS readiness batch remains green (previous run): runtime flags + parity checks.

## XL split strategy (execution-ready)

### Batch B — Internal extraction without behavior change
- Target: `public/js/pos-wizard.js`
- Action: isolate pure helper blocks (normalizers/readers) behind local functions.
- Constraint: zero behavior change, no new flags.
- Proof: existing `posWizardComposerAware` sentinels unchanged.

### Batch C — Shared adapter seam for POS/Kiosk payload compatibility
- Target: `public/js/pos-wizard.js` + focused JS sentinel additions.
- Action: add adapter function to normalize composer step payload shape.
- Constraint: legacy path untouched when flag OFF.
- Proof: add 2-3 sentinel cases on malformed/missing step payloads.

### Batch D — Runtime hardening and docs
- Target: tests + execution report + rollout notes.
- Action: codify fallback guarantees and final pass matrix.
- Proof: sentinels + selective smoke.

## Risks and controls

- Risk: regressions in monolithic legacy file (`pos-wizard.js`).
- Control: one-file bounded edits per batch + sentinel lock before/after each batch.
- Risk: accidental behavior shift when flag OFF.
- Control: explicit OFF-path assertions in `posWizardComposerAware.spec.js`.

## Verdict (Batch A)

Batch A: **PASS** (baseline locked, refactor split ready, no product regression introduced).
