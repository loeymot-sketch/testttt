import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
const ROOT = resolve(__dirname, '../../..');
const read = (rel) => readFileSync(resolve(ROOT, rel), 'utf8');

/**
 * M7-02 — parked-order recall silently dropped unavailable items/variations
 * (backend built warnings; frontend discarded them + showed unconditional
 * success). The cashier must be told the restored cart differs from what was
 * parked. recall() must carry the backend warnings; restoreOrder() must surface
 * any drop (purged items OR unavailable variations) as a warning, not success.
 */
describe('M7-02 parked recall surfaces dropped items/variations', () => {
    it('recall action carries the backend unavailable-variation warnings', () => {
        const store = read('resources/js/store/modules/posParked.js');
        expect(store).toMatch(/_recall_warnings\s*=\s*parkedOrder\.warnings/);
    });
    it('restoreOrder surfaces drops as a warning (not unconditional success)', () => {
        const comp = read('resources/js/components/admin/pos/ParkedOrdersComponent.vue');
        const idx = comp.indexOf('async restoreOrder');
        const block = comp.slice(idx, idx + 1400);
        expect(block).toMatch(/_recall_purged_item_ids/);
        expect(block).toMatch(/_recall_warnings/);
        expect(block).toMatch(/alertService\.warning/);
    });
});
