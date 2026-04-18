import { describe, it, expect } from 'vitest';

/**
 * [POS-9.1.6] dine-in feature flag.
 *
 * Purely unit-test the dineInEnabled resolver without mounting the full
 * PosComponent (which pulls Vuex, router, Swiper, etc.).
 */
function dineInEnabledFrom(setting) {
    const s = setting || {};
    const raw = s.pos_dine_in_enabled ?? s['pos.dine_in_enabled'] ?? 0;
    return String(raw) === '1' || raw === true;
}

describe('POS dine-in feature flag', () => {
    it('defaults to false when setting is empty', () => {
        expect(dineInEnabledFrom({})).toBe(false);
        expect(dineInEnabledFrom(null)).toBe(false);
        expect(dineInEnabledFrom(undefined)).toBe(false);
    });

    it('stays false when the flag is explicitly 0 / false / empty', () => {
        expect(dineInEnabledFrom({ pos_dine_in_enabled: 0 })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: '0' })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: false })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: '' })).toBe(false);
    });

    it('flips to true when the flag is 1 / true / "1"', () => {
        expect(dineInEnabledFrom({ pos_dine_in_enabled: 1 })).toBe(true);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: '1' })).toBe(true);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: true })).toBe(true);
    });

    it('accepts the dotted-key variant pos.dine_in_enabled', () => {
        expect(dineInEnabledFrom({ 'pos.dine_in_enabled': '1' })).toBe(true);
    });
});
