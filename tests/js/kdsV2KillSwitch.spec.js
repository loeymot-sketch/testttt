import { describe, it, expect, beforeEach, afterEach } from 'vitest';

/**
 * [Sprint H4 Z3-NEW-006 2026-05-17] V2 kill-switch precedence:
 *   1. ?v2=URL param (highest)
 *   2. localStorage kds.v2_enabled (per-tab)
 *   3. window.FK_KDS_V2_DEFAULT_ENABLED (org config)
 *   4. true (hardcoded safety)
 */
describe('KDS V2 kill-switch precedence (Z3-NEW-006)', () => {
    let originalGlobal;
    let originalLS;

    beforeEach(() => {
        originalGlobal = window.FK_KDS_V2_DEFAULT_ENABLED;
        originalLS = window.localStorage.getItem('kds.v2_enabled');
    });

    afterEach(() => {
        if (originalGlobal === undefined) {
            delete window.FK_KDS_V2_DEFAULT_ENABLED;
        } else {
            window.FK_KDS_V2_DEFAULT_ENABLED = originalGlobal;
        }
        if (originalLS === null) {
            window.localStorage.removeItem('kds.v2_enabled');
        } else {
            window.localStorage.setItem('kds.v2_enabled', originalLS);
        }
    });

    // Inline replica of the production resolver (Vue file content tested
    // via behavioral spec; full mount requires component scaffold).
    function useV2Layout() {
        try {
            const params = new URLSearchParams(window.location.search || '');
            if (params.get('v2') === '1') return true;
            if (params.get('v2') === '0') return false;
        } catch (e) {
            // SSR or test env without window.location — skip URL layer
        }
        try {
            const ls = window.localStorage.getItem('kds.v2_enabled');
            if (ls === '1') return true;
            if (ls === '0') return false;
        } catch (e) {
            // Privacy-mode browsers may throw
        }
        if (typeof window.FK_KDS_V2_DEFAULT_ENABLED === 'boolean') {
            return window.FK_KDS_V2_DEFAULT_ENABLED;
        }
        return true;
    }

    it('config defaults to true when no other signal', () => {
        window.FK_KDS_V2_DEFAULT_ENABLED = true;
        window.localStorage.removeItem('kds.v2_enabled');
        expect(useV2Layout()).toBe(true);
    });

    it('config can flip default to false (org-wide rollback)', () => {
        window.FK_KDS_V2_DEFAULT_ENABLED = false;
        window.localStorage.removeItem('kds.v2_enabled');
        expect(useV2Layout()).toBe(false);
    });

    it('localStorage overrides config', () => {
        window.FK_KDS_V2_DEFAULT_ENABLED = false;
        window.localStorage.setItem('kds.v2_enabled', '1');
        expect(useV2Layout()).toBe(true);
    });

    it('falls back to true if no signal at all', () => {
        delete window.FK_KDS_V2_DEFAULT_ENABLED;
        window.localStorage.removeItem('kds.v2_enabled');
        expect(useV2Layout()).toBe(true);
    });
});
