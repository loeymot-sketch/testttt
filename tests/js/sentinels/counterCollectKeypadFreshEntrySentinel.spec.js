import { describe, expect, it } from 'vitest';
import { readFileSync } from 'fs';
import { resolve } from 'path';

/**
 * [GOAL-2026-05-29 BUG-CASH-KEYPAD] Owner-reported P1: clicking "Encaisser" →
 * Espèce → tapping the numpad gave "weird numbers".
 *
 * Root cause: PosCounterCollectModal pre-fills the received-amount field with
 * the order total ("8,50") and auto-selects it. Physical typing replaces the
 * selection, but `numpadInput` did blind concatenation
 * (`cashReceivedRaw + val`) → tapping "1" produced "8,501". This sentinel
 * locks the fresh-entry-on-first-tap fix + the single-decimal-separator guard
 * against regression.
 */
const src = readFileSync(
  resolve(process.cwd(), 'resources/js/components/admin/pos/PosCounterCollectModal.vue'),
  'utf-8',
);

describe('PosCounterCollectModal — cash keypad fresh-entry (owner Encaisser bug)', () => {
  it('declares the cashFieldPristine flag', () => {
    expect(src).toMatch(/cashFieldPristine\s*:\s*true/);
  });

  it('numpadInput starts a FRESH entry on first tap (does not append onto the pre-fill)', () => {
    // The pristine branch resets `base` to '' so the first digit replaces the
    // pre-filled total instead of concatenating ("8,50" + "1" → "8,501").
    expect(src).toMatch(/this\.cashFieldPristine\s*\?\s*['"]{2}\s*:/);
    // Naked concatenation onto cashReceivedRaw must be gone.
    expect(src).not.toMatch(/cashReceivedRaw\s*=\s*String\(this\.cashReceivedRaw\s*\|\|\s*['"]{2}\)\s*\+\s*val/);
  });

  it('guards against a second decimal separator', () => {
    expect(src).toMatch(/base\.includes\(['"],['"]\)\s*\|\|\s*base\.includes\(['"]\.['"]\)/);
  });

  it('marks the field non-pristine on physical input, backspace and clear', () => {
    // onReceivedInput + numpadBack + numpadClear all flip pristine off.
    const offCount = (src.match(/this\.cashFieldPristine\s*=\s*false/g) || []).length;
    expect(offCount).toBeGreaterThanOrEqual(3);
  });

  it('re-arms pristine when the modal pre-fills (watcher + setMode)', () => {
    const onCount = (src.match(/this\.cashFieldPristine\s*=\s*true/g) || []).length;
    expect(onCount).toBeGreaterThanOrEqual(2);
  });

  it('passes the FR decimal separator to the shared numpad', () => {
    expect(src).toMatch(/:decimal-separator=["']{1,2},["']{1,2}/);
  });
});
