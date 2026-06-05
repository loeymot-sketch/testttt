import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
const ROOT = resolve(__dirname, '../../..');

/**
 * M1-01 — the no-sale "Ouvrir tiroir" must reach the backend NF525 audit route
 * (cash-drawer.open / F-7), not only the local hardware bridge — else the i18n
 * "Action tracée" promise is unfulfilled (nothing traced server-side).
 */
describe('M1-01 no-sale drawer backend audit wiring', () => {
    const src = readFileSync(resolve(ROOT, 'resources/js/components/admin/pos/PosComponent.vue'), 'utf8');
    it('triggerNoSaleOpenDrawer POSTs the backend cash-drawer/open audit route', () => {
        const start = src.indexOf('triggerNoSaleOpenDrawer: async function');
        expect(start).toBeGreaterThan(-1);
        const block = src.slice(start, start + 1400);
        expect(block).toMatch(/cash-drawer\/open/);
        expect(block).toMatch(/X-Idempotency-Key/);
    });
});
