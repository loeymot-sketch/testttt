import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-GAP-FIX-03 | @source Gap-Hunt Phase E S1-P1 GAP-002 (P1 quick-win XS)
 * @reason
 *   `is_rush` ORPHAN SIGNAL: backend computes it
 *   (app/Services/Kiosk/KioskMenuService.php::computeIsRush) and Vuex stores it
 *   (resources/js/store/modules/kioskMenu.js SET_BRANCH_FLAGS), but ZERO Vue
 *   components consume it. Client never sees "kitchen rush" warning.
 *
 *   Fix: mount rush banner in KioskWaitingComponent.vue (NON-frozen per
 *   CLAUDE.md §7) consuming Vuex getter `kioskMenu/kioskBranchFlags`. Banner
 *   shown ONLY when is_rush=true && !isReady (preparing state). Client
 *   renegotiates expectation BEFORE picking up the order.
 *
 * Sentinel guards (static source, no mount machinery):
 *   1. Banner DOM node present with data-testid hook + role/aria-live for a11y.
 *   2. Computed property `isRush` consumes the kioskMenu Vuex getter.
 *   3. i18n keys `kiosk.rush.active_title` + `kiosk.rush.subtitle` resolved in
 *      FR/EN/AR (no raw label leak).
 */
describe('Kiosk rush banner — is_rush signal consumer (GAP-FIX-03)', () => {
    const componentPath = resolve(
        process.cwd(),
        'resources/js/components/frontend/kiosk/KioskWaitingComponent.vue',
    );
    const source = readFileSync(componentPath, 'utf8');

    it('mounts a kiosk-rush-banner with role=status + aria-live + testid hook (a11y + e2e ready)', () => {
        // Banner DOM must exist with the WCAG live region pattern for assistive tech.
        const banner = source.match(/<div[^>]*class="kiosk-rush-banner"[^>]*>/);
        expect(banner).not.toBeNull();
        expect(banner[0]).toMatch(/role="status"/);
        expect(banner[0]).toMatch(/aria-live="polite"/);
        expect(banner[0]).toMatch(/data-testid="kiosk-rush-banner"/);
        // Visibility predicate: only when rush AND still preparing.
        expect(banner[0]).toMatch(/v-if="isRush\s*&&\s*!isReady"/);
    });

    it('isRush computed property pulls from kioskMenu Vuex getter (server-driven flag)', () => {
        // The orphan-signal heal must read the existing Vuex getter, NOT introduce
        // a new local source of truth. SSOT is kioskMenu.branchFlags (SET_BRANCH_FLAGS).
        expect(source).toMatch(/isRush\s*\(\s*\)\s*\{/);
        expect(source).toMatch(/this\.\$store\.getters\['kioskMenu\/kioskBranchFlags'\]/);
    });

    it('i18n keys kiosk.rush.active_title + kiosk.rush.subtitle resolved in FR/EN/AR', () => {
        // Template must use $t() — sentinel ensures no raw label leak ("Label.X").
        expect(source).toMatch(/\$t\(['"]kiosk\.rush\.active_title['"]\)/);
        expect(source).toMatch(/\$t\(['"]kiosk\.rush\.subtitle['"]\)/);

        // Verify each locale resolves both keys (no missing translation).
        for (const locale of ['fr', 'en', 'ar']) {
            const localePath = resolve(process.cwd(), `resources/js/languages/${locale}.json`);
            const dict = JSON.parse(readFileSync(localePath, 'utf8'));
            const rush = dict?.kiosk?.rush;
            expect(rush, `${locale}.json kiosk.rush block exists`).toBeDefined();
            expect(typeof rush.active_title, `${locale} active_title string`).toBe('string');
            expect(rush.active_title.length, `${locale} active_title non-empty`).toBeGreaterThan(0);
            expect(typeof rush.subtitle, `${locale} subtitle string`).toBe('string');
            expect(rush.subtitle.length, `${locale} subtitle non-empty`).toBeGreaterThan(0);
        }
    });
});
