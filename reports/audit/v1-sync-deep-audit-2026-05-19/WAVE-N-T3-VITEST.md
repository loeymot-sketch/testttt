# Wave N T3 — Vitest JS

## Headline

**237 spec files / 1529 tests / 1518 passed / 8 failed / 3 skipped** — Duration 16.30s

Vitest config: `./vitest.config.mjs`
Branch: `heal/cms-pr1-quickwins-2026-05-18` HEAD `a9b745060`
Run timestamp: 02:10:49

## Failures (8 across 3 files)

### File 1 — `tests/js/kioskOfflineQueueV2.spec.js` (5 failures)
Suite: **kioskOfflineQueue v2**
- × renders the conflict modal entries and emits cancel/force events
- × keeps focus trapped inside the conflict modal
- × is accessible to axe when opened
- × shows an empty state when there are no conflict entries
- × tracks modal opening via the emitted hook

**Root cause** (all 5 share it): `TypeError: _ctx.$t is not a function` — the i18n global mock (`$t`) is not stubbed in the test mount, so the conflict-modal component crashes on render. Test-harness setup issue, not a regression in production code path.

### File 2 — `tests/js/posWizardComposerProfile.spec.js` (1 failure)
Suite: **POS wizard composer profile runtime contract**
- × keeps POS item grids consuming the item payload without a separate heuristic layer

`AssertionError: expected '<template>…[POS-V5-D…' to contain ':items="items"'`. The POS V5 grid template no longer exposes the literal `:items="items"` binding the sentinel asserts on — likely renamed/refactored (template now uses POS-V5-D… prefix). Sentinel needs to be updated to track the new binding, or the binding restored verbatim.

### File 3 — `tests/js/sentinels/f004KioskCancelReasonSent.spec.js` (2 failures)
Suite: **F-004 — Kiosk cancel sends whitelisted reason**
- × KioskPaymentComponent never posts cancel-status without reason key
  - `AssertionError: expected 'change-status/${this._lastOrder.id}`,…' to match /reason\s*:/`
- × KioskWaitingComponent customer cancel sends customer_request reason
  - `AssertionError: expected '<template>…' to match /…/${[^}]+}`[\s\S]{0,250}?reason\s*:\s*'customer_request'`

F-004 NF525-adjacent sentinel: the `reason:` key is missing (or moved out of the regex's 250-char window) on the `change-status/${order.id}` POST call from KioskPaymentComponent and KioskWaitingComponent. **Potential real regression** — cancel without whitelisted reason violates F-004 audit invariant. Needs investigation in Wave N follow-up.

## Verdict

**AMBER** — 1518/1529 pass (99.3%). 5 failures are test-harness i18n mock omission (low signal, no prod impact). 1 failure is a sentinel binding-name drift (POS V5 grid). **2 failures are F-004 NF525 cancel-reason sentinels and warrant a Wave N heal sub-task** to confirm whether KioskPayment/KioskWaiting still emit `reason:` on cancel POSTs.

Read-only audit. No code changes performed.
