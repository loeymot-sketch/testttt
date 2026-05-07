import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-CV1-KDS-A11Y-RICH-001
 * @source RED-R4 KD1 + KD2
 *
 * Sentinel guarantees that the KDS surface keeps :
 *   - role="article" + aria-labelledby on every order card column (4 cards)
 *   - id="order-{id}-title" on the order serial element each card
 *   - a single dedicated <div role="status" aria-live="polite"> region
 *     for ACCEPT → PREPARING → PREPARED transition announcements
 *   - a kdsAnnounceTransition() method called from orderStatus on success
 *
 * Anti-régression : if any of those structural a11y invariants vanishes,
 * screen-reader users lose access to the kitchen production state.
 *
 * NOTE : axe-core 0-violations is validated separately by the Playwright
 * E2E spec (cv1-kds-a11y-rich-2026-05-08). This sentinel only locks the
 * structure so it doesn't drift between commits.
 */
describe('KDS a11y rich — structure (CV1-KDS-A11Y-RICH-001)', () => {
    const componentSource = readFileSync(
        resolve(
            process.cwd(),
            'resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue',
        ),
        'utf8',
    );

    it('every order card carries role="article" (4 cards: dinein / online / takeaway / kiosk)', () => {
        const matches = componentSource.match(/role="article"/g) || [];
        expect(matches.length).toBeGreaterThanOrEqual(4);
    });

    it('every order card binds aria-labelledby to the per-order title id', () => {
        // Strict patterns for each card variable
        expect(componentSource).toMatch(/:aria-labelledby="'order-' \+ dineinOrder\.id \+ '-title'"/);
        expect(componentSource).toMatch(/:aria-labelledby="'order-' \+ onlineOrder\.id \+ '-title'"/);
        expect(componentSource).toMatch(/:aria-labelledby="'order-' \+ takeawayOrder\.id \+ '-title'"/);
        expect(componentSource).toMatch(/:aria-labelledby="'order-' \+ kioskOrder\.id \+ '-title'"/);
    });

    it('the order serial element exposes id="order-{id}-title" on each card', () => {
        // Each card has a :id binding using the same convention
        expect(componentSource).toMatch(/:id="'order-' \+ dineinOrder\.id \+ '-title'"/);
        expect(componentSource).toMatch(/:id="'order-' \+ onlineOrder\.id \+ '-title'"/);
        expect(componentSource).toMatch(/:id="'order-' \+ takeawayOrder\.id \+ '-title'"/);
        expect(componentSource).toMatch(/:id="'order-' \+ kioskOrder\.id \+ '-title'"/);
    });

    it('a single aria-live polite region exists with role="status" and aria-atomic', () => {
        expect(componentSource).toMatch(/role="status"/);
        expect(componentSource).toMatch(/aria-live="polite"/);
        expect(componentSource).toMatch(/aria-atomic="true"/);
        expect(componentSource).toMatch(/data-testid="kds-aria-live"/);
        // Bound to kdsAriaLiveMessage data property
        expect(componentSource).toMatch(/\{\{\s*kdsAriaLiveMessage\s*\}\}/);
    });

    it('kdsAnnounceTransition method is wired into orderStatus success path', () => {
        // Method definition
        expect(componentSource).toMatch(/kdsAnnounceTransition\s*\(\s*order\s*,\s*status\s*\)\s*\{/);
        // Called inside orderStatus.then(...)
        expect(componentSource).toMatch(/this\.kdsAnnounceTransition\(\s*order\s*,\s*status\s*\)/);
    });

    it('aria-live region is visually hidden via .sr-only (no kitchen layout reflow)', () => {
        expect(componentSource).toMatch(/class="sr-only kds-aria-live"/);
        // Visually-hidden CSS pattern (clip:rect(0,0,0,0) is the WCAG SR-only standard)
        expect(componentSource).toMatch(/clip:\s*rect\(0,\s*0,\s*0,\s*0\)/);
    });

    it('does NOT regress to a card without role="article" (defensive negative)', () => {
        // Negative: every <div ... :class="kdsWaitClass(...)"> for the 4 cards
        // MUST have role="article" near it. Approximation: count card wrappers
        // matching `kdsWaitClass(<var>)` and ensure each is followed within 200
        // chars by role="article".
        const cardWrapperRegex = /:class="(?:[^"]*?kdsWaitClass\(([^)]+)\)[^"]*?)"[\s\S]{0,300}?role="article"/g;
        const wrapperMatches = [...componentSource.matchAll(cardWrapperRegex)];
        expect(wrapperMatches.length).toBeGreaterThanOrEqual(4);
    });
});
