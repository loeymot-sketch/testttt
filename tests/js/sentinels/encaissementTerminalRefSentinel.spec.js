import { describe, expect, it } from 'vitest';
import { existsSync, readFileSync } from 'fs';
import { resolve } from 'path';

/**
 * [G-H unified-encaissement — 2026-06-05] Locks the non-frozen Terminal-manuel
 * (SumUp) reference heal on PosCounterCollectModal:
 *   - CARD mode is the manual Terminal (owner mandate: TPE not live → manual SumUp
 *     card→réf). The cashier captures the SumUp reference, persisted via the existing
 *     confirmCounterPayment `note` (no backend change, no frozen PaymentComponent touch).
 * Regression guard so the field + wiring + label are not silently dropped.
 */
describe('G-H Terminal-manuel SumUp reference (PosCounterCollectModal)', () => {
  const modalPath = resolve(__dirname, '../../../resources/js/components/admin/pos/PosCounterCollectModal.vue');
  const frPath = resolve(__dirname, '../../../resources/js/languages/fr.json');
  const source = existsSync(modalPath) ? readFileSync(modalPath, 'utf8') : '';
  const fr = existsSync(frPath) ? JSON.parse(readFileSync(frPath, 'utf8')) : {};
  const labels = fr.label || {};

  it('declares a cardReference data field', () => {
    expect(source).toMatch(/cardReference:\s*''/);
  });

  it('renders the reference input only for the CARD (Terminal) mode', () => {
    expect(source).toMatch(/selectedMode === 'CARD'/);
    expect(source).toMatch(/data-testid="pos-counter-collect-card-ref-input"/);
    expect(source).toMatch(/v-model="cardReference"/);
  });

  it('folds the reference into the persisted note via buildCollectNote()', () => {
    expect(source).toMatch(/buildCollectNote\s*\(\)/);
    expect(source).toMatch(/note:\s*this\.buildCollectNote\(\)/);
    expect(source).toMatch(/Terminal manuel \(SumUp\) — réf:/);
  });

  it('clears the reference on fresh-order open', () => {
    expect(source).toMatch(/this\.cardReference\s*=\s*''/);
  });

  it('relabels CARD to the manual Terminal and provides the ref i18n keys (fr)', () => {
    expect(labels.encaisser_mode_card).toBe('Terminal (manuel)');
    expect(labels.encaisser_terminal_ref).toBeTruthy();
    expect(labels.encaisser_terminal_ref_placeholder).toBeTruthy();
  });
});
