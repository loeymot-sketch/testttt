/**
 * [abuse-e2e STOCK-GRID-01 2026-06-16] Regression guard for the stock-management product grid.
 *
 * The product cards used viewport breakpoints (`lg:grid-cols-3`) while the grid container is only
 * ~600px (behind a 260px category panel + app nav), so at a ~1200px laptop each card was 191px and
 * the flex-1 product name collapsed to ~9px ("Sandwich Cayenne" → "S a"). Fixed with a
 * container-responsive `repeat(auto-fill, minmax(220px, 1fr))` so cards never drop below 220px.
 *
 * jsdom does no layout, so the *behavioural* proof is the live Playwright re-measure (name no longer
 * clipped). This source guard just prevents a silent revert back to the viewport-breakpoint columns.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const SRC = readFileSync(
    resolve(process.cwd(), 'resources/js/components/admin/stock/StockRuptureDashboardComponent.vue'),
    'utf8',
);

describe('STOCK-GRID-01 — product grid is container-responsive, not viewport-locked', () => {
    it('uses auto-fill minmax(220px) so cards never starve the product name', () => {
        expect(SRC).toMatch(/grid-template-columns:\s*repeat\(\s*auto-fill\s*,\s*minmax\(\s*220px\s*,\s*1fr\s*\)\s*\)/);
    });

    it('no longer forces viewport columns on the grid class (the cause of the 9px name collapse)', () => {
        // the breakpoint column utilities must be gone from the element class attribute
        // (a mention inside the explanatory CSS comment is fine — only the class= matters)
        expect(SRC).not.toMatch(/class="[^"]*grid-cols-3/);
        expect(SRC).not.toMatch(/class="[^"]*sm:grid-cols-2/);
        expect(SRC).toMatch(/class="stock-product-grid grid gap-3"/);
    });
});
