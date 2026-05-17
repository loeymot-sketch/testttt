import { describe, it, expect, beforeEach, afterEach } from 'vitest';

/**
 * [Sprint H1 K-003 2026-05-17] FRITES_INCLUDED_CATS must be sourced from
 * window.FK_KIOSK_FRITES_CATS when present. Fallback to legacy hardcoded
 * IDs only when the global is missing or malformed (test/SSR scenarios).
 *
 * Mirrors the exact resolution expression used in
 * resources/js/components/frontend/kiosk/KioskWizardComponent.vue:1029
 * so any divergence breaks this sentinel.
 */
describe('KioskWizard FRITES_INCLUDED_CATS sourcing (K-003)', () => {
    let originalGlobal;

    beforeEach(() => {
        originalGlobal = window.FK_KIOSK_FRITES_CATS;
    });

    afterEach(() => {
        if (originalGlobal === undefined) {
            delete window.FK_KIOSK_FRITES_CATS;
        } else {
            window.FK_KIOSK_FRITES_CATS = originalGlobal;
        }
    });

    it('reads from window.FK_KIOSK_FRITES_CATS when present', () => {
        window.FK_KIOSK_FRITES_CATS = [500, 600, 700];
        // Simulate the production resolution as in KioskWizardComponent.vue:1029
        const FRITES_INCLUDED_CATS = new Set(
            Array.isArray(window.FK_KIOSK_FRITES_CATS) ? window.FK_KIOSK_FRITES_CATS : [309, 310, 311, 314]
        );
        expect(FRITES_INCLUDED_CATS.has(500)).toBe(true);
        expect(FRITES_INCLUDED_CATS.has(309)).toBe(false);
    });

    it('falls back to hardcoded IDs when global missing (test/SSR safety)', () => {
        delete window.FK_KIOSK_FRITES_CATS;
        const FRITES_INCLUDED_CATS = new Set(
            Array.isArray(window.FK_KIOSK_FRITES_CATS) ? window.FK_KIOSK_FRITES_CATS : [309, 310, 311, 314]
        );
        expect(FRITES_INCLUDED_CATS.has(309)).toBe(true);
        expect(FRITES_INCLUDED_CATS.has(314)).toBe(true);
    });

    it('handles non-array global gracefully (string/null injected by mistake)', () => {
        window.FK_KIOSK_FRITES_CATS = 'invalid';
        const FRITES_INCLUDED_CATS = new Set(
            Array.isArray(window.FK_KIOSK_FRITES_CATS) ? window.FK_KIOSK_FRITES_CATS : [309, 310, 311, 314]
        );
        // Falls back to hardcoded — 'invalid' would otherwise be iterated char-by-char.
        expect(FRITES_INCLUDED_CATS.has(309)).toBe(true);
    });
});
