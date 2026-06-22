import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * @FK-ID FK-WAVE-N-KDS-OVERFLOW-001
 * @source Wave M M-KDS-6 F1 P0 (2026-05-24) + owner-mandate operational safety
 *
 * Sentinel for the KDS overflow chip — chef-visibility safety net.
 *
 * Wave M empirical finding (M-KDS-6 F1 P0 OPERATIONAL):
 *   `KdsV2Grid.vue:55` `slice(0, 8)` silently drops orders 9+ from the FIFO
 *   4×2 grid. There was NO overflow chip, NO count indicator, NO keyboard
 *   shortcut beyond [A]–[H]. Per owner verbatim mandate « chef qui pourrait
 *   sortir une commande incomplète » this is an operational safety risk:
 *   if the chef thinks the board fully shows the queue, orders 9+ silently
 *   age past SLA and the chef cannot see they exist.
 *
 * Heal (this commit): a fixed-position chip top-right of `.kds-v2` (which is
 * `position: relative`) appears when `activeOrders.length > 8`, showing
 * "+N <suffix>" with `role="status"` and `aria-live="polite"`. Independent
 * of the full S3 PROPOSAL Option A/B/C layout redesign (owner-gate) — this
 * is the operational safety net BEFORE the owner picks a layout option.
 *
 * Critical correctness invariant: the trigger reads `activeOrders.length`
 * (the partition the grid actually slices on line 55), NOT `orders.length`.
 * Using `orders.length` would falsely count recently-served PREPARED
 * orders (which live in the bottom strip via `recentlyServed`) and produce
 * a chip when no real backlog exists. Locked here.
 *
 * Structural invariants this sentinel locks:
 *   1. `overflowActiveCount` computed exists and is derived from
 *      `activeOrders.length`, NOT `orders.length` (the correctness anchor).
 *   2. The template renders the chip with `v-if="overflowActiveCount > 0"`.
 *   3. The chip carries `role="status"` and `aria-live="polite"` (a11y
 *      surface for screen readers + visual peripheral attention).
 *   4. The chip uses the canonical Cayenne red brand color (#F4501E) on
 *      white text — high-contrast WCAG AA verified, peripheral-visible.
 *   5. The pulse animation is neutralized under `prefers-reduced-motion: reduce`.
 *   6. The i18n key `label.kds_orders_waiting_more` exists in fr/en/ar
 *      (suffix-only phrasing, number-agnostic so "+1" and "+5" both read
 *      correctly without plural grammar awkwardness).
 *
 * Anti-régression: if any of these drifts, the chef once again loses
 * visibility on orders 9+ and operational safety regresses to the
 * M-KDS-6 F1 P0 state Wave M caught empirically.
 */
describe('KDS Wave N — Overflow chip safety net (FK-WAVE-N-KDS-OVERFLOW-001)', () => {
    const gridPath = resolve(
        process.cwd(),
        'resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue',
    );
    const gridSource = readFileSync(gridPath, 'utf8');
    const frJson = JSON.parse(
        readFileSync(resolve(process.cwd(), 'resources/js/languages/fr.json'), 'utf8'),
    );
    const enJson = JSON.parse(
        readFileSync(resolve(process.cwd(), 'resources/js/languages/en.json'), 'utf8'),
    );
    const arJson = JSON.parse(
        readFileSync(resolve(process.cwd(), 'resources/js/languages/ar.json'), 'utf8'),
    );

    it('grid exposes overflowActiveCount computed derived from activeOrders.length', () => {
        // The computed must exist as a named property.
        expect(gridSource).toMatch(/overflowActiveCount\s*\(\s*\)\s*\{/);
        // CRITICAL correctness anchor: the body must reference activeOrders.length,
        // NOT this.orders.length. The grid slices activeOrders (ACCEPT|PREPARING),
        // and recentlyServed (PREPARED) is rendered in the bottom strip — not "waiting".
        const computedMatch = gridSource.match(
            /overflowActiveCount\s*\(\s*\)\s*\{[\s\S]*?\n\s{0,8}\}/,
        );
        expect(computedMatch, 'expected overflowActiveCount body to be parseable').not.toBeNull();
        const body = computedMatch[0];
        expect(body).toMatch(/this\.activeOrders\.length/);
        // The threshold is 8 (grid capacity 4×2). Math.max(0, …) prevents a
        // negative value when activeOrders.length < 8 so the v-if stays cleanly off.
        expect(body).toMatch(/Math\.max\(\s*0\s*,\s*this\.activeOrders\.length\s*-\s*8\s*\)/);
        // Guard against the spec drift that would count orders.length (incl. PREPARED).
        expect(body).not.toMatch(/this\.orders\.length/);
    });

    it('template renders the overflow chip with v-if="overflowActiveCount > 0"', () => {
        expect(gridSource).toMatch(/v-if="overflowActiveCount\s*>\s*0"/);
        // The chip class must be the canonical one.
        expect(gridSource).toMatch(/class="kds-overflow-chip"/);
        // The chip must display the dynamic count via mustache interpolation.
        expect(gridSource).toMatch(/\+\{\{\s*overflowActiveCount\s*\}\}/);
        // The chip must consume the i18n key (no hardcoded suffix).
        expect(gridSource).toMatch(/\$t\(\s*['"]label\.kds_orders_waiting_more['"]\s*\)/);
    });

    it('chip carries role="status" and aria-live="polite" (a11y for screen readers)', () => {
        // Slice the chip block from the template so we assert ON the chip itself.
        const chipBlock = gridSource.match(
            /<div[\s\S]*?class="kds-overflow-chip"[\s\S]*?>[\s\S]*?<\/div>/,
        );
        expect(chipBlock, 'expected the chip block in template').not.toBeNull();
        expect(chipBlock[0]).toMatch(/role="status"/);
        expect(chipBlock[0]).toMatch(/aria-live="polite"/);
    });

    it('chip uses the Cayenne red brand color (#F4501E) with WCAG-AA dark text (#1A1A1A)', () => {
        const styleBlock = gridSource.match(/<style\s+scoped>[\s\S]*?<\/style>/);
        expect(styleBlock, 'expected <style scoped> block').not.toBeNull();
        const css = styleBlock[0];
        // Chip selector and brand color present together.
        expect(css).toMatch(/\.kds-overflow-chip\s*\{[^}]*background:\s*#F4501E/);
        // [stale-sentinel realign 2026-06-03] Text MUST be dark #1A1A1A, not white:
        // white on #F4501E = 3.46:1 (FAILS WCAG AA); #1A1A1A on #F4501E = 4.87:1 (PASSES).
        // The C-004 a11y fix corrected the source to dark text; this assertion lagged behind it.
        expect(css).toMatch(/\.kds-overflow-chip\s*\{[^}]*color:\s*#1A1A1A/);
        // z-index keeps the chip above grid cards.
        expect(css).toMatch(/\.kds-overflow-chip\s*\{[^}]*z-index:\s*100/);
        // Position absolute anchored top-right of the .kds-v2 (which is relative).
        expect(css).toMatch(/\.kds-overflow-chip\s*\{[^}]*position:\s*absolute/);
    });

    it('pulse animation is neutralized under prefers-reduced-motion: reduce', () => {
        const styleBlock = gridSource.match(/<style\s+scoped>[\s\S]*?<\/style>/);
        expect(styleBlock, 'expected <style scoped> block').not.toBeNull();
        const css = styleBlock[0];
        // The keyframe exists.
        expect(css).toMatch(/@keyframes\s+kds-overflow-pulse/);
        // The reduced-motion media query disables the animation on the chip class.
        const reducedMotionMatch = css.match(
            /@media\s*\(\s*prefers-reduced-motion\s*:\s*reduce\s*\)\s*\{[\s\S]*?\.kds-overflow-chip\s*\{[^}]*animation:\s*none/,
        );
        expect(
            reducedMotionMatch,
            'expected @media (prefers-reduced-motion: reduce) to set animation:none on .kds-overflow-chip',
        ).not.toBeNull();
    });

    it('i18n key kds_orders_waiting_more exists in fr/en/ar under label namespace', () => {
        expect(frJson.label.kds_orders_waiting_more, 'fr.label.kds_orders_waiting_more').toBeTruthy();
        expect(enJson.label.kds_orders_waiting_more, 'en.label.kds_orders_waiting_more').toBeTruthy();
        expect(arJson.label.kds_orders_waiting_more, 'ar.label.kds_orders_waiting_more').toBeTruthy();
        // The phrasing must be number-agnostic (no "{n}" placeholder) — the
        // template already injects "+{{ overflowActiveCount }}" as a prefix,
        // so the i18n value is the suffix only ("en attente" / "waiting" / "في الانتظار").
        // Guards against drift where someone adds "commandes en attente" which
        // reads "+1 commandes en attente" (plural mismatch with "1").
        expect(frJson.label.kds_orders_waiting_more).not.toMatch(/\{n\}|\{count\}/);
        expect(enJson.label.kds_orders_waiting_more).not.toMatch(/\{n\}|\{count\}/);
        expect(arJson.label.kds_orders_waiting_more).not.toMatch(/\{n\}|\{count\}/);
    });
});
