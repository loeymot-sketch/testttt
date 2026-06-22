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
    const t = typeof raw;
    if (t !== 'boolean' && t !== 'number' && t !== 'string') return false;
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

    it('snake_case key wins over dotted-key (preserves intentional 0)', () => {
        // ?? short-circuits on 0 → does NOT fallback to dotted-key
        expect(dineInEnabledFrom({
            pos_dine_in_enabled: 0,
            'pos.dine_in_enabled': 1,
        })).toBe(false);
        // snake_case = 1 wins regardless of dotted-key
        expect(dineInEnabledFrom({
            pos_dine_in_enabled: 1,
            'pos.dine_in_enabled': 0,
        })).toBe(true);
    });

    it('rejects non-strict boolean strings (true/TRUE/yes)', () => {
        expect(dineInEnabledFrom({ pos_dine_in_enabled: 'true' })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: 'TRUE' })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: 'yes' })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: 'on' })).toBe(false);
    });

    it('rejects numeric values other than 1 or "1"', () => {
        expect(dineInEnabledFrom({ pos_dine_in_enabled: 2 })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: -1 })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: 0.5 })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: '2' })).toBe(false);
    });

    it('rejects explicit null / NaN on the value', () => {
        expect(dineInEnabledFrom({ pos_dine_in_enabled: null })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: NaN })).toBe(false);
        // undefined on the value triggers ?? fallback to next key, then default 0
        expect(dineInEnabledFrom({ pos_dine_in_enabled: undefined })).toBe(false);
    });

    it('rejects non-primitive values (object, array, function)', () => {
        expect(dineInEnabledFrom({ pos_dine_in_enabled: {} })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: [] })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: [1] })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: () => 1 })).toBe(false);
    });

    it('coerces numeric 1 to "1" via String() comparison (intentional)', () => {
        // Documents the design choice: String(raw) === '1' is intentional
        // to handle backend payload variants (Eloquent cast 0/1 vs '0'/'1').
        expect(String(1)).toBe('1');
        expect(dineInEnabledFrom({ pos_dine_in_enabled: 1 })).toBe(true);
        expect(String('1')).toBe('1');
        expect(dineInEnabledFrom({ pos_dine_in_enabled: '1' })).toBe(true);
    });

    it('[V10 #1] strict typeof guard prevents String([1]) === "1" leak', () => {
        // Pre-V10 #1: dineInEnabledFrom({pos_dine_in_enabled: [1]}) returned true
        // because String([1]) === '1'. Hardened with typeof check.
        expect(dineInEnabledFrom({ pos_dine_in_enabled: [1] })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: ['1'] })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: Symbol('1') })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: 1n })).toBe(false); // BigInt
    });
});
